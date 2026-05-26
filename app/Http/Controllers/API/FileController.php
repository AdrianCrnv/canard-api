<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\OperationReports;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\AbstractHandler;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Models\Maintenance;
use Carbon\Carbon;
use ZipArchive;
use OpenApi\Attributes as OA;

class FileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // =========================================================================
    // CHUNK UPLOAD
    // =========================================================================

    #[OA\Get(
        path: '/api/files/check-chunk',
        summary: 'Check if a chunk already exists (resumable.js)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'resumableIdentifier', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resumableChunkNumber', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chunk exists'),
            new OA\Response(response: 204, description: 'Chunk not found'),
        ]
    )]
    public function checkChunk(Request $request): \Illuminate\Http\Response
    {
        $path     = storage_path('app/platform/chunks/');
        $fileName = '*' . $request->resumableIdentifier . '.' . $request->resumableChunkNumber . '.part';
        $chunk    = glob($path . $fileName);

        return count($chunk)
            ? response('ok', 200)
            : response('ko', 204);
    }

    #[OA\Post(
        path: '/api/files/upload/{operation}',
        summary: 'Upload a file (supports chunked upload)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Upload progress or completed'),
            new OA\Response(response: 403, description: 'Permission denied'),
        ]
    )]
    public function upload(FileReceiver $receiver, Operation $operation): JsonResponse|\Illuminate\Http\Response
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if (!$permissions->contains('name', 'file_upload')) {
            return response('Permission denied', 403);
        }

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            return $this->saveFile($save->getFile(), $operation);
        }

        /** @var AbstractHandler $handler */
        $handler = $save->handler();

        return response()->json(['done' => $handler->getPercentageDone()]);
    }

    #[OA\Post(
        path: '/api/files/direct-upload/{operation}',
        summary: 'Register a direct S3 upload for an operation (videos)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Upload registered'),
        ]
    )]
    public function directUpload(Request $request, Operation $operation): JsonResponse
    {
        $new_file = Media::create([
            'model_type'        => 'App\Operation',
            'model_id'          => $operation->id,
            'collection_name'   => 'videos',
            'file_name'         => $request['name'],
            'name'              => preg_replace("/\.[^.]+$/", "", $request['name']),
            'mime_type'         => $request['type'],
            'disk'              => 's3',
            'size'              => $request['size'],
            'manipulations'     => json_encode([]),
            'custom_properties' => json_encode(['upload_confirmed' => true]),
            'responsive_images' => json_encode([]),
            'is_measured'       => 0,
        ]);

        return response()->json(['upload' => 'confirmed', 'file' => $new_file]);
    }

    #[OA\Post(
        path: '/api/files/direct-upload-maintenance/{maintenance}',
        summary: 'Register a direct S3 upload for a maintenance record',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'maintenance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Upload registered'),
        ]
    )]
    public function directUploadMaintenance(Request $request, Maintenance $maintenance): JsonResponse
    {
        $new_file = Media::create([
            'model_type'        => 'App\Maintenance',
            'model_id'          => $maintenance->id,
            'collection_name'   => 'maintenance',
            'file_name'         => $request['name'],
            'name'              => preg_replace("/\.[^.]+$/", "", $request['name']),
            'mime_type'         => $request['type'],
            'disk'              => 's3',
            'size'              => $request['size'],
            'manipulations'     => json_encode([]),
            'custom_properties' => json_encode(['upload_confirmed' => true]),
            'responsive_images' => json_encode([]),
            'is_measured'       => 0,
        ]);

        return response()->json(['upload' => 'confirmed', 'file' => $new_file]);
    }

    #[OA\Post(
        path: '/api/files/confirm-upload/{mediaItem}',
        summary: 'Confirm a media upload (legacy endpoint)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'mediaItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Confirmation acknowledged'),
        ]
    )]
    public function confirmUpload(Media $mediaItem): JsonResponse
    {
        return response()->json(['confirmation' => 'ok']);
    }

    // =========================================================================
    // MAINTENANCE FILES
    // =========================================================================

    #[OA\Post(
        path: '/api/files/maintenance/{maintenanceId}',
        summary: 'Upload a file to a maintenance record on S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'maintenanceId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File uploaded'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'S3 error'),
        ]
    )]
    public function uploadMaintenance(Request $request, $maintenanceId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200',
        ]);

        try {
            $maintenance = Maintenance::findOrFail($maintenanceId);
            $file        = $request->file('file');
            $fileName    = $file->getClientOriginalName();

            $storedPath = $file->storeAs("maintenance/$maintenanceId", $fileName, 's3');
            Storage::disk('s3')->setVisibility($storedPath, 'public');
            $url = Storage::disk('s3')->url($storedPath);

            return response()->json([
                'success' => true,
                'message' => 'Archivo subido exitosamente',
                'file'    => [
                    'id'   => uniqid(),
                    'name' => $fileName,
                    'path' => $storedPath,
                    'url'  => $url,
                    'size' => $file->getSize(),
                    'type' => $file->getMimeType(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al subir archivo a S3', ['error' => $e->getMessage(), 'maintenance_id' => $maintenanceId]);
            return response()->json(['success' => false, 'message' => 'Error al subir el archivo: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/api/files/maintenance/download/{fileId}',
        summary: 'Generate a temporary download URL for a maintenance file',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Temporary URL returned'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function downloadMaintenanceFile($fileId): JsonResponse
    {
        $user = Auth::user();

        if (!$user->can('file_download')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $file = Media::findOrFail($fileId);

            if ($file->model_type !== 'App\Maintenance') {
                return response()->json(['error' => 'Invalid file type'], 400);
            }

            $filePath = 'maintenance/' . $file->model_id . '/' . $file->file_name;

            if (!Storage::disk('s3')->exists($filePath)) {
                return response()->json(['error' => 'File not found in S3'], 404);
            }

            $temporaryUrl = Storage::disk('s3')->temporaryUrl(
                $filePath,
                Carbon::now()->addMinutes(10),
                [
                    'ResponseContentType'        => 'application/octet-stream',
                    'ResponseContentDisposition' => 'attachment; filename="' . $file->file_name . '"',
                ]
            );

            return response()->json(['url' => $temporaryUrl, 'name' => $file->file_name]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/api/files/maintenance/{id}',
        summary: 'Rename a maintenance file in DB and S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File renamed'),
            new OA\Response(response: 404, description: 'File not found in S3'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateMaintenanceFile(Request $request, $id): JsonResponse
    {
        $this->authorize('file_edit');

        $request->validate([
            'name'      => 'required',
            'extension' => 'nullable',
        ]);

        $name      = $request->input('name');
        $extension = $request->input('extension');

        $file = Media::where('id', $id)->where('model_type', 'App\Maintenance')->first();

        $old_name_file = $file->file_name;

        Media::where('id', $id)->update([
            'name'      => $name,
            'file_name' => $name . '.' . $extension,
        ]);

        $oldFilePath = 'maintenance/' . $file->model_id . '/' . $old_name_file;
        $newFilePath = 'maintenance/' . $file->model_id . '/' . $name . '.' . $extension;

        if (Storage::disk('s3')->exists($oldFilePath)) {
            Storage::disk('s3')->move($oldFilePath, $newFilePath);
        } else {
            return response()->json(['error' => 'File not found in S3'], 404);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    // =========================================================================
    // OPERATION FILE CRUD
    // =========================================================================

    #[OA\Put(
        path: '/api/files/{id}',
        summary: 'Rename an operation report (R) or operation file (F)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function update(Request $request, $id): JsonResponse
    {
        $this->authorize('file_edit');

        $folderMapping = Operation::getFolderMapping();

        $request->validate([
            'type'        => 'required',
            'name'        => 'required',
            'description' => 'nullable',
        ]);

        $type        = $request->input('type');
        $name        = $request->input('name');
        $description = $request->input('description', '');

        try {
            if ($type === 'R') {
                $report           = OperationReports::findOrFail($id);
                $old_name_report  = $report->name;
                $old_description  = $report->description;

                $report->name        = $name;
                $report->description = $description;
                $report->save();

                $operation = Operation::find($report->operation_id);

                if (!$operation) {
                    return response()->json(['error' => 'Operation not found'], 404);
                }

                $folderType  = $folderMapping[$operation->type_id] ?? 'Unknown';
                $oldFilePath = "$folderType/$operation->id/reports/$old_name_report";
                $newFilePath = "$folderType/$operation->id/reports/$name";

                if (Storage::disk('s3')->exists($oldFilePath)) {
                    Storage::disk('s3')->move($oldFilePath, $newFilePath);
                } else {
                    return response()->json(['error' => 'File not found in S3'], 404);
                }

                $changes = [];
                if ($old_name_report !== $name) $changes[] = "Name: '{$old_name_report}' → '{$name}'";
                if ($old_description !== $description) $changes[] = "Description: '{$old_description}' → '{$description}'";
                if (!empty($changes)) {
                    ActivityLog::log('update', 'Operation', $operation->id, "Edited report on operation #{$operation->id} ({$operation->type->name}): " . implode(', ', $changes));
                }

                return response()->json(['message' => 'OK'], 200);

            } elseif ($type === 'F') {
                $file            = OperationFiles::findOrFail($id);
                $old_name_file   = $file->file_name;
                $old_description = $file->description;

                $file->file_name   = $name;
                $file->description = $description;
                $file->save();

                $task = Task::find($file->task_id);

                if (!$task) {
                    return response()->json(['error' => 'Task not found'], 404);
                }

                $folderType  = $folderMapping[$task->operation->type_id];
                $oldFilePath = "$folderType/{$task->operation->id}/{$task->id}/$old_name_file";
                $newFilePath = "$folderType/{$task->operation->id}/{$task->id}/$name";

                if (Storage::disk('s3')->exists($oldFilePath)) {
                    Storage::disk('s3')->move($oldFilePath, $newFilePath);
                } else {
                    return response()->json(['error' => 'File not found in S3'], 404);
                }

                $changes = [];
                if ($old_name_file !== $name) $changes[] = "Name: '{$old_name_file}' → '{$name}'";
                if ($old_description !== $description) $changes[] = "Description: '{$old_description}' → '{$description}'";
                if (!empty($changes)) {
                    ActivityLog::log('update', 'Operation', $task->operation->id, "Edited file on operation #{$task->operation->id} ({$task->operation->type->name}): " . implode(', ', $changes));
                }

                return response()->json(['message' => 'OK'], 200);
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Put(
        path: '/api/files/description',
        summary: 'Update the description of an operation file',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Description updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function updateDescription(Request $request): JsonResponse
    {
        $this->authorize('file_edit');

        $request->validate([
            'id'          => 'required',
            'description' => 'nullable',
        ]);

        $id          = $request->input('id');
        $description = $request->input('description', '');

        try {
            $file           = OperationFiles::findOrFail($id);
            $oldDescription = $file->description;
            $file->description = $description;
            $file->save();

            $task        = Task::find($file->task_id);
            $operationId = $task ? $task->operation_id : null;

            $changes = (string) $oldDescription !== (string) $description
                ? "Description: '{$oldDescription}' → '{$description}'"
                : 'No changes';

            ActivityLog::log('update', 'Operation', $operationId,
                "File '{$file->file_name}' edited by '" . Auth::user()->name . "': {$changes}");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in updateDescription: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'An unexpected error occurred'], 500);
        }

        return response()->json(['success' => true, 'message' => 'File updated successfully'], 200);
    }

    #[OA\Get(
        path: '/api/files/download/{mediaItem}',
        summary: 'Download a media file (local disk)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'mediaItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File stream'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function download(Media $mediaItem): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if (!$permissions->contains('name', 'file_download')) {
            return response('Permission denied', 403);
        }

        if (Auth::user()->hasRole('admin') === false) {
            $operation = Operation::find($mediaItem->model_id);

            if (!$operation) {
                return response('File\'s operation not found', 404);
            }

            if ($operation->operator != Auth::user()->operator) {
                return response('Permission denied', 403);
            }
        }

        return response()->download($mediaItem->getPath(), $mediaItem->file_name);
    }

    #[OA\Get(
        path: '/api/files/temp-url/{id}',
        summary: 'Generate a temporary S3 URL for a report (R) or operation file (F)',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type_file', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['R', 'F'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Temporary URL returned'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Error generating URL'),
        ]
    )]
    public function getTempUrl(Request $request, $id): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'type_file' => 'required|string|in:R,F',
        ]);

        $typeFile = $validated['type_file'];

        if (!$user->can('file_download')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        try {
            $temporaryUrl = null;

            if ($typeFile === 'R') {
                $report    = OperationReports::findOrFail($id);
                $operation = $report->operation;

                if (!$operation) {
                    return response()->json(['error' => 'Operation not found'], 404);
                }

                $folderMapping = Operation::getFolderMapping();
                $folderType    = $folderMapping[$operation->type_id] ?? null;

                if (!$folderType) {
                    return response()->json(['error' => 'Invalid folder mapping for operation type'], 500);
                }

                $filePath = "$folderType/{$operation->id}/reports/{$report->name}";

                if (!Storage::disk('s3')->exists($filePath)) {
                    return response()->json(['error' => 'File not found in S3'], 404);
                }

                $temporaryUrl = Storage::disk('s3')->temporaryUrl($filePath, Carbon::now()->addMinutes(10));

                ActivityLog::log('download', 'Operation', $operation->id,
                    "Report '{$report->name}' downloaded from operation #{$operation->id} by '" . Auth::user()->name . "'");

            } elseif ($typeFile === 'F') {
                $file = OperationFiles::findOrFail($id);
                $task = $file->task;

                if (!$task || !$task->operation) {
                    return response()->json(['error' => 'Task or operation not found'], 404);
                }

                $folderMapping = Operation::getFolderMapping();
                $folderType    = $folderMapping[$task->operation->type_id] ?? null;

                if (!$folderType) {
                    return response()->json(['error' => 'Invalid folder mapping for operation type'], 500);
                }

                $filePath = $task->operation->type_id == 21
                    ? "$folderType/{$task->operation->id}/{$task->id}/1/{$file->file_name}"
                    : "$folderType/{$task->operation->id}/{$task->id}/{$file->file_name}";

                if (!Storage::disk('s3')->exists($filePath)) {
                    return response()->json(['error' => 'File not found in S3'], 404);
                }

                $temporaryUrl = Storage::disk('s3')->temporaryUrl($filePath, Carbon::now()->addMinutes(10));

                ActivityLog::log('download', 'Operation', $task->operation->id,
                    "File '{$file->file_name}' downloaded from operation #{$task->operation->id} by '" . Auth::user()->name . "'");
            }

            return response()->json(['url' => $temporaryUrl]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while generating the URL'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/files/{mediaItem}',
        summary: 'Delete a media item',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'mediaItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Permission denied'),
        ]
    )]
    public function destroy(Media $mediaItem): string
    {
        $this->authorize('file_delete');

        return ($mediaItem->delete()) ? 'ok' : 'error';
    }

    #[OA\Get(
        path: '/api/files/operation/download',
        summary: 'Download an operation file from S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File stream'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function downloadFile(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $type = $request->input('type');
            $id   = $request->input('id');

            $file        = OperationFiles::findOrFail($id);
            $task        = Task::find($file->task_id);
            $operationId = $task->operation->id;

            $filePath = $type === 'AerodromeBeacon'
                ? "{$type}/{$operationId}/{$task->id}/1/{$file->file_name}"
                : "{$type}/{$operationId}/{$task->id}/{$file->file_name}";

            $fileContent = Storage::disk('s3')->get($filePath);
            $mimeType    = Storage::disk('s3')->mimeType($filePath);

            ActivityLog::log('download', 'Operation', $operationId,
                "File '{$file->file_name}' downloaded from operation #{$operationId} by '" . Auth::user()->name . "'");

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $file->file_name, [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $file->file_name . '"',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/api/files/markings/download',
        summary: 'Download a Markings operation file from S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File stream'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function downloadMarkingsFile(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $fileId      = $request->input('id');
            $user        = Auth::user();
            $permissions = $user->getAllPermissions();

            if (!$permissions->contains('name', 'file_download')) {
                return response()->json(['error' => 'Permission denied'], 403);
            }

            $file      = OperationFiles::findOrFail($fileId);
            $task      = Task::findOrFail($file->task_id);
            $operation = Operation::findOrFail($task->operation_id);

            if (!$user->hasRole('admin')) {
                if ($operation->operator_id != $user->operator_id) {
                    return response()->json(['error' => 'Permission denied'], 403);
                }
            }

            $filePath = "Markings/{$operation->id}/{$task->id}/{$file->file_name}";

            if (!Storage::disk('s3')->exists($filePath)) {
                return response()->json(['error' => 'File not found in storage'], 404);
            }

            $fileContent = Storage::disk('s3')->get($filePath);
            $mimeType    = Storage::disk('s3')->mimeType($filePath);

            ActivityLog::log('download', 'Operation', $operation->id,
                "Downloaded file '{$file->file_name}' from Markings operation #{$operation->id}");

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $file->file_name, [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $file->file_name . '"',
            ]);
        } catch (\Exception $e) {
            Log::error('Error downloading markings file: ' . $e->getMessage());
            return response()->json(['error' => 'Error downloading file: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/api/files/operation/delete',
        summary: 'Delete an operation file from DB and S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function deleteFile(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        try {
            $type = $request->input('type');
            $id   = $request->input('id');

            $file        = OperationFiles::findOrFail($id);
            $task        = Task::find($file->task_id);
            $operationId = $task->operation->id;

            if ($type === 'AerodromeBeacon') {
                $filePath = "{$type}/{$operationId}/{$task->id}/1/{$file->file_name}";
                ActivityLog::log('delete', 'Operation', $operationId, "Deleted file '{$file->file_name}' from Aerodrome Beacon operation #{$operationId}");
            } else {
                $filePath = "{$type}/{$operationId}/{$task->id}/{$file->file_name}";
                ActivityLog::log('delete', 'Operation', $operationId, "Deleted file '{$file->file_name}' from {$type} operation #{$operationId}");
            }

            Storage::disk('s3')->delete($filePath);
            $file->delete();

            return response('ok', 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/files/operation/log-download',
        summary: 'Log a file download event',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Logged'),
        ]
    )]
    public function logDownload(Request $request): JsonResponse
    {
        try {
            $file        = OperationFiles::findOrFail($request->input('id'));
            $task        = Task::find($file->task_id);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('download', 'Operation', $operationId,
                "File '{$file->file_name}' downloaded from operation #{$operationId} by '" . Auth::user()->name . "'");
        } catch (\Exception $e) {
            // silently fail — no interrumpir la descarga
        }

        return response()->json(['ok' => true]);
    }

    #[OA\Post(
        path: '/api/files/operation/register',
        summary: 'Register an operation file record in DB',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function registerOperationFile(Request $request): JsonResponse
    {
        try {
            OperationFiles::create([
                'file_name'   => $request->input('file_name'),
                'description' => $request->input('description', ''),
                'type'        => $request->input('type'),
                'size'        => $request->input('size'),
                'task_id'     => $request->input('task_id'),
            ]);

            return response()->json(['success' => true, 'message' => 'File registered successfully']);
        } catch (\Exception $e) {
            Log::error('Error registering operation file: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // CSV DOWNLOAD
    // =========================================================================

    #[OA\Get(
        path: '/api/files/operations/{operationId}/csv',
        summary: 'Download all CSV files for an operation as a ZIP',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ZIP download URL returned'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 404, description: 'No CSV files found'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function downloadAllCSV($operationId): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user->can('file_download')) {
                return response()->json(['error' => 'Permission denied'], 403);
            }

            $operation = Operation::findOrFail($operationId);

            if (!$user->hasRole('admin') && $operation->operator != $user->operator) {
                return response()->json(['error' => 'Permission denied'], 403);
            }

            $tasks = Task::where('operation_id', $operationId)->get();

            $hasCSVFiles = false;
            foreach ($tasks as $task) {
                if (OperationFiles::where('task_id', $task->id)->where('type', 'csv')->count() > 0) {
                    $hasCSVFiles = true;
                    break;
                }
            }

            if (!$hasCSVFiles) {
                return response()->json(['error' => 'No CSV files found for this operation'], 404);
            }

            $tempPath = storage_path('app/platform/temp/operation_' . $operationId);
            if (!File::exists($tempPath)) {
                File::makeDirectory($tempPath, 0777, true);
            }

            $folderMapping  = Operation::getFolderMapping();
            $folderType     = $folderMapping[$operation->type_id] ?? 'Unknown';
            $downloadedFiles = [];
            $totalFiles     = 0;
            $failedFiles    = [];

            foreach ($tasks as $task) {
                $csvFiles = OperationFiles::where('task_id', $task->id)->where('type', 'csv')->get();

                if ($csvFiles->count() === 0) {
                    continue;
                }

                $taskFolderName = 'Task_' . $task->id;
                if ($task->type && $task->type->name) {
                    $taskFolderName .= '_' . Str::slug($task->type->name);
                }
                if ($task->description) {
                    $taskFolderName .= '_' . Str::slug($task->description);
                }

                $taskPath = $tempPath . '/' . $taskFolderName;
                if (!File::exists($taskPath)) {
                    File::makeDirectory($taskPath, 0777, true);
                }

                foreach ($csvFiles as $file) {
                    $totalFiles++;
                    try {
                        $s3FilePath = $folderType . '/' . $operation->id . '/' . $task->id . '/' . $file->file_name;

                        if (Storage::disk('s3')->exists($s3FilePath)) {
                            $fileContent   = Storage::disk('s3')->get($s3FilePath);
                            $localFilePath = $taskPath . '/' . $file->file_name;
                            file_put_contents($localFilePath, $fileContent);

                            $downloadedFiles[] = [
                                'task_id'   => $task->id,
                                'task_name' => $taskFolderName,
                                'file_name' => $file->file_name,
                                'local_path' => $localFilePath,
                            ];
                        } else {
                            $failedFiles[] = ['file' => $file->file_name, 'reason' => 'File not found in S3'];
                            Log::warning('CSV file not found in S3', ['path' => $s3FilePath, 'file' => $file->file_name]);
                        }
                    } catch (\Exception $e) {
                        $failedFiles[] = ['file' => $file->file_name, 'reason' => $e->getMessage()];
                        Log::error('Error downloading CSV file', ['file' => $file->file_name, 'error' => $e->getMessage()]);
                    }
                }
            }

            if (empty($downloadedFiles)) {
                File::deleteDirectory($tempPath);
                return response()->json([
                    'success'      => false,
                    'error'        => 'No CSV files could be downloaded from S3. They may have been uploaded to a different path.',
                    'failed_files' => $failedFiles,
                ], 404);
            }

            $zipResult = $this->createZipFromFolder($tempPath, $operation);

            if (!$zipResult['success']) {
                throw new \Exception($zipResult['error']);
            }

            try {
                if (File::exists($tempPath)) {
                    File::deleteDirectory($tempPath);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to delete temporary folder', ['path' => $tempPath, 'error' => $e->getMessage()]);
            }

            return response()->json([
                'success'          => true,
                'message'          => 'CSV files downloaded and compressed successfully',
                'download_url'     => $zipResult['url'],
                'filename'         => $zipResult['filename'],
                's3_path'          => $zipResult['s3_path'],
                'temp_path'        => $tempPath,
                'total_files'      => $totalFiles,
                'downloaded_files' => count($downloadedFiles),
                'failed_files'     => count($failedFiles),
                'files'            => $downloadedFiles,
                'failures'         => $failedFiles,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in downloadAllCSV', ['operation_id' => $operationId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Error downloading CSV files: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/api/files/temp/cleanup',
        summary: 'Delete a temporary ZIP file from S3',
        security: [['bearerAuth' => []]],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Cleaned up'),
            new OA\Response(response: 400, description: 'Invalid path'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function cleanupTempFiles(Request $request): JsonResponse
    {
        try {
            $request->validate(['s3_path' => 'required|string']);

            $s3Path = $request->input('s3_path');
            $user   = Auth::user();

            if (!$user->can('file_download')) {
                return response()->json(['error' => 'Permission denied'], 403);
            }

            if (!Str::startsWith($s3Path, 'temp/downloads/')) {
                Log::warning('Attempt to delete non-temp file', ['path' => $s3Path, 'user' => $user->id]);
                return response()->json(['success' => false, 'error' => 'Invalid file path'], 400);
            }

            if (Storage::disk('s3')->exists($s3Path)) {
                Storage::disk('s3')->delete($s3Path);
                return response()->json(['success' => true, 'message' => 'Temporary file cleaned up successfully']);
            }

            return response()->json(['success' => true, 'message' => 'File already removed or does not exist']);
        } catch (\Exception $e) {
            Log::error('Error cleaning up temporary files', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Error cleaning up temporary files'], 500);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function saveFile($file, $operation): JsonResponse
    {
        $operation->addMedia($file)->toMediaCollection('videos');
        return response()->json(['done' => '100']);
    }

    private function createZipFromFolder($folderPath, $operation): array
    {
        try {
            $zipFileName = 'Operation_' . $operation->id . '_CSV_' . date('Y-m-d_H-i-s') . '.zip';
            $zipPath     = storage_path('app/platform/temp/' . $zipFileName);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return ['success' => false, 'error' => 'Cannot create ZIP file'];
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath     = $file->getRealPath();
                    $relativePath = 'Operation_' . $operation->id . '/' . substr($filePath, strlen($folderPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            if (!file_exists($zipPath) || filesize($zipPath) == 0) {
                return ['success' => false, 'error' => 'ZIP file was not created properly'];
            }

            $s3ZipPath = 'temp/downloads/' . $zipFileName;
            $zipStream = fopen($zipPath, 'r');
            Storage::disk('s3')->put($s3ZipPath, $zipStream);
            fclose($zipStream);

            $downloadUrl = Storage::disk('s3')->temporaryUrl(
                $s3ZipPath,
                Carbon::now()->addMinutes(30),
                [
                    'ResponseContentType'        => 'application/zip',
                    'ResponseContentDisposition' => 'attachment; filename="' . $zipFileName . '"',
                ]
            );

            unlink($zipPath);

            return ['success' => true, 'url' => $downloadUrl, 'filename' => $zipFileName, 's3_path' => $s3ZipPath];
        } catch (\Exception $e) {
            Log::error('Error creating ZIP file', ['folder' => $folderPath, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Failed to create ZIP: ' . $e->getMessage()];
        }
    }
}
