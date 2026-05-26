<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\LightsProcessingJob;
use App\LightsVideo;
use App\ResultsRwyLights;
use App\Operation;
use OpenApi\Attributes as OA;

class LightsProcessingController extends Controller
{
    private string $apiBaseUrl;
    private string $apiPassword;

    public function __construct()
    {
        $this->apiBaseUrl = env('RWLights_API_URL');
        $this->apiPassword = env('RWLights_API_KEY');
    }

    #[OA\Post(
        path: '/api/lights/processing/start-frame-extraction',
        summary: 'Iniciar extracción de frames de un job de procesamiento',
        security: [['bearerAuth' => []]],
        tags: ['LightsProcessing'],
        responses: [
            new OA\Response(response: 200, description: 'Extracción iniciada correctamente'),
            new OA\Response(response: 404, description: 'Job no encontrado'),
            new OA\Response(response: 500, description: 'Error al iniciar la extracción'),
        ]
    )]
    public function startFrameExtraction(Request $request): JsonResponse
    {
        try {
            $job = LightsProcessingJob::findOrFail($request->job_id);

            $s3FolderPath = $this->buildS3FolderPath($job);

            $payload = [
                's3_url'        => $s3FolderPath,
                'fligth_speed'  => (float) $job->fly_speed,
                'objetive_mpf'  => (float) $job->objective_mpf,
                'pwd'           => $this->apiPassword,
            ];

            $response = Http::timeout(30)->post($this->apiBaseUrl . '/Lights/VideoSampling', $payload);

            if (!$response->successful()) {
                throw new \Exception('API request failed with status ' . $response->status() . ': ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['job_id']) || !isset($data['started'])) {
                throw new \Exception('Invalid API response: ' . json_encode($data));
            }

            $processUuid = $data['job_id'];

            $job->updateProcessingStatus($processUuid);

            return response()->json([
                'success'      => true,
                'message'      => 'Frame extraction started',
                'process_uuid' => $processUuid,
                'job_id'       => $job->id,
            ]);

        } catch (\Exception $e) {
            Log::error('=== START FRAME EXTRACTION ERROR ===', [
                'job_id' => $request->job_id ?? null,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            if (isset($job)) {
                $job->markAsFailed($e->getMessage());
            }

            return response()->json([
                'success' => false,
                'message' => 'Error starting frame extraction: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/lights/processing/{jobId}/progress',
        summary: 'Consultar progreso del procesamiento de frames',
        security: [['bearerAuth' => []]],
        tags: ['LightsProcessing'],
        parameters: [
            new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estado del procesamiento'),
            new OA\Response(response: 404, description: 'Job no encontrado o sin proceso asociado'),
            new OA\Response(response: 500, description: 'Error al consultar el progreso'),
        ]
    )]
    public function checkProgress(int $jobId): JsonResponse
    {
        try {
            $job = LightsProcessingJob::findOrFail($jobId);

            if (!$job->process_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing job found',
                ], 404);
            }

            $response = Http::timeout(10)->post($this->apiBaseUrl . '/Lights/status', [
                'job_id' => $job->process_uuid,
                'pwd'    => $this->apiPassword,
            ]);

            if (!$response->successful()) {
                throw new \Exception('API request failed: ' . $response->body());
            }

            $data     = $response->json();
            $status   = $data['status'] ?? 'unknown';
            $progress = $data['progress'] ?? 0;

            if ($progress >= 100 || $status === 'completed') {
                $this->finalizeProcessing($job);

                return response()->json([
                    'success'  => true,
                    'status'   => 'completed',
                    'progress' => 100,
                    'job_id'   => $jobId,
                    'message'  => 'Processing completed successfully',
                ]);
            }

            if ($status === 'failed' || $status === 'error') {
                $apiError = $data['error'] ?? $data['message'] ?? 'unknown error';
                $this->handleProcessingFailure($job, 'Frame extraction failed: ' . $apiError);

                return response()->json([
                    'success'       => false,
                    'status'        => 'failed',
                    'progress'      => $progress,
                    'error_message' => $job->fresh()->error_message,
                    'message'       => 'Processing failed',
                ], 500);
            }

            return response()->json([
                'success'  => true,
                'status'   => $status,
                'progress' => $progress,
                'job_id'   => $jobId,
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking progress', [
                'job_id' => $jobId,
                'error'  => $e->getMessage(),
            ]);

            $errorMessage = isset($job)
                ? ($job->fresh()->error_message ?? $e->getMessage())
                : $e->getMessage();

            return response()->json([
                'success'       => false,
                'status'        => 'failed',
                'error_message' => $errorMessage,
                'message'       => 'Error checking progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private function finalizeProcessing(LightsProcessingJob $job): void
    {
        $job->update(['status' => LightsProcessingJob::STATUS_SAVING_RESULTS]);

        try {
            DB::transaction(function () use ($job) {
                if ($job->result_rwy_light_id) {
                    $result = ResultsRwyLights::find($job->result_rwy_light_id);
                    Log::info('Using existing result', ['result_id' => $result->id]);
                } else {
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
                        'is_valid'          => 0,
                        'is_video'          => empty($job->srt_s3_path) ? 1 : 0,
                    ]);

                    Log::info('ResultsRwyLights created', [
                        'result_id' => $result->id,
                        'job_id'    => $job->id,
                    ]);

                    LightsVideo::create([
                        'result_rwy_light_id' => $result->id,
                        'file_type'           => 'video',
                        'filename'            => basename($job->video_s3_path),
                        'size_bytes'          => $job->video_size_bytes,
                    ]);

                    if ($job->srt_s3_path) {
                        LightsVideo::create([
                            'result_rwy_light_id' => $result->id,
                            'file_type'           => 'srt',
                            'filename'            => basename($job->srt_s3_path),
                            'size_bytes'          => $job->srt_size_bytes,
                        ]);
                    }
                }

                $this->registerImages($result, $job);

                $job->update([
                    'result_rwy_light_id' => $result->id,
                    'status'              => LightsProcessingJob::STATUS_COMPLETED,
                    'process_uuid'        => null,
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Error saving results to database', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $job->markAsFailed('Error saving results to database: ' . $e->getMessage());

            throw $e;
        }
    }

    private function registerImages(ResultsRwyLights $result, LightsProcessingJob $job): void
    {
        try {
            $operation      = Operation::find($job->operation_id);
            $typeId         = $operation ? $operation->type_id : null;
            $baseFolderPath = "Lights/{$job->operation_id}/{$job->task_id}/{$job->run}/{$job->side}";

            if (!Storage::disk('s3')->exists($baseFolderPath)) {
                Log::warning('Image folder does not exist', ['path' => $baseFolderPath]);
                return;
            }

            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            $imageFiles = array_filter(
                Storage::disk('s3')->allFiles($baseFolderPath),
                function (string $file) use ($imageExtensions): bool {
                    if (str_contains($file, '/thumbnail/') || str_contains($file, '/thumbnails/')) {
                        return false;
                    }
                    return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $imageExtensions);
                }
            );

            sort($imageFiles);

            foreach ($imageFiles as $imagePath) {
                $filename      = basename($imagePath);
                $pathParts     = explode('/', $imagePath);
                array_pop($pathParts);
                $thumbnailPath = implode('/', $pathParts) . '/thumbnail/' . $filename;

                \App\LightsImage::create([
                    'type_id'               => $typeId,
                    'results_rwy_lights_id' => $result->id,
                    'txy_id'                => null,
                    'direction'             => $job->side,
                    'image_path'            => $imagePath,
                    'thumbnail_path'        => $thumbnailPath,
                    'reviewed'              => 0,
                    'type_upload'           => 'video',
                    'flight_altitude'       => null,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error registering images', [
                'result_id' => $result->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function handleProcessingFailure(LightsProcessingJob $job, string $errorMessage): void
    {
        try {
            if ($job->video_s3_path) {
                Storage::disk('s3')->delete($job->video_s3_path);
            }

            if ($job->srt_s3_path) {
                Storage::disk('s3')->delete($job->srt_s3_path);
            }

            $job->markAsFailed($errorMessage);

        } catch (\Exception $e) {
            Log::error('Error handling processing failure', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function buildS3FolderPath(LightsProcessingJob $job): string
    {
        $folderMapping = Operation::getFolderMapping();
        $baseFolder    = $folderMapping[$job->operation->type_id] ?? 'Lights';

        return sprintf(
            's3://%s/%s/%d/%d/%d/%s/',
            env('AWS_BUCKET'),
            $baseFolder,
            $job->operation_id,
            $job->task_id,
            $job->run,
            $job->side
        );
    }
}
