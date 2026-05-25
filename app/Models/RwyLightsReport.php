<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Symfony\Component\Yaml\Yaml;

class RwyLightsReport extends Report
{
    public function generate(Operation $operation, $language)
    {
        try {
            // Obtener detalles de la operación
            $details = $this->get_operation_details($operation, $language);
            $operator = Operator::where('id', $operation['operator_id'])->get();
            $details['title'] = trans('report_rwy_lights.title', [], $language);

            // Estructura para agrupar imágenes por task
            $taskImages = [];
            $runwayDiagram = null;

            // Obtener todas las tareas de la operación
            $tasks = Task::where('operation_id', $operation->id)
                ->orderBy('id', 'asc')
                ->get();

            // Crear directorio temporal para imágenes procesadas
            $tempFolderPath = storage_path('app/platform/temp/reports/imgs');
            File::ensureDirectoryExists($tempFolderPath, 0777);

            // === DIAGRAMA ===
            $runway = Runway::find($operation->subject_id);

            // Calcular rotación del mapa para que la pista quede horizontal (bearing → 90° en pantalla)
            $mapRotation = 0;
            if ($runway) {
                $rHeaders = $runway->headers;
                $rh1 = $rHeaders->get(0);
                $rh2 = $rHeaders->get(1);
                if ($rh1 && $rh2 && $rh1->threshold_latitude && $rh2->threshold_latitude) {
                    $rwBearing = $this->computeBearing(
                        (float) $rh1->threshold_latitude, (float) $rh1->threshold_longitude,
                        (float) $rh2->threshold_latitude, (float) $rh2->threshold_longitude
                    );
                    $rwBearingNorm = fmod($rwBearing, 180);
                    $mapRotation = $rwBearingNorm - 90;   // GD+ = CCW en pantalla, necesitamos CW para pasar bearing→90°
                    Log::info("[LIGHTS MAP] runway bearing={$rwBearing} norm={$rwBearingNorm} mapRotation={$mapRotation}");
                }
            }

            if ($runway) {
                $media = $runway->getFirstMedia('rwy_lights_diagram');

                if ($media) {
                    try {
                        // Obtener ruta local del diagrama (evita llamada HTTP)
                        $diagramSourcePath = $media->getPath();
                        $optimizedDiagramPath = $this->optimizeImage($diagramSourcePath, $tempFolderPath);

                        $runwayDiagram = [
                            'local_path' => $optimizedDiagramPath,
                            'runway_name' => $runway->name ?? 'Runway'
                        ];
                    } catch (\Exception $e) {
                        Log::warning('4b. Could not load runway diagram: ' . $e->getMessage());
                    }
                }
            }

            foreach ($tasks as $task) {

                $validResults = ResultsRwyLights::where('task_id', $task->id)
                    ->where('is_valid', 1)
                    ->get();

                if ($validResults->isEmpty()) {
                    continue;
                }

                $taskImages[$task->id] = [
                    'task_description' => $task->description,
                    'images' => []
                ];

                foreach ($validResults as $result) {

                    $images = LightsImage::where('results_rwy_lights_id', $result->id)
                        ->orderByRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(image_path, '/', -1), '.', 1) AS UNSIGNED)")
                        ->get();

                    foreach ($images as $image) {

                        try {
                            $existsInS3 = Storage::disk('s3')->exists($image->image_path);

                            if (!$existsInS3) {
                                continue;
                            }

                            $imageContent = Storage::disk('s3')->get($image->image_path);

                            $imageFileName = basename($image->image_path);
                            $tempImagePath = $tempFolderPath . '/' . uniqid() . '_temp_' . $imageFileName;
                            File::put($tempImagePath, $imageContent);

                            // Dimensiones originales
                            $originalSize = getimagesize($tempImagePath);
                            $origWidth = $originalSize[0];
                            $origHeight = $originalSize[1];

                            // Optimizar
                            $optimizedImagePath = $this->optimizeImage($tempImagePath, $tempFolderPath);

                            if (File::exists($tempImagePath)) {
                                File::delete($tempImagePath);
                            }

                            // Dimensiones optimizadas
                            $optSize = getimagesize($optimizedImagePath);
                            $optWidth = $optSize[0];
                            $optHeight = $optSize[1];

                            // Ratios de escala
                            $scaleX = $optWidth / $origWidth;
                            $scaleY = $optHeight / $origHeight;

                            // Buscar detecciones de esta imagen
                            $detections = LightsDetection::where('image_id', $image->id)->get();

                            // Datos para la tabla del reporte
                            $detectionsData = [];

                            if ($detections->count() > 0) {
                                $imgResource = imagecreatefromjpeg($optimizedImagePath);

                                if ($imgResource) {
                                    imagealphablending($imgResource, true);

                                    $green = imagecolorallocate($imgResource, 0, 255, 0);

                                    foreach ($detections as $detection) {
                                        imagesetthickness($imgResource, 3);

                                        if ($detection->bbox_x !== null && $detection->bbox_width && $detection->bbox_height) {
                                            // Detección con bbox real: dibujar recuadro escalado
                                            $x1 = (int) round($detection->bbox_x * $scaleX);
                                            $y1 = (int) round($detection->bbox_y * $scaleY);
                                            $x2 = (int) round(($detection->bbox_x + $detection->bbox_width) * $scaleX);
                                            $y2 = (int) round(($detection->bbox_y + $detection->bbox_height) * $scaleY);
                                        } else {
                                            // Detección antigua (solo pixel): recuadro fijo centrado
                                            $cx = (int) round($detection->pixel_x * $scaleX);
                                            $cy = (int) round($detection->pixel_y * $scaleY);
                                            $size = 15;
                                            $x1 = $cx - $size;
                                            $y1 = $cy - $size;
                                            $x2 = $cx + $size;
                                            $y2 = $cy + $size;
                                        }

                                        $x1 = max(0, min($x1, $optWidth - 1));
                                        $y1 = max(0, min($y1, $optHeight - 1));
                                        $x2 = max(0, min($x2, $optWidth - 1));
                                        $y2 = max(0, min($y2, $optHeight - 1));

                                        imagerectangle($imgResource, $x1, $y1, $x2, $y2, $green);

                                        $label = (string) ($detection->unique_detection_id ?? $detection->detection_number);
                                        $labelY = max(0, $y1 - 14);
                                        imagestring($imgResource, 3, $x1, $labelY, $label, $green);

                                        $detectionsData[] = [
                                            'detection_number' => $detection->detection_number,
                                            'type_name'        => $detection->type->name ?? 'N/A',
                                            'status_name'      => $detection->status->name ?? 'N/A',
                                            'status_id'        => $detection->status_id,
                                            'latitude'         => $detection->coordinate_latitude,
                                            'longitude'        => $detection->coordinate_longitude,
                                        ];
                                    }

                                    // Reset thickness
                                    imagesetthickness($imgResource, 1);

                                    // Guardar imagen con recuadros
                                    imagejpeg($imgResource, $optimizedImagePath, 90);
                                    imagedestroy($imgResource);

                                } else {
                                    Log::error("        ✗ No se pudo cargar la imagen para dibujar detecciones");
                                }
                            }

                            $taskFullName = ($task->type->name ?? 'N/A') . ' ' . $task->description;

                            // Mapa: posición de la foto (marker rojo) + detecciones con coords (markers azules)
                            $mapLocalPath = null;
                            $mapMarkers   = [];

                            $imageCoords = $this->getImageCoords($image);
                            if ($imageCoords) {
                                $bearing = null;
                                if ($runway) {
                                    $headers = $runway->headers;
                                    $oppositeHeader = $headers->reject(fn($h) => $h->name === $image->direction)->first();
                                    if ($oppositeHeader && $oppositeHeader->threshold_latitude && $oppositeHeader->threshold_longitude) {
                                        $bearing = $this->computeBearing(
                                            $imageCoords[0], $imageCoords[1],
                                            (float) $oppositeHeader->threshold_latitude,
                                            (float) $oppositeHeader->threshold_longitude
                                        );
                                    }
                                }
                                $mapMarkers[] = ['lat' => $imageCoords[0], 'lng' => $imageCoords[1], 'color' => 'red', 'bearing' => $bearing];
                            }

                            $coordRows = LightsDetection::where('image_id', $image->id)
                                ->whereNotNull('coordinate_latitude')
                                ->whereNotNull('coordinate_longitude')
                                ->where('coordinate_latitude', '!=', 0)
                                ->where('coordinate_longitude', '!=', 0)
                                ->get(['coordinate_latitude', 'coordinate_longitude']);

                            foreach ($coordRows as $d) {
                                $mapMarkers[] = ['lat' => (float) $d->coordinate_latitude, 'lng' => (float) $d->coordinate_longitude, 'color' => 'green'];
                            }

                            if (!empty($mapMarkers)) {
                                $lats = array_column($mapMarkers, 'lat');
                                $lngs = array_column($mapMarkers, 'lng');
                                $mapLocalPath = $this->downloadStaticMap(
                                    array_sum($lats) / count($lats),
                                    array_sum($lngs) / count($lngs),
                                    $mapMarkers,
                                    $tempFolderPath, '', 700, 150, 16, 8, 3,
                                    $mapRotation
                                );
                            }

                            $taskImages[$task->id]['images'][] = [
                                'image_id'       => $image->id,
                                'local_path'     => $optimizedImagePath,
                                'direction'      => $image->direction,
                                'original_path'  => $image->image_path,
                                'task_full_name' => $taskFullName,
                                'comment'        => $image->comment ?? null,
                                'detections'     => $detectionsData,
                                'map_local_path' => $mapLocalPath,
                            ];


                        } catch (\Exception $e) {
                            Log::error("        ✗ Error procesando imagen id={$image->id}: " . $e->getMessage());
                            Log::error("          Trace: " . $e->getTraceAsString());
                            continue;
                        }
                    }
                }

                $imgCount = count($taskImages[$task->id]['images']);
            }


