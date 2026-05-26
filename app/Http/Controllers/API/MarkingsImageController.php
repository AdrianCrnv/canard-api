<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\MarkingDefect;
use App\MarkingTypeDefect;
use App\MarkingsImage;
use App\MarkingsVideo;
use App\Operation;
use App\ResultsRwyMarkings;
use App\Runway;
use App\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Symfony\Component\Yaml\Yaml;
use OpenApi\Attributes as OA;

class MarkingsImageController extends Controller
{
    #[OA\Get(
        path: '/api/markings/images/{operationId}/{taskId}/{runId}/{imageId}',
        summary: 'Obtener datos de una imagen de markings con navegación y defectos',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        parameters: [
            new OA\Parameter(name: 'operationId',  in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',       in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',        in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'imageId',      in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'filter_task_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos de la imagen con defectos y navegación'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 404, description: 'Imagen no encontrada'),
            new OA\Response(response: 500, description: 'Error al cargar'),
        ]
    )]
    public function showMarkingsImageData(
        int $operationId,
        int $taskId,
        int $runId,
        int $imageId,
        Request $request
    ): JsonResponse {
        try {
            $this->authorize('operation_view');

            $filterTaskId = $request->query('filter_task_id');

            if ($imageId === 0) {
                $taskType = Task::findOrFail($taskId)->type_id;

                $taskIds = $filterTaskId
                    ? collect([$filterTaskId])
                    : Task::where('operation_id', $operationId)->where('type_id', $taskType)->pluck('id');

                $runIds = ResultsRwyMarkings::whereIn('task_id', $taskIds)->where('is_valid', true)->pluck('id');

                $firstImage = MarkingsImage::whereIn('rwy_id', $runIds)
                    ->get()
                    ->sortBy(fn($img) => (int) pathinfo($img->image_path, PATHINFO_FILENAME))
                    ->first();

                if (!$firstImage) {
                    return response()->json(['error' => 'No valid images found for this task'], 404);
                }

                $firstRun = ResultsRwyMarkings::findOrFail($firstImage->rwy_id);

                return response()->json([
                    'redirect' => [
                        'operation_id'   => $operationId,
                        'task_id'        => $filterTaskId,
                        'run'            => $firstRun->run,
                        'image_id'       => $firstImage->id,
                        'filter_task_id' => $filterTaskId,
                    ],
                ]);
            }

            $markingsImage = MarkingsImage::findOrFail($imageId);
            $run           = ResultsRwyMarkings::findOrFail($markingsImage->rwy_id);
            $operation     = Operation::findOrFail($run->operation_id);

            if (Auth::user()->hasRole('company')) {
                $operationCompanyId = \App\CompanyOperation::where('operation_id', $operation->id)->value('company_id');
                $companyUser        = \App\CompanyUser::where('user_id', Auth::user()->id)->value('company_id');

                if ($operationCompanyId !== $companyUser) {
                    return response()->json(['error' => 'No tienes acceso a esta operación'], 403);
                }
            }

            $taskType        = Task::findOrFail($taskId)->type_id;
            $allTypeTaskIds  = Task::where('operation_id', $operation->id)
                ->where('type_id', $taskType)
                ->orderBy('id')
                ->pluck('id');

            $taskIds = $filterTaskId ? collect([$filterTaskId]) : $allTypeTaskIds;

            $runIds = ResultsRwyMarkings::whereIn('task_id', $taskIds)
                ->where('is_valid', true)
                ->pluck('id');

            $allImages = MarkingsImage::whereIn('markings_image.rwy_id', $runIds)
                ->join('results_rwy_markings', 'markings_image.rwy_id', '=', 'results_rwy_markings.id')
                ->select('markings_image.*', 'results_rwy_markings.task_id')
                ->get()
                ->sortBy([
                    ['task_id', 'asc'],
                    fn($a, $b) => (int) pathinfo($a->image_path, PATHINFO_FILENAME) <=> (int) pathinfo($b->image_path, PATHINFO_FILENAME),
                ])
                ->pluck('id')
                ->values();

            $currentIndex = $allImages->search($imageId);
            $prevImageId  = $currentIndex > 0 ? $allImages[$currentIndex - 1] : 0;
            $nextImageId  = $currentIndex < $allImages->count() - 1 ? $allImages[$currentIndex + 1] : 0;

            $task   = Task::findOrFail($taskId);
            $runway = Runway::with('headers')->find($run->rwy_id);

            $isOblique      = $task->type_id === 81;
            $runwayHeader1  = null;
            $runwayHeader2  = null;
            $firstImageName = null;

            if ($isOblique && $runway) {
                $headers       = $runway->headers;
                $runwayHeader1 = $headers->first();
                $runwayHeader2 = $headers->count() >= 2 ? $headers->get(1) : null;

                $firstImg = MarkingsImage::where('rwy_id', $run->id)->orderBy('id')->first();
                if ($firstImg) {
                    $firstImageName = basename($firstImg->image_path);
                }
            }

            $defectTypes = MarkingTypeDefect::orderBy('name')->get();

            $defects = MarkingDefect::where('operation_id', $operation->id)
                ->where('task_id', $taskId)
                ->where('run', $runId)
                ->where('image_id', $imageId)
                ->with('typeDefect')
                ->orderBy('created_at')
                ->get()
                ->map(fn($defect, $index) => [
                    'id'               => $defect->id,
                    'defect_id'        => $defect->defect_id,
                    'unique_defect_id' => $defect->unique_defect_id,
                    'image_index'      => $index + 1,
                    'pixel_x'          => $defect->pixel_x,
                    'pixel_y'          => $defect->pixel_y,
                    'latitude'         => $defect->latitude,
                    'longitude'        => $defect->longitude,
                    'altitude'         => $defect->altitude,
                    'severity'         => $defect->severity,
                    'type_defect_name' => $defect->typeDefect?->name,
                    'removed'          => $defect->removed,
                    'created_at'       => $defect->created_at,
                    'updated_at'       => $defect->updated_at,
                ]);

            $validRunIds = ResultsRwyMarkings::whereIn('task_id', $taskIds)
                ->where('is_valid', true)
                ->pluck('run', 'task_id');

            $uniqueDefects = MarkingDefect::whereIn('task_id', $taskIds)
                ->where(function ($query) use ($validRunIds) {
                    foreach ($validRunIds as $tid => $rid) {
                        $query->orWhere(fn($q) => $q->where('task_id', $tid)->where('run', $rid));
                    }
                })
                ->whereNotNull('unique_defect_id')
                ->where('removed', 0)
                ->selectRaw('unique_defect_id, MIN(defect_id) as defect_id, COUNT(*) as count')
                ->groupBy('unique_defect_id')
                ->orderBy('unique_defect_id')
                ->get();

            $tasksWithImages = ResultsRwyMarkings::whereIn('task_id', $allTypeTaskIds)
                ->where('is_valid', true)
                ->whereHas('images')
                ->pluck('task_id')
                ->unique();

            $taskOptions = Task::whereIn('id', $tasksWithImages)
                ->with('type')
                ->get(['id', 'description', 'type_id']);

            return response()->json([
                'operation'      => $operation,
                'markings_image' => $markingsImage,
                'task'           => $task,
                'run'            => $run,
                'runway'         => $runway,
                'task_id'        => $taskId,
                'run_id'         => $runId,
                'image_id'       => $imageId,
                'total_images'   => $allImages->count(),
                'img_index'      => $currentIndex + 1,
                'prev_image_id'  => $prevImageId,
                'next_image_id'  => $nextImageId,
                'image_ids'      => $allImages->toArray(),
                'defects'        => $defects,
                'defect_types'   => $defectTypes,
                'unique_defects' => $uniqueDefects,
                'task_ids'       => $allTypeTaskIds,
                'filter_task_id' => $filterTaskId,
                'task_options'   => $taskOptions,
                'is_oblique'     => $isOblique,
                'runway_header1' => $runwayHeader1,
                'runway_header2' => $runwayHeader2,
                'first_image_name' => $firstImageName,
            ]);

        } catch (\Exception $e) {
            Log::error('Error en showMarkingsImageData: ' . $e->getMessage());

            return response()->json([
                'error' => 'Error al cargar la imagen: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/markings/images/comment',
        summary: 'Guardar comentario de una imagen de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        responses: [
            new OA\Response(response: 200, description: 'Comentario guardado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al guardar'),
        ]
    )]
    public function saveImageComment(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'image_id' => 'required|integer',
                'comment'  => 'nullable|string|max:2000',
            ]);

            $image          = MarkingsImage::findOrFail($request->image_id);
            $image->comment = $request->comment;
            $image->save();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Error saving image comment: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error'   => 'Error saving comment',
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/markings/images/{imageId}/url',
        summary: 'Obtener URL temporal S3 de una imagen de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        parameters: [
            new OA\Parameter(name: 'imageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'URL temporal generada'),
            new OA\Response(response: 404, description: 'Imagen no encontrada en S3'),
            new OA\Response(response: 500, description: 'Error al generar URL'),
        ]
    )]
    public function getImageUrl(int $imageId): JsonResponse
    {
        try {
            $image = MarkingsImage::findOrFail($imageId);

            if (!$image->image_path || !Storage::disk('s3')->exists($image->image_path)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Image not found in S3',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'url'     => Storage::disk('s3')->temporaryUrl($image->image_path, now()->addMinutes(20)),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/markings/runs/images',
        summary: 'Obtener URLs temporales de todas las imágenes de un run',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        parameters: [
            new OA\Parameter(name: 'task_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',     in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de imágenes con URLs temporales'),
            new OA\Response(response: 404, description: 'Run no encontrado'),
            new OA\Response(response: 500, description: 'Error al cargar imágenes'),
        ]
    )]
    public function getRunImages(Request $request): JsonResponse
    {
        try {
            $result = ResultsRwyMarkings::where('task_id', $request->task_id)
                ->where('run', $request->run)
                ->first();

            if (!$result) {
                return response()->json(['images' => []], 404);
            }

            $images = [];

            foreach (MarkingsImage::where('rwy_id', $result->id)->orderBy('image_path')->get() as $image) {
                if ($image->image_path && Storage::disk('s3')->exists($image->image_path)) {
                    $images[] = [
                        'id'  => $image->id,
                        'url' => Storage::disk('s3')->temporaryUrl($image->image_path, now()->addMinutes(30)),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'images'  => $images,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting run images: ' . $e->getMessage());

            return response()->json(['message' => 'Error loading images'], 500);
        }
    }

    #[OA\Patch(
        path: '/api/markings/images/review-status',
        summary: 'Actualizar el estado de revisión de una imagen de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado'),
            new OA\Response(response: 500, description: 'Error al actualizar'),
        ]
    )]
    public function updateReviewStatus(Request $request): JsonResponse
    {
        try {
            $image           = MarkingsImage::findOrFail($request->input('image_id'));
            $image->reviewed = $request->input('status');
            $image->save();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Error en updateReviewStatus: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/markings/images/download/{taskId}/{run}',
        summary: 'Descargar todas las imágenes de un run como ZIP',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run',    in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Archivo ZIP con las imágenes'),
            new OA\Response(response: 404, description: 'No hay imágenes'),
            new OA\Response(response: 500, description: 'Error al generar el ZIP'),
        ]
    )]
    public function downloadAllImages(int $taskId, int $run): mixed
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $result = ResultsRwyMarkings::where('task_id', $taskId)->where('run', $run)->firstOrFail();
            $images = MarkingsImage::where('rwy_id', $result->id)->orderBy('id')->get();

            if ($images->isEmpty()) {
                return response()->json(['error' => 'No images found'], 404);
            }

            $zipFileName = "markings_task{$taskId}_run{$run}_" . now()->format('Ymd_His') . '.zip';
            $zipPath     = tempnam(sys_get_temp_dir(), 'markings_') . '.zip';
            $zip         = new \ZipArchive();

            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return response()->json(['error' => 'Could not create ZIP file'], 500);
            }

            $tmpFiles = [];

            foreach ($images as $image) {
                try {
                    $filename = basename($image->image_path);

                    if ($zip->locateName($filename) !== false) {
                        $filename = pathinfo($filename, PATHINFO_FILENAME)
                            . '_' . $image->id . '.'
                            . pathinfo($filename, PATHINFO_EXTENSION);
                    }

                    $tmpFile = tempnam(sys_get_temp_dir(), 'img_');
                    file_put_contents($tmpFile, Storage::disk('s3')->get($image->image_path));
                    $zip->addFile($tmpFile, $filename);
                    $tmpFiles[] = $tmpFile;

                } catch (\Exception $e) {
                    Log::warning('Could not add image to ZIP', [
                        'image_id' => $image->id,
                        'path'     => $image->image_path,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $zip->close();

            foreach ($tmpFiles as $tmpFile) {
                @unlink($tmpFile);
            }

            if (ob_get_level()) {
                ob_end_clean();
            }

            return response()->download($zipPath, $zipFileName, [
                'Content-Type' => 'application/zip',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Error downloading markings images', [
                'task_id' => $taskId,
                'run'     => $run,
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error generating download'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/markings/srt',
        summary: 'Eliminar un archivo SRT de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        responses: [
            new OA\Response(response: 200, description: 'Archivo SRT eliminado'),
            new OA\Response(response: 400, description: 'El archivo no es SRT'),
            new OA\Response(response: 404, description: 'Archivo no encontrado'),
            new OA\Response(response: 500, description: 'Error al eliminar'),
        ]
    )]
    public function deleteSrt(Request $request): JsonResponse
    {
        try {
            $srtFile = MarkingsVideo::findOrFail($request->input('srt_id'));

            if ($srtFile->file_type !== 'srt') {
                return response()->json([
                    'success' => false,
                    'error'   => 'The file is not an SRT file',
                ], 400);
            }

            $video = MarkingsVideo::where('srt_id', $srtFile->id)->where('file_type', 'video')->first();

            if ($video) {
                $video->srt_id = null;
                $video->save();
            }

            if (Storage::disk('s3')->exists($srtFile->s3_path)) {
                Storage::disk('s3')->delete($srtFile->s3_path);
            }

            $srtFile->delete();

            return response()->json([
                'success' => true,
                'message' => 'SRT file deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting SRT file: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error'   => 'An error occurred while deleting the SRT file',
            ], 500);
        }
    }

    // =========================================================================
    // COORDENADAS
    // =========================================================================

    #[OA\Get(
        path: '/api/markings/images/coordinates/{operationId}/{taskId}/{runId}/{imageName}',
        summary: 'Obtener coordenadas GPS de una imagen de markings (EXIF o YAML)',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsImages'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'imageName',   in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Coordenadas GPS de la imagen'),
            new OA\Response(response: 404, description: 'Imagen o recurso no encontrado'),
            new OA\Response(response: 500, description: 'Error al leer metadatos'),
        ]
    )]
    public function getImageCoordinates(
        int    $operationId,
        int    $taskId,
        int    $runId,
        string $imageName
    ): JsonResponse {
        $folderPath    = "Markings/{$operationId}/{$taskId}/{$runId}";
        $markingsImage = MarkingsImage::where(
            'image_path', 'like', "%Markings/{$operationId}/{$taskId}/{$runId}/{$imageName}"
        )->first();

        if ($markingsImage && $markingsImage->type_upload === 'video') {
            return $this->getCoordinatesFromYaml($folderPath, $imageName);
        }

        return $this->getCoordinatesFromExif($folderPath, $imageName);
    }

    private function getCoordinatesFromYaml(string $folderPath, string $imageName): JsonResponse
    {
        $yamlPath = $folderPath . '/info_frames.yaml';

        if (!Storage::disk('s3')->exists($yamlPath)) {
            Log::warning('[getCoordinatesFromYaml] YAML not found: ' . $yamlPath);
            return response()->json(['error' => 'info_frames.yaml not found'], 404);
        }

        $data     = Yaml::parse(Storage::disk('s3')->get($yamlPath));
        $imageKey = pathinfo($imageName, PATHINFO_FILENAME);

        if (!isset($data[$imageKey])) {
            Log::warning('[getCoordinatesFromYaml] Key not found in YAML: ' . $imageKey);
            return response()->json(['error' => 'Image key not found in YAML'], 404);
        }

        $dronePosition = $data[$imageKey]['drone_position'] ?? null;

        if (!$dronePosition || count($dronePosition) < 2) {
            Log::warning('[getCoordinatesFromYaml] No drone_position for key: ' . $imageKey);
            return response()->json(['error' => 'No drone position in YAML'], 404);
        }

        return response()->json([
            'latitude'  => $dronePosition[0],
            'longitude' => $dronePosition[1],
        ]);
    }

    private function getCoordinatesFromExif(string $folderPath, string $imageName): JsonResponse
    {
        if (!Storage::disk('s3')->exists($folderPath)) {
            return response()->json(['error' => 'Folder not found'], 404);
        }

        $findImage = collect(Storage::disk('s3')->files($folderPath))
            ->first(fn($f) => strtolower(pathinfo($f, PATHINFO_BASENAME)) === strtolower($imageName));

        if (is_null($findImage)) {
            return response()->json(['error' => 'Image not found'], 404);
        }

        $tempImagePath = storage_path('app/platform/temp/image.jpg');
        file_put_contents($tempImagePath, Storage::disk('s3')->get($findImage));

        try {
            $imageInfo = (new Imagick($tempImagePath))->getImageProperties('exif:*');
        } catch (\Exception $e) {
            unlink($tempImagePath);
            return response()->json([
                'error'   => 'Error reading image metadata',
                'message' => $e->getMessage(),
            ], 500);
        }

        unlink($tempImagePath);

        return response()->json([
            'latitude'  => $this->extractGpsCoordinate($imageInfo, 'GPSLatitude', 'GPSLatitudeRef'),
            'longitude' => $this->extractGpsCoordinate($imageInfo, 'GPSLongitude', 'GPSLongitudeRef'),
        ]);
    }

    private function extractGpsCoordinate(array $imageInfo, string $coordinateKey, string $referenceKey): ?float
    {
        if (!isset($imageInfo["exif:{$coordinateKey}"], $imageInfo["exif:{$referenceKey}"])) {
            return null;
        }

        $parts = preg_split('/,\s*/', $imageInfo["exif:{$coordinateKey}"]);

        if (count($parts) !== 3) {
            return null;
        }

        try {
            $coordinate = $this->divideFraction($parts[0])
                + $this->divideFraction($parts[1]) / 60
                + $this->divideFraction($parts[2]) / 3600;

            $reference  = in_array($imageInfo["exif:{$referenceKey}"], ['N', 'E']) ? 1 : -1;

            return round($coordinate * $reference, 7);

        } catch (\Exception $e) {
            Log::error('[extractGpsCoordinate] Error: ' . $e->getMessage());
            return null;
        }
    }

    private function divideFraction(string $fraction): float
    {
        $fraction = trim($fraction);

        if (str_contains($fraction, '/')) {
            [$num, $den] = explode('/', $fraction);
            return $den != 0 ? $num / $den : 0;
        }

        return (float) $fraction;
    }
}
