<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Operation;
use App\ResultFlightTurn;
use App\FlightTurnImage;
use App\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class FlightTurnController extends Controller
{
    #[OA\Get(
        path: '/api/flight-turn/{operationId}/{taskId}/{runId}',
        summary: 'Get temporary image URLs for a FlightTurn run',
        security: [['bearerAuth' => []]],
        tags: ['FlightTurn'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run images returned'),
            new OA\Response(response: 404, description: 'Operation not found'),
        ]
    )]
    public function viewFlightTurn($operationId, $taskId, $runId): JsonResponse
    {
        $operation  = Operation::find($operationId);
        $folderPath = "FlightTurn/$operationId/$taskId/$runId";
        $files      = Storage::disk('s3')->files($folderPath);

        $imageUrls = [];
        foreach ($files as $file) {
            $imageUrls[] = Storage::disk('s3')->temporaryUrl($file, Carbon::now()->addMinutes(8));
        }

        return response()->json([
            'operation'      => $operation,
            'folderPath'     => $folderPath,
            'infoOperation'  => ['operationId' => $operationId, 'taskId' => $taskId, 'runId' => $runId],
            'imageUrls'      => $imageUrls,
        ]);
    }

    #[OA\Delete(
        path: '/api/flight-turn/{folder}/{operationId}/{taskId}/{runId}',
        summary: 'Delete a FlightTurn run (DB records + S3 folder)',
        security: [['bearerAuth' => []]],
        tags: ['FlightTurn'],
        parameters: [
            new OA\Parameter(name: 'folder',      in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run deleted'),
            new OA\Response(response: 404, description: 'Folder does not exist'),
        ]
    )]
    public function deleteRun($folder, $operationId, $taskId, $runId): JsonResponse
    {
        $folderPath = "$folder/$operationId/$taskId/$runId/";

        if ($folder === 'FlightTurn') {
            $flightTurn = ResultFlightTurn::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->first();

            if ($flightTurn) {
                $flightTurn->images()->delete();
                $flightTurn->delete();
            }
        }

        if (Storage::disk('s3')->exists($folderPath)) {
            Storage::disk('s3')->deleteDirectory($folderPath);
            return response()->json([
                'message' => 'Folder deleted successfully',
                'task_id' => $taskId,
                'run_id'  => $runId,
            ], 200);
        }

        return response()->json(['message' => 'Folder does not exist'], 404);
    }

    #[OA\Get(
        path: '/api/flight-turn/{operationId}/{taskId}/result',
        summary: 'Get all thumbnail URLs for a FlightTurn task result',
        security: [['bearerAuth' => []]],
        tags: ['FlightTurn'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Task result images returned'),
            new OA\Response(response: 404, description: 'FlightTurn not found'),
        ]
    )]
    public function viewResultTask($operationId, $taskId): JsonResponse
    {
        $flightTurn = ResultFlightTurn::where('operation_id', $operationId)
            ->where('task_id', $taskId)
            ->firstOrFail();

        $operation = Operation::findOrFail($operationId);
        $images    = $flightTurn->images;

        $imageUrls = [];
        foreach ($images as $index => $image) {
            $temporaryUrl = $image->thumbnail_path !== null
                ? Storage::disk('s3')->temporaryUrl($image->thumbnail_path, Carbon::now()->addMinutes(8))
                : null;

            $imageUrls[] = [
                'url'      => $temporaryUrl,
                'index'    => $index + 1,
                'reviewed' => $image->reviewed,
                'ft_id'    => $image->id,
            ];
        }

        $tasksWithImages = $operation->tasks->map(function ($task) use ($operationId) {
            $hasImages = ResultFlightTurn::where('operation_id', $operationId)
                ->where('task_id', $task->id)
                ->exists();

            return [
                'id'        => $task->id,
                'name'      => $task->type->name,
                'hasImages' => $hasImages,
            ];
        });

        return response()->json([
            'imageUrls'       => $imageUrls,
            'tasksWithImages' => $tasksWithImages,
        ]);
    }

    #[OA\Get(
        path: '/api/flight-turn/image',
        summary: 'Get the first image of a FlightTurn task with a temporary URL',
        security: [['bearerAuth' => []]],
        tags: ['FlightTurn'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Image data returned'),
            new OA\Response(response: 404, description: 'Flight turn or image not found'),
        ]
    )]
    public function viewImage(Request $request): JsonResponse
    {
        $operationId = $request->get('operationId');
        $taskId      = $request->get('taskId');

        $flightTurn = ResultFlightTurn::where('operation_id', $operationId)
            ->where('task_id', $taskId)
            ->first();

        if (!$flightTurn) {
            return response()->json(['error' => 'Flight turn not found.'], 404);
        }

        $flightTurnImage = FlightTurnImage::where('ft_id', $flightTurn->id)->first();

        if (!$flightTurnImage) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $temporaryUrl = Storage::disk('s3')->temporaryUrl(
            $flightTurnImage->image_path,
            Carbon::now()->addMinutes(8)
        );

        return response()->json([
            'ft_id'           => $flightTurn->id,
            'image_path'      => $flightTurnImage->image_path,
            'temporary_url'   => $temporaryUrl,
            'typeName'        => $flightTurn->task->type->name,
            'isReviewed'      => $flightTurnImage->reviewed,
            'flightTurnImage' => $flightTurnImage,
        ]);
    }

    #[OA\Put(
        path: '/api/flight-turn/review',
        summary: 'Update the reviewed status of a FlightTurn image',
        security: [['bearerAuth' => []]],
        tags: ['FlightTurn'],
        responses: [
            new OA\Response(response: 200, description: 'Status updated'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateReviewStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'imageId' => 'required|integer',
            'status'  => 'required|integer',
        ]);

        $flightTurnImage = FlightTurnImage::where('ft_id', $validated['imageId'])->first();

        if (!$flightTurnImage) {
            return response()->json(['message' => 'Image not found'], 404);
        }

        $flightTurnImage->reviewed = (int) $validated['status'];
        $flightTurnImage->save();

        $resultFlightTurn = ResultFlightTurn::find($flightTurnImage->ft_id);

        if ($resultFlightTurn && $resultFlightTurn->task_id) {
            $task = Task::find($resultFlightTurn->task_id);

            if ($task) {
                $task->status_id = $flightTurnImage->reviewed === 1 ? 3 : 1;
                $task->save();
            }
        }

        return response()->json([
            'message'         => 'Reviewed status updated successfully',
            'isReviewed'      => $flightTurnImage->reviewed,
            'flightTurnImage' => $flightTurnImage,
        ]);
    }
}