            $taskIds = array_keys($taskImages);

            if (empty($taskIds)) {
                Log::error("6b. ✗ ERROR: No hay tasks con imágenes, no se puede generar el reporte");
                throw new \Exception('No images found for this operation');
            }

            sort($taskIds);
            $firstTaskId = min($taskIds);
            $secondTaskId = max($taskIds);

            $firstTaskImages = $taskImages[$firstTaskId]['images'];

            if (empty($firstTaskImages)) {
                Log::error("6d. ✗ ERROR: Primer task no tiene imágenes");
                throw new \Exception('First task has no images');
            }

            $directions = array_unique(array_column($firstTaskImages, 'direction'));

            if (count($directions) < 2) {
                Log::error("6e. ✗ ERROR: Se necesitan al menos 2 direcciones, encontradas: " . count($directions));
                throw new \Exception('Need at least 2 directions (images from both runway directions required), found: ' . count($directions));
            }

            $parsed = [];
            foreach ($directions as $dir) {
                preg_match('/^(\d+)(.*)$/', $dir, $matches);
                $parsed[] = [
                    'original' => $dir,
                    'number' => (int)($matches[1] ?? 0),
                    'letters' => $matches[2] ?? ''
                ];
            }
            usort($parsed, fn($a, $b) => $a['number'] <=> $b['number']);

