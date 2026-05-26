<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Als;
use App\Models\AlsDetection;
use App\Models\MeasurementAls;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\ResultsAls;
use App\Models\Task;
use App\Services\ExifMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class AlsDetectionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/als/image-view/{operationId}/{fileId}',
        summary: 'Obtiene los datos de visualización de imagen ALS con sus detecciones',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'fileId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos de la imagen con detecciones y navegación'),
            new OA\Response(response: 403, description: 'Sin permiso operation_view'),
            new OA\Response(response: 404, description: 'Operación o fichero no encontrado'),
        ]
    )]
    public function showImageView(int $operationId, int $fileId): JsonResponse
    {
        $this->authorize('operation_view');

        $operation     = Operation::findOrFail($operationId);
        $operationFile = OperationFiles::findOrFail($fileId);

        $detections = AlsDetection::where('operation_file_id', $fileId)
            ->where('removed', 0)
            ->orderBy('detection_number')
            ->get();

        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $taskFiles = OperationFiles::where('task_id', $operationFile->task_id)
            ->get()
            ->filter(fn ($f) => in_array(strtolower(pathinfo($f->file_name, PATHINFO_EXTENSION)), $imageExts))
            ->values();

        $currentIdx = $taskFiles->search(fn ($f) => $f->id == $fileId);
        $prevFileId = $currentIdx > 0 ? $taskFiles[$currentIdx - 1]->id : null;
        $nextFileId = $currentIdx < $taskFiles->count() - 1 ? $taskFiles[$currentIdx + 1]->id : null;
        $fileIndex  = $currentIdx + 1;
        $totalFiles = $taskFiles->count();

        return response()->json([
            'operation'      => $operation,
            'operation_file' => $operationFile,
            'detections'     => $detections,
            'prev_file_id'   => $prevFileId,
            'next_file_id'   => $nextFileId,
            'file_index'     => $fileIndex,
            'total_files'    => $totalFiles,
        ], 200);
    }

    #[OA\Get(
        path: '/api/als/generate-image/{fileId}',
        summary: 'Genera imagen ALS con bounding boxes de detecciones superpuestos',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        parameters: [
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'URL de la imagen generada con sus dimensiones'),
            new OA\Response(response: 404, description: 'Fichero no encontrado en S3'),
            new OA\Response(response: 500, description: 'Error al crear la imagen'),
        ]
    )]
    public function generateImage(int $fileId): JsonResponse
    {
        $file     = OperationFiles::findOrFail($fileId);
        $filePath = "ALS/{$file->task->operation_id}/{$file->task_id}/{$file->file_name}";

        if (!Storage::disk('s3')->exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $imageContents = Storage::disk('s3')->get($filePath);
        $img           = imagecreatefromstring($imageContents);

        if (!$img) {
            return response()->json(['error' => 'Failed to create image'], 500);
        }

        $imageWidth  = imagesx($img);
        $imageHeight = imagesy($img);

        $detections = AlsDetection::where('operation_file_id', $fileId)
            ->where('removed', 0)
            ->orderBy('detection_number')
            ->get();

        $fontPath           = public_path('fonts/arial.ttf');
        $rectangleThickness = 6;
        $fontSize           = 30;
        $padding            = 10;
        $black              = imagecolorallocate($img, 0, 0, 0);

        foreach ($detections as $det) {
            $x1 = (int) $det->bbox_x;
            $y1 = (int) $det->bbox_y;
            $x2 = (int) ($det->bbox_x + $det->bbox_width);
            $y2 = (int) ($det->bbox_y + $det->bbox_height);

            $color = $det->detection_type === 'M'
                ? imagecolorallocate($img, 51, 255, 51)
                : imagecolorallocate($img, 255, 165, 0);

            if ($x1 >= 0 && $y1 >= 0 && $x2 <= $imageWidth && $y2 <= $imageHeight) {
                for ($i = 0; $i < $rectangleThickness; $i++) {
                    imagerectangle($img, $x1 - $i, $y1 - $i, $x2 + $i, $y2 + $i, $color);
                }

                $text  = (string) $det->detection_number;
                $textX = $x1;
                $textY = $y1 - 20;

                if (file_exists($fontPath)) {
                    $bbox  = imagettfbbox($fontSize, 0, $fontPath, $text);
                    $textW = $bbox[2] - $bbox[0];
                    $textH = abs($bbox[1] - $bbox[7]);

                    imagefilledrectangle(
                        $img,
                        $textX - $padding, $textY - $textH - $padding,
                        $textX + $textW + $padding, $textY + $padding,
                        $color
                    );
                    imagettftext($img, $fontSize, 0, $textX, $textY, $black, $fontPath, $text);
                }
            }
        }

        $tempDir = storage_path('app/public/img/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempPath = $tempDir . "/als_image_{$fileId}.jpg";
        imagejpeg($img, $tempPath, 90);
        imagedestroy($img);

        [$width, $height] = getimagesize($tempPath);

        return response()->json([
            'url'    => asset("storage/img/temp/als_image_{$fileId}.jpg"),
            'width'  => $width,
            'height' => $height,
        ]);
    }

    #[OA\Post(
        path: '/api/als/generate-cutout',
        summary: 'Recorta una región de la imagen ALS temporal generada',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['fileId', 'topLeftX', 'topLeftY', 'bottomRightX', 'bottomRightY'],
                properties: [
                    new OA\Property(property: 'fileId',       type: 'integer'),
                    new OA\Property(property: 'topLeftX',     type: 'number'),
                    new OA\Property(property: 'topLeftY',     type: 'number'),
                    new OA\Property(property: 'bottomRightX', type: 'number'),
                    new OA\Property(property: 'bottomRightY', type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'URL de la imagen recortada con sus dimensiones'),
            new OA\Response(response: 404, description: 'Imagen temporal no encontrada'),
            new OA\Response(response: 500, description: 'Error al recortar la imagen'),
        ]
    )]
    public function generateCutout(Request $request): JsonResponse
    {
        $request->validate([
            'fileId'       => 'required|exists:operation_files,id',
            'topLeftX'     => 'required|numeric',
            'topLeftY'     => 'required|numeric',
            'bottomRightX' => 'required|numeric',
            'bottomRightY' => 'required|numeric',
        ]);

        $fileId    = $request->fileId;
        $imagePath = public_path("storage/img/temp/als_image_{$fileId}.jpg");

        if (!file_exists($imagePath)) {
            return response()->json(['error' => 'Temp image not found, reload the page'], 404);
        }

        $tx  = max(0, (int) $request->topLeftX);
        $ty  = max(0, (int) $request->topLeftY);
        $bx  = (int) $request->bottomRightX;
        $by  = (int) $request->bottomRightY;
        $img = imagecreatefromjpeg($imagePath);
        $bx  = min(imagesx($img), $bx);
        $by  = min(imagesy($img), $by);

        $cropW   = max(1, $bx - $tx);
        $cropH   = max(1, $by - $ty);
        $cropped = imagecrop($img, ['x' => $tx, 'y' => $ty, 'width' => $cropW, 'height' => $cropH]);
        imagedestroy($img);

        if (!$cropped) {
            return response()->json(['error' => 'Crop failed'], 500);
        }

        $outPath = public_path("storage/img/temp/als_cutout_{$fileId}.jpg");
        imagejpeg($cropped, $outPath, 90);
        imagedestroy($cropped);

        return response()->json([
            'imageUrl'      => asset("storage/img/temp/als_cutout_{$fileId}.jpg"),
            'croppedWidth'  => $cropW,
            'croppedHeight' => $cropH,
        ]);
    }

    #[OA\Post(
        path: '/api/als/detections',
        summary: 'Guarda una detección manual sobre un fichero de operación ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_file_id', 'bbox_x', 'bbox_y', 'bbox_width', 'bbox_height'],
                properties: [
                    new OA\Property(property: 'operation_file_id', type: 'integer'),
                    new OA\Property(property: 'bbox_x',            type: 'number'),
                    new OA\Property(property: 'bbox_y',            type: 'number'),
                    new OA\Property(property: 'bbox_width',        type: 'number',  minimum: 1),
                    new OA\Property(property: 'bbox_height',       type: 'number',  minimum: 1),
                    new OA\Property(property: 'comment',           type: 'string',  maxLength: 1000, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detección creada con su número asignado'),
            new OA\Response(response: 403, description: 'Sin permiso operation_edit'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function saveDetection(Request $request): JsonResponse
    {
        $this->authorize('operation_edit');

        $request->validate([
            'operation_file_id' => 'required|exists:operation_files,id',
            'bbox_x'            => 'required|numeric',
            'bbox_y'            => 'required|numeric',
            'bbox_width'        => 'required|numeric|min:1',
            'bbox_height'       => 'required|numeric|min:1',
            'comment'           => 'nullable|string|max:1000',
        ]);

        $maxNum = AlsDetection::where('operation_file_id', $request->operation_file_id)
            ->max('detection_number') ?? 0;

        $det = AlsDetection::create([
            'operation_file_id' => $request->operation_file_id,
            'detection_number'  => $maxNum + 1,
            'bbox_x'            => $request->bbox_x,
            'bbox_y'            => $request->bbox_y,
            'bbox_width'        => $request->bbox_width,
            'bbox_height'       => $request->bbox_height,
            'comment'           => $request->comment ?? null,
            'detection_type'    => 'M',
        ]);

        return response()->json([
            'id'               => $det->id,
            'detection_number' => $det->detection_number,
        ]);
    }

    #[OA\Delete(
        path: '/api/als/detections',
        summary: 'Elimina o marca como removida una detección ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['detection_id'],
                properties: [
                    new OA\Property(property: 'detection_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detección eliminada o marcada como removida'),
            new OA\Response(response: 403, description: 'Sin permiso operation_edit'),
            new OA\Response(response: 404, description: 'Detección no encontrada'),
        ]
    )]
    public function deleteDetection(Request $request): JsonResponse
    {
        $this->authorize('operation_edit');

        $det = AlsDetection::findOrFail($request->detection_id);

        if ($det->detection_type === 'M') {
            $det->delete();
        } else {
            $det->update(['removed' => 1]);
        }

        return response()->json(['success' => true]);
    }

    #[OA\Post(
        path: '/api/als/toggle-reviewed',
        summary: 'Alterna el estado de revisión de un fichero de operación',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_file_id'],
                properties: [
                    new OA\Property(property: 'operation_file_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado de revisión actualizado'),
            new OA\Response(response: 403, description: 'Sin permiso operation_edit'),
            new OA\Response(response: 404, description: 'Fichero no encontrado'),
        ]
    )]
    public function toggleReviewed(Request $request): JsonResponse
    {
        $this->authorize('operation_edit');

        $file           = OperationFiles::findOrFail($request->operation_file_id);
        $file->reviewed = $file->reviewed ? 0 : 1;
        $file->save();

        return response()->json(['reviewed' => (bool) $file->reviewed]);
    }

    #[OA\Get(
        path: '/api/als/image-coordinates/{operationId}/{fileId}',
        summary: 'Extrae metadatos GPS y EXIF de una imagen ALS desde S3',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'fileId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Coordenadas, altitud, gimbal pitch, focal length y modelo de cámara'),
            new OA\Response(response: 404, description: 'Imagen no encontrada en S3'),
            new OA\Response(response: 500, description: 'Error al extraer metadatos EXIF'),
        ]
    )]
    public function getImageCoordinates(int $operationId, int $fileId): JsonResponse
    {
        $file     = OperationFiles::findOrFail($fileId);
        $filePath = "ALS/{$operationId}/{$file->task_id}/{$file->file_name}";

        if (!Storage::disk('s3')->exists($filePath)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        try {
            $exifService = new ExifMetadataService();
            $metadata    = $exifService->extractFromS3($filePath);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $altitude         = null;
        $gimbalPitch      = null;
        $relativeAltitude = null;

        try {
            $imageContents = Storage::disk('s3')->get($filePath);
            $tempPath      = storage_path('app/platform/temp/exif_alt_' . uniqid() . '.jpg');
            file_put_contents($tempPath, $imageContents);

            $img       = new \Imagick($tempPath);
            $imageInfo = $img->getImageProperties('exif:*');

            try {
                $xmpStart = strpos($imageContents, '<x:xmpmeta');
                $xmpEnd   = strpos($imageContents, '</x:xmpmeta>');
                if ($xmpStart !== false && $xmpEnd !== false) {
                    $xmp = substr($imageContents, $xmpStart, $xmpEnd - $xmpStart + 12);
                    if (preg_match('/drone-dji:GimbalPitchDegree="([^"]+)"/', $xmp, $m)) {
                        $gimbalPitch = (float) $m[1];
                    }
                    if (preg_match('/drone-dji:RelativeAltitude="([^"]+)"/', $xmp, $m)) {
                        $relativeAltitude = (float) $m[1];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('ALS XMP error: ' . $e->getMessage());
            }

            $img->destroy();
            unlink($tempPath);

            if (isset($imageInfo['exif:GPSAltitude'])) {
                $parts    = explode('/', $imageInfo['exif:GPSAltitude']);
                $altitude = count($parts) === 2
                    ? round((float) $parts[0] / (float) $parts[1], 2)
                    : (float) $parts[0];
            }
        } catch (\Exception $e) {
            Log::warning('ALS altitud EXIF error: ' . $e->getMessage());
        }

        return response()->json([
            'latitude'     => $metadata['latitude'],
            'longitude'    => $metadata['longitude'],
            'altitude'     => $relativeAltitude ?? $altitude ?? 40,
            'gimbal_pitch' => $gimbalPitch ?? 0,
            'focal_length' => $metadata['focal_length'],
            'camera_model' => $metadata['camera_model'],
        ]);
    }

    #[OA\Delete(
        path: '/api/als/delete-run/{folder}/{operationId}/{taskId}/{runId}',
        summary: 'Elimina un run de resultados ALS y su carpeta en S3',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        parameters: [
            new OA\Parameter(name: 'folder',      in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Carpeta eliminada correctamente'),
            new OA\Response(response: 404, description: 'Carpeta no existe en S3'),
        ]
    )]
    public function deleteRun(string $folder, int $operationId, int $taskId, int $runId): JsonResponse
    {
        $folderPath = "$folder/$operationId/$taskId/$runId/";

        if ($folder == 'Als') {
            $als = ResultsAls::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->first();
            $als->images()->delete();
            $als->delete();
        }

        if (Storage::disk('s3')->exists($folderPath)) {
            Storage::disk('s3')->deleteDirectory($folderPath);
            return response()->json(['message' => 'Folder deleted successfully'], 200);
        }

        return response()->json(['message' => 'Folder does not exist'], 404);
    }

    #[OA\Post(
        path: '/api/als/trigger-manual-projection',
        summary: 'Lanza una proyección manual de coordenadas desde un píxel de imagen ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_file_id', 'pixel_x', 'pixel_y'],
                properties: [
                    new OA\Property(property: 'operation_file_id', type: 'integer'),
                    new OA\Property(property: 'pixel_x',           type: 'number'),
                    new OA\Property(property: 'pixel_y',           type: 'number'),
                    new OA\Property(property: 'gimbal_pitch',      type: 'number',  nullable: true),
                    new OA\Property(property: 'drone_height',      type: 'number',  nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resultado de la proyección manual'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al llamar a la API externa o fallo del job'),
        ]
    )]
    public function triggerManualProjection(Request $request): JsonResponse
    {
        $request->validate([
            'operation_file_id' => 'required|exists:operation_files,id',
            'pixel_x'           => 'required|numeric',
            'pixel_y'           => 'required|numeric',
            'gimbal_pitch'      => 'nullable|numeric',
            'drone_height'      => 'nullable|numeric',
        ]);

        $file      = OperationFiles::findOrFail($request->operation_file_id);
        $operation = Operation::findOrFail($file->task->operation_id);

        $s3FolderPath = sprintf(
            's3://%s/ALS/%s/%s/',
            env('AWS_BUCKET'),
            $operation->id,
            $file->task_id
        );

        $payload = [
            's3_url'         => $s3FolderPath,
            'data_orig_type' => 'images',
            'pitch'          => (int) round((float) ($request->gimbal_pitch ?? 0)),
            'drone_height'   => (int) round((float) ($request->drone_height ?? 40)),
            'pixel'          => [(int) $request->pixel_x, (int) $request->pixel_y],
            'image'          => $file->file_name,
            'pwd'            => env('RWLights_API_KEY'),
        ];

        Log::info('ALS ManualProjection payload', $payload);

        try {
            $response = Http::timeout(30)
                ->post(env('RWLights_API_URL') . '/Lights/ManualProjection', $payload);

            Log::info('ALS ManualProjection response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if (!$response->successful()) {
                return response()->json(['success' => false, 'error' => 'API call failed: ' . $response->body()], 500);
            }

            $data = $response->json();

            // Polling si la API devuelve job_id en lugar de coords directas
            if (isset($data['job_id']) && !isset($data['lat'])) {
                $jobId    = $data['job_id'];
                $maxTries = 10;
                $result   = null;

                for ($i = 0; $i < $maxTries; $i++) {
                    sleep(1);
                    $statusResponse = Http::timeout(10)
                        ->post(env('RWLights_API_URL') . '/Lights/status', [
                            'job_id' => $jobId,
                            'pwd'    => env('RWLights_API_KEY'),
                        ]);

                    $statusData = $statusResponse->json();
                    $status     = $statusData['status']   ?? 'unknown';
                    $progress   = $statusData['progress'] ?? 0;

                    Log::info('ALS ManualProjection polling', [
                        'attempt'  => $i + 1,
                        'status'   => $status,
                        'progress' => $progress,
                    ]);

                    if ($progress >= 100 || $status === 'completed') {
                        $result = $statusData;
                        break;
                    }

                    if ($status === 'failed' || $status === 'error') {
                        return response()->json(['success' => false, 'error' => 'API job failed'], 500);
                    }
                }

                $data = $result ?? $statusData ?? $data;
            }

            Log::info('ALS ManualProjection completed', $data);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('ALS ManualProjection error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/als/manual-projection-status/{jobId}',
        summary: 'Consulta el estado de un job de proyección manual ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        parameters: [
            new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estado actual del job'),
            new OA\Response(response: 500, description: 'Error al consultar el estado'),
        ]
    )]
    public function checkManualProjectionStatus(string $jobId): JsonResponse
    {
        try {
            $response = Http::timeout(10)
                ->post(env('RWLights_API_URL') . '/Lights/status', [
                    'job_id' => $jobId,
                    'pwd'    => env('RWLights_API_KEY'),
                ]);

            Log::info('ALS ManualProjection status', ['job_id' => $jobId, 'body' => $response->body()]);

            if (!$response->successful()) {
                return response()->json(['success' => false, 'error' => 'API call failed'], 500);
            }

            $data = $response->json();

            return response()->json([
                'success'  => true,
                'status'   => $data['status']   ?? 'unknown',
                'progress' => $data['progress'] ?? 0,
                'data'     => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/als/confirm-other-files-upload
     * Confirms an "other" file upload done via S3 multipart.
     */
    #[OA\Post(
        path: '/api/als/confirm-other-files-upload',
        summary: 'Confirma la subida de un fichero "other" a S3 y lo registra en operation_files',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['task_id', 'file_name', 'file_size', 'file_type'],
                properties: [
                    new OA\Property(property: 'task_id',     type: 'integer'),
                    new OA\Property(property: 'file_name',   type: 'string'),
                    new OA\Property(property: 'file_size',   type: 'integer', minimum: 1),
                    new OA\Property(property: 'file_type',   type: 'string'),
                    new OA\Property(property: 'description', type: 'string',  nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fichero registrado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al guardar el fichero'),
        ]
    )]
    public function confirmOtherFilesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'     => 'required|integer|exists:tasks,id',
            'file_name'   => 'required|string',
            'file_size'   => 'required|integer|min:1',
            'file_type'   => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $file = OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => $validated['description'] ?? '',
                'file_type'   => 'als_other',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            ActivityLog::log(
                'upload',
                'Operation',
                optional(Task::find($validated['task_id'])->operation)->id,
                "Other file '{$validated['file_name']}' uploaded to ALS operation (task #{$validated['task_id']}) by '" . Auth::user()->name . "'"
            );

            return response()->json(['success' => true, 'file_id' => $file->id]);
        } catch (\Exception $e) {
            ActivityLog::log(
                'error',
                'Operation',
                null,
                "Error uploading other file '{$validated['file_name']}' (task #{$validated['task_id']}): " . $e->getMessage()
            );

            return response()->json(['error' => 'Error saving file data'], 500);
        }
    }

    /**
     * POST /api/als/confirm-images-upload
     * Confirms an image upload done via S3 multipart and creates the MeasurementAls record.
     */
    #[OA\Post(
        path: '/api/als/confirm-images-upload',
        summary: 'Confirma la subida de una imagen ALS a S3 y crea el registro MeasurementAls',
        security: [['bearerAuth' => []]],
        tags: ['ALS Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['task_id', 'file_name', 'file_size', 'file_type', 'image_type'],
                properties: [
                    new OA\Property(property: 'task_id',    type: 'integer'),
                    new OA\Property(property: 'file_name',  type: 'string'),
                    new OA\Property(property: 'file_size',  type: 'integer', minimum: 1),
                    new OA\Property(property: 'file_type',  type: 'string'),
                    new OA\Property(property: 'image_type', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Imagen registrada y MeasurementAls creado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al guardar los datos de la imagen'),
        ]
    )]
    public function confirmImagesUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'    => 'required|integer|exists:tasks,id',
            'file_name'  => 'required|string',
            'file_size'  => 'required|integer|min:1',
            'file_type'  => 'required|string',
            'image_type' => 'required|integer',
        ]);

        try {
            OperationFiles::create([
                'file_name'   => $validated['file_name'],
                'description' => null,
                'file_type'   => 'als_image',
                'type'        => $validated['file_type'],
                'size'        => $validated['file_size'],
                'task_id'     => $validated['task_id'],
            ]);

            $task      = Task::findOrFail($validated['task_id']);
            $operation = $task->operation;
            $als       = Als::where('header_id', $operation->subject_id)->first();

            $resultsAls = ResultsAls::firstOrCreate(
                ['task_id' => $validated['task_id']],
                ['als_id'  => $als->id]
            );

            $imageType = $validated['image_type'];

            MeasurementAls::where('result_id', $resultsAls->id)
                ->where('image_type', $imageType)
                ->update(['is_measurement_valid' => 0]);

            $lastNumber = MeasurementAls::where('result_id', $resultsAls->id)
                ->where('image_type', $imageType)
                ->max('measurement_number') ?? 0;

            MeasurementAls::create([
                'result_id'            => $resultsAls->id,
                'image_name'           => $validated['file_name'],
                'image_type'           => $imageType,
                'measurement_number'   => $lastNumber + 1,
                'is_measurement_valid' => 1,
            ]);

            ActivityLog::log(
                'upload',
                'Operation',
                $operation->id,
                "Image '{$validated['file_name']}' uploaded to ALS operation #{$operation->id} (task #{$task->id}) by '" . Auth::user()->name . "'"
            );

            return response()->json(['success' => true, 'message' => 'Image uploaded successfully']);
        } catch (\Exception $e) {
            ActivityLog::log(
                'error',
                'Operation',
                null,
                "Error uploading image '{$validated['file_name']}' (task #{$validated['task_id']}): " . $e->getMessage()
            );

            return response()->json(['error' => 'Error saving image data'], 500);
        }
    }
}
