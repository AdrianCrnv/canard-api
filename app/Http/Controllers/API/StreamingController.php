<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class StreamingController extends Controller
{
    // ── Start server ──────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/streaming/server/start',
        summary: 'Start the EC2 streaming instance via Lambda',
        security: [['bearerAuth' => []]],
        tags: ['Streaming'],
        responses: [
            new OA\Response(response: 200, description: 'EC2 instance started'),
            new OA\Response(response: 500, description: 'Failed to start instance'),
        ]
    )]
    public function startServer(Request $request): JsonResponse
    {
        try {
            $result = $this->callLambda('start');

            if ($result['success']) {
                return response()->json([
                    'status'      => 'success',
                    'message'     => 'EC2 instance started successfully',
                    'instance_id' => $result['instance_id'] ?? null,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to start EC2 instance',
                'error'   => $result['error'],
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error starting EC2: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Error starting EC2 instance',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Stop server ───────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/streaming/server/stop',
        summary: 'Stop the EC2 streaming instance via Lambda',
        security: [['bearerAuth' => []]],
        tags: ['Streaming'],
        responses: [
            new OA\Response(response: 200, description: 'EC2 instance stopped'),
            new OA\Response(response: 500, description: 'Failed to stop instance'),
        ]
    )]
    public function stopServer(Request $request): JsonResponse
    {
        try {
            $result = $this->callLambda('stop');

            if ($result['success']) {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'EC2 instance stopped successfully',
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to stop EC2 instance',
                'error'   => $result['error'],
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error stopping EC2: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Error stopping EC2 instance',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Server status ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/streaming/server/status',
        summary: 'Get the current status of the EC2 streaming instance',
        security: [['bearerAuth' => []]],
        tags: ['Streaming'],
        responses: [
            new OA\Response(response: 200, description: 'Server status data'),
            new OA\Response(response: 500, description: 'Failed to retrieve status'),
        ]
    )]
    public function getServerStatus(Request $request): JsonResponse
    {
        try {
            $result = $this->callLambda('status');

            if ($result['success']) {
                return response()->json([
                    'status'        => 'success',
                    'server_status' => $result['state'] ?? 'unknown',
                    'instance_id'   => $result['instance_id'] ?? null,
                    'server_ip'     => $result['server_ip'] ?? null,
                    'instance_type' => $result['instance_type'] ?? null,
                    'launch_time'   => $result['launch_time'] ?? null,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to get server status',
                'error'   => $result['error'],
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error getting server status: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Error getting server status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function callLambda(string $action): array
    {
        try {
            $lambdaUrl = config('aws.lambda_streaming_url');

            if (!$lambdaUrl) {
                throw new \Exception('AWS_LAMBDA_STREAMING_URL not configured');
            }

            $response = Http::timeout(150)->post($lambdaUrl, [
                'action' => $action,
                'token'  => env('AWS_PASSWORD'),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success'       => $data['success'] ?? false,
                    'instance_id'   => $data['instance_id'] ?? null,
                    'state'         => $data['state'] ?? null,
                    'server_ip'     => $data['server_ip'] ?? $data['public_ip'] ?? null,
                    'instance_type' => $data['instance_type'] ?? null,
                    'launch_time'   => $data['launch_time'] ?? null,
                    'error'         => $data['error'] ?? null,
                ];
            }

            return ['success' => false, 'error' => 'HTTP error: ' . $response->status()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Lambda call failed: ' . $e->getMessage()];
        }
    }
}
