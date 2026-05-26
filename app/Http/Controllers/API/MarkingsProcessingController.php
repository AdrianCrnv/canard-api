<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\GpuJobQueue;
use App\Jobs\ProcessYamlJob;
use App\MarkingDefect;
use App\MarkingsImage;
use App\ResultsRwyMarkings;
use App\Services\GpuLambdaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use OpenApi\Attributes as OA;

class MarkingsProcessingController extends Controller
{
    public function __construct(protected GpuLambdaService $lambda) {}

    #[OA\Post(
        path: '/api/markings/processing/status',
        summary: 'Actualizar el estado de procesamiento de un run de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsProcessing'],
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado correctamente'),
            new OA\Response(response: 404, description: 'Run no encontrado'),
        ]
    )]
    public function updateStatus(Request $request): JsonResponse
    {
        if (!isset($request->operation_id)) {
            $run = ResultsRwyMarkings::where('process_uuid', $request->input('process_uuid'))
                ->where('task_id', $request->input('task_id'))
                ->where('run', $request->input('run_id'))
                ->first();

            if (!$run) {
                return response()->json(['message' => 'Run not found'], 404);
            }

            $run->status = $request->input('status');

            if ($request->input('status') === 'Error') {
                $run->process_uuid = null;

                GpuJobQueue::where('result_type', 'rwm')
                    ->where('result_id', $run->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed']);

                $this->lambda->stopIfQueueEmpty();
            }

            if ($request->input('status') === 'Processed') {
                $yamlResult = $this->processYamlResults(
                    $run->operation_id,
                    $run->task_id,
                    $run->run
                );

                $run->read_yaml = $yamlResult['success'] ? 1 : 0;

                GpuJobQueue::where('result_type', 'rwm')
                    ->where('result_id', $run->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'done']);

                $this->lambda->stopIfQueueEmpty();
            }

            $run->save();

            return response()->json(['message' => 'Status updated successfully'], 200);
        }

        $run = ResultsRwyMarkings::where('operation_id', $request->input('operation_id'))
            ->where('task_id', $request->input('task_id'))
            ->where('run', $request->input('run_id'))
            ->first();

        if (!$run) {
            return response()->json(['message' => 'Run not found'], 404);
        }

        $run->process_uuid = $request->input('process_uuid');
        $run->status       = $request->input('status');
        $run->save();

        return response()->json(['message' => 'Process UUID and status updated successfully'], 200);
    }

    #[OA\Post(
        path: '/api/markings/processing/save-yaml',
        summary: 'Encolar el procesado de resultados YAML para un run de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsProcessing'],
        responses: [
            new OA\Response(response: 200, description: 'Job encolado o ya procesado'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function saveYamlToDatabase(Request $request): JsonResponse
    {
        $request->validate([
            'operation_id' => 'required|integer',
            'task_id'      => 'required|integer',
            'run_id'       => 'required|integer',
        ]);

        $run = ResultsRwyMarkings::where('operation_id', $request->operation_id)
            ->where('task_id', $request->task_id)
            ->where('run', $request->run_id)
            ->first();

        if (!$run || $run->read_yaml == 1) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        ProcessYamlJob::dispatch(
            $request->operation_id,
            $request->task_id,
            $request->run_id,
            'RWM',
            auth()->id()
        );

        return response()->json(['message' => 'Processing started'], 200);
    }

    #[OA\Get(
        path: '/api/markings/processing/data',
        summary: 'Obtener datos procesados de un run de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsProcessing'],
        parameters: [
            new OA\Parameter(name: 'operation_id', in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'task_id',      in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',          in: 'query', required: true,  schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos procesados del run'),
            new OA\Response(response: 404, description: 'Datos no encontrados'),
        ]
    )]
    public function getProcessedDataDB(Request $request): JsonResponse
    {
        $run = ResultsRwyMarkings::where('operation_id', $request->get('operation_id'))
            ->where('task_id', $request->get('task_id'))
            ->where('run', $request->get('run'))
            ->first();

        if (!$run) {
            return response()->json(['error' => 'Datos no encontrados'], 404);
        }

        return response()->json([
            'images'             => $run->images,
            'num_imgs_processed' => $run->num_imgs_processed,
        ]);
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private function processYamlResults(int $operationId, int $taskId, int $runId): array
    {
        try {
            $yamlPath = "Markings/{$operationId}/{$taskId}/{$runId}/results_rwm.yaml";

            if (!Storage::disk('s3')->exists($yamlPath)) {
                return ['success' => false, 'message' => 'YAML file not found'];
            }

            $data = Yaml::parse(Storage::disk('s3')->get($yamlPath));

            if (empty($data)) {
                return ['success' => false, 'message' => 'YAML file is empty'];
            }

            MarkingDefect::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->delete();

            $uniqueDefects     = [];
            $detectionsCount   = 0;
            $imagesProcessed   = 0;

            foreach ($data as $imageData) {
                $imagePath = $imageData['image_path'];

                if (preg_match('/s3:\/\/[^\/]+\/(.+)/', $imagePath, $matches)) {
                    $imagePath = $matches[1];
                }

                $markingImage = MarkingsImage::where('image_path', $imagePath)->first();

                if (!$markingImage) {
                    Log::warning("Image not found in database: {$imagePath}");
                    continue;
                }

                $metadata = $this->getImageMetadata($imagePath);
                $imageId  = $markingImage->id;
                $defects  = $imageData['defects'] ?? [];

                foreach ($defects as $defect) {
                    $defectId                  = $defect['defect_id'];
                    $uniqueDefects[$defectId]  = true;

                    $pixelX      = $defect['pixel'][0];
                    $pixelY      = $defect['pixel'][1];
                    $coordinates = $this->calculateDefectCoordinates($pixelX, $pixelY, $metadata);

                    MarkingDefect::create([
                        'operation_id'    => $operationId,
                        'task_id'         => $taskId,
                        'run'             => $runId,
                        'image_id'        => $imageId,
                        'defect_id'       => $defectId,
                        'unique_defect_id' => $defectId,
                        'pixel_x'         => $pixelX,
                        'pixel_y'         => $pixelY,
                        'type_defect'     => $defect['type_defect'] ?? null,
                        'latitude'        => $coordinates['latitude'],
                        'longitude'       => $coordinates['longitude'],
                        'altitude'        => $coordinates['altitude'],
                    ]);

                    $detectionsCount++;
                }

                $imagesProcessed++;
            }

            $uniqueCount = count($uniqueDefects);

            return [
                'success'          => true,
                'message'          => "{$imagesProcessed} images processed, {$uniqueCount} unique defects ({$detectionsCount} detections)",
                'images_count'     => $imagesProcessed,
                'defects_count'    => $uniqueCount,
                'detections_count' => $detectionsCount,
            ];

        } catch (\Exception $e) {
            Log::error('Error processing YAML: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Error processing YAML: ' . $e->getMessage()];
        }
    }

    private function getImageMetadata(string $imagePath): array
    {
        try {
            if (!Storage::disk('s3')->exists($imagePath)) {
                Log::warning("Image file not found in S3: {$imagePath}");
                return $this->getDefaultMetadata();
            }

            $mimeType = Storage::disk('s3')->mimeType($imagePath);

            $metadata = [
                'path'      => $imagePath,
                'mime_type' => $mimeType,
                'size'      => Storage::disk('s3')->size($imagePath),
            ];

            if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
                $exifData = $this->getExifData($imagePath);
                if ($exifData) {
                    $metadata = array_merge($metadata, $exifData);
                }
            }

            if (!isset($metadata['gps_latitude']) || !isset($metadata['gps_longitude'])) {
                $metadata['gps_latitude']  = 40.472275;
                $metadata['gps_longitude'] = -3.560833;
                $metadata['gps_altitude']  = 100;
            }

            $metadata['width']        = $metadata['width'] ?? 4000;
            $metadata['height']       = $metadata['height'] ?? 3000;
            $metadata['focal_length'] = $metadata['focal_length'] ?? 4.4;

            return $metadata;

        } catch (\Exception $e) {
            Log::error("Error reading metadata for {$imagePath}: " . $e->getMessage());
            return $this->getDefaultMetadata();
        }
    }

    private function getDefaultMetadata(): array
    {
        return [
            'width'         => 4000,
            'height'        => 3000,
            'focal_length'  => 4.4,
            'gps_latitude'  => 40.472275,
            'gps_longitude' => -3.560833,
            'gps_altitude'  => 100,
        ];
    }

    private function getExifData(string $imagePath): ?array
    {
        try {
            $tempPath = sys_get_temp_dir() . '/' . basename($imagePath);
            file_put_contents($tempPath, Storage::disk('s3')->get($imagePath));

            $exif = @exif_read_data($tempPath);
            unlink($tempPath);

            if (!$exif) {
                return null;
            }

            $data = [
                'make'         => $exif['Make'] ?? null,
                'model'        => $exif['Model'] ?? null,
                'datetime'     => $exif['DateTime'] ?? null,
                'width'        => $exif['COMPUTED']['Width'] ?? $exif['ExifImageWidth'] ?? 4000,
                'height'       => $exif['COMPUTED']['Height'] ?? $exif['ExifImageLength'] ?? 3000,
                'focal_length' => $exif['FocalLength'] ?? 4.4,
            ];

            if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                $data['gps_latitude']  = $this->convertGPStoDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                $data['gps_longitude'] = $this->convertGPStoDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
            }

            if (isset($exif['GPSAltitude'])) {
                $data['gps_altitude'] = $this->parseGPSValue($exif['GPSAltitude']);
            }

            if ($data['focal_length']) {
                $data['focal_length'] = $this->parseGPSValue($data['focal_length']);
            }

            return $data;

        } catch (\Exception $e) {
            Log::warning('Could not read EXIF data: ' . $e->getMessage());
            return null;
        }
    }

    private function calculateDefectCoordinates(int $pixelX, int $pixelY, array $metadata): array
    {
        $imageLat   = $metadata['gps_latitude'];
        $imageLon   = $metadata['gps_longitude'];
        $altitude   = $metadata['gps_altitude'];
        $imageWidth = $metadata['width'];
        $imageHeight = $metadata['height'];
        $focalLength = $metadata['focal_length'];

        // DJI M3T sensor (1/1.3")
        $sensorWidth  = 9.6;
        $sensorHeight = 7.2;

        $gsdWidth  = ($altitude * $sensorWidth)  / ($focalLength * $imageWidth);
        $gsdHeight = ($altitude * $sensorHeight) / ($focalLength * $imageHeight);

        $deltaX = $pixelX - ($imageWidth / 2);
        $deltaY = ($imageHeight / 2) - $pixelY;

        $offsetLat = ($deltaY * $gsdHeight) / 111320;
        $offsetLon = ($deltaX * $gsdWidth)  / (111320 * cos(deg2rad($imageLat)));

        return [
            'latitude'  => round($imageLat + $offsetLat, 8),
            'longitude' => round($imageLon + $offsetLon, 8),
            'altitude'  => $altitude,
        ];
    }

    private function convertGPStoDecimal(array $coordinate, string $hemisphere): ?float
    {
        if (count($coordinate) < 3) {
            return null;
        }

        $decimal = $this->parseGPSValue($coordinate[0])
            + $this->parseGPSValue($coordinate[1]) / 60
            + $this->parseGPSValue($coordinate[2]) / 3600;

        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    private function parseGPSValue(mixed $value): float
    {
        if (is_string($value) && str_contains($value, '/')) {
            [$num, $den] = explode('/', $value);
            return $den != 0 ? $num / $den : 0;
        }

        return (float) $value;
    }
}
