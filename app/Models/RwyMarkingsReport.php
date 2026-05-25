<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

class RwyMarkingsReport extends Report
{
    public function generate(Operation $operation, $language)
    {
        try {
            // Obtener detalles de la operación
            $details = $this->get_operation_details($operation, $language);
            $operator = Operator::where('id', $operation['operator_id'])->get();
            $details['title'] = trans('report_rwy_markings.title', [], $language);

            // Estructura para agrupar imágenes por task
            $taskImages = [];

            $runwayDiagram = null;

            // Obtener todas las tareas de la operación
            $tasks = Task::where('operation_id', $operation->id)->get();

            // Crear directorio temporal para imágenes procesadas
            $tempFolderPath = storage_path('app/platform/temp/reports/imgs');

            File::ensureDirectoryExists($tempFolderPath, 0777);

            $runway = Runway::find($operation->subject_id);

            // Calcular rotación del mapa para que la pista quede horizontal (bearing → 90° en pantalla)
            // GD imagerotate: positivo = CCW. Fórmula: 90 - bearing_normalizado_0_180
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
                    // Normalizar a 0-180 (runway bidireccional) y calcular rotación CCW
                    $rwBearingNorm = fmod($rwBearing, 180);
                    $mapRotation = $rwBearingNorm - 90;   // GD+ = CCW en pantalla, necesitamos CW para pasar bearing→90°
                    Log::info("[MARKINGS MAP] runway bearing={$rwBearing} norm={$rwBearingNorm} mapRotation={$mapRotation}");
                }
            }