            $firstDirection = $parsed[0]['original'];
            $secondDirection = $parsed[1]['original'];

            $imagesByDirection = [];

            $imagesByDirection[$firstDirection] = [];
            foreach ($taskIds as $taskId) {
                foreach ($taskImages[$taskId]['images'] as $image) {
                    if ($image['direction'] === $firstDirection) {
                        $imagesByDirection[$firstDirection][] = $image;
                    }
                }
            }

            $imagesByDirection[$secondDirection] = [];
            foreach (array_reverse($taskIds) as $taskId) {
                foreach ($taskImages[$taskId]['images'] as $image) {
                    if ($image['direction'] === $secondDirection) {
                        $imagesByDirection[$secondDirection][] = $image;
                    }
                }
            }

            // === MAPAS DE OVERVIEW POR DIRECCIÓN ===
            $directionMaps = [];

            foreach ([$firstDirection, $secondDirection] as $direction) {
                // Recopilar todos los image_id de esta dirección
                $imageIds = [];
                foreach ($taskIds as $tid) {
                    foreach ($taskImages[$tid]['images'] as $img) {
                        if ($img['direction'] === $direction && isset($img['image_id'])) {
                            $imageIds[] = $img['image_id'];
                        }
                    }
                }

                if (empty($imageIds)) continue;

                // Detecciones únicas con coordenadas (misma lógica que la vista de results)
                $rows = LightsDetection::whereIn('image_id', $imageIds)
                    ->whereNotNull('coordinate_latitude')
                    ->whereNotNull('coordinate_longitude')
                    ->where('coordinate_latitude', '!=', 0)
                    ->where('coordinate_longitude', '!=', 0)
                    ->get(['unique_detection_id', 'detection_number', 'coordinate_latitude', 'coordinate_longitude']);

                // Deduplicar por unique_detection_id (igual que la vista de results)
                $seen = [];
                $mapMarkers = [];
                foreach ($rows as $det) {
                    $key = $det->unique_detection_id ?? ('d_' . $det->detection_number);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $mapMarkers[] = [
                        'lat'   => (float) $det->coordinate_latitude,
                        'lng'   => (float) $det->coordinate_longitude,
                        'color' => 'green',
                    ];
                }

                if (empty($mapMarkers)) continue;

                // Centro = punto medio entre los dos thresholds del runway
                $headers = $runway ? $runway->headers : collect();
                $h1 = $headers->get(0);
                $h2 = $headers->get(1);

                if ($h1 && $h2 && $h1->threshold_latitude && $h2->threshold_latitude) {
                    $centerLat = ((float)$h1->threshold_latitude  + (float)$h2->threshold_latitude)  / 2;
                    $centerLng = ((float)$h1->threshold_longitude + (float)$h2->threshold_longitude) / 2;
                } else {
                    $lats = array_column($mapMarkers, 'lat');
                    $lngs = array_column($mapMarkers, 'lng');
                    $centerLat = array_sum($lats) / count($lats);
                    $centerLng = array_sum($lngs) / count($lngs);
                }

                $directionMaps[$direction] = $this->downloadStaticMap(
                    $centerLat,
                    $centerLng,
                    $mapMarkers,
                    $tempFolderPath,
                    '', 700, 320, 14, 5, 2,
                    $mapRotation
                );
            }

