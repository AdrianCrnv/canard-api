<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Header;
use App\Models\MarkerPoints;
use App\Models\Parameter;
use App\Models\Runway;
use App\Models\RunwayComposition;
use App\Models\System;
use App\Models\Taxiway;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class TaxiwayController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/taxiways/form-data',
        summary: 'Get data needed to build the create taxiway form',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
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

        $taxiway       = new Taxiway(['airport_id' => $airport->id]);
        $mapCenter     = $this->getMapCenter($airport);
        $otherTaxiways = Taxiway::where('airport_id', $airport->id)->get();

        return response()->json([
            'airport'            => $airport,
            'mapCenter'          => $mapCenter,
            'compositions'       => RunwayComposition::all(),
            'mission_start_type' => DB::table('mission_start_type')->get(),
            'task_types'         => $this->prepareTaxiwayParameters($taxiway, 1),
            'parameters'         => $this->prepareTaxiwayParameters($taxiway),
            'other_twys'         => $otherTaxiways,
            'other_twy_markers'  => MarkerPoints::whereIn('subject_id', $otherTaxiways->pluck('id'))->where('subject_type_id', 8)->get(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/taxiways',
        summary: 'Create a new taxiway with markers and parameters',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        responses: [
            new OA\Response(response: 201, description: 'Taxiway created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'airport_id'     => 'required',
            'name'           => [
                'required',
                Rule::unique('taxiways')->where(fn($q) => $q->where('airport_id', request('airport_id'))),
            ],
            'width'          => 'required|numeric|between:0,999999.99',
            'composition_id' => 'required',
        ]);

        try {
            $taxiway = Taxiway::create([
                'name'           => $request['name'],
                'width'          => $request['width'],
                'airport_id'     => $request['airport_id'],
                'composition_id' => $request['composition_id'],
            ]);

            $this->storeMarkers($request->all(), $request, $taxiway->id);
            $this->storeTaxiwayParameters($request);

            $airport = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New airside system: Taxiway '{$taxiway->name}' at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($taxiway->load('markerPoints'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/taxiways/{taxiway}',
        summary: 'Get taxiway data with markers and parameters',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'taxiway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Taxiway data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Taxiway $taxiway): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json($this->buildTaxiwayPayload($taxiway));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/taxiways/{taxiway}/edit-data',
        summary: 'Get taxiway data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'taxiway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Taxiway with markers and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Taxiway $taxiway): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildTaxiwayPayload($taxiway));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/taxiways/{taxiway}',
        summary: 'Update a taxiway',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'taxiway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Taxiway updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Taxiway $taxiway): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'airport_id'     => 'required',
            'name'           => [
                'required',
                Rule::unique('taxiways')->ignore($taxiway->id)->where(fn($q) => $q->where('airport_id', request('airport_id'))),
            ],
            'width'          => 'required',
            'composition_id' => 'required',
        ]);

        try {
            $changes = [];
            if ($taxiway->name !== $request['name']) $changes[] = "Name: '{$taxiway->name}' → '{$request['name']}'";
            if ((string) $taxiway->width !== (string) $request['width']) $changes[] = "Width: '{$taxiway->width}' → '{$request['width']}'";

            $jsonDef        = $request->all();
            $counterMarkers = $jsonDef['number_markers'];

            $oldMarkers = MarkerPoints::where('subject_id', $taxiway->id)
                ->where('subject_type_id', 8)
                ->orderBy('order')
                ->get()
                ->keyBy('order');

            $taxiway->airport_id     = $request['airport_id'];
            $taxiway->name           = $request['name'];
            $taxiway->width          = $request['width'];
            $taxiway->composition_id = $request['composition_id'];
            $taxiway->save();

            $markerChanges = [];
            for ($i = 1; $i <= $counterMarkers; $i++) {
                $order  = $request['marker_order' . $i];
                $newLat = round((float) $request['marker_latitude' . $i], 8);
                $newLng = round((float) $request['marker_longitude' . $i], 8);

                if ($oldMarkers->has($order)) {
                    $old    = $oldMarkers->get($order);
                    $oldLat = round((float) $old->lat, 8);
                    $oldLng = round((float) $old->lng, 8);
                    if ($oldLat !== $newLat || $oldLng !== $newLng) {
                        $markerChanges[] = "Marker #{$order}: ({$oldLat}, {$oldLng}) → ({$newLat}, {$newLng})";
                    }
                } else {
                    $markerChanges[] = "Marker #{$order} added ({$newLat}, {$newLng})";
                }
            }

            foreach ($oldMarkers as $order => $old) {
                $found = false;
                for ($i = 1; $i <= $counterMarkers; $i++) {
                    if ((string) $request['marker_order' . $i] === (string) $order) { $found = true; break; }
                }
                if (!$found) $markerChanges[] = "Marker #{$order} removed";
            }

            if (!empty($markerChanges)) $changes = array_merge($changes, $markerChanges);

            $airport     = Airport::find($request['airport_id']);
            $description = "Updated airside system: Taxiway '{$taxiway->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (!empty($changes) ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);

            $this->storeMarkers($jsonDef, $request, $taxiway->id);
            $this->updateTaxiwayParameters($request);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($taxiway->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/taxiways/{taxiway}',
        summary: 'Delete a taxiway and its parameters',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'taxiway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Taxiway deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Taxiway $taxiway): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $airport = Airport::find($taxiway->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $taxiway->airport_id, "Deleted airside system: Taxiway '{$taxiway->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            Parameter::where('subject_id', $taxiway->id)->where('subject_type_id', 8)->delete();
            $taxiway->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Taxiway deleted successfully.']);
    }

    // ── Taxiways in airport ───────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/taxiways',
        summary: 'Get all taxiways for an airport',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of taxiways'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getTaxiwaysInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json($airport->taxiways->load('markerPoints', 'composition'));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/taxiways/operation',
        summary: 'Get taxiways for an airport in operation creation context',
        security: [['bearerAuth' => []]],
        tags: ['Taxiways'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of taxiways'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getTaxiwaysInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        return response()->json($airport->taxiways);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildTaxiwayPayload(Taxiway $taxiway): array
    {
        $airport   = Airport::find($taxiway->airport_id);
        $mapCenter = $this->getMapCenter($airport);

        $exists          = Parameter::where('subject_id', $taxiway->id)->where('parameter_type_id', 11)->where('task_type_id', 35)->first();
        $value_parameter = $exists?->value;

        $otherTaxiways = Taxiway::where('airport_id', $taxiway->airport_id)->get();

        return [
            'taxiway'            => $taxiway,
            'airport'            => $airport,
            'mapCenter'          => $mapCenter,
            'markers'            => MarkerPoints::where('subject_id', $taxiway->id)->where('subject_type_id', 8)->get(),
            'compositions'       => RunwayComposition::all(),
            'task_types'         => $this->prepareTaxiwayParameters($taxiway, 1),
            'parameters'         => $this->prepareTaxiwayParameters($taxiway),
            'mission_start_type' => DB::table('mission_start_type')->get(),
            'value_parameter'    => $value_parameter,
            'other_twys'         => $otherTaxiways,
            'other_twy_markers'  => MarkerPoints::whereIn('subject_id', $otherTaxiways->pluck('id'))->where('subject_type_id', 8)->get(),
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

    public function storeMarkers(array $jsonDef, Request $request, int $taxiway_id): void
    {
        $counterMarkers = $jsonDef['number_markers'];
        $taxiway        = Taxiway::find($taxiway_id);

        $this->destroyMarkers($taxiway, $request);

        for ($i = 1; $i <= $counterMarkers; $i++) {
            $request->validate([
                'marker_order'     . $i => 'required|numeric',
                'marker_latitude'  . $i => 'required|numeric|between:-90,90',
                'marker_longitude' . $i => 'required|numeric|between:-180,180',
                'marker_elevation' . $i => 'required|numeric',
            ]);

            MarkerPoints::create([
                'subject_id'      => $taxiway_id,
                'order'           => $request['marker_order' . $i],
                'lat'             => $request['marker_latitude' . $i],
                'lng'             => $request['marker_longitude' . $i],
                'height'          => $request['marker_elevation' . $i],
                'subject_type_id' => 8,
            ]);
        }
    }

    public function destroyMarkers(Taxiway $taxiway, Request $request): void
    {
        $this->authorize('airport_delete');

        MarkerPoints::where('subject_id', $taxiway->id)->where('subject_type_id', 8)->delete();
    }

    public function prepareTaxiwayParameters(Taxiway $taxiway, ?int $opt = null): array
    {
        $system              = System::where('name', 'Taxiway Pavement')->first();
        $taxiway_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system->id)->get();

        if ($opt) {
            return $taxiway_task_type_id->map(fn($t) => TaskType::find($t->task_type_id))->filter()->values()->all();
        }

        $parameters = [];
        foreach ($taxiway_task_type_id as $taxiway_task_type) {
            $parameter_type_rows = DB::table('parameter_type_task_type')->where('task_type_id', $taxiway_task_type->task_type_id)->get();
            foreach ($parameter_type_rows as $parameter_type) {
                $parameters[] = [
                    'task_type'      => TaskType::find($parameter_type->task_type_id),
                    'parameter_type' => DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first(),
                    'value'          => Parameter::where([
                        'parameter_type_id' => $parameter_type->parameter_type_id,
                        'subject_id'        => $taxiway->id,
                        'task_type_id'      => $parameter_type->task_type_id,
                    ])->first(),
                ];
            }
        }

        return $parameters;
    }

    public function storeTaxiwayParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key !== '0') continue;

            $taxiwayId = Taxiway::where('airport_id', $request['airport_id'])->where('name', $request['name'])->value('id');

            Parameter::where('subject_id', $request['taxiway_id'])->where('parameter_type_id', 11)->where('task_type_id', 35)->delete();

            Parameter::create([
                'subject_type_id'   => 8,
                'subject_id'        => $taxiwayId,
                'parameter_type_id' => 11,
                'task_type_id'      => 35,
                'value'             => $request['0'],
            ]);
        }
    }

    public function updateTaxiwayParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key !== '0') continue;

            $taxiwayId = (int) Taxiway::where('airport_id', $request['airport_id'])->where('name', $request['name'])->value('id');

            Parameter::where('subject_id', $taxiwayId)->where('parameter_type_id', 11)->where('task_type_id', 35)->delete();

            Parameter::create([
                'subject_type_id'   => 8,
                'subject_id'        => $taxiwayId,
                'parameter_type_id' => 11,
                'task_type_id'      => 35,
                'value'             => $request['0'],
            ]);
        }
    }
}
