<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\ResultsRwyMarkings;
use App\Runway;
use App\Stretch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class MarkingsController extends Controller
{
    #[OA\Delete(
        path: '/api/markings/diagram',
        summary: 'Eliminar el diagrama de markings de una pista',
        security: [['bearerAuth' => []]],
        tags: ['Markings'],
        responses: [
            new OA\Response(response: 200, description: 'Diagrama eliminado correctamente'),
            new OA\Response(response: 404, description: 'No existe diagrama'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al eliminar'),
        ]
    )]
    public function deleteDiagram(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'runway_id' => 'required|exists:runways,id',
            ]);

            $runway = Runway::findOrFail($request->runway_id);

            if (!$runway->hasMedia('rwy_marking_diagram')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No diagram found to delete',
                ], 404);
            }

            $runway->clearMediaCollection('rwy_marking_diagram');

            return response()->json([
                'success' => true,
                'message' => 'Diagram deleted successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error deleting runway diagram: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Delete failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/markings/diagram',
        summary: 'Subir o reemplazar el diagrama de markings de una pista',
        security: [['bearerAuth' => []]],
        tags: ['Markings'],
        responses: [
            new OA\Response(response: 200, description: 'Diagrama subido correctamente'),
            new OA\Response(response: 400, description: 'No se proporcionó archivo'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al subir'),
        ]
    )]
    public function uploadDiagram(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'runway_image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
                'runway_id'    => 'required|exists:runways,id',
            ]);

            $runway = Runway::findOrFail($request->runway_id);

            if (!$request->file('runway_image')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded',
                ], 400);
            }

            $runway->clearMediaCollection('rwy_marking_diagram');

            $mediaItem  = $runway->addMediaFromRequest('runway_image')
                ->toMediaCollection('rwy_marking_diagram', 'public');

            $diagramUrl = $runway->getFirstMediaUrl('rwy_marking_diagram');

            return response()->json([
                'success'     => true,
                'message'     => 'Runway markings diagram uploaded successfully',
                'diagram_url' => $diagramUrl,
                'media_id'    => $mediaItem->id,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Runway markings diagram upload error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/markings/stretches',
        summary: 'Guardar la configuración de stretches de markings para una pista',
        security: [['bearerAuth' => []]],
        tags: ['Markings'],
        responses: [
            new OA\Response(response: 200, description: 'Stretches guardados correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al guardar'),
        ]
    )]
    public function saveMarkings(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'runway_id'              => 'required|integer|exists:runways,id',
                'stretches'              => 'required|array|min:1',
                'stretches.*.name'       => 'required|string|max:255',
                'stretches.*.start'      => 'required|numeric|min:0',
                'stretches.*.end'        => 'required|numeric',
                'stretches.*.order'      => 'required|integer|min:1',
                'stretches.*.enable'     => 'required|in:0,1',
            ]);

            $runwayId    = $request->runway_id;
            $stretchType = 5;
            $stretches   = $request->stretches;

            DB::beginTransaction();

            try {
                Stretch::where('subject_id', $runwayId)
                    ->where('stretch_type', $stretchType)
                    ->delete();

                $insertedCount = 0;

                foreach ($stretches as $stretch) {
                    Stretch::create([
                        'stretch_type'                => $stretchType,
                        'subject_id'                  => $runwayId,
                        'order'                       => intval($stretch['order']),
                        'name'                        => $stretch['name'],
                        'start_thr'                   => 1,
                        'end_thr'                     => 1,
                        'distance_to_rwy_limit_start' => floatval($stretch['start']),
                        'distance_to_rwy_limit_end'   => floatval($stretch['end']),
                        'start_lat'                   => 0,
                        'start_lon'                   => 0,
                        'start_elevation'             => 0,
                        'end_lat'                     => 0,
                        'end_lon'                     => 0,
                        'end_elevation'               => 0,
                        'enable'                      => boolval($stretch['enable']),
                    ]);

                    $insertedCount++;
                }

                DB::commit();

                return response()->json([
                    'success'       => true,
                    'message'       => "Markings guardados exitosamente. {$insertedCount} stretches configurados.",
                    'inserted'      => $insertedCount,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error durante la transacción saveMarkings: ' . $e->getMessage());
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Error de validación saveMarkings:', $e->errors());

            return response()->json([
                'success' => false,
                'message' => 'Error de validación en los datos enviados.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error general en saveMarkings: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar los markings: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/markings/runs/toggle-valid',
        summary: 'Marcar o desmarcar un run de markings como válido',
        security: [['bearerAuth' => []]],
        tags: ['Markings'],
        responses: [
            new OA\Response(response: 200, description: 'Estado de validez actualizado'),
            new OA\Response(response: 404, description: 'Run no encontrado'),
            new OA\Response(response: 500, description: 'Error al actualizar'),
        ]
    )]
    public function toggleValidRun(Request $request): JsonResponse
    {
        try {
            $result = ResultsRwyMarkings::where('task_id', $request->task_id)
                ->where('run', $request->run)
                ->first();

            if (!$result) {
                return response()->json(['message' => 'Run not found'], 404);
            }

            if ($request->is_valid) {
                ResultsRwyMarkings::where('task_id', $request->task_id)
                    ->where('run', '!=', $request->run)
                    ->update(['is_valid' => false]);
            }

            $result->update(['is_valid' => (bool) $request->is_valid]);

            $validLabel = $request->is_valid ? 'marked as valid' : 'unmarked as valid';
            ActivityLog::log(
                'validate',
                'Operation',
                (int) $result->operation_id,
                "Markings run #{$request->run} {$validLabel} for operation #{$result->operation_id}, task #{$request->task_id}"
            );

            return response()->json([
                'success' => true,
                'message' => $request->is_valid ? 'Run marked as valid' : 'Run marked as not valid',
            ]);

        } catch (\Exception $e) {
            Log::error('Error toggling valid run: ' . $e->getMessage(), [
                'task_id'  => $request->task_id,
                'run'      => $request->run,
                'is_valid' => $request->is_valid,
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error updating run: ' . $e->getMessage(),
            ], 500);
        }
    }
}
