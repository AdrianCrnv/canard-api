<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Intervention\Image\Facades\Image;

class AlsReport extends Report {

    use HasFactory;

    public function generateOLD(Operation $operation, $language){

        $als = Als::where('header_id', $operation['subject_id'])->first();
        $diagramUrl = $als->getFirstMediaUrl('als_diagram');

        $details = $this->get_operation_details($operation, $language);

        $operator = Operator::where('id', $operation['operator_id'])->get();

        $details['title'] = trans('report_als.title', [], $language);
        $images = [];
        $results = [];

        $tasks = Task::where('operation_id', $operation->id)->get();

        function icreate($filename)
        {
            $isize = getimagesize($filename);
            if ($isize['mime']=='image/jpeg')
                return imagecreatefromjpeg($filename);
            elseif ($isize['mime']=='image/png')
                return imagecreatefrompng($filename);
        }

        function resizeAspectW($image, $width)
        {
            $aspect = imagesx($image) / imagesy($image);
            $height = $width / $aspect;
            $new = imageCreateTrueColor($width, $height);

            imagecopyresampled($new, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            return $new;
        }

        function resizeAspectH($image, $height)
        {
            $aspect = imagesx($image) / imagesy($image);
            $width = $height * $aspect;
            $new = imageCreateTrueColor($width, $height);

            imagecopyresampled($new, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            return $new;
        }

        foreach ($tasks as $task) {
            $result_als = ResultsAls::where('task_id', $task->id)->first();
            $result = ResultsAls::where('task_id', $task->id)->first();

            if(!empty($result)){
                $measurements = MeasurementAls::where('result_id', $result->id)->get();
                $task_type = TaskType::find($task->type_id);
                switch ($task_type->id) {
                    case 29:
                        $name = 'Vertical Angle';
                        break;
                    case 30:
                        $name = 'Angular Coverage';
                        break;
                    case 31:
                        $name = 'Lights Intensity';
                        break;
                    case 32:
                        $name = 'Dirt and Aging';
                        break;
                    default:
                        die('Error');
                        break;
                }

                foreach ($measurements as $measurement) {
                    if($measurement->is_measurement_valid){
                        $image_type = DB::table('image_types')->find($measurement->image_type);
                        $medias = Media::where('name', $measurement->image_name)->get();

                        foreach ($medias as $media) {
                            $media_path = $media->id . '/' . $media->file_name;
                            $s3_img = Storage::disk('s3')->get($media_path);
                            Storage::disk('public')->put($media->file_name, $s3_img);

                            //I need change resolution of file
                            $imagefile = asset('app/public/'.$media->file_name);
                            $filename = $media->file_name;

                            //Save resize img.jpg
                            $imgh = icreate($imagefile);
                            $imgr = resizeAspectH($imgh, 1040);
                            $resizeimg = imagejpeg($imgr, 'app/public/'.$media->file_name);

                            array_push($images, array(
                                'id'            => $image_type->id,
                                'file_name'     => $media->file_name,
                                'task_type'     => $name,
                                'image_type'    => $image_type->name,
                            ));

                        }
                    }
                    array_push($results, array(
                        'task_type' => $name,
                        'comments' => $result_als->observations,
                    ));
                }
            }
        }

        $id = array_column($images, 'id');
        $id_results = array_column($results, 'comments');
        array_multisort($id, SORT_ASC, $images);
        array_multisort($id_results, SORT_ASC, $results);

        $path = storage_path('app/platform/temp/');

        $pathCopy = storage_path('app/platform/temp2/');

        // Create the temp dir if it does not exist
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        // ESTO CREA LA CARPETA TEMP2 SI NO EXISTE COMO YA SE CREO NO HACE FALTA
        if(!file_exists($pathCopy)) {
            mkdir($pathCopy, 0755, true);
        }

        $fileName = 'als_report_op_' . $operation->id . '.pdf';
        $filePath = $path . $fileName;
        $filePathCopy = $pathCopy . $fileName;

        // Generate the PDF content
        $view = view('reports.als', compact('language', 'diagramUrl', 'details', 'results', 'images', 'operator', 'operation'))->render();

        // Create an instance of mPDF
        $pdf = new Mpdf();

        $pdf->WriteHTML($view);

        // Guardar el archivo PDF en la carpeta temp
        $pdf->Output($filePath, 'F');

        // Copiar el archivo a la carpeta temp2
        copy($filePath, $filePathCopy);

        // Add the file to medialibrary and associate it to the operation
        $file = $operation
            ->addMedia($filePath)
            ->withCustomProperties(['language' => $language])
            ->toMediaCollection('reports');

        // Return the file as response
        return response()->file($filePathCopy);
    }

    public function generate(Operation $operation, $language)
    {
        $folderMapping = Operation::getFolderMapping();
        $fileName = 'als_report_op_' . $operation->id . '.pdf';
        $tempFolderPath = public_path('temp/reports/imgs');

        // Continuar con el código para generar el PDF
        $als = Als::where('header_id', $operation['subject_id'])->first();
        $diagramUrl = $als->getFirstMediaUrl('als_diagram');
        $details = $this->get_operation_details($operation, $language);
        $operator = Operator::where('id', $operation['operator_id'])->get();

        $details['title'] = trans('report_als.title', [], $language);
        $images = [];
        $results = [];

        $tasks = Task::where('operation_id', $operation->id)->get();

        foreach ($tasks as $task)
        {
            //si no tiene result no pinta la imagen
            $result = ResultsAls::where('task_id', $task->id)->first();

            if (!empty($result)) {
                $measurements = MeasurementAls::where('result_id', $result->id)->get();
                $task_type = TaskType::find($task->type_id);

                switch ($task_type->id) {
                    case 29:
                        $name = 'Vertical Angle';
                        break;
                    case 30:
                        $name = 'Angular Coverage';
                        break;
                    case 31:
                        $name = 'Lights Intensity';
                        break;
                    case 32:
                        $name = 'Dirt and Aging';
                        break;
                    default:
                        die('Error');
                        break;
                }

                foreach ($measurements as $measurement) {
                    if ($measurement->is_measurement_valid) {
                        $image_type = DB::table('image_types')->find($measurement->image_type);

                        if ($image_type->name === 'VIDEO') {
                            continue;
                        }

                        // Nombre base del archivo sin extensión
                        $baseFileName = $measurement->image_name;

                        // Ruta de la carpeta de la tarea en S3
                        $s3TaskFolder = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$task->id}/";

                        // Listar archivos en la carpeta de la tarea
                        $filesInTask = Storage::disk('s3')->files($s3TaskFolder);

                        // Buscar el archivo que coincida con el nombre base sin extensión
                        $matchingFile = null;

                        // Preparar nombre de BD sin extensión (por si la tiene)
                        $dbFileWithoutExt = pathinfo($baseFileName, PATHINFO_FILENAME);

                        foreach ($filesInTask as $file) {

                            $s3FileBaseName = basename($file);
                            $s3FileWithoutExt = pathinfo($s3FileBaseName, PATHINFO_FILENAME);

                            // Comparar: si coinciden los nombres sin extensión, es un match
                            if ($dbFileWithoutExt === $s3FileWithoutExt) {
                                $matchingFile = $file;
                                break;
                            }
                        }

                        // Si no encontramos una imagen válida, saltamos esta iteración
                        if (!$matchingFile) {
                            continue;
                        }

                        try {
                            // Descargar el contenido del archivo desde S3
                            $fileContent = Storage::disk('s3')->get($matchingFile);

                            // Verificar que el archivo no está vacío
                            if (empty($fileContent)) {
                                continue;
                            }

                            if (!File::exists($tempFolderPath)) {
                                File::makeDirectory($tempFolderPath, 0755, true, true);
                                Log::info('Directorio creado: ' . $tempFolderPath);
                            }

                            // Redimensionar y optimizar la imagen
                            $image = Image::make($fileContent)->resize(null, 700, function ($constraint) {
                                $constraint->aspectRatio();
                                $constraint->upsize();
                            })->encode('jpg', 85);

                            // Definir el nombre temporal con la extensión correcta
                            $fileNameWithExtension = basename($matchingFile);

                            // Guardar en la ruta temporal
                            $tempFilePath = $tempFolderPath . '/' . $fileNameWithExtension;
                            File::put($tempFilePath, (string)$image);

                            // Agregar imagen al array
                            array_push($images, [
                                'id'         => $image_type->id,
                                'file_name'  => $fileNameWithExtension,
                                'task_type'  => $name,
                                'image_type' => $image_type->name,
                            ]);

                        } catch (\Exception $e) {
                            Log::alert($e);
                            continue; // Saltar esta imagen si falla
                        }
                    }
                }

                array_push($results, [
                    'task_type' => $name,
                    'comments'  => $result->observations,
                ]);
            }
        }

        try {
            // Ordenar imágenes y resultados
            array_multisort(array_column($images, 'id'), SORT_ASC, $images);
            array_multisort(array_column($results, 'comments'), SORT_ASC, $results);

            // Especificar carpeta temporal para MPDF
            $mpdfConfig = [
                'tempDir' => public_path("temp/reports/")
            ];

            // Generar PDF con MPDF
            $pdf = new Mpdf($mpdfConfig);
            $view = view('reports.als', compact('language', 'diagramUrl', 'details', 'results', 'images', 'operator', 'operation'))->render();
            $pdf->WriteHTML($view);

            // Definir ruta temporal en public/temp/reports
            $tempPath = public_path("temp/reports/" . uniqid('report_', true) . ".pdf");
            File::ensureDirectoryExists(dirname($tempPath)); // Asegurar que la carpeta exista
            $pdf->Output($tempPath, 'F');

            // Verificar existencia y generar nombre único en S3
            $s3FileName = $fileName;
            $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
            $counter = 1;

            while (Storage::disk('s3')->exists($s3PdfPath)) {
                $s3FileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$counter}." . pathinfo($fileName, PATHINFO_EXTENSION);
                $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
                $counter++;
            }

            // Subir PDF a S3
            Storage::disk('s3')->put($s3PdfPath, File::get($tempPath));

            // Guardar en la base de datos
            OperationReports::create([
                'name' => $s3FileName,
                'description' => '',
                'type' => 'pdf',
                'size' => File::size($tempPath),
                'operation_id' => $operation->id,
            ]);

            // Eliminar archivo temporal y imagenes temporales
            File::delete($tempPath);

            if (File::exists($tempFolderPath)) {
                File::cleanDirectory($tempFolderPath);
            }

            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al generar el reporte'], 500);
        }
    }

}
