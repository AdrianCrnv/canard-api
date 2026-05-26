<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\Airport;
use App\FloodlightTower;
use App\Header;
use App\Parameter;
use App\Runway;
use App\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class FloodlightTowerController extends Controller
{
    #[OA\Get(
        path: '/api/airports/{airport}/floodlight-towers/create',
        summary: 'Get data needed to create a new Floodlight Tower (parameters, task types, map center)',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Creation form data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $floodlight             = new FloodlightTower;
        $floodlight->airport_id = $airport->id;

        [$mapLat, $mapLng] = $this->getCenterMapLatLng($airport);

        return response()->json([
            'airport'            => $airport,
            'all_floodlights'    => $airport->floodlight,
            'task_types'         => $this->prepareFloodlightParameters($floodlight, 10, 1),
            'parameters'         => $this->prepareFloodlightParameters($floodlight, 10),
            'map_center'         => ['lat' => $mapLat, 'lng' => $mapLng],
        ]);
    }

    #[OA\Post(
        path: '/api/airports/{airport}/floodlight-towers',
        summary: 'Create a new Floodlight Tower',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Floodlight Tower created'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function store(Request $request, Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'name' => [
                'required',
                Rule::unique('floodlight_towers')->where(fn ($q) => $q->where('airport_id', request('airport_id'))),
            ],
            'floodlight_latitude'      => 'required|numeric|between:-90,90',
            'floodlight_longitude'     => 'required|numeric|between:-180,180',
            'ort_floodlight_elevation' => 'required|numeric|between:0,9999.999',
            'height'                   => 'required|numeric|between:0,9999.999',
        ]);

        try {
            $floodlightTower = FloodlightTower::create([
                'airport_id' => $request['airport_id'],
                'name'       => $request['name'],
                'lat'        => $request['floodlight_latitude'],
                'lng'        => $request['floodlight_longitude'],
                'elevation'  => $request['ort_floodlight_elevation'],
                'height'     => $request['height'],
            ]);

            $this->storeParameters($request, $floodlightTower->id);

            ActivityLog::log('create', 'Airport', (int) $request['airport_id'],
                "New system: Floodlight Tower '{$floodlightTower->name}' at airport '{$airport->name}' ({$airport->icao_code})");

            return response()->json(['message' => 'Floodlight Tower created', 'floodlight' => $floodlightTower], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/floodlight-towers/{floodlight}',
        summary: 'Get Floodlight Tower details (read-only parameters)',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'floodlight', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Floodlight Tower data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function show(FloodlightTower $floodlight): JsonResponse
    {
        $this->authorize('airport_edit');

        $airport       = Airport::findOrFail($floodlight->airport_id);
        [$mapLat, $mapLng] = $this->getCenterMapLatLng($airport);

        return response()->json([
            'floodlight' => $floodlight,
            'airport'    => $airport,
            'task_types' => $this->prepareFloodlightParameters($floodlight, 10, 1),
            'parameters' => $this->prepareFloodlightParameters($floodlight, 10),
            'map_center' => ['lat' => $mapLat, 'lng' => $mapLng],
        ]);
    }

    #[OA\Get(
        path: '/api/floodlight-towers/{floodlight}/edit',
        summary: 'Get Floodlight Tower data for editing (includes all floodlights in airport)',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'floodlight', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Edit form data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function edit(FloodlightTower $floodlight): JsonResponse
    {
        $this->authorize('airport_edit');

        $airport       = Airport::findOrFail($floodlight->airport_id);
        [$mapLat, $mapLng] = $this->getCenterMapLatLng($airport);

        return response()->json([
            'floodlight'      => $floodlight,
            'airport'         => $airport,
            'all_floodlights' => $airport->floodlight,
            'task_types'      => $this->prepareFloodlightParameters($floodlight, 10, 1),
            'parameters'      => $this->prepareFloodlightParameters($floodlight, 10),
            'map_center'      => ['lat' => $mapLat, 'lng' => $mapLng],
        ]);
    }

    #[OA\Put(
        path: '/api/floodlight-towers/{floodlight}',
        summary: 'Update a Floodlight Tower',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'floodlight', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Floodlight Tower updated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function update(Request $request, FloodlightTower $floodlight): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'airport_id' => 'required',
            'name'       => [
                'required',
                Rule::unique('floodlight_towers')->ignore($floodlight->id)->where(fn ($q) => $q->where('airport_id', request('airport_id'))),
            ],
            'floodlight_latitude'      => 'required|numeric|between:-90,90',
            'floodlight_longitude'     => 'required|numeric|between:-180,180',
            'ort_floodlight_elevation' => 'required|numeric|between:0,9999.999',
            'height'                   => 'required|numeric|between:0,9999.999',
        ]);

        try {
            $before = [
                'Name'      => $floodlight->name,
                'Latitude'  => $floodlight->lat,
                'Longitude' => $floodlight->lng,
                'Elevation' => $floodlight->elevation,
                'Height'    => $floodlight->height,
            ];

            $floodlight->airport_id = $request['airport_id'];
            $floodlight->name       = $request['name'];
            $floodlight->lat        = $request['floodlight_latitude'];
            $floodlight->lng        = $request['floodlight_longitude'];
            $floodlight->elevation  = $request['ort_floodlight_elevation'];
            $floodlight->height     = $request['height'];
            $floodlight->save();

            $this->updateParameters($request, $floodlight->id);

            $after = [
                'Name'      => $floodlight->name,
                'Latitude'  => $floodlight->lat,
                'Longitude' => $floodlight->lng,
                'Elevation' => $floodlight->elevation,
                'Height'    => $floodlight->height,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport     = Airport::find($request['airport_id']);
            $description = "Updated system: Floodlight Tower '{$floodlight->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');

            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);

            return response()->json(['message' => 'Floodlight Tower updated', 'floodlight' => $floodlight]);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/floodlight-towers/{floodlight}',
        summary: 'Delete a Floodlight Tower',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'floodlight', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function destroy(FloodlightTower $floodlight): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $check = DB::table('operator_airport')
                ->where('operator_id', Auth::user()->operator_id)
                ->where('subject_type_id', 4)
                ->where('subject_id', $floodlight->airport_id)
                ->first();

            if ($check === null) {
                return response()->json(['error' => 'Unauthorized action.'], 403);
            }

            $airport = Airport::find($floodlight->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $floodlight->airport_id,
                "Deleted system: Floodlight Tower '{$floodlight->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            $floodlight->delete();

            return response()->json(['message' => 'Floodlight Tower deleted']);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}/floodlight-towers',
        summary: 'Get all Floodlight Towers for an airport',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of Floodlight Towers'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getFloodlightsInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $floodlights = FloodlightTower::where('subject_id', $airport->id)->get();

        return response()->json($floodlights);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/floodlight-towers/operation',
        summary: 'Get all Floodlight Towers for an airport (operation context)',
        security: [['bearerAuth' => []]],
        tags: ['FloodlightTower'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of Floodlight Towers'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getFloodlightsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        return response()->json($airport->floodlights);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function getCenterMapLatLng(Airport $airport): array
    {
        $runway  = Runway::where('airport_id', $airport->id)->first();
        $headers = Header::where('runway_id', $runway->id)->get()->sortBy('bearing');

        $headers = array_values($headers->all());

        $lat = ($headers[0]->threshold_latitude  + $headers[1]->threshold_latitude)  / 2;
        $lng = ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2;

        return [$lat, $lng];
    }

    private function prepareFloodlightParameters($floodlight, int $systemId, $opt = null): array
    {
        $taskTypeIds = DB::table('systems_id_task_type_id')->where('system_id', $systemId)->get();
        $result      = [];

        if ($opt) {
            foreach ($taskTypeIds as $row) {
                $result[] = TaskType::where('id', $row->task_type_id)->first();
            }
            return $result;
        }

        foreach ($taskTypeIds as $row) {
            $paramTypeRows = DB::table('parameter_type_task_type')->where('task_type_id', $row->task_type_id)->get();
            foreach ($paramTypeRows as $paramTypeRow) {
                $taskType      = TaskType::where('id', $paramTypeRow->task_type_id)->first();
                $paramTypeName = DB::table('parameter_types')->where('id', $paramTypeRow->parameter_type_id)->first();
                $value         = Parameter::where([
                    'parameter_type_id' => $paramTypeRow->parameter_type_id,
                    'subject_id'        => $floodlight->id,
                    'task_type_id'      => $paramTypeRow->task_type_id,
                ])->first();

                $result[] = ['task_type' => $taskType, 'parameter_type' => $paramTypeName, 'value' => $value];
            }
        }

        return $result;
    }

    private function storeParameters(Request $request, int $floodlightId): void
    {
        $skipKeys = ['_token', 'airport_id', 'name', 'floodlight_latitude', 'floodlight_longitude', 'ort_floodlight_elevation', 'height'];

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skipKeys)) continue;

            $ids    = explode('-', $key);
            $exists = Parameter::where(['subject_id' => $floodlightId, 'parameter_type_id' => $ids[0], 'task_type_id' => $ids[1]])->first();
            if ($exists) $exists->delete();

            $this->parameterCreate($request, $key, [48], $ids, $floodlightId);
        }
    }

    private function parameterCreate(Request $request, string $key, array $taskTypeIds, array $ids, int $floodlightId): void
    {
        foreach ($taskTypeIds as $id) {
            Parameter::create([
                'subject_type_id'   => 11,
                'subject_id'        => $floodlightId,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
        }
    }

    private function updateParameters(Request $request, int $floodlightId): void
    {
        $skipKeys = ['_method', '_token', 'airport_id', 'name', 'floodlight_latitude', 'floodlight_longitude', 'ort_floodlight_elevation', 'height'];

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skipKeys)) continue;

            $ids = explode('-', $key);
            $this->parameterUpdate($request, $key, [48], $ids, $floodlightId);
        }
    }

    private function parameterUpdate(Request $request, string $key, array $taskTypeIds, array $ids, int $floodlightId): void
    {
        foreach ($taskTypeIds as $id) {
            $exists = Parameter::where(['subject_id' => $floodlightId, 'parameter_type_id' => $ids[0], 'task_type_id' => $id])->first();
            if ($exists) $exists->delete();

            Parameter::create([
                'subject_type_id'   => 11,
                'subject_id'        => $floodlightId,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
        }
    }
}