            $totalImages = 0;
            foreach ($taskImages as $taskData) {
                $totalImages += count($taskData['images']);
            }

            $folderMapping = Operation::getFolderMapping();
            $fileName = 'rwy_lights_report_op_' . $operation->id . '.pdf';
            $tempPath = storage_path('app/platform/temp/reports/' . uniqid('report_', true) . '.pdf');

            File::ensureDirectoryExists(dirname($tempPath));

            $mpdfConfig = [
                'tempDir' => storage_path('app/platform/temp/mpdf/'),
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 16,
                'margin_bottom' => 16,
                'margin_header' => 9,
                'margin_footer' => 9,
                'img_dpi' => 170,
                'dpi' => 96,
                'use_kwt' => true,
                'compress' => true,
                'CSSselectMedia' => 'mpdf',
                'setAutoTopMargin' => 'stretch',
                'setAutoBottomMargin' => 'stretch'
            ];

            File::ensureDirectoryExists($mpdfConfig['tempDir']);

            $pdf = new Mpdf($mpdfConfig);
            $pdf->SetCompression(true);
            $pdf->simpleTables = true;
            $pdf->packTableData = true;

            $html = view('reports.rwylights', [
                'language' => $language,
                'details' => $details,
                'taskImages' => $taskImages,
                'imagesByDirection' => $imagesByDirection,
                'directionMaps' => $directionMaps,
                'operation' => $operation,
                'operator' => $operator,
                'runwayDiagram' => $runwayDiagram
            ])->render();

            $pdf->WriteHTML($html);

            $pdf->Output($tempPath, 'F');

            // === SUBIR A S3 ===
            $s3FileName = $fileName;
            $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
            $counter = 1;

