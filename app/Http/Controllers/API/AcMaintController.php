<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AcMaintDetection;
use App\Models\AcMaintImage;
use App\Models\AircraftDetectionType;
use App\Models\ActivityLog;
use App\Models\GviStation;
use App\Models\GviTaskParameter;
use App\Models\Operation;
use App\Models\ResultAcMaint;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use OpenApi\Attributes as OA;

class AcMaintController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/ac-maint/{operationId}/{taskId}/{runId}',
        summary: 'Obtiene las URLs temporales de las imágenes de un run de mantenimiento de aeronave',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del run e imágenes'),
            new OA\Response(response: 404, description: 'Operación no encontrada'),
        ]
    )]
    public function viewAircraftMaintenance(int $operationId, int $taskId, int $runId): JsonResponse
    {
        $operation = Operation::find($operationId);

        if (!$operation) {
            return response()->json(['message' => 'Operation not found'], 404);
        }

        // Construye la ruta de la carpeta inicial
        $folderPath = 'AcMaint/' . $operationId . '/' . $taskId . '/' . $runId;

        // Obtiene la lista de archivos de la carpeta
        $files     = Storage::disk('s3')->files($folderPath);
        $imageUrls = [];

        foreach ($files as $file) {
            $imageUrls[] = Storage::disk('s3')->temporaryUrl($file, Carbon::now()->addMinutes(8));
        }

        return response()->json([
            'operation'     => $operation,
            'folder_path'   => $folderPath,
            'info_operation' => [
                'operationId' => $operationId,
                'taskId'      => $taskId,
                'runId'       => $runId,
            ],
            'image_urls'    => $imageUrls,
        ], 200);
    }

    #[OA\Delete(
        path: '/api/ac-maint/{folder}/{operationId}/{taskId}/{runId}',
        summary: 'Elimina un run de AcMaint de S3 y sus registros en base de datos',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'folder',      in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Carpeta eliminada correctamente'),
            new OA\Response(response: 404, description: 'Carpeta no encontrada en S3'),
        ]
    )]
    public function deleteRun(string $folder, int $operationId, int $taskId, int $runId): JsonResponse
    {
        $folderPath = "$folder/$operationId/$taskId/$runId/";

        if ($folder == 'AcMaint') {
            $acMaint = ResultAcMaint::where('operation_id', $operationId)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->first();

            if ($acMaint) {
                // Eliminar las detecciones asociadas a cada imagen
                foreach ($acMaint->images as $acMaintImage) {
                    $acMaintImage->detections()->delete();
                }

                // Eliminar las imágenes asociadas
                $acMaint->images()->delete();
                $acMaint->delete();
            }
        }

        if (Storage::disk('s3')->exists($folderPath)) {
            Storage::disk('s3')->deleteDirectory($folderPath);
            ActivityLog::log(
                'delete',
                'Operation',
                $operationId,
                "AcMaint run #{$runId} deleted from operation #{$operationId} (task #{$taskId}) by '" . Auth::user()->name . "'"
            );
            return response()->json(['message' => 'Folder deleted successfully'], 200);
        }

        return response()->json(['message' => 'Folder does not exist'], 404);
    }

    #[OA\Get(
        path: '/api/ac-maint/{operationId}/tasks/{taskId}/results',
        summary: 'Obtiene los resultados, imágenes y detecciones del último run válido de una tarea',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resultados de la tarea'),
            new OA\Response(response: 404, description: 'Resultado no encontrado'),
        ]
    )]
    public function viewResultTask(int $operationId, int $taskId): JsonResponse
    {
        $acMaint = ResultAcMaint::where('operation_id', $operationId)
            ->where('task_id', $taskId)
            ->where('is_valid', 1)
            ->orderBy('id', 'desc')
            ->firstOrFail();

        $operation = Operation::findOrFail($operationId);

        // Obtener el Task relacionado con la operación
        $task           = Task::find($taskId);
        $taskTypeId     = $task->type->id;
        $parameters     = GviTaskParameter::where('task_type_id', $taskTypeId)->get();
        $taskParameters = $parameters->first();

        $images          = $acMaint->images;
        $imageUrls       = [];
        $detectionsTable = [];

        foreach ($images as $index => $image) {
            $file         = $image->thumbnail_path;
            $temporaryUrl = $file !== null
                ? Storage::disk('s3')->temporaryUrl($file, Carbon::now()->addMinutes(8))
                : null;

            $acMaintImage = AcMaintImage::find($image->id);

            // Obtener las detecciones con detection_number usando una consulta RAW
            $detections = DB::select("
                WITH detections_with_numbers AS (
                    SELECT
                        d.id,
                        d.image_id,
                        d.bbox_x,
                        d.bbox_y,
                        d.bbox_width,
                        d.bbox_height,
                        d.bbox_dim_cm_width,
                        d.bbox_dim_cm_height,
                        d.type_id,
                        d.confidence,
                        d.coordinate_latitude,
                        d.coordinate_longitude,
                        d.coordinate_altitude,
                        d.is_duplicated,
                        d.detection_type,
                        d.station1,
                        d.station2,
                        d.created_at,
                        d.updated_at,
                        ROW_NUMBER() OVER (PARTITION BY r.operation_id ORDER BY d.created_at ASC) AS detection_number
                    FROM
                        aircraft_maintenance_detection d
                    JOIN
                        aircraft_maintenance_image i ON d.image_id = i.id
                    JOIN
                        results_aircraft_maintenance r ON i.ac_maint_id = r.id
                    WHERE
                        r.operation_id = ?
                )
                SELECT *
                FROM detections_with_numbers
                WHERE image_id = ?
                ORDER BY detection_number
            ", [$operation->id, $image->id]);

            // Añadir el nombre del tipo a cada detección
            foreach ($detections as $detection) {
                if ($detection->type_id == 0) {
                    $detection->type_name = 'Unknown';
                } else {
                    $acType               = AircraftDetectionType::find($detection->type_id);
                    $detection->type_name = $acType ? $acType->type : 'Unknown';
                }

                $detectionsTable[] = (object) [
                    'id'                  => $detection->id,
                    'image_index'         => $index + 1,
                    'detection_index'     => $detection->detection_number,
                    'bbox_x'              => $detection->bbox_x,
                    'bbox_y'              => $detection->bbox_y,
                    'bbox_width'          => $detection->bbox_width,
                    'bbox_height'         => $detection->bbox_height,
                    'bbox_dim_cm_width'   => $detection->bbox_dim_cm_width,
                    'bbox_dim_cm_height'  => $detection->bbox_dim_cm_height,
                    'type_id'             => $detection->type_id,
                    'type_name'           => $detection->type_name,
                    'confidence'          => $detection->confidence,
                    'coordinate_latitude' => $detection->coordinate_latitude,
                    'coordinate_longitude'=> $detection->coordinate_longitude,
                    'coordinate_altitude' => $detection->coordinate_altitude,
                    'is_duplicated'       => $detection->is_duplicated,
                    'detection_type'      => $detection->detection_type,
                    'station1'            => $detection->station1,
                    'station2'            => $detection->station2,
                    'created_at'          => $detection->created_at,
                    'updated_at'          => $detection->updated_at,
                ];
            }

            // Tasks con indicador de si tienen imágenes
            $tasksWithImages = $operation->tasks->map(function ($task) use ($operationId) {
                $hasImages = ResultAcMaint::where('operation_id', $operationId)
                    ->where('task_id', $task->id)
                    ->exists();
                return (object) [
                    'id'        => $task->id,
                    'name'      => $task->type->name,
                    'hasImages' => $hasImages,
                ];
            });

            $imageUrls[] = [
                'url'              => $temporaryUrl,
                'index'            => $index + 1,
                'isDetected'       => $acMaintImage->detections()->exists(),
                'reviewed'         => $image->reviewed,
                'ac_maint_image_id'=> $image->id,
            ];
        }

        return response()->json([
            'images'          => $imageUrls,
            'detections'      => $detectionsTable,
            'operation'       => $operation,
            'selected_task_id'=> $taskId,
            'tasks'           => $tasksWithImages,
            'task_parameters' => $taskParameters,
        ], 200);
    }

    #[OA\Get(
        path: '/api/ac-maint/images/{acmaintImgId}',
        summary: 'Obtiene el detalle de una imagen concreta con sus detecciones y navegación anterior/siguiente',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'acmaintImgId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la imagen'),
            new OA\Response(response: 404, description: 'Imagen, registro de mantenimiento u operación no encontrados'),
        ]
    )]
    public function viewSpecificImage(int $acmaintImgId): JsonResponse
    {
        $acMaintImage = AcMaintImage::find($acmaintImgId);

        if (!$acMaintImage) {
            return response()->json(['message' => 'Image not found'], 404);
        }

        $acmaint = ResultAcMaint::find($acMaintImage->ac_maint_id);

        if (!$acmaint) {
            return response()->json(['message' => 'Maintenance record not found'], 404);
        }

        $operation = Operation::find($acmaint->operation_id);

        if (!$operation) {
            return response()->json(['message' => 'Operation not found'], 404);
        }

        $acMaintIds = ResultAcMaint::where('operation_id', $operation->id)
            ->where('task_id', $acmaint->task_id)
            ->pluck('id');

        // Obtener todas las imágenes y ordenarlas por id
        $allImages   = AcMaintImage::whereIn('ac_maint_id', $acMaintIds)->orderBy('id')->get();
        $totalImages = $allImages->count();

        // Encontrar el índice de la imagen actual
        $currentIndex = $allImages->pluck('id')->search($acmaintImgId);
        $imgIndex     = $currentIndex + 1;

        // Determinar IDs de imagen anterior y siguiente
        $prevImageId = $currentIndex > 0 ? $allImages[$currentIndex - 1]->id : 0;
        $nextImageId = $currentIndex < $totalImages - 1 ? $allImages[$currentIndex + 1]->id : 0;

        $imageIds = $allImages->pluck('id')->toArray();

        // Obtener las detecciones con detection_number usando una consulta RAW
        $detections = DB::select("
            WITH detections_with_numbers AS (
                SELECT
                    d.id,
                    d.image_id,
                    d.bbox_x,
                    d.bbox_y,
                    d.bbox_width,
                    d.bbox_height,
                    d.bbox_dim_cm_width,
                    d.bbox_dim_cm_height,
                    d.type_id,
                    d.confidence,
                    d.coordinate_latitude,
                    d.coordinate_longitude,
                    d.coordinate_altitude,
                    d.is_duplicated,
                    d.detection_type,
                    d.station1,
                    d.station2,
                    d.created_at,
                    d.updated_at,
                    ROW_NUMBER() OVER (PARTITION BY r.operation_id ORDER BY d.created_at ASC) AS detection_number
                FROM
                    aircraft_maintenance_detection d
                JOIN
                    aircraft_maintenance_image i ON d.image_id = i.id
                JOIN
                    results_aircraft_maintenance r ON i.ac_maint_id = r.id
                WHERE
                    r.operation_id = ?
            )
            SELECT *
            FROM detections_with_numbers
            WHERE image_id = ?
            ORDER BY detection_number
        ", [$operation->id, $acmaintImgId]);

        // Añadir el nombre del tipo a cada detección
        foreach ($detections as $detection) {
            if ($detection->type_id == 0) {
                $detection->type_name = 'Unknown';
            } else {
                $acType               = AircraftDetectionType::find($detection->type_id);
                $detection->type_name = $acType ? $acType->type : 'Unknown';
            }
        }

        $aircraftDetectionsTypes = AircraftDetectionType::all();

        $task           = Task::find($acmaint->task_id);
        $taskTypeId     = $task->type->id;
        $parameters     = GviTaskParameter::where('task_type_id', $taskTypeId)->get();
        $taskParameters = $parameters->first();

        $stations = GviStation::where('section_id', $taskParameters->section_type_id)->get();

        return response()->json([
            'operation'               => $operation,
            'ac_maint_image'          => $acMaintImage,
            'task_id'                 => $acmaint->task_id,
            'run_id'                  => $acmaint->run,
            'ac_maint_img_id'         => $acmaintImgId,
            'total_images'            => $totalImages,
            'img_index'               => $imgIndex,
            'prev_image_id'           => $prevImageId,
            'next_image_id'           => $nextImageId,
            'detections'              => $detections,
            'image_ids'               => $imageIds,
            'aircraft_detection_types'=> $aircraftDetectionsTypes,
            'all_images'              => $allImages,
            'task_parameters'         => $taskParameters,
            'stations'                => $stations,
        ], 200);
    }

    #[OA\Get(
        path: '/api/ac-maint/images/{imageId}/bboxes',
        summary: 'Genera una imagen JPEG con los bounding boxes de las detecciones dibujados y devuelve su URL',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'imageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'URL de la imagen generada con dimensiones'),
            new OA\Response(response: 404, description: 'Imagen no encontrada'),
            new OA\Response(response: 500, description: 'Error al generar la imagen'),
        ]
    )]
    public function generateImageWithBboxes(int $imageId): JsonResponse
    {
        $acMaintImage = AcMaintImage::find($imageId);

        if (!$acMaintImage) {
            return response()->json(['error' => 'Image record not found.'], 404);
        }

        $acmaint = ResultAcMaint::find($acMaintImage->ac_maint_id);

        $detections = DB::select("
            WITH detections_with_numbers AS (
                SELECT
                    d.id,
                    d.image_id,
                    d.bbox_x,
                    d.bbox_y,
                    d.bbox_width,
                    d.bbox_height,
                    d.coordinate_latitude,
                    d.coordinate_longitude,
                    d.coordinate_altitude,
                    d.detection_type,
                    ROW_NUMBER() OVER (PARTITION BY r.operation_id ORDER BY d.created_at ASC) AS detection_number
                FROM
                    aircraft_maintenance_detection d
                JOIN
                    aircraft_maintenance_image i ON d.image_id = i.id
                JOIN
                    results_aircraft_maintenance r ON i.ac_maint_id = r.id
                WHERE
                    r.operation_id = ?
            )
            SELECT *
            FROM detections_with_numbers
            WHERE image_id = ?
            ORDER BY detection_number
        ", [$acmaint->operation_id, $imageId]);

        // Cargar la imagen original desde S3
        $s3             = Storage::disk('s3');
        $imagePath      = $acMaintImage->image_path;
        $imageContents  = $s3->get($imagePath);

        $img = imagecreatefromstring($imageContents);
        if (!$img) {
            return response()->json(['error' => 'Failed to create image from file.'], 500);
        }

        $imageWidth  = imagesx($img);
        $imageHeight = imagesy($img);

        $color    = imagecolorallocate($img, 51, 255, 51);
        $black    = imagecolorallocate($img, 0, 0, 0);
        $fontPath = public_path('fonts/arial.ttf');
        $fontSize = 80;
        $padding  = 15;

        foreach ($detections as $detection) {
            $x      = $detection->bbox_x;
            $y      = $detection->bbox_y;
            $width  = $detection->bbox_width;
            $height = $detection->bbox_height;

            if ($x + $width <= $imageWidth && $y + $height <= $imageHeight) {
                // Dibujar un recuadro más grueso
                for ($i = 0; $i < 10; $i++) {
                    imagerectangle($img, $x - $i, $y - $i, $x + $width + $i, $y + $height + $i, $color);
                }

                $text  = (string) $detection->detection_number;
                $textX = $x;
                $textY = $y - 20;

                $textBoundingBox = imagettfbbox($fontSize, 0, $fontPath, $text);
                $textWidth       = $textBoundingBox[2] - $textBoundingBox[0];
                $textHeight      = abs($textBoundingBox[1] - $textBoundingBox[7]);

                $bgX1 = $textX - $padding + 6;
                $bgY1 = $textY - $textHeight - $padding;
                $bgX2 = $textX + $textWidth + $padding;
                $bgY2 = $textY + $padding;
                imagefilledrectangle($img, $bgX1, $bgY1, $bgX2, $bgY2, $color);

                imagettftext($img, $fontSize, 0, $textX, $textY, $black, $fontPath, $text);
            }
        }

        $tempImagePath = public_path("img/temp/temp_image_with_bboxes_$imageId.jpg");
        imagejpeg($img, $tempImagePath);

        list($width, $height) = getimagesize($tempImagePath);

        return response()->json([
            'url'    => asset("img/temp/temp_image_with_bboxes_$imageId.jpg"),
            'width'  => $width,
            'height' => $height,
        ]);
    }

    #[OA\Put(
        path: '/api/ac-maint/images/{imageId}/review',
        summary: 'Actualiza el estado de revisión de una imagen de AcMaint',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'imageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['imageId', 'status'],
                properties: [
                    new OA\Property(property: 'imageId', type: 'integer'),
                    new OA\Property(property: 'status',  type: 'integer', enum: [0, 1]),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado de revisión actualizado'),
            new OA\Response(response: 404, description: 'Imagen no encontrada'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function updateReviewStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'imageId' => 'required|integer',
            'status'  => 'required|integer',
        ]);

        $acMaintImage = AcMaintImage::find($validated['imageId']);

        if ($acMaintImage) {
            $acMaintImage->reviewed = (int) $validated['status'];
            $acMaintImage->save();

            return response()->json(['message' => 'Revisado actualizado correctamente']);
        }

        return response()->json(['message' => 'Imagen no encontrada'], 404);
    }

    #[OA\Post(
        path: '/api/ac-maint/images/cutout',
        summary: 'Recorta una imagen temporal con bounding boxes según las coordenadas indicadas',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['imageId', 'topLeftX', 'topLeftY', 'bottomRightX', 'bottomRightY'],
                properties: [
                    new OA\Property(property: 'imageId',      type: 'integer'),
                    new OA\Property(property: 'topLeftX',     type: 'number'),
                    new OA\Property(property: 'topLeftY',     type: 'number'),
                    new OA\Property(property: 'bottomRightX', type: 'number'),
                    new OA\Property(property: 'bottomRightY', type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'URL de la imagen recortada con dimensiones'),
            new OA\Response(response: 400, description: 'Dimensiones de recorte inválidas'),
            new OA\Response(response: 422, description: 'Error de validación'),
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

        $imageId   = $request->input('imageId');
        $imagePath = public_path("img/temp/temp_image_with_bboxes_$imageId.jpg");

        $image       = Image::make($imagePath);
        $topLeftX    = (int) $request->topLeftX;
        $topLeftY    = (int) $request->topLeftY;
        $bottomRightX = (int) $request->bottomRightX;
        $bottomRightY = (int) $request->bottomRightY;
        $width       = $bottomRightX - $topLeftX;
        $height      = $bottomRightY - $topLeftY;

        if ($width <= 0 || $height <= 0) {
            return response()->json(['error' => 'Las dimensiones del recorte deben ser mayores que cero.'], 400);
        }

        $croppedImage     = $image->crop($width, $height, $topLeftX, $topLeftY);
        $croppedImagePath = public_path('img/temp/temp_image_cutout.jpg');
        $croppedImage->save($croppedImagePath);

        return response()->json([
            'imageUrl'      => asset('img/temp/temp_image_cutout.jpg'),
            'croppedWidth'  => $croppedImage->width(),
            'croppedHeight' => $croppedImage->height(),
        ]);
    }

    #[OA\Post(
        path: '/api/ac-maint/detections',
        summary: 'Guarda una nueva detección manual en una imagen de AcMaint',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['image_id', 'bbox_x', 'bbox_y', 'bbox_width', 'bbox_height', 'type_id', 'confidence'],
                properties: [
                    new OA\Property(property: 'image_id',             type: 'integer'),
                    new OA\Property(property: 'bbox_x',               type: 'number'),
                    new OA\Property(property: 'bbox_y',               type: 'number'),
                    new OA\Property(property: 'bbox_width',           type: 'number'),
                    new OA\Property(property: 'bbox_height',          type: 'number'),
                    new OA\Property(property: 'bbox_dim_cm_width',    type: 'number',  nullable: true),
                    new OA\Property(property: 'bbox_dim_cm_height',   type: 'number',  nullable: true),
                    new OA\Property(property: 'type_id',              type: 'integer'),
                    new OA\Property(property: 'confidence',           type: 'integer'),
                    new OA\Property(property: 'coordinate_latitude',  type: 'number',  nullable: true),
                    new OA\Property(property: 'coordinate_longitude', type: 'number',  nullable: true),
                    new OA\Property(property: 'coordinate_altitude',  type: 'number',  nullable: true),
                    new OA\Property(property: 'station1',             type: 'string',  nullable: true),
                    new OA\Property(property: 'station2',             type: 'string',  nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detección guardada correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
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
            'station1'             => 'nullable|string',
            'station2'             => 'nullable|string',
        ]);

        $detection                       = new AcMaintDetection();
        $detection->image_id             = $validated['image_id'];
        $detection->bbox_x               = $validated['bbox_x'];
        $detection->bbox_y               = $validated['bbox_y'];
        $detection->bbox_width           = $validated['bbox_width'];
        $detection->bbox_height          = $validated['bbox_height'];
        $detection->bbox_dim_cm_width    = $validated['bbox_dim_cm_width'];
        $detection->bbox_dim_cm_height   = $validated['bbox_dim_cm_height'];
        $detection->type_id              = $validated['type_id'];
        $detection->confidence           = $validated['confidence'];
        $detection->coordinate_latitude  = $validated['coordinate_latitude'];
        $detection->coordinate_longitude = $validated['coordinate_longitude'];
        $detection->coordinate_altitude  = $validated['coordinate_altitude'];
        $detection->is_duplicated        = 0;
        $detection->detection_type       = 'M';
        $detection->station1             = $validated['station1'];
        $detection->station2             = $validated['station2'];
        $detection->save();

        return response()->json(['success' => true, 'message' => 'Save new detection']);
    }

    #[OA\Delete(
        path: '/api/ac-maint/detections/{detectionId}',
        summary: 'Elimina una detección de AcMaint por su ID',
        security: [['bearerAuth' => []]],
        tags: ['AcMaint'],
        parameters: [
            new OA\Parameter(name: 'detectionId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detección eliminada correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al eliminar la detección'),
        ]
    )]
    public function deleteDetection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'detection_id' => 'required|integer',
        ]);

        $deleted = AcMaintDetection::where('id', $validated['detection_id'])->delete();

        if ($deleted) {
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Failed to delete detection.'], 500);
    }
}
