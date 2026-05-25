<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

class TxyLightsReport extends Report
{
    public function generate(Operation $operation, $language)
    {
        try {
            // Obtener detalles de la operación
            $details = $this->get_operation_details($operation, $language);
            $operator = Operator::where('id', $operation['operator_id'])->get();
            $details['title'] = trans('report_txy_lights.title', [], $language);

            // Estructura para agrupar imágenes por task
            $taskImages = [];

            // Obtener todas las tareas de la operación
            $tasks = Task::where('operation_id', $operation->id)->get();

            // Crear directorio temporal para imágenes procesadas
            $tempFolderPath = storage_path('app/platform/temp/reports/imgs');
            File::ensureDirectoryExists($tempFolderPath, 0777);

            foreach ($tasks as $task) {

                // Obtener resultados para esta tarea
                $results_txy_lights = ResultsTxyLights::where('task_id', $task->id)->get();

                if ($results_txy_lights->isNotEmpty()) {
                    // Inicializar array para esta tarea si hay resultados
                    $taskImages[$task->id] = [
                        'task_description' => $task->description,
                        'images' => []
                    ];

                    foreach ($results_txy_lights as $result) {

                        // Obtener el nombre de la taxiway
                        $taxiway = DB::table('taxiways')->where('id', $result->txy_id)->first();
                        $taxiway_name = $taxiway ? $taxiway->name : 'Unknown';

                        // Obtener imágenes para este resultado
                        $images = LightsImage::where('txy_id', $result->id)->get();

                        foreach ($images as $image) {
                            try {

                                // Construir la ruta completa de S3 si es necesario
                                // Si image_path ya contiene la ruta completa, usa directamente:
                                $s3Path = $image->image_path;

                                // Si necesitas construir la ruta, descomenta y ajusta según tu estructura:
                                // $folderMapping = Operation::getFolderMapping();
                                // $s3Path = "{$folderMapping[$operation->type_id]}/{$operation->id}/images/{$image->image_path}";


                                // Verificar que la imagen existe en S3
                                if (!Storage::disk('s3')->exists($s3Path)) {
                                    Log::warning("Imagen no encontrada en S3: {$s3Path}");
                                    continue;
                                }

                                // Obtener la imagen de S3
                                $imageContent = Storage::disk('s3')->get($s3Path);
                                $imageFileName = basename($s3Path);
                                $tempImagePath = $tempFolderPath . '/' . uniqid() . '_temp_' . $imageFileName;

                                // Guardar la imagen temporalmente
                                File::put($tempImagePath, $imageContent);

                                // Procesar y optimizar la imagen
                                $optimizedImagePath = $this->optimizeImage($tempImagePath, $tempFolderPath);

                                // Eliminar la imagen temporal sin optimizar
                                if (File::exists($tempImagePath)) {
                                    File::delete($tempImagePath);
                                }

                                // Agregar la imagen optimizada a la colección
                                $taskImages[$task->id]['images'][] = [
                                    'local_path' => $optimizedImagePath,
                                    'taxiway_name' => $taxiway_name,
                                    'observations' => $result->observations ?? '',
                                    'original_path' => $image->image_path,
                                    'task_full_name' => $task->type->name . ' ' . $task->description
                                ];
                            } catch (\Exception $e) {
                                Log::error('Error procesando imagen: ' . $e->getMessage());
                                continue;
                            }
                        }
                    }
                }
            }

            // Debug info
            $totalImages = 0;
            foreach ($taskImages as $taskData) {
                $totalImages += count($taskData['images']);
            }

            // Construir string de todas las taxiways inspeccionadas
            $all_subjects_with_picture = [];
            $processedTaxiways = [];

            foreach ($taskImages as $taskData) {
                foreach ($taskData['images'] as $image) {
                    $taxiwayName = $image['taxiway_name'];
                    if (!in_array($taxiwayName, $processedTaxiways)) {
                        $processedTaxiways[] = $taxiwayName;
                        $all_subjects_with_picture[] = ['name' => $taxiwayName];
                    }
                }
            }

            // Crear el string de todos los subjects
            $all_subjects_string = "";
            $i = 0;
            foreach($all_subjects_with_picture as $subject){
                if($i != 0){
                    $all_subjects_string = $all_subjects_string. ', ';
                }
                $all_subjects_string = $all_subjects_string.$subject['name'];
                $i = $i+1;
            }

            // Contar tareas completadas
            $tasks_completed = $tasks->filter(function($task) {
                $statusValue = is_object($task->status) ? $task->status->id : $task->status;
                return $statusValue == 3;
            })->count();

            // Configurar rutas para el PDF
            $folderMapping = Operation::getFolderMapping();
            $fileName = 'txy_lights_report_op_' . $operation->id . '.pdf';
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
                'img_dpi' => 96,
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

            $html = view('reports.txylights', [
                'language' => $language,
                'details' => $details,
                'taskImages' => $taskImages,
                'operation' => $operation,
                'operator' => $operator,
                'tasks' => $tasks,
                'tasks_completed' => $tasks_completed,
                'all_subjects_string' => $all_subjects_string,
                'all_subjects_with_picture' => $all_subjects_with_picture
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
            $this->cleanupTempImages($taskImages, $tempFolderPath);

            // Mostrar el PDF al usuario
            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            Log::error('Error generando reporte TxyLights: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            // En caso de error, también limpiar archivos temporales
            if (isset($tempFolderPath)) {
                $this->cleanupTempImages($taskImages ?? [], $tempFolderPath);
            }
            return response()->json(['error' => 'Error generating report: ' . $e->getMessage()], 500);
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
    private function cleanupTempImages($taskImages, $tempFolderPath)
    {
        try {
            // Limpiar imágenes de tareas
            foreach ($taskImages as $taskData) {
                foreach ($taskData['images'] as $image) {
                    if (isset($image['local_path']) && File::exists($image['local_path'])) {
                        File::delete($image['local_path']);
                    }
                }
            }

            // Limpiar directorio temporal si está vacío
            if (File::isDirectory($tempFolderPath) && count(File::files($tempFolderPath)) === 0) {
                File::deleteDirectory($tempFolderPath);
            }
        } catch (\Exception $e) {
            Log::error('Error limpiando archivos temporales: ' . $e->getMessage());
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
        $maxWidth = 800;  // Ancho máximo en píxeles
        $maxHeight = 600; // Alto máximo en píxeles

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
