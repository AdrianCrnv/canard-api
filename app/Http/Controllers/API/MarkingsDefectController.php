<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\MarkingDefect;
use App\MarkingsImage;
use App\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class MarkingsDefectController extends Controller
{
    #[OA\Get(
        path: '/api/markings/defects/{operationId}/{taskId}/{runId}',
        summary: 'Listar defectos de markings de un run',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsDefects'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'taskId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runId',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de defectos'),
        ]
    )]
    public function getDefects(int $operationId, int $taskId, int $runId): JsonResponse
    {
        $defects = MarkingDefect::where('operation_id', $operationId)
            ->where('task_id', $taskId)
            ->where('run', $runId)
            ->orderBy('defect_id')
            ->get();

        return response()->json([
            'success' => true,
            'defects' => $defects,
        ]);
    }

    #[OA\Patch(
        path: '/api/markings/defects/toggle',
        summary: 'Activar o desactivar todos los registros de un defecto en un run',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsDefects'],
        responses: [
            new OA\Response(response: 200, description: 'Defecto actualizado correctamente'),
            new OA\Response(response: 500, description: 'Error al actualizar'),
        ]
    )]
    public function toggleDefect(Request $request): JsonResponse
    {
        try {
            MarkingDefect::where('operation_id', $request->operation_id)
                ->where('task_id', $request->task_id)
                ->where('run', $request->run)
                ->where('defect_id', $request->defect_id)
                ->update(['removed' => $request->removed]);

            $message = $request->removed ? 'Defect removed successfully' : 'Defect restored successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error updating defect: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Patch(
        path: '/api/markings/defects/{defectId}/toggle',
        summary: 'Activar o desactivar un defecto individual por ID',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsDefects'],
        parameters: [
            new OA\Parameter(name: 'defectId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Defecto actualizado correctamente'),
            new OA\Response(response: 404, description: 'Defecto no encontrado'),
            new OA\Response(response: 500, description: 'Error al actualizar'),
        ]
    )]
    public function toggleDefectById(Request $request): JsonResponse
    {
        try {
            $defect          = MarkingDefect::findOrFail($request->defect_id);
            $defect->removed = $request->removed;
            $defect->save();

            $message = $request->removed ? 'Defect removed successfully' : 'Defect restored successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error updating defect: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/markings/defects',
        summary: 'Crear un defecto manual en una imagen de markings',
        security: [['bearerAuth' => []]],
        tags: ['MarkingsDefects'],
        responses: [
            new OA\Response(response: 200, description: 'Defecto creado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al crear'),
        ]
    )]
    public function storeDefect(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'operation_id' => 'required|integer',
                'task_id'      => 'required|integer',
                'run_id'       => 'required|integer',
                'image_id'     => 'required|integer',
                'pixel_x'      => 'required|integer',
                'pixel_y'      => 'required|integer',
                'severity'     => 'required|integer',
                'type_id'      => 'required|integer',
            ]);

            $image       = MarkingsImage::findOrFail($request->image_id);
            $apiBaseUrl  = env('RWLights_API_URL');
            $apiPassword = env('RWLights_API_KEY');
            $imagePath   = $image->image_path;
            $imageName   = basename($imagePath);
            $s3Folder    = 's3://' . env('AWS_BUCKET') . '/' . trim(dirname($imagePath), '/') . '/';

            $response = Http::timeout(30)->post($apiBaseUrl . '/Lights/ManualProjection', [
                's3_url'         => $s3Folder,
                'data_orig_type' => $image->type_upload ?? 'images',
                'pitch'          => -20,
                'drone_height'   => $image->flight_altitude ?? 30,
                'pixel'          => [$request->pixel_x, $request->pixel_y],
                'image'          => $imageName,
                'pwd'            => $apiPassword,
            ]);

            if (!$response->successful()) {
                throw new \Exception('VideoSampling API failed: ' . $response->body());
            }

            $apiData     = $response->json();
            $processUuid = $apiData['job_id'] ?? null;
            $latitude    = null;
            $longitude   = null;

            if ($processUuid) {
                for ($i = 0; $i < 10; $i++) {
                    sleep(1);

                    $statusResponse = Http::timeout(15)->post($apiBaseUrl . '/Lights/status', [
                        'job_id' => $processUuid,
                        'pwd'    => $apiPassword,
                    ]);

                    if (!$statusResponse->successful()) {
                        Log::warning('Status check failed', ['attempt' => $i + 1]);
                        continue;
                    }

                    $statusData = $statusResponse->json();
                    $status     = $statusData['status'] ?? 'unknown';
                    $progress   = $statusData['progress'] ?? 0;

                    if ($progress >= 100 || $status === 'completed') {
                        $latitude  = $statusData['result']['lat'] ?? null;
                        $longitude = $statusData['result']['lon'] ?? null;
                        break;
                    }

                    if ($status === 'failed' || $status === 'error') {
                        Log::error('Defect processing failed', ['response' => $statusData]);
                        break;
                    }
                }
            }

            $maxDefectId = MarkingDefect::where('operation_id', $request->operation_id)
                ->where('task_id', $request->task_id)
                ->where('run', $request->run_id)
                ->max('defect_id');

            $newDefectId = ($maxDefectId ?? 0) + 1;

            $sameTypeTaskIds = Task::where('operation_id', $request->operation_id)
                ->where('type_id', Task::find($request->task_id)->type_id)
                ->pluck('id');

            $maxUniqueDefectId = MarkingDefect::whereIn('task_id', $sameTypeTaskIds)
                ->where('operation_id', $request->operation_id)
                ->max('unique_defect_id');

            $uniqueDefectId = $request->unique_defect_id
                ? (int) $request->unique_defect_id
                : ($maxUniqueDefectId ?? 0) + 1;

            $patchPath = null;

            if (!$request->unique_defect_id) {
                try {
                    $patchSize  = 400;
                    $outputSize = 300;

                    $img       = \Intervention\Image\Facades\Image::make(Storage::disk('s3')->get($image->image_path));
                    $imgWidth  = $img->width();
                    $imgHeight = $img->height();

                    $x = max(0, $request->pixel_x - $patchSize);
                    $y = max(0, $request->pixel_y - $patchSize);
                    $w = min($patchSize * 2, $imgWidth  - $x);
                    $h = min($patchSize * 2, $imgHeight - $y);

                    $img->crop($w, $h, $x, $y)->resize($outputSize, $outputSize);

                    $patchFilename = 'patch_' . $request->image_id . '_' . $request->pixel_x . '_' . $request->pixel_y . '.jpg';
                    $patchPath     = dirname($image->image_path) . '/patches/' . $patchFilename;

                    Storage::disk('s3')->put($patchPath, $img->encode('jpg', 60)->getEncoded());

                } catch (\Exception $e) {
                    Log::warning('Error generating patch: ' . $e->getMessage());
                    $patchPath = null;
                }
            }

            $defect = MarkingDefect::create([
                'operation_id'     => $request->operation_id,
                'task_id'          => $request->task_id,
                'run'              => $request->run_id,
                'image_id'         => $request->image_id,
                'defect_id'        => $newDefectId,
                'unique_defect_id' => $uniqueDefectId,
                'pixel_x'          => $request->pixel_x,
                'pixel_y'          => $request->pixel_y,
                'latitude'         => $latitude,
                'longitude'        => $longitude,
                'severity'         => $request->severity,
                'type_defect'      => $request->type_id,
                'removed'          => 0,
                'patch_path'       => $patchPath,
            ]);

            return response()->json([
                'success'      => true,
                'message'      => 'Defect created successfully',
                'defect'       => $defect,
                'api_response' => $apiData ?? null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error creating defect: ' . $e->getMessage(),
            ], 500);
        }
    }
}
