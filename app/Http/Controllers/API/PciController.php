<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessYamlJob;
use App\Models\ActivityLog;
use App\Models\ResultPci;
use App\Models\ResultsPciParams;
use App\Services\GpuLambdaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class PciController extends Controller
{
    public function __construct(protected GpuLambdaService $lambda) {}

    // ── Delete run ────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/pci/{folder}/{operationId}/{taskId}/{runId}',
        summary: 'Delete a PCI run and its S3 folder',
        security: [['bearerAuth' => []]],
        tags: ['PCI'],
        parameters: [
            new OA\Parameter(name: 'folder',      in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Folder deleted'),
            new OA\Response(response: 404, description: 'Folder not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deleteRun(string $folder, int $operationId, int $taskId, int $runId): JsonResponse
    {
        $folderPath = "$folder/$operationId/$taskId/$runId/";

        try {
            if ($folder === 'PCI') {
                $pci = ResultPci::where('operation_id', $operationId)
                    ->where('task_id', $taskId)
                    ->where('run', $runId)
                    ->first();

                if ($pci) {
                    if ($pci->params_id) {
                        ResultPci::where('params_id', $pci->params_id)->update(['params_id' => null]);
                        ResultsPciParams::where('id', $pci->params_id)->delete();
                    }

                    foreach ($pci->pciImages as $pciImage) {
                        $pciImage->pciDetections()->delete();
                    }

                    $pci->pciImages()->delete();
                    $pci->delete();
                }
            }

            if (Storage::disk('s3')->exists($folderPath)) {
                Storage::disk('s3')->deleteDirectory($folderPath);
                ActivityLog::log('delete', 'Operation', $operationId, "Deleted PCI run #{$runId} from operation #{$operationId}, task #{$taskId}");
                return response()->json(['message' => 'Folder deleted successfully']);
            }

            return response()->json(['message' => 'Folder does not exist'], 404);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in deleteRun: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    // ── Update status ─────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/pci/status',
        summary: 'Update PCI processing status or process UUID',
        security: [['bearerAuth' => []]],
        tags: ['PCI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'operation_id',  type: 'integer', nullable: true),
                    new OA\Property(property: 'process_uuid',  type: 'string'),
                    new OA\Property(property: 'task_id',       type: 'integer'),
                    new OA\Property(property: 'run',           type: 'integer'),
                    new OA\Property(property: 'status',        type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'PCI entry not found'),
        ]
    )]
    public function updateStatus(Request $request): JsonResponse
    {
        if (!isset($request->operation_id)) {
            $pci = ResultPci::where('process_uuid', $request->input('process_uuid'))
                ->where('task_id', $request->input('task_id'))
                ->where('run', $request->input('run'))
                ->first();

            if (!$pci) {
                return response()->json(['message' => 'Pci entry not found'], 404);
            }

            $pci->status = $request->input('status');

            if ($request->input('status') === 'Error') {
                $pci->process_uuid = null;
                \App\Models\GpuJobQueue::where('result_type', 'pci')
                    ->where('result_id', $pci->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed']);
                $this->lambda->stopIfQueueEmpty();
            }

            if ($request->input('status') === 'Processed') {
                \App\Models\GpuJobQueue::where('result_type', 'pci')
                    ->where('result_id', $pci->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'done']);
                $this->lambda->stopIfQueueEmpty();
            }

            $pci->save();

            return response()->json(['message' => 'Status updated successfully']);
        }

        $pci = ResultPci::where('operation_id', $request->input('operation_id'))
            ->where('task_id', $request->input('task_id'))
            ->where('run', $request->input('run'))
            ->first();

        if (!$pci) {
            return response()->json(['message' => 'Pci entry not found'], 404);
        }

        $pci->process_uuid = $request->input('process_uuid');
        $pci->save();

        return response()->json(['message' => 'Process UUID updated successfully']);
    }

    // ── Save YAML ─────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/pci/save-yaml',
        summary: 'Dispatch YAML processing job for a PCI run',
        security: [['bearerAuth' => []]],
        tags: ['PCI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_id', 'task_id', 'run_id'],
                properties: [
                    new OA\Property(property: 'operation_id', type: 'integer'),
                    new OA\Property(property: 'task_id',      type: 'integer'),
                    new OA\Property(property: 'run_id',       type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Processing started'),
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

        ProcessYamlJob::dispatch($request->operation_id, $request->task_id, $request->run_id, 'PCI', auth()->id());

        return response()->json(['message' => 'Processing started']);
    }

    // ── Get processed data ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/pci/processed-db',
        summary: 'Get processed PCI data from the database',
        security: [['bearerAuth' => []]],
        tags: ['PCI'],
        parameters: [
            new OA\Parameter(name: 'operation_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'task_id',      in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',          in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Images and processed count'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getProcessedDataDB(Request $request): JsonResponse
    {
        $pci = ResultPci::where('operation_id', $request->get('operation_id'))
            ->where('task_id', $request->get('task_id'))
            ->where('run', $request->get('run'))
            ->first();

        if (!$pci) {
            return response()->json(['error' => 'Datos no encontrados'], 404);
        }

        return response()->json([
            'images'              => $pci->images,
            'num_imgs_processed'  => $pci->num_imgs_processed,
        ]);
    }

    // ── Toggle valid run ──────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/pci/run/valid',
        summary: 'Mark or unmark a PCI run as valid',
        security: [['bearerAuth' => []]],
        tags: ['PCI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['task_id', 'run', 'is_valid'],
                properties: [
                    new OA\Property(property: 'task_id',  type: 'integer'),
                    new OA\Property(property: 'run',      type: 'integer'),
                    new OA\Property(property: 'is_valid', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Run validity updated'),
            new OA\Response(response: 404, description: 'Run not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function toggleValidRun(Request $request): JsonResponse
    {
        try {
            $result = ResultPci::where('task_id', $request->task_id)
                ->where('run', $request->run)
                ->first();

            if (!$result) {
                return response()->json(['message' => 'Run not found'], 404);
            }

            if ($request->is_valid) {
                ResultPci::where('task_id', $request->task_id)
                    ->where('run', '!=', $request->run)
                    ->update(['is_valid' => false]);
            }

            $result->update(['is_valid' => (bool) $request->is_valid]);

            $action = $request->is_valid ? 'Marked as valid' : 'Marked as not valid';
            ActivityLog::log('update', 'Operation', $result->operation_id, "{$action} PCI run #{$request->run} for task #{$request->task_id} on operation #{$result->operation_id}");

            return response()->json([
                'success' => true,
                'message' => $request->is_valid ? 'Run marked as valid' : 'Run marked as not valid',
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling valid PCI run: ' . $e->getMessage());
            return response()->json(['message' => 'Error updating run'], 500);
        }
    }
}
