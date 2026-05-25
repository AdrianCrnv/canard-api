<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\OperationReports;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class OperationMediaController extends Controller
{
    #[OA\Post(
        path: '/api/ct/operations/{operation}/media/reset',
        summary: 'Elimina los media IDs medidos de una operación en S3 y en base de datos',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations Media'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Media eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function resetMediaIds(Operation $operation): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'operation_edit')) {
            $this->deleteMediaIds($operation);

            return response()->json(['message' => 'Success'], 200);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Get(
        path: '/api/ct/operations/{operation}/media',
        summary: 'Obtiene los IDs y nombres de los archivos de vídeo de una operación',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations Media'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de media IDs'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getMediaIds(Operation $operation): mixed
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'operation_edit')) {
            $id_op = $operation->id;

            $operation_media = Media::where([
                ['model_id', $id_op],
                ['model_type', 'App\Operation'],
                ['collection_name', 'videos'],
            ])->select('id', 'file_name')->get();

            return $operation_media->toJson();
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Post(
        path: '/api/ct/media/migrate-s3',
        summary: 'Migra los archivos de S3 de la estructura legacy (Media ID) a la nueva estructura de carpetas',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations Media'],
        responses: [
            new OA\Response(response: 200, description: 'Migración completada'),
            new OA\Response(response: 500, description: 'Error al listar carpetas en S3'),
        ]
    )]
    public function scriptMediaS3New(): JsonResponse
    {
        try {
            // Listar las carpetas (Directorios)
            $folders = Storage::disk('s3')->directories();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al listar carpetas en S3'], 500);
        }

        $folderMapping = Operation::getFolderMapping();

        foreach ($folders as $folder) {
            $folderName = $folder;

            if (!is_numeric($folderName)) {
                continue; // No es un ID válido, saltar
            }

            $media = Media::find($folderName);

            if (!$media) {
                continue;
            }

            $operation = Operation::find($media->model_id);

            if (!$operation) {
                continue;
            }

            $taskId = null;

            if ($media->collection_name === 'videos') {
                $nameParts = explode('_', $media->file_name);

                if ($operation->type_id === 8 && count($nameParts) > 1 && !str_starts_with($media->file_name, 'DJI_')) {
                    $taskId = $nameParts[1];
                } elseif (str_contains($media->file_name, 'structure')) {
                    if ($operation->type_id === 5) {
                        $task   = Task::where('type_id', 15)->where('operation_id', $operation->id)->first();
                        $taskId = $task ? $task->id : null;
                    } elseif ($operation->type_id === 6) {
                        $task   = Task::where('type_id', 24)->where('operation_id', $operation->id)->first();
                        $taskId = $task ? $task->id : null;
                    }
                } elseif (in_array($operation->type_id, [5, 6, 7]) && count($nameParts) > 2) { // ILS LOC, ILS GP o VOR
                    $taskId = $nameParts[2];
                } else {
                    $task   = $operation->tasks()->first();
                    $taskId = $task ? $task->id : null;

                    if ($operation->type_id === 8 && !$taskId) {
                        Log::alert("Revisar Operation: {$operation->id}");
                    }
                }

                if ($taskId) {
                    $filename      = $media->file_name;
                    $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
                    $sourceKey     = "{$media->id}/{$filename}";
                    $destKey       = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$taskId}/{$filename}";

                    try {
                        // Copiar archivo en S3
                        Storage::disk('s3')->copy($sourceKey, $destKey);

                        // Registrar en operation_files
                        OperationFiles::create([
                            'file_name'   => $filename,
                            'description' => '',
                            'type'        => $fileExtension,
                            'size'        => $media->size,
                            'task_id'     => $taskId,
                            'created_at'  => $media->created_at,
                            'updated_at'  => now(),
                        ]);

                        // Borrar directorio y registro original
                        Storage::disk('s3')->deleteDirectory("{$media->id}/");
                        $media->delete();
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            } elseif ($media->collection_name === 'reports') {
                $filename      = $media->file_name;
                $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
                $sourceKey     = "{$media->id}/{$filename}";
                $destKey       = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$filename}";

                try {
                    // Copiar archivo en S3
                    Storage::disk('s3')->copy($sourceKey, $destKey);

                    // Registrar en operation_reports
                    OperationReports::create([
                        'name'         => $filename,
                        'description'  => '',
                        'type'         => $fileExtension,
                        'size'         => $media->size,
                        'operation_id' => $operation->id,
                        'created_at'   => $media->created_at,
                        'updated_at'   => now(),
                    ]);

                    // Borrar directorio y registro original
                    Storage::disk('s3')->deleteDirectory("{$media->id}/");
                    $media->delete();
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return response()->json(['message' => 'Completed.'], 200);
    }

    // Creates a database entry for a media object passed
    public function createMediaEntry(Operation $operation, $file, string $type = ''): mixed
    {
        $id_op = $operation->id;

        // Extract file name from the path
        $full_file_name    = $file['file_name'] ?? null;
        $file_name_array   = explode('/', $full_file_name);
        $file_name_extension = end($file_name_array);
        $file_name_array   = explode('.', $file_name_extension);
        $file_name         = $file_name_array[0]; // Gets the first position of the array

        $size         = $file['size'];
        $mime_type    = $file['mime_type'];
        $model_typeDB = "App\Operation";

        $new_file = Media::create([
            'model_type'        => $model_typeDB,
            'model_id'          => $id_op,
            'collection_name'   => 'videos',
            'file_name'         => $file_name_extension,
            'name'              => $file_name, // File name without extension
            'mime_type'         => $mime_type,
            'disk'              => 's3',
            'size'              => $size,
            'manipulations'     => json_encode([]),
            'custom_properties' => json_encode(['upload_confirmed' => true]),
            'responsive_images' => json_encode([]),
            'is_measured'       => 1,
        ]);

        if ($type == 'ALS') {
            return $file_name;
        }

        return $new_file->id;
    }

    public function deleteMediaIds(Operation $operation): void
    {
        $id_op = $operation->id;

        $operation_media_files = Media::where([
            ['model_id', $id_op],
            ['model_type', 'App\Operation'],
            ['collection_name', 'videos'],
        ])->select('id', 'file_name', 'is_measured')->get();

        foreach ($operation_media_files as $file) {
            if ($file->is_measured == 1) {
                $existingId = strval($file->id);
                $name       = $file->file_name;
                Storage::disk('s3')->delete($existingId . '/' . $name);
                $file->delete();
            }
        }
    }
}
