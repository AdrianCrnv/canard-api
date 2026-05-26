<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class LightsManualProjectionController extends Controller
{
    private string $apiBaseUrl;
    private string $apiPassword;

    public function __construct()
    {
        $this->apiBaseUrl = env('RWLights_API_URL');
        $this->apiPassword = env('RWLights_API_KEY');
    }

    #[OA\Post(
        path: '/api/lights/manual-projection/{operationId}/{taskId}/{run}/{side}',
        summary: 'Recalcular proyecciones tras añadir coordenadas manuales',
        security: [['bearerAuth' => []]],
        tags: ['LightsManualProjection'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',         in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'side',        in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Proyección iniciada correctamente'),
            new OA\Response(response: 500, description: 'Error al llamar a la API de proyección'),
        ]
    )]
    public function triggerManualProjection(
        Request $request,
        int     $operationId,
        int     $taskId,
        int     $run,
        string  $side
    ): JsonResponse {
        $pitch     = $request->input('pitch', 0);
        $pixelX    = $request->input('pixel_x');
        $pixelY    = $request->input('pixel_y');
        $imageName = $request->input('image');

        try {
            $s3FolderPath = sprintf(
                's3://%s/Lights/%s/%s/%s/%s/',
                env('AWS_BUCKET'),
                $operationId,
                $taskId,
                $run,
                $side
            );

            $payload = [
                's3_url'         => $s3FolderPath,
                'data_orig_type' => 'video',
                'pitch'          => $pitch,
                'pwd'            => $this->apiPassword,
            ];

            if (!is_null($pixelX) && !is_null($pixelY)) {
                $payload['pixel'] = [(int) $pixelX, (int) $pixelY];
            }

            if (!is_null($imageName)) {
                $payload['image'] = $imageName;
            }

            $response = Http::timeout(30)->post($this->apiBaseUrl . '/Lights/ManualProjection', $payload);

            if (!$response->successful()) {
                Log::error('ManualProjection API call failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'error'   => 'API call failed',
                    'status'  => $response->status(),
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data'    => $response->json(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error calling ManualProjection API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/lights/manual-projection/status/{jobId}',
        summary: 'Consultar estado de una proyección manual en curso',
        security: [['bearerAuth' => []]],
        tags: ['LightsManualProjection'],
        parameters: [
            new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estado de la proyección'),
            new OA\Response(response: 500, description: 'Error al consultar el estado'),
        ]
    )]
    public function checkManualProjectionStatus(string $jobId): JsonResponse
    {
        try {
            $response = Http::timeout(10)->post($this->apiBaseUrl . '/Lights/status', [
                'job_id' => $jobId,
                'pwd'    => $this->apiPassword,
            ]);

            if (!$response->successful()) {
                Log::error('Status API call failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return response()->json([
                    'success'     => false,
                    'error'       => 'API call failed',
                    'http_status' => $response->status(),
                ], 500);
            }

            $data = $response->json();

            return response()->json([
                'success'  => true,
                'status'   => $data['status'] ?? 'unknown',
                'progress' => $data['progress'] ?? 0,
                'data'     => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking ManualProjection status', [
                'job_id' => $jobId,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
