<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\Camera;
use App\FodDetection;
use App\FodImage;
use App\FodType;
use App\Operation;
use App\ResultFod;
use App\ResultsFodParams;
use App\Task;
use App\Services\ExifMetadataService;
use App\Services\GpuLambdaService;
use App\Jobs\ProcessYamlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;
use OpenApi\Attributes as OA;

class FodController extends Controller
{
    public function __construct(protected GpuLambdaService $lambda) {}

    // =========================================================================
    // RUN MANAGEMENT
    // =========================================================================

    #[OA\Delete(
        path: '/api/fod/{folder}/{operationId}/{taskId}/{runId}',
        summary: 'Delete a FOD run (DB records, detections, images and S3 folder)',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'folder',      in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run deleted'),
            new OA\Response(response: 404, description: 'Folder does not exist'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function deleteRun($folder, $operationId, $taskId, $runId): JsonResponse
    {
        $folderPath = "$folder/$operationId/$taskId/$runId/";

        try {
            if ($folder === 'FOD') {
                $fod = ResultFod::where('operation_id', $operationId)
                    ->where('task_id', $taskId)
                    ->where('run', $runId)
                    ->first();

                if ($fod) {
                    if ($fod->params_id) {
                        ResultFod::where('params_id', $fod->params_id)->update(['params_id' => null]);
                        ResultsFodParams::where('id', $fod->params_id)->delete();
                    }

                    foreach ($fod->fodImages as $fodImage) {
                        $fodImage->fodDetections()->delete();
                    }

                    $fod->fodImages()->delete();
                    $fod->delete();
                }
            }

            if (Storage::disk('s3')->exists($folderPath)) {
                Storage::disk('s3')->deleteDirectory($folderPath);
                ActivityLog::log('delete', 'Operation', (int) $operationId,
                    "Deleted FOD run #{$runId} from operation #{$operationId}, task #{$taskId}");
                return response()->json(['message' => 'Folder deleted successfully'], 200);
            }

            return response()->json(['message' => 'Folder does not exist'], 404);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in deleteRun: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Put(
        path: '/api/fod/run/valid',
        summary: 'Toggle the valid flag for a FOD run',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Valid flag updated'),
            new OA\Response(response: 404, description: 'Run not found'),
            new OA\Response(response: 500, description: 'Error updating run'),
        ]
    )]
    public function toggleValidRun(Request $request): JsonResponse
    {
        try {
            $result = ResultFod::where('task_id', $request->task_id)
                ->where('run', $request->run)
                ->first();

            if (!$result) {
                return response()->json(['message' => 'Run not found'], 404);
            }

            if ($request->is_valid) {
                ResultFod::where('task_id', $request->task_id)
                    ->where('run', '!=', $request->run)
                    ->update(['is_valid' => false]);
            }

            $result->update(['is_valid' => (bool) $request->is_valid]);

            $action = $request->is_valid ? 'Marked as valid' : 'Marked as not valid';
            ActivityLog::log('update', 'Operation', $result->operation_id,
                "{$action} FOD run #{$request->run} for task #{$request->task_id} on operation #{$result->operation_id}");

            return response()->json([
                'success' => true,
                'message' => $request->is_valid ? 'Run marked as valid' : 'Run marked as not valid',
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling valid FOD run: ' . $e->getMessage());
            return response()->json(['message' => 'Error updating run'], 500);
        }
    }

    // =========================================================================
    // RESULTS / VIEWING
    // =========================================================================

    #[OA\Get(
        path: '/api/fod/{operationId}/{taskId}/{runId}/processed',
        summary: 'Get processed FOD YAML data and image IDs for a run',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Processed FOD data'),
            new OA\Response(response: 400, description: 'No processed data found'),
            new OA\Response(response: 500, description: 'Error reading YAML'),
        ]
    )]
    public function viewFODProcessed($operationId, $taskId, $runId): JsonResponse
    {
        $operation  = Operation::find($operationId);
        $folderPath = "FOD/$operationId/$taskId/$runId/analysis_results/";
        $files      = Storage::disk('s3')->files($folderPath);

        if (!empty($files)) {
            return response()->json(['error' => 'No processed'], 400);
        }

        $folders = Storage::disk('s3')->directories($folderPath);
        if (empty($folders)) {
            return response()->json(['error' => 'No folders found'], 400);
        }

        $folderName     = basename($folders[0]);
        $folderYaml     = "FOD/$operationId/$taskId/$runId/analysis_results/$folderName/fods_identified.yaml";
        $folderPathFull = "FOD/$operationId/$taskId/$runId/analysis_results/$folderName/fods_identified/";

        try {
            $yamlContent = Storage::disk('s3')->get($folderYaml);
            $yamlArray   = Yaml::parse($yamlContent);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reading or parsing YAML file'], 500);
        }

        $imageIds = [];
        foreach ($yamlArray as $entry) {
            if (isset($entry['image_id'], $entry['image_path'])) {
                $imageName    = basename($entry['image_path']);
                $fullImagePath = $folderPathFull . $imageName;
                if (Storage::disk('s3')->exists($fullImagePath)) {
                    $imageIds[$imageName] = $entry['image_id'];
                }
            }
        }

        $fodTypes = DB::table('fod_types')->pluck('type')->toArray();
        array_unshift($fodTypes, 'Unknown FOD');

        return response()->json([
            'operation'      => $operation,
            'folderPath'     => $folderPathFull,
            'fodTypes'       => $fodTypes,
            'infoOperation'  => ['operationId' => $operationId, 'taskId' => $taskId, 'runId' => $runId],
            'yamlContent'    => $yamlArray,
            'imageIds'       => $imageIds,
        ]);
    }

