<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\PciDetection;
use App\Models\PciImage;
use App\Models\PciType;
use App\Models\ResultPci;
use App\Models\ResultsPciParams;
use App\Models\Task;
use App\Services\ExifMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use OpenApi\Attributes as OA;

class PciDetectionController extends Controller
{
    // ── View specific PCI image ───────────────────────────────────────────────

    #[OA\Get(
        path: '/api/pci/image/{pciimgid}',
        summary: 'Get data for a specific PCI image including detections and navigation',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        parameters: [
            new OA\Parameter(name: 'pciimgid',      in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'filter_task_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Image data with detections'),
            new OA\Response(response: 404, description: 'Image not found'),
        ]
    )]
    public function viewSpecificPCI(int $pciimgid, Request $request): JsonResponse
    {
        $pciImage  = PciImage::find($pciimgid);
        if (!$pciImage) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $pci       = $pciImage->pci;
        $operation = $pci->operation;
        $filterTaskId = $request->query('filter_task_id');

        $allTaskIds = ResultPci::where('operation_id', $operation->id)
            ->pluck('task_id')
            ->unique();

        if ($filterTaskId) {
            $validRun       = ResultPci::where('operation_id', $operation->id)
                ->where('task_id', $filterTaskId)
                ->where('is_valid', true)
                ->first();
            $filteredPciIds = $validRun ? collect([$validRun->id]) : collect([]);
        } else {
            $filteredPciIds = ResultPci::where('operation_id', $operation->id)
                ->where('is_valid', true)
                ->pluck('id');
        }

        $allImages    = PciImage::whereIn('pci_id', $filteredPciIds)->orderBy('id')->pluck('id');
        $currentIndex = $allImages->search($pciimgid);
        if ($currentIndex === false) $currentIndex = 0;

        $params = null;
        $camera = null;
        if ($pci->params_id) {
            $params = ResultsPciParams::find($pci->params_id);
            if ($params?->camera_id) {
                $camera = Camera::find($params->camera_id);
            }
        }

        $detections = PciDetection::where('image_id', $pciimgid)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->map(function ($detection, $index) {
                $detection->type_name = $detection->type_id === null
                    ? 'Unknown'
                    : (PciType::find($detection->type_id)->type ?? 'Unknown');

                return [
                    'id'                  => $detection->id,
                    'image_index'         => $index + 1,
                    'detection_index'     => $detection->detection_number,
                    'polygon_points'      => $detection->polygon_points,
                    'polygon_area_cm2'    => $detection->polygon_area_cm2,
                    'polygon_centroid_x'  => $detection->polygon_centroid_x,
                    'polygon_centroid_y'  => $detection->polygon_centroid_y,
                    'severity'            => $detection->severity,
                    'type_id'             => $detection->type_id,
                    'type_name'           => $detection->type_name,
                    'confidence'          => $detection->confidence,
                    'coordinate_latitude' => $detection->coordinate_latitude,
                    'coordinate_longitude'=> $detection->coordinate_longitude,
                    'coordinate_altitude' => $detection->coordinate_altitude,
                    'is_duplicated'       => $detection->is_duplicated,
                    'detection_type'      => $detection->detection_type,
                    'removed'             => $detection->removed,
                    'created_at'          => $detection->created_at,
                    'updated_at'          => $detection->updated_at,
                ];
            });

        return response()->json([
            'operation'    => $operation,
            'pciImage'     => $pciImage,
            'taskId'       => $pci->task_id,
            'runId'        => $pci->run,
            'pciimgid'     => $pciimgid,
            'totalImages'  => $allImages->count(),
            'imgIndex'     => $currentIndex + 1,
            'prevImageId'  => $currentIndex > 0 ? $allImages[$currentIndex - 1] : 0,
            'nextImageId'  => $currentIndex < $allImages->count() - 1 ? $allImages[$currentIndex + 1] : 0,
            'imageIds'     => $allImages->values(),
            'detections'   => $detections,
            'pcis_types'   => PciType::all(),
            'params'       => $params,
            'camera'       => $camera,
            'taskOptions'  => Task::whereIn('id', $allTaskIds)->with('type')->get(['id', 'description', 'type_id']),
            'currentTask'  => Task::find($pci->task_id),
            'filterTaskId' => $filterTaskId,
        ]);
    }

    // ── Generate image with polygons ──────────────────────────────────────────

    #[OA\Get(
        path: '/api/pci/image/{imageId}/polygons',
        summary: 'Render PCI image with detection polygons drawn on it',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        parameters: [
            new OA\Parameter(name: 'imageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'URL and dimensions of rendered image'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function generateImageWithPolygons(int $imageId): JsonResponse
    {
        $pciImage = PciImage::find($imageId);
        if (!$pciImage) {
            return response()->json(['error' => 'Image record not found.'], 404);
        }

        $altitude        = $pciImage->pci->params->altitude ?? 20;
        $scaleFactor     = sqrt(5 / max(1, $altitude));
        $fontSize        = max(12, 50 * $scaleFactor);
        $polygonThickness = max(2, 8 * $scaleFactor);

        $detections = PciDetection::query()
            ->join('pci_image', 'pci_detection.image_id', '=', 'pci_image.id')
            ->join('results_pci', 'pci_image.pci_id', '=', 'results_pci.id')
            ->where('results_pci.operation_id', $pciImage->pci->operation->id)
            ->where('pci_detection.image_id', $pciImage->id)
            ->where('pci_detection.removed', 0)
            ->orderBy('pci_detection.created_at', 'ASC')
            ->get();

        $imageContents = Storage::disk('s3')->get($pciImage->image_path);
        $img = imagecreatefromstring($imageContents);
        if (!$img) {
            return response()->json(['error' => 'Failed to create image from file.'], 500);
        }

        imageantialias($img, true);
        $imageWidth  = imagesx($img);
        $imageHeight = imagesy($img);
        $black       = imagecolorallocate($img, 0, 0, 0);
        $fontPath    = public_path('fonts/arial.ttf');
        $padding     = 10;

        foreach ($detections as $detection) {
            if (empty($detection->polygon_points)) continue;

            $polygonPoints = json_decode($detection->polygon_points, true);
            if (!$polygonPoints || count($polygonPoints) < 3) continue;

            $color = ($detection->removed === 1)
                ? imagecolorallocate($img, 255, 200, 100)
                : imagecolorallocate($img, 51, 255, 51);

            $points      = [];
            $validPolygon = true;

            foreach ($polygonPoints as $point) {
                $x = (int) $point['x'];
                $y = (int) $point['y'];
                if ($x < 0 || $x >= $imageWidth || $y < 0 || $y >= $imageHeight) {
                    $validPolygon = false;
                    break;
                }
                $points[] = $x;
                $points[] = $y;
            }

            if (!$validPolygon || count($points) < 6) continue;

            $transparentColor = ($detection->removed === 1)
                ? imagecolorallocatealpha($img, 255, 200, 100, 100)
                : imagecolorallocatealpha($img, 51, 255, 51, 100);

            imagefilledpolygon($img, $points, (int)(count($points) / 2), $transparentColor);

            for ($i = 0; $i < $polygonThickness; $i++) {
                $thickPoints = [];
                for ($j = 0; $j < count($points); $j += 2) {
                    $thickPoints[] = $points[$j] + ($i % 2 ? 1 : -1) * ($i / 2);
                    $thickPoints[] = $points[$j + 1] + ($i % 2 ? 1 : -1) * ($i / 2);
                }
                imagepolygon($img, $thickPoints, (int)(count($thickPoints) / 2), $color);
            }

            $centroidX = (int) $detection->polygon_centroid_x;
            $centroidY = (int) $detection->polygon_centroid_y;

            if ($centroidX >= 0 && $centroidX < $imageWidth && $centroidY >= 0 && $centroidY < $imageHeight) {
                $text          = (string) $detection->detection_number;
                $bbox          = imagettfbbox($fontSize, 0, $fontPath, $text);
                $textWidth     = $bbox[2] - $bbox[0];
                $textHeight    = abs($bbox[1] - $bbox[7]);
                $circleRadius  = max($textWidth, $textHeight) / 2 + $padding;

                imagefilledellipse($img, $centroidX, $centroidY, (int)($circleRadius * 2), (int)($circleRadius * 2), $color);
                imageellipse($img, $centroidX, $centroidY, (int)($circleRadius * 2), (int)($circleRadius * 2), $black);
                imagettftext($img, $fontSize, 0, (int)($centroidX - $textWidth / 2), (int)($centroidY + $textHeight / 2), $black, $fontPath, $text);
            }
        }

        $tempDir = public_path('img/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempImagePath = public_path("img/temp/temp_image_with_polygons_$imageId.jpg");
        imagejpeg($img, $tempImagePath, 90);
        imagedestroy($img);

        [$width, $height] = getimagesize($tempImagePath);

        return response()->json([
            'url'    => asset("img/temp/temp_image_with_polygons_$imageId.jpg"),
            'width'  => $width,
            'height' => $height,
        ]);
    }

    // ── Generate image cutout ─────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/pci/image/cutout',
        summary: 'Crop a region from a previously rendered PCI image',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
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
            new OA\Response(response: 200, description: 'Cutout URL and dimensions'),
            new OA\Response(response: 400, description: 'Invalid crop dimensions'),
            new OA\Response(response: 422, description: 'Validation error'),
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
        $imagePath = public_path("img/temp/temp_image_with_polygons_$imageId.jpg");

        $topLeftX     = (int) $request->topLeftX;
        $topLeftY     = (int) $request->topLeftY;
        $bottomRightX = (int) $request->bottomRightX;
        $bottomRightY = (int) $request->bottomRightY;
        $width        = $bottomRightX - $topLeftX;
        $height       = $bottomRightY - $topLeftY;

        if ($width <= 0 || $height <= 0) {
            return response()->json(['error' => 'Las dimensiones del recorte deben ser mayores que cero.'], 400);
        }

        $croppedImage = Image::make($imagePath)->crop($width, $height, $topLeftX, $topLeftY);
        $croppedPath  = public_path('img/temp/temp_image_cutout_pci.jpg');
        $croppedImage->save($croppedPath);

        return response()->json([
            'imageUrl'      => asset('img/temp/temp_image_cutout_pci.jpg'),
            'croppedWidth'  => $croppedImage->width(),
            'croppedHeight' => $croppedImage->height(),
        ]);
    }

    // ── Save manual detection ─────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/pci/detections',
        summary: 'Save a manually drawn polygon detection',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['image_id', 'polygon_points', 'polygon_area_cm2', 'polygon_centroid_x', 'polygon_centroid_y', 'type_id', 'severity', 'confidence'],
                properties: [
                    new OA\Property(property: 'image_id',             type: 'integer'),
                    new OA\Property(property: 'polygon_points',       type: 'string', description: 'JSON array of {x,y} points'),
                    new OA\Property(property: 'polygon_area_cm2',     type: 'number'),
                    new OA\Property(property: 'polygon_centroid_x',   type: 'number'),
                    new OA\Property(property: 'polygon_centroid_y',   type: 'number'),
                    new OA\Property(property: 'type_id',              type: 'integer'),
                    new OA\Property(property: 'severity',             type: 'string', enum: ['low', 'moderate', 'high', 'severe']),
                    new OA\Property(property: 'confidence',           type: 'integer', minimum: 0, maximum: 100),
                    new OA\Property(property: 'coordinate_latitude',  type: 'number', nullable: true),
                    new OA\Property(property: 'coordinate_longitude', type: 'number', nullable: true),
                    new OA\Property(property: 'coordinate_altitude',  type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detection saved'),
            new OA\Response(response: 400, description: 'Invalid polygon points'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Patch image creation failed'),
        ]
    )]
    public function saveManualNewDetection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image_id'             => 'required|integer',
            'polygon_points'       => 'required|string',
            'polygon_area_cm2'     => 'required|numeric|min:0',
            'polygon_centroid_x'   => 'required|numeric',
            'polygon_centroid_y'   => 'required|numeric',
            'type_id'              => 'required|integer',
            'severity'             => 'required|in:low,moderate,high,severe',
            'confidence'           => 'required|integer|min:0|max:100',
            'coordinate_latitude'  => 'nullable|numeric',
            'coordinate_longitude' => 'nullable|numeric',
            'coordinate_altitude'  => 'nullable|numeric',
        ]);

        $polygonPoints = json_decode($validated['polygon_points'], true);
        if (!$polygonPoints || !is_array($polygonPoints) || count($polygonPoints) < 3) {
            return response()->json(['error' => 'Invalid polygon points. At least 3 points required.'], 400);
        }

        $pciImage = PciImage::find($validated['image_id']);
        if (!$pciImage) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $lastDetectionNumber = PciDetection::whereHas('pciImage', function ($query) use ($pciImage) {
            $query->where('pci_id', $pciImage->pci_id);
        })->max('detection_number') ?? 0;

        $minX = min(array_column($polygonPoints, 'x'));
        $maxX = max(array_column($polygonPoints, 'x'));
        $minY = min(array_column($polygonPoints, 'y'));
        $maxY = max(array_column($polygonPoints, 'y'));

        $detection = new PciDetection([
            'image_id'             => $validated['image_id'],
            'detection_number'     => $lastDetectionNumber + 1,
            'polygon_points'       => $validated['polygon_points'],
            'polygon_area_cm2'     => $validated['polygon_area_cm2'],
            'polygon_centroid_x'   => $validated['polygon_centroid_x'],
            'polygon_centroid_y'   => $validated['polygon_centroid_y'],
            'type_id'              => $validated['type_id'],
            'severity'             => $validated['severity'],
            'confidence'           => $validated['confidence'],
            'coordinate_latitude'  => $validated['coordinate_latitude'],
            'coordinate_longitude' => $validated['coordinate_longitude'],
            'coordinate_altitude'  => $validated['coordinate_altitude'],
            'is_duplicated'        => 0,
            'detection_type'       => 'M',
        ]);
        $detection->save();

        $newS3Path = dirname($pciImage->image_path) . '/patches/' . $detection->id . '.jpg';

        try {
            (new \App\Services\ThumbnailService())->cropAndUpload(
                $pciImage->image_path,
                (int) $minX,
                (int) $minY,
                (int) ($maxX - $minX),
                (int) ($maxY - $minY),
                $newS3Path
            );

            return response()->json([
                'success'          => true,
                'message'          => 'Polygon detection saved successfully.',
                'detection_id'     => $detection->id,
                'detection_number' => $lastDetectionNumber + 1,
                'severity'         => $validated['severity'],
            ]);
        } catch (\Exception $e) {
            $detection->delete();
            return response()->json(['error' => 'Failed to create patch image: ' . $e->getMessage()], 500);
        }
    }

    // ── Update review status ──────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/pci/image/review',
        summary: 'Mark or unmark a PCI image as reviewed',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pciImageId', 'status'],
                properties: [
                    new OA\Property(property: 'pciImageId', type: 'integer'),
                    new OA\Property(property: 'status',     type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Review status updated'),
            new OA\Response(response: 404, description: 'Image not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateReviewStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pciImageId' => 'required|exists:pci_image,id',
            'status'     => 'required|boolean',
        ]);

        $pciImage = PciImage::find($validated['pciImageId']);
        if (!$pciImage) {
            return response()->json(['message' => 'Imagen no encontrada'], 404);
        }

        $pciImage->reviewed = (int) $validated['status'];
        $pciImage->save();

        return response()->json(['message' => 'Revisado actualizado correctamente']);
    }

    // ── Delete detection (soft/hard) ──────────────────────────────────────────

    #[OA\Delete(
        path: '/api/pci/detection',
        summary: 'Soft or hard delete a detection based on its type',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
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
            new OA\Response(response: 200, description: 'Detection removed'),
            new OA\Response(response: 404, description: 'Detection not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function deleteDetection(Request $request): JsonResponse
    {
        $validated  = $request->validate(['detection_id' => 'required|integer']);
        $detection  = PciDetection::find($validated['detection_id']);

        if (!$detection) {
            return response()->json(['error' => 'Detection not found.'], 404);
        }

        if ($detection->detection_type === 'A') {
            if ($detection->removed == 1) {
                $detection->delete();
            } else {
                $detection->removed = 1;
                $detection->save();
            }
        } elseif ($detection->detection_type === 'M') {
            $detection->delete();
        }

        return response()->json(['success' => true]);
    }

    // ── Image coordinates ─────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/pci/{operationId}/{taskId}/{runId}/image/{imageName}/coordinates',
        summary: 'Extract GPS coordinates from image EXIF metadata',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'imageName',   in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Latitude and longitude'),
            new OA\Response(response: 404, description: 'Image not found'),
        ]
    )]
    public function getImageCoordinates(int $operationId, int $taskId, int $runId, string $imageName): JsonResponse
    {
        $folderPath = "PCI/$operationId/$taskId/$runId";

        if (!Storage::disk('s3')->exists($folderPath)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $images    = Storage::disk('s3')->files($folderPath);
        $findImage = collect($images)->first(fn($image) =>
            strtolower(pathinfo($image, PATHINFO_BASENAME)) === strtolower($imageName)
        );

        if (is_null($findImage)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        try {
            $metadata = (new ExifMetadataService())->extractFromS3($findImage);
        } catch (\Exception $e) {
            Log::error('Error reading EXIF: ' . $e->getMessage());
            $metadata = ['latitude' => null, 'longitude' => null];
        }

        return response()->json([
            'latitude'  => $metadata['latitude'],
            'longitude' => $metadata['longitude'],
        ]);
    }

    // ── Disable detection ─────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/pci/detection/disable',
        summary: 'Soft-disable a detection (set removed = 1)',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['detection_id'],
                properties: [new OA\Property(property: 'detection_id', type: 'integer')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detection disabled'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function disableDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        $detection = PciDetection::findOrFail($validated['detection_id']);
        $detection->removed = 1;
        $detection->save();
        return response()->json(['success' => true]);
    }

    // ── Restore detection ─────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/pci/detection/restore',
        summary: 'Restore a soft-disabled detection (set removed = 0)',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['detection_id'],
                properties: [new OA\Property(property: 'detection_id', type: 'integer')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detection restored'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restoreDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        $detection = PciDetection::findOrFail($validated['detection_id']);
        $detection->removed = 0;
        $detection->save();
        return response()->json(['success' => true]);
    }

    // ── Hard delete detection ─────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/pci/detection/hard-delete',
        summary: 'Permanently delete a detection',
        security: [['bearerAuth' => []]],
        tags: ['PCI Detections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['detection_id'],
                properties: [new OA\Property(property: 'detection_id', type: 'integer')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Detection deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function hardDeleteDetection(Request $request): JsonResponse
    {
        $validated = $request->validate(['detection_id' => 'required|integer']);
        PciDetection::findOrFail($validated['detection_id'])->delete();
        return response()->json(['success' => true]);
    }
}
