<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Operation;
use App\Models\ResultsRwyLights;
use App\Models\ResultsRwyMarkings;
use App\Models\ResultsTxyLights;
use App\Models\Runway;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class RunwayRunController extends Controller
{
    // ── Get task runs ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operations/{operation}/tasks/{taskId}/runs',
        summary: 'Get runs for a task, resolved by operation type (RWY or TXY lights)',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',    in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of runs with image count'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getTaskRuns(Operation $operation, int $taskId): JsonResponse
    {
        // NOTE: original code called operation() as a global function (bug).
        // Fixed: operation is now a route-model-bound parameter.
        if ($operation->type_id == 11) {
            $runs = ResultsTxyLights::where('task_id', $taskId)->withCount('lightsImages')->get();
        } else {
            $runs = ResultsRwyLights::where('task_id', $taskId)->withCount('lightsImages')->get();
        }

        return response()->json(['runs' => $runs]);
    }

    // ── View light image ──────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operations/light-image',
        summary: 'Get pre-signed URL for the first lights image of a task run',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Image URL and run data'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function viewLightImage(Request $request): JsonResponse
    {
        $operationId = $request->get('operationId');
        $taskId      = $request->get('taskId');
        $operation   = Operation::find($operationId);

        if (!$operation) {
            return response()->json(['error' => 'Operation not found.'], 404);
        }

        if ($operation->type_id == 11) {
            $lightRun = ResultsTxyLights::where('operation_id', $operationId)->where('task_id', $taskId)->first();
        } else {
            $lightRun = ResultsRwyLights::where('operation_id', $operationId)->where('task_id', $taskId)->first();
        }

        if (!$lightRun) {
            return response()->json(['error' => 'Light run not found.'], 404);
        }

        $runId      = $lightRun->id;
        $lightImage = $operation->type_id == 11
            ? \App\Models\LightsImage::where('txy_id', $runId)->first()
            : \App\Models\LightsImage::where('rwy_id', $runId)->first();

        if (!$lightImage) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $temporaryUrl = Storage::disk('s3')->temporaryUrl($lightImage->image_path, Carbon::now()->addMinutes(8));

        return response()->json([
            'run_id'        => $runId,
            'image_path'    => $lightImage->image_path,
            'temporary_url' => $temporaryUrl,
            'typeName'      => $lightRun->task->type->name,
            'lightImage'    => $lightImage,
        ]);
    }

    // ── Run images — lights ───────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operations/{operationId}/tasks/{taskId}/runs/{runId}/images/lights',
        summary: 'Get pre-signed image URLs for a lights run',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Images with temporary URLs'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getRunImagesLights(int $operationId, int $taskId, int $runId): JsonResponse
    {
        $operation = Operation::findOrFail($operationId);
        $task      = $operation->tasks()->findOrFail($taskId);
        $run       = $task->resultsLights()->findOrFail($runId);
        $images    = $run->images;

        if ($images->isEmpty()) {
            return response()->json(['error' => 'No images found for the selected run.'], 404);
        }

        $imageData = $images->map(fn($image) => [
            'id'           => $image->id,
            'temporaryUrl' => Storage::disk('s3')->temporaryUrl($image->image_path, now()->addMinutes(20)),
            'direction'    => $image->direction,
            'reviewed'     => $image->reviewed,
        ]);

        return response()->json(['images' => $imageData, 'totalImages' => $images->count()]);
    }

    // ── Run images — markings ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operations/{operationId}/tasks/{taskId}/runs/{runId}/images/markings',
        summary: 'Get pre-signed image URLs for a markings run',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Images with temporary URLs'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getRunImagesMarkings(int $operationId, int $taskId, int $runId): JsonResponse
    {
        $operation = Operation::findOrFail($operationId);
        $task      = $operation->tasks()->findOrFail($taskId);
        $run       = $task->resultsMarkings()->where('run', $runId)->first();
        $images    = $run->images;

        if ($images->isEmpty()) {
            return response()->json(['error' => 'No images found for the selected run.'], 404);
        }

        $imageData = $images->map(fn($image) => [
            'id'           => $image->id,
            'temporaryUrl' => Storage::disk('s3')->temporaryUrl($image->image_path, now()->addMinutes(20)),
            'direction'    => $image->direction,
            'reviewed'     => $image->reviewed,
        ]);

        return response()->json(['images' => $imageData, 'totalImages' => $images->count()]);
    }

    // ── Toggle run validation ─────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/operations/runs/toggle-validation',
        summary: 'Mark or unmark a run as valid for RWY lights, TXY lights or markings',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operationId', 'taskId', 'runNumber', 'isValid'],
                properties: [
                    new OA\Property(property: 'operationId', type: 'integer'),
                    new OA\Property(property: 'taskId',      type: 'integer'),
                    new OA\Property(property: 'runNumber',   type: 'integer'),
                    new OA\Property(property: 'isValid',     type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Validation updated'),
            new OA\Response(response: 400, description: 'Invalid operation type'),
        ]
    )]
    public function toggleRunValidation(Request $request): JsonResponse
    {
        $operationId = $request->input('operationId');
        $taskId      = $request->input('taskId');
        $runNumber   = $request->input('runNumber');
        $isValid     = $request->input('isValid');
        $operation   = Operation::findOrFail($operationId);

        $modelClass = match ($operation->type_id) {
            10      => ResultsRwyLights::class,
            11      => ResultsTxyLights::class,
            22      => ResultsRwyMarkings::class,
            default => null,
        };

        if (!$modelClass) {
            return response()->json(['error' => 'Invalid operation type'], 400);
        }

        $run = $modelClass::where('task_id', $taskId)->where('operation_id', $operationId)->where('run', $runNumber)->first();

        if (!$run) {
            return response()->json(['success' => false, 'error' => 'Run not found.']);
        }

        if ($isValid) {
            $modelClass::where('task_id', $taskId)->where('operation_id', $operationId)->update(['is_valid' => 0]);
        }

        $run->is_valid = $isValid ? 1 : 0;
        $updated = $run->save();

        return $updated
            ? response()->json(['success' => true, 'message' => 'Run validation updated successfully.', 'isValid' => $isValid])
            : response()->json(['success' => false, 'error' => 'Failed to update run validation.']);
    }

    // ── Get run validation status ─────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operations/runs/validation-status',
        summary: 'Get the is_valid flag for a specific run',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runNumber',   in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Validation status'),
        ]
    )]
    public function getRunValidationStatus(Request $request): JsonResponse
    {
        $operationId = $request->input('operationId');
        $taskId      = $request->input('taskId');
        $runNumber   = $request->input('runNumber');
        $operation   = Operation::findOrFail($operationId);

        $modelClass = $operation->type_id == 11 ? ResultsTxyLights::class : ResultsRwyLights::class;

        $run = $modelClass::where('task_id', $taskId)->where('operation_id', $operationId)->where('run', $runNumber)->first();

        return $run
            ? response()->json(['success' => true, 'isValid' => $run->is_valid])
            : response()->json(['success' => false, 'error' => 'Run not found.']);
    }

    // ── Delete run ────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/operations/runs',
        summary: 'Delete a run and its S3 folder',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operationId', 'taskId', 'runId'],
                properties: [
                    new OA\Property(property: 'operationId', type: 'integer'),
                    new OA\Property(property: 'taskId',      type: 'integer'),
                    new OA\Property(property: 'runId',       type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Run deleted'),
            new OA\Response(response: 400, description: 'Invalid operation type'),
            new OA\Response(response: 404, description: 'Run not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deleteRun(Request $request): JsonResponse
    {
        $operationId = $request->input('operationId');
        $taskId      = $request->input('taskId');
        $runId       = $request->input('runId');
        $operation   = Operation::findOrFail($operationId);

        [$modelClass, $folderType] = match ($operation->type_id) {
            10      => [ResultsRwyLights::class, 'Lights'],
            11      => [ResultsTxyLights::class, 'Lights'],
            22      => [ResultsRwyMarkings::class, 'Markings'],
            default => [null, null],
        };

        if (!$modelClass) {
            return response()->json(['error' => 'Invalid operation type'], 400);
        }

        try {
            $toDelete = $modelClass::where('operation_id', $operationId)->where('task_id', $taskId)->where('run', $runId)->first();

            if (!$toDelete) {
                return response()->json(['message' => 'Run not found'], 404);
            }

            $toDelete->images()->delete();
            $toDelete->delete();

            $folderPath = "$folderType/$operationId/$taskId/$runId/";

            if (Storage::disk('s3')->exists($folderPath)) {
                Storage::disk('s3')->deleteDirectory($folderPath);
            } else {
                return response()->json(['warning' => "Folder not found in S3: $folderPath"]);
            }

            ActivityLog::log('delete', 'Operation', (int) $operationId, "Deleted {$folderType} run #{$runId} from operation #{$operationId}, task #{$taskId}");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in deleteRun: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Run deleted successfully', 'status' => 'success']);
    }

    // ── Upload lights diagram ─────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/runways/{runway}/lights-diagram',
        summary: 'Upload a runway lights diagram image',
        security: [['bearerAuth' => []]],
        tags: ['Runway Runs'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['diagram'],
                    properties: [
                        new OA\Property(property: 'diagram', type: 'string', format: 'binary'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Diagram uploaded'),
            new OA\Response(response: 400, description: 'No file uploaded'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function uploadLightsDiagram(Request $request, Runway $runway): JsonResponse
    {
        try {
            $request->validate([
                'diagram' => 'required|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if (!$request->file('diagram')) {
                return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
            }

            $runway->clearMediaCollection('rwy_lights_diagram');
            $mediaItem  = $runway->addMediaFromRequest('diagram')->toMediaCollection('rwy_lights_diagram', 'public');
            $diagramUrl = $runway->getFirstMediaUrl('rwy_lights_diagram');

            return response()->json([
                'success'     => true,
                'message'     => 'Runway lights diagram uploaded successfully',
                'diagram_url' => $diagramUrl,
                'media_id'    => $mediaItem->id,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Runway lights diagram upload error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }
}
