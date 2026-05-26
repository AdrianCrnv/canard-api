<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Header;
use App\Models\MarkerPoints;
use App\Models\Parameter;
use App\Models\Runway;
use App\Models\Surveillance;
use App\Models\System;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class SurveillanceController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/surveillances/form-data',
        summary: 'Get data needed to build the create surveillance form',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Form data with map center and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $surveillance       = new Surveillance(['airport_id' => $airport->id]);
        $mapCenter          = $this->getMapCenter($airport);
        $otherSurveillances = Surveillance::where('airport_id', $airport->id)->get();

        return response()->json([
            'airport'              => $airport,
            'mapCenter'            => $mapCenter,
            'mission_start_type'   => DB::table('mission_start_type')->get(),
            'task_types'           => $this->prepareSurveillanceParameters($surveillance, 1),
            'parameters'           => $this->prepareSurveillanceParameters($surveillance),
            'other_svllcs'         => $otherSurveillances,
            'other_svllc_markers'  => MarkerPoints::whereIn('subject_id', $otherSurveillances->pluck('id'))->where('subject_type_id', 9)->get(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/surveillances',
        summary: 'Create a new surveillance system with markers and parameters',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        responses: [
            new OA\Response(response: 201, description: 'Surveillance created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'airport_id' => 'required',
            'name'       => [
                'required',
                Rule::unique('surveillances')->where(fn($q) => $q->where('airport_id', request('airport_id'))),
            ],
        ]);

        try {
            $surveillance = Surveillance::create([
                'name'       => $request['name'],
                'airport_id' => $request['airport_id'],
            ]);

            $this->storeMarkers($request->all(), $request, $surveillance->id);
            $this->storeSurveillanceParameters($request);

            $airport = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New airside system: Surveillance '{$surveillance->name}' at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($surveillance->load('markerPoints'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/surveillances/{surveillance}',
        summary: 'Get surveillance data with markers and parameters',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'surveillance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Surveillance data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Surveillance $surveillance): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json($this->buildSurveillancePayload($surveillance, subjectTypeForOthers: 8));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/surveillances/{surveillance}/edit-data',
        summary: 'Get surveillance data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'surveillance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Surveillance with markers and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Surveillance $surveillance): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildSurveillancePayload($surveillance, subjectTypeForOthers: 9));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/surveillances/{surveillance}',
        summary: 'Update a surveillance system',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'surveillance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Surveillance updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Surveillance $surveillance): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'airport_id' => 'required',
            'name'       => [
                'required',
                Rule::unique('surveillances')->ignore($surveillance->id)->where(fn($q) => $q->where('airport_id', request('airport_id'))),
            ],
        ]);

        try {
            $oldName = $surveillance->name;

            $surveillance->airport_id = $request['airport_id'];
            $surveillance->name       = $request['name'];
            $surveillance->save();

            $this->storeMarkers($request->all(), $request, $surveillance->id);
            $this->updateSurveillanceParameters($request);

            $changes = [];
            if ($oldName !== $surveillance->name) {
                $changes[] = "Name: '{$oldName}' → '{$surveillance->name}'";
            }

            $airport     = Airport::find($request['airport_id']);
            $description = "Updated airside system: Surveillance '{$surveillance->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Operation', (int) $request['airport_id'], $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($surveillance->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/surveillances/{surveillance}',
        summary: 'Delete a surveillance system and its parameters',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'surveillance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Surveillance deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Surveillance $surveillance): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $airport = Airport::find($surveillance->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $surveillance->airport_id, "Deleted airside system: Surveillance '{$surveillance->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            Parameter::where('subject_id', $surveillance->id)->where('subject_type_id', 8)->delete();
            $surveillance->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Surveillance deleted successfully.']);
    }

    // ── Surveillances in airport ──────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/surveillances',
        summary: 'Get all surveillances for an airport',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of surveillances'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getSurveillancesInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json($airport->surveillances);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/surveillances/operation',
        summary: 'Get surveillances for an airport in operation creation context',
        security: [['bearerAuth' => []]],
        tags: ['Surveillances'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of surveillances'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getSurveillancesInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        return response()->json($airport->surveillances);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildSurveillancePayload(Surveillance $surveillance, int $subjectTypeForOthers): array
    {
        $airport   = Airport::find($surveillance->airport_id);
        $mapCenter = $this->getMapCenter($airport);

        $exists         = Parameter::where('subject_id', $surveillance->id)->where('parameter_type_id', 11)->where('task_type_id', 35)->first();
        $value_parameter = $exists?->value;

        $otherSurveillances = Surveillance::where('airport_id', $surveillance->airport_id)->get();

        return [
            'surveillance'        => $surveillance,
            'airport'             => $airport,
            'mapCenter'           => $mapCenter,
            'markers'             => MarkerPoints::where('subject_id', $surveillance->id)->where('subject_type_id', 9)->get(),
            'task_types'          => $this->prepareSurveillanceParameters($surveillance, 1),
            'parameters'          => $this->prepareSurveillanceParameters($surveillance),
            'mission_start_type'  => DB::table('mission_start_type')->get(),
            'value_parameter'     => $value_parameter,
            'other_svllcs'        => $otherSurveillances,
            'other_svllc_markers' => MarkerPoints::whereIn('subject_id', $otherSurveillances->pluck('id'))->where('subject_type_id', $subjectTypeForOthers)->get(),
        ];
    }

    private function getMapCenter(Airport $airport): array
    {
        $runway  = Runway::where('airport_id', $airport->id)->first();
        $headers = Header::where('runway_id', $runway->id)->get();

        return [
            'lat' => ($headers[0]->threshold_latitude + $headers[1]->threshold_latitude) / 2,
            'lng' => ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2,
        ];
    }

    public function storeMarkers(array $jsonDef, Request $request, int $surveillance_id): void
    {
        $counterMarkers = $jsonDef['number_markers'];
        $surveillance   = Surveillance::find($surveillance_id);

        $this->destroyMarkers($surveillance, $request);

        for ($i = 1; $i <= $counterMarkers; $i++) {
            $request->validate([
                'marker_order'     . $i => 'required|numeric',
                'marker_latitude'  . $i => 'required|numeric|between:-90,90',
                'marker_longitude' . $i => 'required|numeric|between:-180,180',
                'marker_elevation' . $i => 'required|numeric',
            ]);

            MarkerPoints::create([
                'subject_id'      => $surveillance_id,
                'order'           => $request['marker_order' . $i],
                'lat'             => $request['marker_latitude' . $i],
                'lng'             => $request['marker_longitude' . $i],
                'height'          => $request['marker_elevation' . $i],
                'subject_type_id' => 9,
            ]);
        }
    }

    public function destroyMarkers(Surveillance $surveillance, Request $request): void
    {
        $this->authorize('airport_delete');

        MarkerPoints::where('subject_id', $surveillance->id)->where('subject_type_id', 9)->delete();
    }

    public function prepareSurveillanceParameters(Surveillance $surveillance, ?int $opt = null): array
    {
        $system                    = System::where('name', 'Surveillance')->first();
        $surveillance_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system->id)->get();

        if ($opt) {
            return $surveillance_task_type_id->map(fn($t) => TaskType::find($t->task_type_id))->filter()->values()->all();
        }

        $parameters = [];
        foreach ($surveillance_task_type_id as $surveillance_task_type) {
            $parameter_type_rows = DB::table('parameter_type_task_type')->where('task_type_id', $surveillance_task_type->task_type_id)->get();
            foreach ($parameter_type_rows as $parameter_type) {
                $parameters[] = [
                    'task_type'      => TaskType::find($parameter_type->task_type_id),
                    'parameter_type' => DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first(),
                    'value'          => Parameter::where([
                        'parameter_type_id' => $parameter_type->parameter_type_id,
                        'subject_id'        => $surveillance->id,
                        'task_type_id'      => $parameter_type->task_type_id,
                    ])->first(),
                ];
            }
        }

        return $parameters;
    }

    public function storeSurveillanceParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key !== '0') continue;

            $surveillanceId = Surveillance::where('airport_id', $request['airport_id'])->where('name', $request['name'])->value('id');

            Parameter::where('subject_id', $request['surveillance_id'])->where('parameter_type_id', 11)->where('task_type_id', 35)->delete();

            Parameter::create([
                'subject_type_id'   => 9,
                'subject_id'        => $surveillanceId,
                'parameter_type_id' => 11,
                'task_type_id'      => 35,
                'value'             => $request['0'],
            ]);
        }
    }

    public function updateSurveillanceParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key !== '0') continue;

            $surveillanceId = (int) Surveillance::where('airport_id', $request['airport_id'])->where('name', $request['name'])->value('id');

            Parameter::where('subject_id', $surveillanceId)->where('parameter_type_id', 11)->where('task_type_id', 35)->delete();

            Parameter::create([
                'subject_type_id'   => 9,
                'subject_id'        => $surveillanceId,
                'parameter_type_id' => 11,
                'task_type_id'      => 47,
                'value'             => $request['0'],
            ]);
        }
    }
}
