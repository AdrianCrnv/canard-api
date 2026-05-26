<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Header;
use App\Models\Runway;
use App\Models\Stand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StandController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/stands/form-data',
        summary: 'Get data needed to build the create stand form',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Airport, stands, aircrafts and map center'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        return response()->json([
            'airport'             => $airport,
            'allStands'           => Stand::where('airport_id', $airport->id)->get(),
            'availableAircrafts'  => Aircraft::all(),
            'mapCenter'           => $this->getCenterMapLatLng($airport),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/stands',
        summary: 'Create a new stand and associate aircrafts',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'latitude', 'longitude', 'ort_reference_elevation', 'bearing', 'airport_id_hidden'],
                properties: [
                    new OA\Property(property: 'name',                    type: 'string'),
                    new OA\Property(property: 'airport_id_hidden',       type: 'integer'),
                    new OA\Property(property: 'latitude',                type: 'number'),
                    new OA\Property(property: 'longitude',               type: 'number'),
                    new OA\Property(property: 'ort_reference_elevation', type: 'number'),
                    new OA\Property(property: 'bearing',                 type: 'number'),
                    new OA\Property(property: 'aircrafts',               type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stand created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $validated = $request->validate([
            'name'                    => 'required|string',
            'latitude'                => 'required|numeric',
            'longitude'               => 'required|numeric',
            'ort_reference_elevation' => 'required|numeric',
            'bearing'                 => 'required|numeric',
            'aircrafts'               => 'nullable|array',
        ]);

        try {
            $stand = new Stand([
                'name'       => $validated['name'],
                'airport_id' => $request['airport_id_hidden'],
                'latitude'   => $validated['latitude'],
                'longitude'  => $validated['longitude'],
                'elevation'  => $validated['ort_reference_elevation'],
                'bearing'    => $validated['bearing'],
            ]);
            $stand->save();

            if ($request->has('aircrafts')) {
                $stand->aircrafts()->attach($request->input('aircrafts'));
            }

            $airport = Airport::find($request['airport_id_hidden']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id_hidden'], "New airside system: Stand '{$stand->name}' at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($stand->load('aircrafts'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/stands/{stand}',
        summary: 'Get a single stand with aircrafts and map center',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'stand', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stand data'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Stand $stand): JsonResponse
    {
        return response()->json($this->buildStandPayload($stand));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/stands/{stand}/edit-data',
        summary: 'Get stand data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'stand', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stand with airport, aircrafts and map center'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Stand $stand): JsonResponse
    {
        return response()->json($this->buildStandPayload($stand));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/stands/{stand}',
        summary: 'Update a stand',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'stand', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stand updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Stand $stand): JsonResponse
    {
        $request->validate([
            'name'                    => 'required|string|max:255',
            'latitude'                => 'required|numeric',
            'longitude'               => 'required|numeric',
            'ort_reference_elevation' => 'required|numeric',
            'bearing'                 => 'required|numeric',
            'aircrafts'               => 'array',
        ]);

        try {
            $before = [
                'Name'      => $stand->name,
                'Latitude'  => $stand->latitude,
                'Longitude' => $stand->longitude,
                'Elevation' => $stand->elevation,
                'Bearing'   => $stand->bearing,
            ];

            $stand->name      = $request['name'];
            $stand->latitude  = $request['latitude'];
            $stand->longitude = $request['longitude'];
            $stand->elevation = $request['ort_reference_elevation'];
            $stand->bearing   = $request['bearing'];
            $stand->save();

            $stand->aircrafts()->sync($request['aircrafts'] ?? []);

            $after = [
                'Name'      => $stand->name,
                'Latitude'  => $stand->latitude,
                'Longitude' => $stand->longitude,
                'Elevation' => $stand->elevation,
                'Bearing'   => $stand->bearing,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport     = Airport::find($request['airport_id_hidden']);
            $description = "Updated airside system: Stand '{$stand->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['airport_id_hidden'], $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($stand->fresh()->load('aircrafts'));
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/stands/{stand}',
        summary: 'Delete a stand and detach its aircrafts',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'stand', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stand deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Stand $stand): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $airport = Airport::find($stand->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $stand->airport_id, "Deleted airside system: Stand '{$stand->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            $stand->aircrafts()->detach();
            $stand->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Stand deleted successfully.']);
    }

    // ── Stands in airport ─────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airportId}/stands',
        summary: 'Get all stands for an airport',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'airportId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of stands'),
        ]
    )]
    public function getStandsInAirport(int $airportId): JsonResponse
    {
        return response()->json(Stand::where('airport_id', $airportId)->get());
    }

    #[OA\Get(
        path: '/api/airports/{airport}/stands/operation',
        summary: 'Get stands for an airport in operation creation context',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of stands'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getStandsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        return response()->json($airport->stands);
    }

    // ── Aircrafts for a stand ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/stands/{stand}/aircrafts',
        summary: 'Get aircrafts associated with a stand',
        security: [['bearerAuth' => []]],
        tags: ['Stands'],
        parameters: [
            new OA\Parameter(name: 'stand', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of aircrafts'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getAircrafts(Stand $stand): JsonResponse
    {
        return response()->json(['aircrafts' => $stand->aircrafts()->select('id', 'model')->get()]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildStandPayload(Stand $stand): array
    {
        $airport = Airport::find($stand->airport_id);

        return [
            'stand'              => $stand,
            'airport'            => $airport,
            'allStands'          => Stand::where('airport_id', $airport->id)->get(),
            'availableAircrafts' => Aircraft::all(),
            'selectedAircrafts'  => $stand->aircrafts->pluck('id')->toArray(),
            'mapCenter'          => $this->getCenterMapLatLng($airport),
        ];
    }

    private function getCenterMapLatLng(Airport $airport): array
    {
        $runway  = Runway::where('airport_id', $airport->id)->first();
        $headers = Header::where('runway_id', $runway->id)->get()->sortBy('bearing')->values();

        return [
            'lat' => ($headers[0]->threshold_latitude + $headers[1]->threshold_latitude) / 2,
            'lng' => ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2,
        ];
    }
}
