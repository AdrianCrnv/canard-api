<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AcMaintImage;
use App\Models\AerodromeBeacon;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\OperationReports;
use App\Models\ResultAcMaint;
use App\Models\ResultFlightTurn;
use App\Models\FlightTurnImage;
use App\Models\ResultsBeacon;
use App\Models\ResultsWdi;
use App\Models\Task;
use App\Models\Wdi;
use App\Models\WdiFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use OpenApi\Attributes as OA;

class OperationFileConfirmController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // =========================================================================
    // GENERIC OPERATION FILE
    // =========================================================================

    #[OA\Post(
        path: '/api/files/operation/confirm-upload',
        summary: 'Register an operation file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'File registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error saving file data'),
        ]
    )]
    public function confirmOperationFileUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'         => 'required|integer|exists:tasks,id',
            'file_name'       => 'required|string',
            'file_size'       => 'required|integer',
            'file_type'       => 'required|string',
            'description'     => 'nullable|string',
            'operation_ftype' => 'nullable|string|max:50',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => $validated['operation_ftype'] ?? null,
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task        = Task::find($validated['task_id']);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('upload', 'Operation', $operationId,
                "File '{$validated['file_name']}' uploaded to operation #{$operationId} (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null,
                "Error uploading file '{$validated['file_name']}' (task #{$validated['task_id']}): " . $e->getMessage());

            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    // =========================================================================
    // REPORTS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/reports/confirm-upload',
        summary: 'Register a report after a direct S3 upload (handles duplicate names)',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Report registered'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function confirmReportUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'file_name'    => 'required|string',
            'file_size'    => 'required|integer',
            'file_type'    => 'required|string',
            'description'  => 'nullable|string',
        ]);

        $originalName = $validated['file_name'];
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $baseName     = pathinfo($originalName, PATHINFO_FILENAME);

        $finalName = $originalName;
        $counter   = 1;

        while (OperationReports::where('operation_id', $validated['operation_id'])->where('name', $finalName)->exists()) {
            $finalName = "{$baseName}_{$counter}.{$extension}";
            $counter++;
        }

        $report = OperationReports::create([
            'name'         => $finalName,
            'description'  => $validated['description'] ?? '',
            'type'         => $extension,
            'size'         => $validated['file_size'],
            'operation_id' => $validated['operation_id'],
        ]);

        ActivityLog::log('upload', 'Operation', (int) $validated['operation_id'],
            "Uploaded report {$finalName} to operation #{$validated['operation_id']}");

        return response()->json(['success' => true, 'report_id' => $report->id, 'final_name' => $finalName]);
    }

    // =========================================================================
    // AERODROME BEACON
    // =========================================================================

    #[OA\Post(
        path: '/api/aerodrome_beacon/files/confirm-upload',
        summary: 'Register an Aerodrome Beacon file + ResultsBeacon after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 404, description: 'Airport or beacon not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function confirmAerodromeBeaconUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            'file_name'    => 'required|string',
            'file_size'    => 'required|integer',
            'file_type'    => 'required|string',
        ]);

        $operation = Operation::findOrFail($validated['operation_id']);
        $airport   = $operation->getAirport();

        if (!$airport) {
            return response()->json(['error' => 'Airport not found for this operation'], 404);
        }

        $beacon = AerodromeBeacon::where('airport_id', $airport->id)->first();
        if (!$beacon) {
            return response()->json(['error' => 'Aerodrome beacon not found for this airport'], 404);
        }

        $file = OperationFiles::create([
            'file_name'   => $validated['file_name'],
            'description' => '',
            'type'        => $validated['file_type'],
            'size'        => $validated['file_size'],
            'task_id'     => $validated['task_id'],
        ]);

        ResultsBeacon::create([
            'operation_id' => $validated['operation_id'],
            'task_id'      => $validated['task_id'],
            'beacon_id'    => $beacon->id,
        ]);

        ActivityLog::log('upload', 'Operation', (int) $validated['operation_id'],
            "Uploaded {$validated['file_name']} to Aerodrome Beacon operation #{$validated['operation_id']}, task #{$validated['task_id']}");

        return response()->json(['success' => true, 'file_id' => $file->id]);
    }

    #[OA\Post(
        path: '/api/aerodrome_beacon/files/confirm-images-upload',
        summary: 'Register a beacon image after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error saving file data'),
        ]
    )]
    public function confirmBeaconImagesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'   => 'required|integer|exists:tasks,id',
            'file_name' => 'required|string',
            'file_size' => 'required|integer|min:1',
            'file_type' => 'required|string',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => null,
                'file_type'   => 'beacon_image',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task        = Task::find($validated['task_id']);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('upload', 'Operation', $operationId,
                "Image '{$validated['file_name']}' uploaded to Aerodrome Beacon operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null,
                "Error uploading beacon image '{$validated['file_name']}' (task #{$validated['task_id']}): " . $e->getMessage());
            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    #[OA\Post(
        path: '/api/aerodrome_beacon/files/confirm-other-files-upload',
        summary: 'Register a beacon other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error saving file data'),
        ]
    )]
    public function confirmBeaconOtherFilesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'     => 'required|integer|exists:tasks,id',
            'file_name'   => 'required|string',
            'file_size'   => 'required|integer|min:1',
            'file_type'   => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => 'beacon_other',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task        = Task::find($validated['task_id']);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('upload', 'Operation', $operationId,
                "Other file '{$validated['file_name']}' uploaded to Aerodrome Beacon operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null,
                "Error uploading beacon other file '{$validated['file_name']}' (task #{$validated['task_id']}): " . $e->getMessage());
            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    // =========================================================================
    // WDI
    // =========================================================================

    #[OA\Post(
        path: '/api/wdi/files/confirm-images-upload',
        summary: 'Register a WDI image after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error saving file data'),
        ]
    )]
    public function confirmWdiImagesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'   => 'required|integer|exists:tasks,id',
            'file_name' => 'required|string',
            'file_size' => 'required|integer|min:1',
            'file_type' => 'required|string',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => null,
                'file_type'   => 'wdi_image',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task        = Task::find($validated['task_id']);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('upload', 'Operation', $operationId,
                "Image '{$validated['file_name']}' uploaded to WDI operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    #[OA\Post(
        path: '/api/wdi/files/confirm-other-files-upload',
        summary: 'Register a WDI other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error saving file data'),
        ]
    )]
    public function confirmWdiOtherFilesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'     => 'required|integer|exists:tasks,id',
            'file_name'   => 'required|string',
            'file_size'   => 'required|integer|min:1',
            'file_type'   => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => 'wdi_other',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task        = Task::find($validated['task_id']);
            $operationId = $task ? $task->operation_id : null;

            ActivityLog::log('upload', 'Operation', $operationId,
                "Other file '{$validated['file_name']}' uploaded to WDI operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    #[OA\Post(
        path: '/api/wdi/files/confirm-upload',
        summary: 'Register a WDI file + ResultsWdi after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 404, description: 'Airport or WDI not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmWdiUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            'run'          => 'nullable|integer|min:1',
            's3_path'      => 'required|string',
            'file_size'    => 'required|integer',
            'file_type'    => 'required|string',
        ]);

        $operation = Operation::find($validated['operation_id']);
        $airport   = $operation ? $operation->getAirport() : null;
        $task      = Task::find($validated['task_id']);

        if (!$airport) {
            return response()->json(['error' => 'Airport not found for this operation'], 404);
        }

        $wdi = Wdi::where('airport_id', $airport->id)->where('name', $task->description)->first();
        if (!$wdi) {
            return response()->json(['error' => 'WDI not found for this task'], 404);
        }

        $run = $validated['run'] ?? null;
        if (!$run) {
            $last = ResultsWdi::where('operation_id', $validated['operation_id'])
                ->where('task_id', $validated['task_id'])
                ->orderBy('run', 'desc')
                ->first();
            $run = $last ? $last->run + 1 : 1;
        }

        DB::beginTransaction();

        try {
            $result = ResultsWdi::firstOrCreate(
                ['operation_id' => $validated['operation_id'], 'task_id' => $validated['task_id'], 'run' => $run],
                ['wdi_id' => $wdi->id, 'is_valid' => 0]
            );

            $wdiFile = WdiFile::create([
                's3_path'   => $validated['s3_path'],
                'description' => '',
                'type'      => $validated['file_type'],
                'size'      => $validated['file_size'],
                'result_id' => $result->id,
            ]);

            DB::commit();

            return response()->json(['success' => true, 'result_id' => $result->id, 'wdi_file_id' => $wdiFile->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('confirmWdiUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // AC MAINT
    // =========================================================================

    #[OA\Post(
        path: '/api/acmaint/confirm-upload',
        summary: 'Register an AcMaint image + thumbnail after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmAcMaintUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            'run'          => 'required|integer|min:1',
            's3_path'      => 'required|string',
            'file_name'    => 'required|string',
        ]);

        try {
            $result = ResultAcMaint::firstOrCreate(
                ['operation_id' => $validated['operation_id'], 'task_id' => $validated['task_id'], 'run' => $validated['run']],
                ['is_valid' => 0, 'status' => 'Processed', 'read_yaml' => false]
            );

            $pathInfo      = pathinfo($validated['s3_path']);
            $thumbnailPath = $pathInfo['dirname'] . '/thumbnail/' . $pathInfo['filename'] . '.jpg';
            (new \App\Services\ThumbnailService())->generateThumbnail($validated['s3_path'], $thumbnailPath);

            $image = AcMaintImage::create([
                'ac_maint_id'    => $result->id,
                'image_path'     => $validated['s3_path'],
                'thumbnail_path' => $thumbnailPath,
                'reviewed'       => 0,
            ]);

            return response()->json(['success' => true, 'image_id' => $image->id]);
        } catch (\Exception $e) {
            Log::error('confirmAcMaintUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/acmaint/process-zip',
        summary: 'Extract a ZIP already in S3, upload images and create AcMaint records',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Processed'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function processAcMaintZip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            's3_path'      => 'required|string',
        ]);

        $tempDir = storage_path('app/platform/temp/acmaint_zip_' . $validated['task_id'] . '_' . time());

        try {
            $lastRun = ResultAcMaint::where('task_id', $validated['task_id'])->max('run');
            $run     = $lastRun ? $lastRun + 1 : 1;

            $zipStream    = Storage::disk('s3')->readStream($validated['s3_path']);
            mkdir($tempDir, 0777, true);
            $zipLocalPath = $tempDir . '/upload.zip';
            $dest         = fopen($zipLocalPath, 'wb');
            stream_copy_to_stream($zipStream, $dest);
            fclose($dest);
            fclose($zipStream);

            $zip = new ZipArchive();
            if ($zip->open($zipLocalPath) !== true) {
                throw new \RuntimeException('Unable to open ZIP file');
            }
            $zip->extractTo($tempDir . '/extracted');
            $zip->close();

            $operation  = Operation::find($validated['operation_id']);
            $folderMap  = Operation::getFolderMapping();
            $baseFolder = $folderMap[$operation->type_id] ?? 'AcMaint';
            $s3Base     = "{$baseFolder}/{$validated['operation_id']}/{$validated['task_id']}/{$run}";

            $result = ResultAcMaint::firstOrCreate(
                ['operation_id' => $validated['operation_id'], 'task_id' => $validated['task_id'], 'run' => $run],
                ['is_valid' => 0, 'status' => 'Processed', 'read_yaml' => false]
            );

            $images          = File::allFiles($tempDir . '/extracted');
            $processedImages = 0;

            foreach ($images as $imgFile) {
                $ext = strtolower($imgFile->getExtension());
                if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

                $s3ImagePath   = "{$s3Base}/" . $imgFile->getFilename();
                $thumbnailPath = "{$s3Base}/thumbnail/" . pathinfo($imgFile->getFilename(), PATHINFO_FILENAME) . '.jpg';

                $stream = fopen($imgFile->getRealPath(), 'r');
                Storage::disk('s3')->put($s3ImagePath, $stream);
                fclose($stream);

                (new \App\Services\ThumbnailService())->generateThumbnail($s3ImagePath, $thumbnailPath);

                AcMaintImage::create([
                    'ac_maint_id'    => $result->id,
                    'image_path'     => $s3ImagePath,
                    'thumbnail_path' => $thumbnailPath,
                    'reviewed'       => 0,
                ]);

                $processedImages++;
            }

            Storage::disk('s3')->delete($validated['s3_path']);

            return response()->json(['success' => true, 'result_id' => $result->id, 'images_processed' => $processedImages]);
        } catch (\Exception $e) {
            Log::error('processAcMaintZip error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            if (is_dir($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    #[OA\Post(
        path: '/api/acmaint/confirm-other-files-upload',
        summary: 'Register an AcMaint other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmAcMaintOtherFilesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'     => 'required|integer|exists:tasks,id',
            'file_name'   => 'required|string',
            'file_size'   => 'required|integer|min:1',
            'file_type'   => 'required|string',
            'description' => 'nullable|string|max:500',
        ]);

        try {
            $task = Task::find($validated['task_id']);

            OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => 'acmaint_other',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            ActivityLog::log('upload', 'Operation', $task->operation_id,
                "Other file '{$validated['file_name']}' uploaded to AcMaint operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('confirmAcMaintOtherFilesUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/api/acmaint/image/delete',
        summary: 'Delete an AcMaint image (S3 + DB + detections)',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function deleteAcMaintImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:aircraft_maintenance_image,id',
        ]);

        try {
            $image  = AcMaintImage::findOrFail($validated['id']);
            $result = ResultAcMaint::find($image->ac_maint_id);

            $image->detections()->delete();

            $imgPath   = ltrim($image->image_path ?? '', '/');
            $thumbPath = ltrim($image->thumbnail_path ?? '', '/');
            if ($imgPath)   Storage::disk('s3')->delete($imgPath);
            if ($thumbPath) Storage::disk('s3')->delete($thumbPath);

            $operationId = $result ? $result->operation_id : null;
            $image->delete();

            if ($result && $result->images()->count() === 0) {
                if ($operationId) {
                    $runFolder = "AcMaint/{$operationId}/{$result->task_id}/{$result->run}";
                    if (Storage::disk('s3')->exists($runFolder)) {
                        Storage::disk('s3')->deleteDirectory($runFolder);
                    }
                }
                $result->delete();
            }

            if ($operationId) {
                ActivityLog::log('delete', 'Operation', $operationId,
                    "AcMaint image deleted from operation #{$operationId} by '" . Auth::user()->name . "'");
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('deleteAcMaintImage error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // FLIGHT TURN
    // =========================================================================

    #[OA\Post(
        path: '/api/flightTurn/process-zip',
        summary: 'Extract a FlightTurn ZIP from S3 and upload images',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Processed'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function processFlightTurnZip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            's3_path'      => 'required|string',
        ]);

        $tempDir = storage_path('app/platform/temp/ft_zip_' . $validated['task_id'] . '_' . time());

        try {
            $zipStream    = Storage::disk('s3')->readStream($validated['s3_path']);
            mkdir($tempDir, 0777, true);
            $zipLocalPath = $tempDir . '/upload.zip';
            $dest         = fopen($zipLocalPath, 'wb');
            stream_copy_to_stream($zipStream, $dest);
            fclose($dest);
            fclose($zipStream);

            $zip = new ZipArchive();
            if ($zip->open($zipLocalPath) !== true) {
                throw new \RuntimeException('Unable to open ZIP file');
            }
            $zip->extractTo($tempDir . '/extracted');
            $zip->close();

            $s3Base          = "FlightTurn/{$validated['operation_id']}/{$validated['task_id']}";
            $images          = File::allFiles($tempDir . '/extracted');
            $processedImages = 0;

            foreach ($images as $imgFile) {
                $ext = strtolower($imgFile->getExtension());
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) continue;

                $s3ImagePath = "{$s3Base}/" . $imgFile->getFilename();

                $stream = fopen($imgFile->getRealPath(), 'r');
                Storage::disk('s3')->put($s3ImagePath, $stream);
                fclose($stream);

                OperationFiles::create([
                    'file_name'   => $imgFile->getFilename(),
                    'description' => null,
                    'file_type'   => 'ft_image',
                    'type'        => $ext,
                    'size'        => $imgFile->getSize(),
                    'task_id'     => $validated['task_id'],
                ]);

                $processedImages++;
            }

            Storage::disk('s3')->delete($validated['s3_path']);

            ActivityLog::log('upload', 'Operation', $validated['operation_id'],
                "ZIP extracted: {$processedImages} images uploaded to FlightTurn (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true, 'images_processed' => $processedImages]);
        } catch (\Exception $e) {
            Log::error('processFlightTurnZip error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            if (is_dir($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    // =========================================================================
    // PER-TYPE OTHER FILES (ETOD, ILS, FOD, PCI, VOR, SURVEILLANCE)
    // =========================================================================

    #[OA\Post(
        path: '/api/etod/confirm-images-upload',
        summary: 'Register an ETOD image after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmEtodImagesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'   => 'required|integer|exists:tasks,id',
            'file_name' => 'required|string',
            'file_size' => 'required|integer|min:1',
            'file_type' => 'required|string',
        ]);

        try {
            $task = Task::find($validated['task_id']);

            OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => null,
                'file_type'   => 'etod_image',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            ActivityLog::log('upload', 'Operation', $task->operation_id,
                "ETOD image '{$validated['file_name']}' uploaded to operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('confirmEtodImagesUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/etod/confirm-other-files-upload',
        summary: 'Register an ETOD other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmEtodOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'etod_other', 'ETOD');
    }

    #[OA\Post(
        path: '/api/ils/confirm-other-files-upload',
        summary: 'Register an ILS other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmIlsOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'ils_other', 'ILS');
    }

    #[OA\Post(
        path: '/api/fod/confirm-other-files-upload',
        summary: 'Register a FOD other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmFodOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'fod_other', 'FOD');
    }

    #[OA\Post(
        path: '/api/pci/confirm-other-files-upload',
        summary: 'Register a PCI other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmPciOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'pci_other', 'PCI');
    }

    #[OA\Post(
        path: '/api/vor/confirm-other-files-upload',
        summary: 'Register a VOR other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmVorOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'vor_other', 'VOR');
    }

    #[OA\Post(
        path: '/api/surveillance/confirm-other-files-upload',
        summary: 'Register a Surveillance other file after a direct S3 upload',
        security: [['bearerAuth' => []]],
        tags: ['FileConfirm'],
        responses: [
            new OA\Response(response: 200, description: 'Registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Error'),
        ]
    )]
    public function confirmSurveillanceOtherFilesUpload(Request $request): JsonResponse
    {
        return $this->confirmOtherFileUpload($request, 'surveillance_other', 'Surveillance');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function confirmOtherFileUpload(Request $request, string $fileType, string $label): JsonResponse
    {
        $validated = $request->validate([
            'task_id'     => 'required|integer|exists:tasks,id',
            'file_name'   => 'required|string',
            'file_size'   => 'required|integer|min:1',
            'file_type'   => 'required|string',
            'description' => 'nullable|string|max:500',
        ]);

        try {
            $task = Task::find($validated['task_id']);

            OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => $fileType,
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            ActivityLog::log('upload', 'Operation', $task->operation_id,
                "{$label} other file '{$validated['file_name']}' uploaded to operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'");

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("confirm{$label}OtherFilesUpload error", ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