            while (Storage::disk('s3')->exists($s3PdfPath)) {
                $s3FileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$counter}." . pathinfo($fileName, PATHINFO_EXTENSION);
                $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
                $counter++;
            }

            Storage::disk('s3')->put($s3PdfPath, File::get($tempPath));

            OperationReports::create([
                'name' => $s3FileName,
                'description' => '',
                'type' => 'pdf',
                'size' => File::size($tempPath),
                'operation_id' => $operation->id,
            ]);

            File::delete($tempPath);
            $this->cleanupTempImages($taskImages, $runwayDiagram, $tempFolderPath);

            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            Log::error("✗ LIGHTS REPORT ERROR: " . $e->getMessage());
            Log::error("  File: " . $e->getFile() . ":" . $e->getLine());

            if (isset($tempFolderPath)) {
                $this->cleanupTempImages($taskImages ?? [], $runwayDiagram ?? null, $tempFolderPath);
            }
            throw $e;
        }
    }

    /**
     * Optimiza y guarda una imagen reduciendo calidad y tamaño
     */
    private function optimizeAndSaveImage($imageContent, $outputPath, $quality = 70)
    {
        // Crear imagen desde string
        $sourceImage = imagecreatefromstring($imageContent);

        if (!$sourceImage) {
            throw new \Exception('Could not create image from content');
        }

        // Obtener dimensiones originales
        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        // Calcular nuevas dimensiones (máximo 1200px de ancho)
        $maxWidth = 1200;
        if ($originalWidth > $maxWidth) {
            $ratio = $maxWidth / $originalWidth;
            $newWidth = $maxWidth;
            $newHeight = intval($originalHeight * $ratio);
        } else {
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
        }

        // Crear imagen redimensionada
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Fondo blanco para JPEGs
        $white = imagecolorallocate($resizedImage, 255, 255, 255);
        imagefill($resizedImage, 0, 0, $white);

        // Redimensionar
        imagecopyresampled(
            $resizedImage, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        // Guardar como JPEG con calidad reducida
        imagejpeg($resizedImage, $outputPath, $quality);

        // Liberar memoria
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);
    }

    /**
     * Limpia todos los archivos temporales de imágenes
     */
    private function cleanupTempImages($taskImages, $runwayDiagram, $tempFolderPath)
    {
        try {
            foreach ($taskImages as $taskData) {
                foreach ($taskData['images'] as $image) {
                    if (isset($image['local_path']) && File::exists($image['local_path'])) {
                        File::delete($image['local_path']);
                    }
                    if (isset($image['map_local_path']) && $image['map_local_path'] && File::exists($image['map_local_path'])) {
                        File::delete($image['map_local_path']);
                    }
                }
            }

            if ($runwayDiagram && isset($runwayDiagram['local_path']) && File::exists($runwayDiagram['local_path'])) {
                File::delete($runwayDiagram['local_path']);
            }

            if (File::isDirectory($tempFolderPath) && count(File::files($tempFolderPath)) === 0) {
                File::deleteDirectory($tempFolderPath);
            }
        } catch (\Exception $e) {
            Log::error('Error generando reporte: ' . $e->getMessage());
            throw $e;
        }
    }

    private function optimizeImage($sourcePath, $outputFolder)
    {
        // Obtener información de la imagen
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \Exception('No se pudo obtener información de la imagen');
        }

        // Crear recurso de imagen según el tipo
        $sourceImage = $this->icreate($sourcePath);
        if (!$sourceImage) {
            throw new \Exception('No se pudo crear el recurso de imagen');
        }

        // Obtener dimensiones originales
        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        // Definir dimensiones máximas para el PDF
        $maxWidth = 1150;  // Ancho máximo en píxeles
        $maxHeight = 900; // Alto máximo en píxeles

        // Calcular nuevas dimensiones manteniendo la proporción
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);

        // Solo redimensionar si la imagen es más grande que los límites
        if ($ratio < 1) {
            $newWidth = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);
        } else {
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
        }

        // Crear nueva imagen con las dimensiones optimizadas
        $optimizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preservar transparencia para PNG
        if ($imageInfo['mime'] == 'image/png') {
            imagealphablending($optimizedImage, false);
            imagesavealpha($optimizedImage, true);
            $transparent = imagecolorallocatealpha($optimizedImage, 255, 255, 255, 127);
            imagefilledrectangle($optimizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Redimensionar la imagen
        imagecopyresampled(
            $optimizedImage,
            $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        // Generar nombre único para la imagen optimizada
        $optimizedFileName = uniqid('opt_') . '.jpg'; // Siempre guardamos como JPG para mejor compresión
        $optimizedPath = $outputFolder . '/' . $optimizedFileName;

        // Guardar la imagen optimizada con calidad reducida
        // Calidad de 75-80 es un buen balance entre tamaño y calidad visual
        imagejpeg($optimizedImage, $optimizedPath, 75);

        // Liberar memoria
        imagedestroy($sourceImage);
        imagedestroy($optimizedImage);

        return $optimizedPath;
    }

    private function icreate($filename)
    {
        $isize = getimagesize($filename);
        if ($isize['mime'] == 'image/jpeg') {
            return imagecreatefromjpeg($filename);
        } elseif ($isize['mime'] == 'image/png') {
            return imagecreatefrompng($filename);
        } elseif ($isize['mime'] == 'image/gif') {
            return imagecreatefromgif($filename);
        } elseif ($isize['mime'] == 'image/webp') {
            return imagecreatefromwebp($filename);
        }

        return false;
    }

    private function resizeAspectW($image, $width)
    {
        $aspect = imagesx($image) / imagesy($image);
        $height = $width / $aspect;
        $new = imagecreatetruecolor($width, $height);

        imagecopyresampled($new, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        return $new;
    }

    private function resizeAspectH($image, $height)
    {
        $aspect = imagesx($image) / imagesy($image);
        $width = $height * $aspect;
        $new = imagecreatetruecolor($width, $height);

        imagecopyresampled($new, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        return $new;
    }

    private function getImageCoords(LightsImage $image): ?array
    {
        try {
            $imageName  = basename($image->image_path);
            $folderPath = dirname($image->image_path);

            if ($image->type_upload === 'video') {
                $yamlPath = $folderPath . '/info_frames.yaml';
                if (!Storage::disk('s3')->exists($yamlPath)) return null;

                $data     = Yaml::parse(Storage::disk('s3')->get($yamlPath));
                $imageKey = pathinfo($imageName, PATHINFO_FILENAME);
                $pos      = $data[$imageKey]['drone_position'] ?? null;

                if (!$pos || count($pos) < 2) return null;
                return [(float) $pos[0], (float) $pos[1]];
            }

            // Foto directa: leer EXIF
            $imageContents = Storage::disk('s3')->get($image->image_path);
            $tempPath      = storage_path('app/platform/temp/lights_exif_tmp.jpg');
            file_put_contents($tempPath, $imageContents);

            try {
                $img       = new \Imagick($tempPath);
                $imageInfo = $img->getImageProperties('exif:*');
                $img->destroy();
            } finally {
                @unlink($tempPath);
            }

            $lat = $this->exifGps($imageInfo, 'GPSLatitude',  'GPSLatitudeRef');
            $lng = $this->exifGps($imageInfo, 'GPSLongitude', 'GPSLongitudeRef');

            if ($lat === null || $lng === null) return null;
            return [$lat, $lng];

        } catch (\Exception $e) {
            return null;
        }
    }

    private function exifGps(array $info, string $key, string $refKey): ?float
    {
        if (!isset($info["exif:$key"], $info["exif:$refKey"])) return null;

        $parts = preg_split('/,\s*/', $info["exif:$key"]);
        if (count($parts) !== 3) return null;

        $toDecimal = function (string $v): float {
            $v = trim($v);
            if (str_contains($v, '/')) {
                [$n, $d] = explode('/', $v);
                return $d != 0 ? (float)$n / (float)$d : 0.0;
            }
            return (float)$v;
        };

        $dec = $toDecimal($parts[0]) + $toDecimal($parts[1]) / 60 + $toDecimal($parts[2]) / 3600;
        return in_array($info["exif:$refKey"], ['N', 'E']) ? $dec : -$dec;
    }
}
