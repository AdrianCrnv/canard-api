<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Header;
use App\Models\ResultsWdi;
use App\Models\Runway;
use App\Models\Wdi;
use App\Models\WdiFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class WdiController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/wdis/form-data',
        summary: 'Get data needed to build the create WDI form',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Airport and map center'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $runway  = Runway::where('airport_id', $airport->id)->first();
        $headers = Header::where('runway_id', $runway->id)->orderBy('id', 'desc')->get();

        $mapCenter = [
            'lat' => ($headers->first()->threshold_latitude  + $headers->last()->threshold_latitude)  / 2,
            'lng' => ($headers->first()->threshold_longitude + $headers->last()->threshold_longitude) / 2,
        ];

        return response()->json([
            'airport'   => $airport,
            'mapCenter' => $mapCenter,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/wdis',
        summary: 'Create a new WDI',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        responses: [
            new OA\Response(response: 201, description: 'WDI created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        try {
            $wdi = Wdi::create([
                'airport_id' => $request['airport_id'],
                'name'       => $request['name'],
                'latitude'   => $request['latitude'],
                'longitude'  => $request['longitude'],
                'altitude'   => $request['altitude'],
            ]);

            $airport = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New system: WDI '{$wdi->name}' at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($wdi->load('airport'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/wdis/{wdi}',
        summary: 'Get WDI data',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'wdi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WDI data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Wdi $wdi): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildWdiPayload($wdi));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/wdis/{wdi}/edit-data',
        summary: 'Get WDI data for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'wdi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WDI with airport and coordinates'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Wdi $wdi): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildWdiPayload($wdi));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/wdis/{wdi}',
        summary: 'Update a WDI',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'wdi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WDI updated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Wdi $wdi): JsonResponse
    {
        $this->authorize('airport_edit');

        try {
            $before = [
                'Name'      => $wdi->name,
                'Latitude'  => $wdi->latitude,
                'Longitude' => $wdi->longitude,
                'Altitude'  => $wdi->altitude,
            ];

            $wdi->update([
                'name'      => $request['name'],
                'latitude'  => $request['latitude'],
                'longitude' => $request['longitude'],
                'altitude'  => $request['altitude'],
            ]);

            $after = [
                'Name'      => $wdi->name,
                'Latitude'  => $wdi->latitude,
                'Longitude' => $wdi->longitude,
                'Altitude'  => $wdi->altitude,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport     = Airport::find($wdi->airport_id);
            $description = "Updated system: WDI '{$wdi->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $wdi->airport_id, $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($wdi->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/wdis/{wdi}',
        summary: 'Delete a WDI',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'wdi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WDI deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Wdi $wdi): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $airport = Airport::find($wdi->airport_id);
            ActivityLog::log('delete', 'Operation', (int) $wdi->airport_id, "Deleted system: WDI '{$wdi->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            $wdi->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'WDI deleted successfully.']);
    }

    // ── WDIs in airport ───────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/wdis',
        summary: 'Get all WDIs for an airport',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of WDIs'),
        ]
    )]
    public function inAirport(Airport $airport): JsonResponse
    {
        return response()->json($airport->wdi_list);
    }

    // ── File management ───────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/wdi-files/{fileId}',
        summary: 'Delete a WDI file from S3 and database',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        parameters: [
            new OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File deleted'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deleteFile(int $fileId): JsonResponse
    {
        $file = WdiFile::find($fileId);

        if (!$file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        try {
            DB::beginTransaction();

            if (Storage::disk('s3')->exists($file->s3_path)) {
                Storage::disk('s3')->delete($file->s3_path);
            }

            $resultId = $file->result_id;
            $file->delete();

            if (WdiFile::where('result_id', $resultId)->count() === 0) {
                ResultsWdi::where('id', $resultId)->delete();
            }

            DB::commit();

            return response()->json(['message' => 'File deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Toggle valid run ──────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/wdis/runs/toggle-validation',
        summary: 'Set a specific WDI run as valid and invalidate the rest',
        security: [['bearerAuth' => []]],
        tags: ['WDIs'],
        responses: [
            new OA\Response(response: 200, description: 'Run updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function toggleValidRunWdi(Request $request): JsonResponse
    {
        $request->validate([
            'task_id'      => 'required|integer',
            'run'          => 'required|integer',
            'operation_id' => 'required|integer',
            'is_valid'     => 'required|boolean',
        ]);

        ResultsWdi::where('task_id', $request->task_id)
            ->whereHas('task', fn($q) => $q->where('operation_id', $request->operation_id))
            ->update(['is_valid' => 0]);

        $wdiRun           = ResultsWdi::where('task_id', $request->task_id)->where('run', $request->run)->firstOrFail();
        $wdiRun->is_valid = $request->is_valid;
        $wdiRun->save();

        return response()->json(['success' => true, 'message' => 'Run updated successfully']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildWdiPayload(Wdi $wdi): array
    {
        return [
            'wdi'     => $wdi,
            'airport' => $wdi->airport,
            'lat'     => $wdi->latitude,
            'lng'     => $wdi->longitude,
        ];
    }
}
