<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\LightsImage;
use App\LightsProcessingJob;
use App\LightsVideo;
use App\Operation;
use App\OperationFiles;
use App\ResultsRwyLights;
use App\ResultsTxyLights;
use App\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class LightsController extends Controller
{
    // ---------------------------------------------------------------
    // Upload endpoints
    // ---------------------------------------------------------------

    #[OA\Post(
        path: '/api/lights/confirm-upload',
        summary: 'Confirm S3 multipart upload and create/update a processing job',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Upload confirmed'),
            new OA\Response(response: 404, description: 'Job not found (SRT)'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function confirmUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            's3_path'                  => 'required|string',
            'file_type'                => 'required|string|in:video,srt',
            'metadata'                 => 'required|array',
            'metadata.operation_id'    => 'required|integer',
            'metadata.task_id'         => 'required|integer',
            'metadata.run'             => 'required|integer',
            'metadata.side'            => 'required|string',
            'metadata.runway_id'       => 'nullable|integer',
            'metadata.fly_speed'       => 'nullable|numeric',
            'metadata.objective_mpf'   => 'nullable|numeric',
            'metadata.file_size'       => 'required|integer',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $metadata = $request->input('metadata');
            $fileType = $request->input('file_type');
            $s3Path   = $request->input('s3_path');

            if ($fileType === 'video') {
                $job = LightsProcessingJob::create([
                    'operation_id'     => $metadata['operation_id'],
                    'task_id'          => $metadata['task_id'],
                    'runway_id'        => $metadata['runway_id'] ?? null,
                    'run'              => $metadata['run'],
                    'side'             => $metadata['side'],
                    'fly_speed'        => $metadata['fly_speed'] ?? null,
                    'objective_mpf'    => $metadata['objective_mpf'] ?? null,
                    'video_s3_path'    => $s3Path,
                    'video_size_bytes' => $metadata['file_size'],
                    'status'           => LightsProcessingJob::STATUS_UPLOADING_VIDEO,
                ]);

                return response()->json(['success' => true, 'message' => 'Video upload confirmed', 'job_id' => $job->id]);
            }

            if ($fileType === 'srt') {
                $job = LightsProcessingJob::where('operation_id', $metadata['operation_id'])
                    ->where('task_id', $metadata['task_id'])
                    ->where('run', $metadata['run'])
                    ->where('side', $metadata['side'])
                    ->where('status', '!=', LightsProcessingJob::STATUS_FAILED)
                    ->orderBy('id', 'desc')
                    ->first();

                if (!$job) {
                    Log::error('Processing job not found for SRT file', ['metadata' => $metadata]);
                    return response()->json(['success' => false, 'message' => 'Processing job not found for this SRT file'], 404);
                }

                $job->update([
                    'srt_s3_path'    => $s3Path,
                    'srt_size_bytes' => $metadata['file_size'],
                    'status'         => LightsProcessingJob::STATUS_UPLOADING_SRT,
                ]);

                return response()->json(['success' => true, 'message' => 'SRT upload confirmed', 'job_id' => $job->id]);
            }

            Log::error('Unknown file type', ['file_type' => $fileType]);
            return response()->json(['success' => false, 'message' => 'Unknown file type'], 400);

        } catch (\Exception $e) {
            Log::error('=== LIGHTS CONFIRM UPLOAD ERROR ===', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error confirming upload: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/lights/register-operation-file',
        summary: 'Register an uploaded file as an OperationFile record',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'File registered'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function registerOperationFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            'file_name'    => 'required|string',
            'file_size'    => 'required|integer',
            'file_type'    => 'required|string',
            's3_path'      => 'required|string',
            'description'  => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $record = OperationFiles::create([
                'file_name'   => $request->file_name,
                'description' => $request->description ?? '',
                'type'        => $request->file_type,
                'size'        => $request->file_size,
                'task_id'     => $request->task_id,
            ]);

            return response()->json(['success' => true, 'message' => 'File registered successfully', 'id' => $record->id]);

        } catch (\Exception $e) {
            Log::error('Error registering operation file', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error registering file: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/lights/confirm-rwy-image-upload',
        summary: 'Confirm runway image upload and create ResultsRwyLights + LightsImage records',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Image confirmed'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function confirmRwyImageUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id'    => 'required|integer|exists:operations,id',
            'task_id'         => 'required|integer|exists:tasks,id',
            'runway_id'       => 'nullable|integer|exists:runways,id',
            'run'             => 'required|integer|min:1',
            'side'            => 'required|string|max:10',
            'flight_altitude' => 'nullable|numeric',
            's3_path'         => 'required|string',
            'file_name'       => 'required|string',
        ]);

        try {
            $operation = Operation::findOrFail($validated['operation_id']);

            $result = ResultsRwyLights::firstOrCreate(
                ['task_id' => $validated['task_id'], 'run' => $validated['run'], 'side' => $validated['side']],
                [
                    'rwy_id'            => $validated['runway_id'],
                    'operation_id'      => $validated['operation_id'],
                    'content_type'      => 'images',
                    'processing_status' => 'Processed',
                    'is_valid'          => 0,
                    'is_video'          => 0,
                ]
            );

            $pathInfo      = pathinfo($validated['s3_path']);
            $thumbnailPath = $pathInfo['dirname'] . '/thumbnail/' . $pathInfo['filename'] . '.jpg';

            (new \App\Services\ThumbnailService())->generateThumbnail($validated['s3_path'], $thumbnailPath);

            $image = LightsImage::create([
                'type_id'               => $operation->type_id,
                'results_rwy_lights_id' => $result->id,
                'txy_id'                => null,
                'direction'             => $validated['side'],
                'image_path'            => $validated['s3_path'],
                'thumbnail_path'        => $thumbnailPath,
                'reviewed'              => 0,
                'type_upload'           => 'images',
                'flight_altitude'       => $validated['flight_altitude'],
            ]);

            return response()->json(['success' => true, 'image_id' => $image->id, 'result_id' => $result->id]);

        } catch (\Exception $e) {
            Log::error('confirmRwyImageUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/lights/confirm-txy-upload',
        summary: 'Confirm taxiway file upload and create ResultsTxyLights + LightsImage + OperationFiles records',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Taxiway upload confirmed'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function confirmTxyUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'task_id'      => 'required|integer|exists:tasks,id',
            'run'          => 'required|integer|min:1',
            's3_path'      => 'required|string',
            'file_name'    => 'required|string',
            'file_size'    => 'required|integer',
            'file_type'    => 'required|string',
        ]);

        try {
            $operation = Operation::findOrFail($validated['operation_id']);
            $task      = Task::findOrFail($validated['task_id']);
            $taxi      = \App\Taxiway::where('name', $task->description)->first();

            $result = ResultsTxyLights::firstOrCreate(
                ['task_id' => $validated['task_id'], 'run' => $validated['run']],
                [
                    'txy_id'       => $taxi?->id,
                    'operation_id' => $validated['operation_id'],
                    'is_valid'     => 0,
                ]
            );

            $lightsImage = LightsImage::create([
                'type_id'               => $operation->type_id,
                'results_rwy_lights_id' => null,
                'txy_id'                => $result->id,
                'direction'             => null,
                'image_path'            => '/' . $validated['s3_path'],
                'reviewed'              => 0,
            ]);

            OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => '',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            return response()->json(['success' => true, 'image_id' => $lightsImage->id, 'result_id' => $result->id]);

        } catch (\Exception $e) {
            Log::error('confirmTxyUpload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------
    // Inspection / Run management
    // ---------------------------------------------------------------

    #[OA\Get(
        path: '/api/lights/inspections/{id}',
        summary: 'Get a lights inspection with its videos',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inspection data'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getInspection(int $id): JsonResponse
    {
        try {
            $inspection = ResultsRwyLights::with('videos')->findOrFail($id);
            return response()->json(['success' => true, 'inspection' => $inspection]);
        } catch (\Exception $e) {
            Log::error('Error getting inspection', ['inspection_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Inspection not found'], 404);
        }
    }

    #[OA\Put(
        path: '/api/lights/inspections/{id}/processing-status',
        summary: 'Update the processing status of an inspection',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function updateProcessingStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'processing_status' => 'required|string|in:Uploading,Unprocessed,Processing,Processed,Failed',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $inspection = ResultsRwyLights::findOrFail($id);
            $inspection->update(['processing_status' => $request->processing_status]);

            return response()->json(['success' => true, 'message' => 'Processing status updated successfully', 'inspection' => $inspection]);

        } catch (\Exception $e) {
            Log::error('Error updating inspection status', ['inspection_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/api/lights/runs',
        summary: 'Delete a complete run (jobs, images, detections, result)',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Run deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function deleteRun(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'operation_id' => 'required|integer',
                'task_id'      => 'required|integer',
                'run'          => 'required|integer',
                'side'         => 'required|string',
            ]);

            $operationId = $validated['operation_id'];
            $taskId      = $validated['task_id'];
            $run         = $validated['run'];
            $side        = $validated['side'];

            DB::beginTransaction();

            $jobs = LightsProcessingJob::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $run)
                ->where('side', $side)
                ->get();

            foreach ($jobs as $job) {
                $s3Path = "Lights/{$operationId}/{$taskId}/{$run}/{$side}/";
                $files  = Storage::disk('s3')->files($s3Path);

                if (!empty($files)) {
                    Storage::disk('s3')->delete($files);
                }

                try {
                    Storage::disk('s3')->deleteDirectory($s3Path);
                } catch (\Exception $e) {
                    Log::warning('[deleteRun] Could not delete S3 directory', ['path' => $s3Path, 'error' => $e->getMessage()]);
                }

                $job->delete();
            }

            $result = ResultsRwyLights::where('task_id', $taskId)
                ->where('run', $run)
                ->where('side', $side)
                ->first();

            if (!$result) {
                if ($jobs->count() > 0) {
                    DB::commit();
                    return response()->json(['success' => true, 'message' => "Run {$run} ({$side}) deleted successfully"]);
                }

                DB::rollBack();
                return response()->json(['success' => false, 'message' => "Run {$run} ({$side}) not found"], 404);
            }

            $images = LightsImage::where('results_rwy_lights_id', $result->id)->get();
            foreach ($images as $image) {
                \App\LightsDetection::where('image_id', $image->id)->delete();
            }
            LightsImage::where('results_rwy_lights_id', $result->id)->delete();
            LightsVideo::where('result_rwy_light_id', $result->id)->delete();
            $result->delete();

            DB::commit();

            ActivityLog::log('delete', 'Operation', (int) $operationId, "Deleted Lights run #{$run} ({$side}) from operation #{$operationId}, task #{$taskId}");

            return response()->json(['success' => true, 'message' => "Run {$run} ({$side}) deleted successfully"]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Invalid parameters', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error deleting run: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/lights/complete-extraction',
        summary: 'Mark frame extraction as complete and create a permanent ResultsRwyLights record',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Extraction completed'),
            new OA\Response(response: 404, description: 'Job not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function completeExtraction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'job_id'       => 'required|integer',
            'total_frames' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $job = LightsProcessingJob::findOrFail($request->job_id);

            $existingResult = ResultsRwyLights::where('task_id', $job->task_id)
                ->where('run', $job->run)
                ->where('side', $job->side)
                ->first();

            if ($existingResult) {
                Log::warning('Result already exists for this run', ['existing_result_id' => $existingResult->id]);
                $job->delete();
                DB::commit();

                return response()->json(['success' => true, 'message' => 'Result already exists', 'result_id' => $existingResult->id, 'was_duplicate' => true]);
            }

            $result = ResultsRwyLights::create([
                'task_id'           => $job->task_id,
                'rwy_id'            => $job->runway_id,
                'operation_id'      => $job->operation_id,
                'run'               => $job->run,
                'side'              => $job->side,
                'content_type'      => 'images',
                'fly_speed'         => $job->fly_speed,
                'objective_mpf'     => $job->objective_mpf,
                'processing_status' => 'Unprocessed',
                'process_uuid'      => null,
                'is_valid'          => true,
            ]);

            $job->delete();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Frame extraction completed successfully', 'result_id' => $result->id, 'total_frames' => $request->total_frames]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            Log::error('Job not found', ['job_id' => $request->job_id]);
            return response()->json(['success' => false, 'message' => 'Processing job not found'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('=== COMPLETE EXTRACTION ERROR ===', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error completing extraction: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/lights/toggle-valid',
        summary: 'Mark or unmark a run as valid (only one valid per task+side)',
        security: [['bearerAuth' => []]],
        tags: ['Lights'],
        responses: [
            new OA\Response(response: 200, description: 'Valid status toggled'),
            new OA\Response(response: 404, description: 'Result not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function toggleValid(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_id'   => 'required|integer',
            'result_id' => 'required|integer',
            'is_valid'  => 'required|in:true,false,1,0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $isValid  = filter_var($request->is_valid, FILTER_VALIDATE_BOOLEAN);
            $result   = ResultsRwyLights::findOrFail($request->result_id);
            $side     = $result->side;

            if ($isValid) {
                ResultsRwyLights::where('task_id', $request->task_id)
                    ->where('side', $side)
                    ->where('id', '!=', $request->result_id)
                    ->update(['is_valid' => false]);

                $result->update(['is_valid' => true]);
                $message = "Run marked as valid for side {$side}";
            } else {
                $result->update(['is_valid' => false]);
                $message = 'Run unmarked';
            }

            DB::commit();

            $validLabel = $isValid ? 'marked as valid' : 'unmarked as valid';
            ActivityLog::log('validate', 'Operation', (int) $result->operation_id, "Lights run #{$result->run} ({$side}) {$validLabel} for operation #{$result->operation_id}, task #{$request->task_id}");

            return response()->json(['success' => true, 'message' => $message, 'result_id' => $request->result_id, 'is_valid' => $isValid, 'side' => $side]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Result not found'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('=== TOGGLE VALID ERROR ===', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error toggling valid: ' . $e->getMessage()], 500);
        }
    }
}