            if ($runway) {
                $media = $runway->getFirstMedia('rwy_marking_diagram');

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
                        Log::warning('Could not load runway diagram: ' . $e->getMessage());
                    }
                }
            }

            foreach ($tasks as $task) {

                $validResults = ResultsRwyMarkings::where('task_id', $task->id)
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

                    $images = MarkingsImage::where('rwy_id', $result->id)
                        ->orderByRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(image_path, '/', -1), '.', 1) AS UNSIGNED)")
                        ->get();

                    foreach ($images as $image) {

                        $defects = MarkingDefect::where('image_id', $image->id)->get();

                        try {
                            // Verificar si existe en S3
                            $existsInS3 = Storage::disk('s3')->exists($image->image_path);

                            if (!$existsInS3) {
                                Log::error("      → IMAGEN NO ENCONTRADA EN S3: {$image->image_path}");
                                continue;
                            }

                            $imageContent = Storage::disk('s3')->get($image->image_path);

                            $imageFileName = basename($image->image_path);
                            $tempImagePath = $tempFolderPath . '/' . uniqid() . '_temp_' . $imageFileName;
                            File::put($tempImagePath, $imageContent);

                            // Obtener dimensiones ORIGINALES antes de optimizar
                            $originalSize = getimagesize($tempImagePath);
                            $origWidth = $originalSize[0];
                            $origHeight = $originalSize[1];

                            // Optimizar imagen (resize)
                            $optimizedImagePath = $this->optimizeImage($tempImagePath, $tempFolderPath);

                            // Eliminar temporal sin optimizar
                            if (File::exists($tempImagePath)) {
                                File::delete($tempImagePath);
                            }

                            // Obtener dimensiones OPTIMIZADAS
                            $optSize = getimagesize($optimizedImagePath);
                            $optWidth = $optSize[0];
                            $optHeight = $optSize[1];

                            // Calcular ratio de escala
                            $scaleX = $optWidth / $origWidth;
                            $scaleY = $optHeight / $origHeight;

                            // Buscar defectos de esta imagen (solo no removidos)
                            $defects = MarkingDefect::where('image_id', $image->id)
                                ->where(function ($q) {
                                    $q->whereNull('removed')->orWhere('removed', 0);
                                })
                                ->get();

                            $defectsData = [];
                            if ($defects->count() > 0) {
                                // Cargar la imagen optimizada para dibujar sobre ella
                                $imgResource = imagecreatefromjpeg($optimizedImagePath);

                                if ($imgResource) {
                                    // Cargar el marker PNG
                                    $markerPath = public_path('img/markers/green_Marker.png');

                                    $markerResource = null;
                                    if (file_exists($markerPath)) {
                                        $markerResource = imagecreatefrompng($markerPath);
                                        imagealphablending($markerResource, true);
                                        imagesavealpha($markerResource, true);
                                    }

                                    foreach ($defects as $defect) {
                                        // Escalar coordenadas del pixel original al tamaño optimizado
                                        $markerX = (int) round($defect->pixel_x * $scaleX);
                                        $markerY = (int) round($defect->pixel_y * $scaleY);

                                        if ($markerResource) {
                                            $mW = imagesx($markerResource);
                                            $mH = imagesy($markerResource);

                                            // Escalar marker si es muy grande para la imagen
                                            // Tamaño objetivo: ~30px alto en la imagen optimizada
                                            $targetH = 42;
                                            $ratio = $targetH / $mH;
                                            $targetW = (int) round($mW * $ratio);

                                            // Crear marker escalado
                                            $scaledMarker = imagecreatetruecolor($targetW, $targetH);
                                            imagealphablending($scaledMarker, false);
                                            imagesavealpha($scaledMarker, true);
                                            $transparent = imagecolorallocatealpha($scaledMarker, 0, 0, 0, 127);
                                            imagefill($scaledMarker, 0, 0, $transparent);
                                            imagecopyresampled($scaledMarker, $markerResource, 0, 0, 0, 0, $targetW, $targetH, $mW, $mH);

                                            // Posicionar: ancla en bottom-center (como el CSS translate(-50%, -84%))
                                            $destX = $markerX - (int) round($targetW / 2);
                                            $destY = $markerY - (int) round($targetH * 0.84);

                                            // Asegurar que no se sale de los límites
                                            $destX = max(0, min($destX, $optWidth - $targetW));
                                            $destY = max(0, min($destY, $optHeight - $targetH));

                                            // Dibujar marker con transparencia sobre la imagen
                                            imagealphablending($imgResource, true);
                                            $this->imageCopyAlphaWithOpacity($imgResource, $scaledMarker, $destX, $destY, $targetW, $targetH, 0.6);
                                            imagedestroy($scaledMarker);

                                            // Dibujar el defect_id en blanco dentro del marker
                                            $white = imagecolorallocate($imgResource, 255, 255, 255);
                                            $fontSize = 3; // Fuente GD built-in (1-5, 3 es buen tamaño para 42px marker)
                                            $text = (string) $defect->defect_id;
                                            $textWidth = imagefontwidth($fontSize) * strlen($text);
                                            $textHeight = imagefontheight($fontSize);

                                            // Centrar texto horizontalmente en el marker, y en la parte superior (la "cabeza" del pin)
                                            $textX = $destX + (int) round($targetW / 2) - (int) round($textWidth / 2);
                                            $textY = $destY + (int) round($targetH * 0.15); // ~15% desde arriba del marker (la zona circular)

                                            imagestring($imgResource, $fontSize, $textX, $textY, $text, $white);

                                        } else {
                                            // Fallback: dibujar círculo rojo si no se encuentra el marker PNG
                                            $red = imagecolorallocate($imgResource, 255, 0, 0);
                                            $white = imagecolorallocate($imgResource, 255, 255, 255);
                                            imagefilledellipse($imgResource, $markerX, $markerY, 28, 28, $white);
                                            imagefilledellipse($imgResource, $markerX, $markerY, 24, 24, $red);
                                        }

                                        $defectsData[] = [
                                            'defect_id'    => $defect->defect_id,
                                            'type_name'    => $defect->typeDefect->name ?? 'N/A',
                                            'severity'     => $defect->severity,
                                            'latitude'     => $defect->latitude,
                                            'longitude'    => $defect->longitude,
                                        ];
                                    }

                                    if ($markerResource) {
                                        imagedestroy($markerResource);
                                    }

                                    // Sobreescribir la imagen optimizada con los markers
                                    imagejpeg($imgResource, $optimizedImagePath, 90);
                                    imagedestroy($imgResource);

                                } else {
                                    Log::error("      ✗ No se pudo cargar la imagen optimizada para dibujar markers");
                                }
                            }

                            // Generate static map if image or defects have coordinates
                            $mapLocalPath = null;
                            $mapMarkers = [];

                            Log::info("[MARKINGS MAP] image_id={$image->id} lat={$image->latitude} lng={$image->longitude} heading={$image->heading} task_type='{$task->type->name}'");

                            if ($image->latitude && $image->longitude) {
                                $isOblique = stripos($task->type->name ?? '', 'oblique') !== false;
                                Log::info("[MARKINGS MAP] isOblique=" . ($isOblique ? 'true' : 'false'));

                                // Usar heading del SRT si está disponible; si no, calcular desde header opuesto
                                $bearing = null;
                                if ($isOblique) {
                                    if ($image->heading !== null) {
                                        $bearing = (float) $image->heading;
                                        Log::info("[MARKINGS MAP] bearing desde SRT heading={$bearing}");
                                    } elseif ($runway) {
                                        $headers = $runway->headers;
                                        $oppositeHeader = $headers->reject(fn($h) => $h->name === ($image->direction ?? ''))->first();
                                        Log::info("[MARKINGS MAP] image->direction='{$image->direction}' oppositeHeader=" . ($oppositeHeader ? $oppositeHeader->name : 'null'));
                                        if ($oppositeHeader && $oppositeHeader->threshold_latitude && $oppositeHeader->threshold_longitude) {
                                            $bearing = $this->computeBearing(
                                                (float) $image->latitude, (float) $image->longitude,
                                                (float) $oppositeHeader->threshold_latitude,
                                                (float) $oppositeHeader->threshold_longitude
                                            );
                                            Log::info("[MARKINGS MAP] bearing calculado={$bearing}");
                                        } else {
                                            Log::warning("[MARKINGS MAP] oppositeHeader sin coordenadas, no se puede calcular bearing");
                                        }
                                    }
                                }

                                $mapMarkers[] = [
                                    'lat'     => (float) $image->latitude,
                                    'lng'     => (float) $image->longitude,
                                    'color'   => 'red',
                                    'bearing' => $bearing,
                                ];
                            } else {
                                Log::warning("[MARKINGS MAP] imagen sin coordenadas, no se añade marker rojo");
                            }

                            foreach ($defectsData as $d) {
                                if ($d['latitude'] && $d['longitude']) {
                                    $mapMarkers[] = ['lat' => (float) $d['latitude'], 'lng' => (float) $d['longitude'], 'color' => 'green'];
                                }
                            }

                            Log::info("[MARKINGS MAP] total markers=" . count($mapMarkers) . " (red + " . (count($mapMarkers) - (empty($mapMarkers) ? 0 : 1)) . " green)");

                            if (!empty($mapMarkers)) {
                                $lats = array_column($mapMarkers, 'lat');
                                $lngs = array_column($mapMarkers, 'lng');
                                $mapLocalPath = $this->downloadStaticMap(
                                    array_sum($lats) / count($lats),
                                    array_sum($lngs) / count($lngs),
                                    $mapMarkers,
                                    $tempFolderPath,
                                    '', 700, 150, 16, 8, 3,
                                    $mapRotation
                                );
                                Log::info("[MARKINGS MAP] mapa generado: " . ($mapLocalPath ?? 'null'));
                            } else {
                                Log::warning("[MARKINGS MAP] sin markers, no se genera mapa");
                            }

                            $taskImages[$task->id]['images'][] = [
                                'image_id'       => $image->id,
                                'local_path'     => $optimizedImagePath,
                                'original_path'  => $image->image_path,
                                'task_full_name' => $task->type->name . ' ' . $task->description,
                                'comment'        => $image->comment ?? null,
                                'defects'        => $defectsData,
                                'map_local_path' => $mapLocalPath,
                            ];

                        } catch (\Exception $e) {
                            Log::error("      ✗ Error procesando imagen id={$image->id}: " . $e->getMessage());
                            Log::error("        Trace: " . $e->getTraceAsString());
                            continue;
                        }
                    }
                }

                $imgCount = count($taskImages[$task->id]['images']);
            }

            // === MAPA DE PISTA COMPLETA ===
            $runwayOverviewMap = null;
            try {
                // Recopilar todos los image_id del reporte
                $allImageIds = [];
                foreach ($taskImages as $taskData) {
                    foreach ($taskData['images'] as $img) {
                        if (!empty($img['image_id'])) {
                            $allImageIds[] = $img['image_id'];
                        }
                    }
                }

                if (!empty($allImageIds)) {
                    // Defectos únicos con coordenadas de toda la pista
                    $rows = MarkingDefect::whereIn('image_id', $allImageIds)
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->where('latitude', '!=', 0)
                        ->where('longitude', '!=', 0)
                        ->get(['unique_defect_id', 'latitude', 'longitude']);

                    // Deduplicar por unique_defect_id
                    $seen = [];
                    $overviewMarkers = [];
                    foreach ($rows as $def) {
                        $key = $def->unique_defect_id ?? $def->id;
                        if (isset($seen[$key])) continue;
                        $seen[$key] = true;
                        $overviewMarkers[] = [
                            'lat'   => (float) $def->latitude,
                            'lng'   => (float) $def->longitude,
                            'color' => 'green',
                        ];
                    }

                    if (!empty($overviewMarkers)) {
                        // Centro = punto medio entre los dos thresholds del runway
                        $headers = $runway ? $runway->headers : collect();
                        $h1 = $headers->get(0);
                        $h2 = $headers->get(1);

                        if ($h1 && $h2 && $h1->threshold_latitude && $h2->threshold_latitude) {
                            $centerLat = ((float)$h1->threshold_latitude  + (float)$h2->threshold_latitude)  / 2;
                            $centerLng = ((float)$h1->threshold_longitude + (float)$h2->threshold_longitude) / 2;
                        } else {
                            $lats = array_column($overviewMarkers, 'lat');
                            $lngs = array_column($overviewMarkers, 'lng');
                            $centerLat = array_sum($lats) / count($lats);
                            $centerLng = array_sum($lngs) / count($lngs);
                        }

                        $runwayOverviewMap = $this->downloadStaticMap(
                            $centerLat,
                            $centerLng,
                            $overviewMarkers,
                            $tempFolderPath,
                            '', 700, 320, 14, 5, 2,
                            $mapRotation
                        );

                        Log::info('[MARKINGS MAP] runway overview map generated: ' . ($runwayOverviewMap ?? 'null') . ' (' . count($overviewMarkers) . ' markers)');
                    } else {
                        Log::info('[MARKINGS MAP] no defects with coordinates for runway overview map');
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[MARKINGS MAP] error generating runway overview map: ' . $e->getMessage());
            }

            // Debug info
            $totalImages = 0;
            foreach ($taskImages as $taskData) {
                $totalImages += count($taskData['images']);
            }

            // Configurar rutas para el PDF
            $folderMapping = Operation::getFolderMapping();
            $fileName = 'rwy_markings_report_op_' . $operation->id . '.pdf';
            $tempPath = storage_path('app/platform/temp/reports/' . uniqid('report_', true) . '.pdf');

            // Asegurar que el directorio para el PDF existe
            File::ensureDirectoryExists(dirname($tempPath));

            // Configurar mPDF con optimizaciones
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
                // Optimizaciones para reducir tamaño
                'img_dpi' => 170,
                'dpi' => 96,
                'use_kwt' => true,
                'compress' => true,
                'CSSselectMedia' => 'mpdf',
                'setAutoTopMargin' => 'stretch',
                'setAutoBottomMargin' => 'stretch'
            ];

            // Asegurar que el directorio temp de mPDF existe
            File::ensureDirectoryExists($mpdfConfig['tempDir']);

            $pdf = new Mpdf($mpdfConfig);

            // Configuraciones adicionales para optimización
            $pdf->SetCompression(true);
            $pdf->simpleTables = true;
            $pdf->packTableData = true;

            $html = view('reports.rwymarkings', [
                'language'         => $language,
                'details'          => $details,
                'taskImages'       => $taskImages,
                'operation'        => $operation,
                'operator'         => $operator,
                'runwayDiagram'    => $runwayDiagram,
                'runwayOverviewMap' => $runwayOverviewMap,
            ])->render();

            // Generar contenido del PDF
            $pdf->WriteHTML($html);
            $pdf->Output($tempPath, 'F');

            // Subir PDF a S3
            $s3FileName = $fileName;
            $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
            $counter = 1;

            // Verificar si el archivo ya existe en S3 y generar un nombre único si es necesario
            while (Storage::disk('s3')->exists($s3PdfPath)) {
                $s3FileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$counter}." . pathinfo($fileName, PATHINFO_EXTENSION);
                $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
                $counter++;
            }

            // Subir el archivo a S3
            Storage::disk('s3')->put($s3PdfPath, File::get($tempPath));

            // Guardar en la tabla OperationReports
            OperationReports::create([
                'name' => $s3FileName,
                'description' => '',
                'type' => 'pdf',
                'size' => File::size($tempPath),
                'operation_id' => $operation->id,
            ]);

            // Limpiar archivos temporales del PDF
            File::delete($tempPath);

            // Limpiar todas las imágenes temporales
            $this->cleanupTempImages($taskImages, $runwayDiagram, $tempFolderPath, $runwayOverviewMap);

            // Mostrar el PDF al usuario
            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            // En caso de error, también limpiar archivos temporales
            if (isset($tempFolderPath)) {
                $this->cleanupTempImages($taskImages ?? [], $runwayDiagram ?? null, $tempFolderPath, $runwayOverviewMap ?? null);
            }
            return response()->json(['error' => 'Error generating report: ' . $e->getMessage()], 500);
        }
    }

    private function imageCopyAlphaWithOpacity($dst, $src, $dstX, $dstY, $srcW, $srcH, $opacity)
    {
        // $opacity: 0.0 (invisible) a 1.0 (opaco)
        for ($x = 0; $x < $srcW; $x++) {
            for ($y = 0; $y < $srcH; $y++) {
                $srcColor = imagecolorat($src, $x, $y);
                $srcAlpha = ($srcColor >> 24) & 0x7F; // 0=opaco, 127=transparente

                // Saltar píxeles totalmente transparentes
                if ($srcAlpha >= 125) continue;

                $srcR = ($srcColor >> 16) & 0xFF;
                $srcG = ($srcColor >> 8) & 0xFF;
                $srcB = $srcColor & 0xFF;

                // Aplicar opacidad al canal alfa
                $newAlpha = (int) min(127, $srcAlpha + (127 - $srcAlpha) * (1 - $opacity));

                $dstPx = $dstX + $x;
                $dstPy = $dstY + $y;

                if ($dstPx < 0 || $dstPy < 0 || $dstPx >= imagesx($dst) || $dstPy >= imagesy($dst)) continue;

                $color = imagecolorallocatealpha($dst, $srcR, $srcG, $srcB, $newAlpha);
                imagealphablending($dst, true);
                imagesetpixel($dst, $dstPx, $dstPy, $color);
            }
        }
    }


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

    private function cleanupTempImages($taskImages, $runwayDiagram, $tempFolderPath, $runwayOverviewMap = null)
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

            if ($runwayOverviewMap && File::exists($runwayOverviewMap)) {
                File::delete($runwayOverviewMap);
            }

            if (File::isDirectory($tempFolderPath) && count(File::files($tempFolderPath)) === 0) {
                File::deleteDirectory($tempFolderPath);
            }
        } catch (\Exception $e) {
            Log::error('Error generando reporte: ' . $e->getMessage());
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
}