    #[OA\Get(
        path: '/api/fod/image/{fodimgid}',
        summary: 'Get a specific FOD image with detections, navigation and metadata',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'fodimgid',    in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'filterTaskId', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'FOD image data returned'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function viewSpecificFOD($fodimgid, $filterTaskId = null): JsonResponse
    {
        $fodImage  = FodImage::find($fodimgid);
        $fod       = $fodImage->fod;
        $operation = $fod->operation;

        $allTaskIds = ResultFod::where('operation_id', $operation->id)->pluck('task_id')->unique();

        if ($filterTaskId) {
            $validRun      = ResultFod::where('operation_id', $operation->id)
                ->where('task_id', $filterTaskId)
                ->where('is_valid', true)
                ->first();
            $filteredFodIds = $validRun ? collect([$validRun->id]) : collect([]);
        } else {
            $filteredFodIds = ResultFod::where('operation_id', $operation->id)
                ->where('is_valid', true)
                ->pluck('id');
        }

        $allImages    = FodImage::whereIn('fod_id', $filteredFodIds)->orderBy('id')->pluck('id');
        $currentIndex = $allImages->search($fodimgid);
        if ($currentIndex === false) $currentIndex = 0;

        $prevImageId = $currentIndex > 0 ? $allImages[$currentIndex - 1] : 0;
        $nextImageId = $currentIndex < $allImages->count() - 1 ? $allImages[$currentIndex + 1] : 0;

        $taskOptions = Task::whereIn('id', $allTaskIds)->with('type')->get(['id', 'description', 'type_id']);
        $currentTask = Task::find($fod->task_id);

        $params = null;
        $camera = null;
        if ($fod->params_id) {
            $params = ResultsFodParams::find($fod->params_id);
            if ($params && $params->camera_id) {
                $camera = Camera::find($params->camera_id);
            }
        }

        $detections = FodDetection::where('image_id', $fodimgid)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->map(function ($detection, $index) {
                return [
                    'id'                   => $detection->id,
                    'image_index'          => $index + 1,
                    'detection_index'      => $detection->detection_number,
                    'bbox_x'               => $detection->bbox_x,
                    'bbox_y'               => $detection->bbox_y,
                    'bbox_width'           => $detection->bbox_width,
                    'bbox_height'          => $detection->bbox_height,
                    'bbox_dim_cm_width'    => $detection->bbox_dim_cm_width,
                    'bbox_dim_cm_height'   => $detection->bbox_dim_cm_height,
                    'type_id'              => $detection->type_id,
                    'type_name'            => FodType::find($detection->type_id)->type ?? 'Unknown',
                    'confidence'           => $detection->confidence,
                    'coordinate_latitude'  => $detection->coordinate_latitude,
                    'coordinate_longitude' => $detection->coordinate_longitude,
                    'coordinate_altitude'  => $detection->coordinate_altitude,
                    'is_duplicated'        => $detection->is_duplicated,
                    'detection_type'       => $detection->detection_type,
                    'removed'              => $detection->removed,
                    'created_at'           => $detection->created_at,
                    'updated_at'           => $detection->updated_at,
                ];
            });

        return response()->json([
            'operation'    => $operation,
            'fodImage'     => $fodImage,
            'taskId'       => $fod->task_id,
            'runId'        => $fod->run,
            'fodimgid'     => $fodimgid,
            'totalImages'  => $allImages->count(),
            'imgIndex'     => $currentIndex + 1,
            'prevImageId'  => $prevImageId,
            'nextImageId'  => $nextImageId,
            'imageIds'     => $allImages->toArray(),
            'detections'   => $detections,
            'fods_types'   => FodType::all(),
            'params'       => $params,
            'camera'       => $camera,
            'taskOptions'  => $taskOptions,
            'currentTask'  => $currentTask,
            'filterTaskId' => $filterTaskId,
        ]);
    }

