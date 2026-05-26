<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGpuJob;
use App\Models\GpuJobQueue;
use App\Models\ResultFod;
use App\Models\ResultPci;
use App\Models\ResultsRwyMarkings;
use App\Services\GpuLambdaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class CanardAIController extends Controller
{
    public function __construct(protected GpuLambdaService $lambda)
    {
        $this->middleware('auth:sanctum');
    }

    // ─── Mapa de tipos → config ───────────────────────────────────────────────
    private function typeConfig(string $type): array
    {
        $map = [
            'fod' => [
                'model'   => ResultFod::class,
                's3_dir'  => 'FOD',
                'api_url' => config('aws.fod_api_url'),
                'api_key' => config('aws.fod_api_key'),
            ],
            'pci' => [
                'model'   => ResultPci::class,
                's3_dir'  => 'PCI',
                'api_url' => config('aws.pci_api_url'),
                'api_key' => config('aws.pci_api_key'),
            ],
            'rwm' => [
                'model'   => ResultsRwyMarkings::class,
                's3_dir'  => 'Markings',
                'api_url' => config('aws.rwm_api_url'),
                'api_key' => config('aws.rwm_api_key'),
            ],
        ];

        if (!isset($map[$type])) {
            throw new \InvalidArgumentException("Tipo desconocido: {$type}");
        }

        return $map[$type];
    }

    #[OA\Post(
        path: '/api/canard-ai/process-job',
        summary: 'Encola un job de procesado CANARD-AI (FOD, PCI o RWM) y arranca la instancia GPU si está parada',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['result_type', 'operation_id', 'task_id', 'run_id'],
                properties: [
                    new OA\Property(property: 'result_type',  type: 'string',  enum: ['fod', 'pci', 'rwm']),
                    new OA\Property(property: 'operation_id', type: 'integer'),
                    new OA\Property(property: 'task_id',      type: 'integer'),
                    new OA\Property(property: 'run_id',       type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Job encolado correctamente, devuelve gpu_job_id'),
            new OA\Response(response: 422, description: 'Tipo de resultado desconocido o validación fallida'),
            new OA\Response(response: 500, description: 'Error interno al encolar el job'),
        ]
    )]
    public function processJob(Request $request): JsonResponse
    {
        $request->validate([
            'result_type'  => 'required|in:fod,pci,rwm',
            'operation_id' => 'required|integer',
            'task_id'      => 'required|integer',
            'run_id'       => 'required|integer',
        ]);

        $type        = $request->input('result_type');
        $operationId = $request->input('operation_id');
        $taskId      = $request->input('task_id');
        $runId       = $request->input('run_id');

        try {
            $cfg = $this->typeConfig($type);

            $result = $cfg['model']::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->firstOrFail();

            $s3Path = 's3://' . config('aws.bucket') . '/' . $cfg['s3_dir'] . '/' . $operationId . '/' . $taskId . '/' . $runId . '/';

            $gpuJob = GpuJobQueue::create([
                'result_type' => $type,
                'result_id'   => $result->id,
                'status'      => 'pending',
            ]);

            $result->status = 'Processing';
            $result->save();

            $this->lambda->startIfStopped();

            ProcessGpuJob::dispatch($gpuJob->id, $type, $s3Path, $cfg['api_url'], $cfg['api_key']);

            return response()->json([
                'gpu_job_id' => $gpuJob->id,
                'message'    => 'Job encolado, máquina arrancando',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error("[processJob:{$type}] " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/api/canard-ai/active-job',
        summary: 'Busca el job activo (pending o processing) para un resultado concreto',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        parameters: [
            new OA\Parameter(name: 'result_type',  in: 'query', required: true,  schema: new OA\Schema(type: 'string', enum: ['fod', 'pci', 'rwm'])),
            new OA\Parameter(name: 'operation_id', in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'task_id',      in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run_id',       in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'gpu_job_id del job activo o null si no existe'),
        ]
    )]
    public function getActiveJob(Request $request): JsonResponse
    {
        $type        = $request->query('result_type');
        $operationId = $request->query('operation_id');
        $taskId      = $request->query('task_id');
        $runId       = $request->query('run_id');

        try {
            $cfg = $this->typeConfig($type);

            $result = $cfg['model']::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->first();

            if (!$result) {
                return response()->json(['gpu_job_id' => null]);
            }

            $job = GpuJobQueue::where('result_type', $type)
                ->where('result_id', $result->id)
                ->whereIn('status', ['pending', 'processing'])
                ->latest()
                ->first();

            return response()->json([
                'gpu_job_id' => $job ? $job->id : null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['gpu_job_id' => null]);
        }
    }

    #[OA\Get(
        path: '/api/canard-ai/job-status',
        summary: 'Devuelve el estado actual de un job GPU (polling del cliente)',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        parameters: [
            new OA\Parameter(name: 'gpu_job_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estado del job con process_uuid y error_message opcionales'),
            new OA\Response(response: 404, description: 'Job no encontrado'),
        ]
    )]
    public function getJobStatus(Request $request): JsonResponse
    {
        $job = GpuJobQueue::findOrFail($request->query('gpu_job_id'));

        return response()->json([
            'status'        => $job->status,
            'process_uuid'  => $job->process_uuid  ?? null,
            'error_message' => $job->error_message ?? null,
        ]);
    }

    #[OA\Get(
        path: '/api/canard-ai/queue-status',
        summary: 'Devuelve el recuento de jobs por estado en la cola GPU',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        responses: [
            new OA\Response(response: 200, description: 'Contadores de jobs pending, processing, done y failed'),
        ]
    )]
    public function getQueueStatus(): JsonResponse
    {
        return response()->json([
            'status'     => 'success',
            'pending'    => GpuJobQueue::where('status', 'pending')->count(),
            'processing' => GpuJobQueue::where('status', 'processing')->count(),
            'done'       => GpuJobQueue::where('status', 'done')->count(),
            'failed'     => GpuJobQueue::where('status', 'failed')->count(),
        ]);
    }

    #[OA\Post(
        path: '/api/canard-ai/server/start',
        summary: 'Enciende la instancia CANARD-AI (GPU Lambda)',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        responses: [
            new OA\Response(response: 200, description: 'Instancia arrancada correctamente'),
            new OA\Response(response: 500, description: 'Error al arrancar la instancia'),
        ]
    )]
    public function startServer(): JsonResponse
    {
        try {
            $result = $this->lambda->start();
            if ($result['success']) {
                return response()->json(['status' => 'success', 'message' => 'CANARD-AI started']);
            }
            return response()->json(['status' => 'error', 'error' => $result['error']], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/canard-ai/server/stop',
        summary: 'Apaga la instancia CANARD-AI (GPU Lambda)',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        responses: [
            new OA\Response(response: 200, description: 'Instancia apagada correctamente'),
            new OA\Response(response: 500, description: 'Error al apagar la instancia'),
        ]
    )]
    public function stopServer(): JsonResponse
    {
        try {
            $result = $this->lambda->stop();
            if ($result['success']) {
                return response()->json(['status' => 'success', 'message' => 'CANARD-AI stopped']);
            }
            return response()->json(['status' => 'error', 'error' => $result['error']], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/api/canard-ai/server/status',
        summary: 'Consulta el estado actual de la instancia CANARD-AI',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        responses: [
            new OA\Response(response: 200, description: 'Estado de la instancia GPU'),
            new OA\Response(response: 500, description: 'Error al consultar el estado'),
        ]
    )]
    public function getServerStatus(): JsonResponse
    {
        try {
            $result = $this->lambda->status();
            if ($result['success']) {
                return response()->json(['status' => 'success', 'server_status' => $result['state']]);
            }
            return response()->json(['status' => 'error', 'error' => $result['error']], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/canard-ai/enqueue-job',
        summary: 'Encola un job GPU (legacy, mantener por compatibilidad)',
        security: [['bearerAuth' => []]],
        tags: ['CANARD AI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['result_type', 'result_id'],
                properties: [
                    new OA\Property(property: 'result_type', type: 'string', enum: ['fod', 'pci', 'rwy_lights', 'rwy_markings']),
                    new OA\Property(property: 'result_id',   type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Job encolado correctamente, devuelve job_id'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al encolar el job'),
        ]
    )]
    public function enqueueJob(Request $request): JsonResponse
    {
        $request->validate([
            'result_type' => 'required|in:fod,pci,rwy_lights,rwy_markings',
            'result_id'   => 'required|integer',
        ]);

        try {
            $job = GpuJobQueue::create([
                'result_type' => $request->result_type,
                'result_id'   => $request->result_id,
                'status'      => 'pending',
            ]);

            $this->lambda->startIfStopped();

            return response()->json([
                'status'  => 'success',
                'message' => 'Job enqueued successfully',
                'job_id'  => $job->id,
            ]);
        } catch (\Exception $e) {
            Log::error("Error enqueuing job: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