    #[OA\Get(
        path: '/api/fod/processed-db',
        summary: 'Get processed FOD data from DB for a given run',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'operation_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'task_id',      in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',          in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run data returned'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getProcessedDataDB(Request $request): JsonResponse
    {
        $fod = ResultFod::where('operation_id', $request->get('operation_id'))
            ->where('task_id', $request->get('task_id'))
            ->where('run', $request->get('run'))
            ->first();

        if (!$fod) {
            return response()->json(['error' => 'Datos no encontrados'], 404);
        }

        return response()->json([
            'images'              => $fod->images,
            'num_imgs_processed'  => $fod->num_imgs_processed,
        ]);
    }

    // =========================================================================
    // IMAGE URLS
    // =========================================================================

    #[OA\Post(
        path: '/api/fod/image-url',
        summary: 'Get a temporary S3 URL for a FOD image by path',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Temporary URL returned'),
        ]
    )]
    public function getUrlImageFod(Request $request): JsonResponse
    {
        $imagePath = $request->input('imagePath');
        $imageUrl  = Storage::disk('s3')->temporaryUrl($imagePath, Carbon::now()->addMinutes(10));
        return response()->json(['url' => $imageUrl]);
    }

    #[OA\Post(
        path: '/api/fod/image-url-by-id',
        summary: 'Get a temporary S3 URL for a FOD image by ID within a folder',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Temporary URL returned'),
            new OA\Response(response: 404, description: 'Image not found'),
        ]
    )]
    public function getUrlImageFodPath(Request $request): JsonResponse
    {
        $idFod      = $request->input('idFod');
        $folderPath = $request->input('folderPath');
        $suffix     = '_' . $idFod . '.jpg';

        foreach (Storage::disk('s3')->files($folderPath) as $object) {
            if (str_ends_with(basename($object), $suffix)) {
                return response()->json([
                    'url' => Storage::disk('s3')->temporaryUrl($object, now()->addMinutes(10)),
                ]);
            }
        }

        return response()->json(['error' => 'Image not found'], 404);
    }

    #[OA\Get(
        path: '/api/fod/{operationId}/{taskId}/{runId}/image/{imageName}/coordinates',
        summary: 'Extract GPS coordinates from a FOD image EXIF data',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'imageName',   in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Coordinates returned'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 500, description: 'EXIF read error'),
        ]
    )]
    public function getImageCoordinates($operationId, $taskId, $runId, $imageName): JsonResponse
    {
        $folderPath = "FOD/$operationId/$taskId/$runId";

        if (!Storage::disk('s3')->exists($folderPath)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $findImage = collect(Storage::disk('s3')->files($folderPath))
            ->first(fn ($image) => strtolower(pathinfo($image, PATHINFO_BASENAME)) === strtolower($imageName));

        if (is_null($findImage)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        try {
            $exifService = new ExifMetadataService();
            $metadata    = $exifService->extractFromS3($findImage);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al leer los metadatos de la imagen', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['latitude' => $metadata['latitude'], 'longitude' => $metadata['longitude']]);
    }

    // =========================================================================
    // PROCESSING
    // =========================================================================

    #[OA\Post(
        path: '/api/fod/status',
        summary: 'Update the processing status or UUID of a FOD run',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'FOD entry not found'),
        ]
    )]
    public function updateStatus(Request $request): JsonResponse
    {
        if (!isset($request->operation_id)) {
            $fod = ResultFod::where('process_uuid', $request->input('process_uuid'))
                ->where('task_id', $request->input('task_id'))
                ->where('run', $request->input('run'))
                ->first();

            if (!$fod) {
                return response()->json(['message' => 'Fod entry not found'], 404);
            }

            $fod->status = $request->input('status');

            if ($request->input('status') === 'Error') {
                $fod->process_uuid = null;
                \App\GpuJobQueue::where('result_type', 'fod')->where('result_id', $fod->id)->where('status', 'processing')->update(['status' => 'failed']);
                $this->lambda->stopIfQueueEmpty();
            }

            if ($request->input('status') === 'Processed') {
                \App\GpuJobQueue::where('result_type', 'fod')->where('result_id', $fod->id)->where('status', 'processing')->update(['status' => 'done']);
                $this->lambda->stopIfQueueEmpty();
            }

            $fod->save();
            return response()->json(['message' => 'Status updated successfully'], 200);
        }

        $fod = ResultFod::where('operation_id', $request->input('operation_id'))
            ->where('task_id', $request->input('task_id'))
            ->where('run', $request->input('run'))
            ->first();

        if (!$fod) {
            return response()->json(['message' => 'Fod entry not found'], 404);
        }

        $fod->process_uuid = $request->input('process_uuid');
        $fod->save();
        return response()->json(['message' => 'Process UUID and status updated successfully'], 200);
    }

    #[OA\Post(
        path: '/api/fod/save-yaml',
        summary: 'Dispatch a job to parse the YAML results into the database',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Processing started or already processed'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function saveYamlToDatabase(Request $request): JsonResponse
    {
        $request->validate([
            'operation_id' => 'required|integer',
            'task_id'      => 'required|integer',
            'run_id'       => 'required|integer',
        ]);

        $fod = ResultFod::where('operation_id', $request->operation_id)
            ->where('task_id', $request->task_id)
            ->where('run', $request->run_id)
            ->first();

        if (!$fod || $fod->read_yaml == 1) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        ProcessYamlJob::dispatch($request->operation_id, $request->task_id, $request->run_id, 'FOD', auth()->id());

        return response()->json(['message' => 'Processing started'], 200);
    }

    // =========================================================================
    // DETECTIONS
    // =========================================================================

    #[OA\Put(
        path: '/api/fod/image/review',
        summary: 'Update the reviewed status of a FOD image',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Reviewed status updated'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateReviewStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fodImageId' => 'required|exists:fod_image,id',
            'status'     => 'required|boolean',
        ]);

        $fodImage = FodImage::find($validated['fodImageId']);

        if (!$fodImage) {
            return response()->json(['message' => 'Imagen no encontrada'], 404);
        }

        $fodImage->reviewed = (int) $validated['status'];
        $fodImage->save();

        return response()->json(['message' => 'Revisado actualizado correctamente']);
    }

    #[OA\Post(
        path: '/api/fod/detection',
        summary: 'Save a new manual FOD detection and generate its patch image',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Detection saved'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Patch generation failed'),
        ]
    )]
    public function saveManualNewDetection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image_id'             => 'required|integer',
            'bbox_x'               => 'required|numeric',
            'bbox_y'               => 'required|numeric',
            'bbox_width'           => 'required|numeric',
            'bbox_height'          => 'required|numeric',
            'bbox_dim_cm_width'    => 'nullable|numeric',
            'bbox_dim_cm_height'   => 'nullable|numeric',
            'type_id'              => 'required|integer',
            'confidence'           => 'required|integer',
            'coordinate_latitude'  => 'nullable|numeric',
            'coordinate_longitude' => 'nullable|numeric',
            'coordinate_altitude'  => 'nullable|numeric',
        ]);

        $fodImage = FodImage::find($validated['image_id']);
        if (!$fodImage) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $lastDetectionNumber = FodDetection::whereHas('fodImage', fn ($q) => $q->where('fod_id', $fodImage->fod_id))
            ->max('detection_number') ?? 0;

        $centerX   = $validated['bbox_x'] + ($validated['bbox_width'] / 2);
        $centerY   = $validated['bbox_y'] + ($validated['bbox_height'] / 2);

        $detection = new FodDetection([
            'image_id'             => $validated['image_id'],
            'detection_number'     => $lastDetectionNumber + 1,
            'bbox_x'               => $centerX,
            'bbox_y'               => $centerY,
            'bbox_width'           => $validated['bbox_width'],
            'bbox_height'          => $validated['bbox_height'],
            'bbox_dim_cm_width'    => $validated['bbox_dim_cm_width'],
            'bbox_dim_cm_height'   => $validated['bbox_dim_cm_height'],
            'type_id'              => $validated['type_id'],
            'confidence'           => $validated['confidence'],
            'coordinate_latitude'  => $validated['coordinate_latitude'],
            'coordinate_longitude' => $validated['coordinate_longitude'],
            'coordinate_altitude'  => $validated['coordinate_altitude'],
            'is_duplicated'        => 0,
            'detection_type'       => 'M',
        ]);
        $detection->save();

        $newS3Path = dirname($fodImage->image_path) . '/patches/' . $detection->id . '.jpg';

        try {
            (new \App\Services\ThumbnailService())->cropAndUpload(
                $fodImage->image_path,
                (int) $validated['bbox_x'],
                (int) $validated['bbox_y'],
                (int) $validated['bbox_width'],
                (int) $validated['bbox_height'],
                $newS3Path
            );

            return response()->json([
                'success'          => true,
                'message'          => 'New detection saved successfully.',
                'detection_id'     => $detection->id,
                'detection_number' => $lastDetectionNumber + 1,
            ]);
        } catch (\Exception $e) {
            $detection->delete();
            return response()->json(['error' => 'Failed to create patch image: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/api/fod/detection',
        summary: 'Delete or soft-delete a FOD detection (A=soft, M=hard)',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Detection deleted'),
            new OA\Response(response: 404, description: 'Detection not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function deleteDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        $detection = FodDetection::find($validated['detection_id']);

        if (!$detection) {
            return response()->json(['error' => 'Detection not found.'], 404);
        }

        if ($detection->detection_type === 'A') {
            if ($detection->removed == 1) {
                $detection->delete();
            } else {
                $detection->removed = 1;
                $detection->save();
            }
        } elseif ($detection->detection_type === 'M') {
            $detection->delete();
        }

        return response()->json(['success' => true]);
    }

    #[OA\Put(
        path: '/api/fod/detection/disable',
        summary: 'Soft-disable a FOD detection (removed = 1)',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Detection disabled'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function disableDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        $detection = FodDetection::findOrFail($validated['detection_id']);
        $detection->removed = 1;
        $detection->save();
        return response()->json(['success' => true]);
    }

    #[OA\Put(
        path: '/api/fod/detection/restore',
        summary: 'Restore a soft-disabled FOD detection (removed = 0)',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Detection restored'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function restoreDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        $detection = FodDetection::findOrFail($validated['detection_id']);
        $detection->removed = 0;
        $detection->save();
        return response()->json(['success' => true]);
    }

    #[OA\Delete(
        path: '/api/fod/detection/hard-delete',
        summary: 'Permanently delete a FOD detection',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Detection deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function hardDeleteDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        FodDetection::findOrFail($validated['detection_id'])->delete();
        return response()->json(['success' => true]);
    }

    // =========================================================================
    // IMAGE PROCESSING (LOCAL TEMP)
    // =========================================================================

    #[OA\Get(
        path: '/api/fod/image/{imageId}/bboxes',
        summary: 'Generate a JPEG with bounding boxes drawn for all detections in an image',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        parameters: [
            new OA\Parameter(name: 'imageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Image URL with bboxes'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 500, description: 'Image processing error'),
        ]
    )]
    public function generateImageWithBboxes($imageId): JsonResponse
    {
        $fodImage = FodImage::find($imageId);
        if (!$fodImage) {
            return response()->json(['error' => 'Image record not found.'], 404);
        }

        $altitude            = $fodImage->fod->params->altitude ?? 20;
        $scaleFactor         = sqrt(5 / max(1, $altitude));
        $fontSize            = max(12, 50 * $scaleFactor);
        $rectangleThickness  = max(2, 15 * $scaleFactor);

        $detections = FodDetection::query()
            ->join('fod_image', 'fod_detection.image_id', '=', 'fod_image.id')
            ->join('results_fod', 'fod_image.fod_id', '=', 'results_fod.id')
            ->where('results_fod.operation_id', $fodImage->fod->operation->id)
            ->where('fod_detection.image_id', $fodImage->id)
            ->orderBy('fod_detection.created_at', 'ASC')
            ->get();

        $imageContents = Storage::disk('s3')->get($fodImage->image_path);
        $img           = imagecreatefromstring($imageContents);
        if (!$img) {
            return response()->json(['error' => 'Failed to create image from file.'], 500);
        }

        $imageWidth  = imagesx($img);
        $imageHeight = imagesy($img);
        $black       = imagecolorallocate($img, 0, 0, 0);
        $fontPath    = public_path('fonts/arial.ttf');
        $padding     = 15;

        foreach ($detections as $detection) {
            $centerX = $detection->bbox_x;
            $centerY = $detection->bbox_y;
            $width   = $detection->bbox_width;
            $height  = $detection->bbox_height;
            $x1      = $centerX - ($width / 2);
            $y1      = $centerY - ($height / 2);
            $x2      = $centerX + ($width / 2);
            $y2      = $centerY + ($height / 2);

            $color = ($detection->removed === 1)
                ? imagecolorallocate($img, 255, 200, 100)
                : imagecolorallocate($img, 51, 255, 51);

            if ($x1 >= 0 && $y1 >= 0 && $x2 <= $imageWidth && $y2 <= $imageHeight) {
                for ($i = 0; $i < $rectangleThickness; $i++) {
                    imagerectangle($img, $x1 - $i, $y1 - $i, $x2 + $i, $y2 + $i, $color);
                }

                $text           = (string) $detection->detection_number;
                $textBoundingBox = imagettfbbox($fontSize, 0, $fontPath, $text);
                $textWidth      = $textBoundingBox[2] - $textBoundingBox[0];
                $textHeight     = abs($textBoundingBox[1] - $textBoundingBox[7]);
                $textX          = $x1;
                $textY          = $y1 - 20;

                imagefilledrectangle($img, $textX - $padding, $textY - $textHeight - $padding, $textX + $textWidth + $padding, $textY + $padding, $color);
                imagettftext($img, $fontSize, 0, $textX, $textY, $black, $fontPath, $text);
            }
        }

        $tempDir       = storage_path('app/public/img/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);
        $tempImagePath = $tempDir . "/temp_image_with_bboxes_$imageId.jpg";
        imagejpeg($img, $tempImagePath);

        [$width, $height] = getimagesize($tempImagePath);

        return response()->json([
            'url'    => asset("storage/img/temp/temp_image_with_bboxes_$imageId.jpg"),
            'width'  => $width,
            'height' => $height,
        ]);
    }

    #[OA\Post(
        path: '/api/fod/image/cutout',
        summary: 'Crop a region from a previously generated bbox image',
        security: [['bearerAuth' => []]],
        tags: ['FOD'],
        responses: [
            new OA\Response(response: 200, description: 'Cropped image URL returned'),
            new OA\Response(response: 400, description: 'Invalid crop dimensions'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function generateImageCutout(Request $request): JsonResponse
    {
        $request->validate([
            'imageId'      => 'required|integer',
            'topLeftX'     => 'required|numeric',
            'topLeftY'     => 'required|numeric',
            'bottomRightX' => 'required|numeric',
            'bottomRightY' => 'required|numeric',
        ]);

        $imageId  = $request->input('imageId');
        $image    = Image::make(storage_path("app/public/img/temp/temp_image_with_bboxes_$imageId.jpg"));

        $topLeftX     = (int) $request->topLeftX;
        $topLeftY     = (int) $request->topLeftY;
        $bottomRightX = (int) $request->bottomRightX;
        $bottomRightY = (int) $request->bottomRightY;
        $width        = $bottomRightX - $topLeftX;
        $height       = $bottomRightY - $topLeftY;

        if ($width <= 0 || $height <= 0) {
            return response()->json(['error' => 'Las dimensiones del recorte deben ser mayores que cero.'], 400);
        }

        $croppedImage = $image->crop($width, $height, $topLeftX, $topLeftY);
        $croppedImage->save(public_path('img/temp/temp_image_cutout_fod.jpg'));

        return response()->json([
            'imageUrl'      => asset('img/temp/temp_image_cutout_fod.jpg'),
            'croppedWidth'  => $croppedImage->width(),
            'croppedHeight' => $croppedImage->height(),
        ]);
    }
}
