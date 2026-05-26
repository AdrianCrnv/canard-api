<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Country;
use App\Models\Header;
use App\Models\RunwayComposition;
use App\Models\Runway;
use App\Models\RwyElevationProfile;
use App\Models\Stretch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class RunwayController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/runways/form-data',
        summary: 'Get data needed to build the create runway form',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Airport, countries and compositions'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        return response()->json([
            'airport'      => $airport,
            'countries'    => Country::getCountriesWithAirportsToOperator(),
            'compositions' => RunwayComposition::all(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/runways',
        summary: 'Create a runway with its two headers and default stretches',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        responses: [
            new OA\Response(response: 201, description: 'Runway created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'airport_id_hidden' => 'required',
            'name'              => [
                'required',
                Rule::unique('runways')->where(fn($q) => $q->where('airport_id', $request['airport_id_hidden'])),
            ],
            'length'         => 'required|numeric|between:0,999999.99',
            'width'          => 'nullable|numeric|between:0,999999.99',
            'composition_id' => 'required',
        ]);

        $request->validate([
            'bearing'                        => 'required_without:auto_bearing|numeric|between:0,360',
            'threshold_latitude_header1'     => 'nullable|numeric|between:-90,90',
            'threshold_longitude_header1'    => 'nullable|numeric|between:-180,180',
            'threshold_elevation_header1'    => 'nullable|numeric|between:0,9999.999',
            'thr_rwy_limit_latitude_header1' => 'nullable|numeric|between:-90,90',
            'thr_rwy_limit_longitude_header1'=> 'nullable|numeric|between:-180,180',
            'thr_rwy_limit_elevation_header1'=> 'nullable|numeric|between:0,9999.999',
        ]);

        $request->validate([
            'bearing2'                       => 'required_without:auto_bearing|numeric|between:0,360',
            'threshold_latitude_header2'     => 'nullable|numeric|between:-90,90',
            'threshold_longitude_header2'    => 'nullable|numeric|between:-180,180',
            'threshold_elevation_header2'    => 'nullable|numeric|between:0,9999.999',
            'thr_rwy_limit_latitude_header2' => 'nullable|numeric|between:-90,90',
            'thr_rwy_limit_longitude_header2'=> 'nullable|numeric|between:-180,180',
            'thr_rwy_limit_elevation_header2'=> 'nullable|numeric|between:0,9999.999',
        ]);

        try {
            $runway = Runway::create([
                'airport_id'     => $request['airport_id_hidden'],
                'name'           => $request['name'],
                'length'         => $request['length'],
                'width'          => $request['width'],
                'composition_id' => $request['composition_id'],
            ]);

            Header::create([
                'runway_id'              => $runway->id,
                'name'                   => $request['nameHeader'],
                'bearing'                => $request['bearing'],
                'threshold_latitude'     => $request['threshold_latitude_header1'],
                'threshold_longitude'    => $request['threshold_longitude_header1'],
                'threshold_elevation'    => $request['threshold_elevation_header1'],
                'thr_rwy_limit_latitude' => $request['thr_rwy_limit_latitude_header1'],
                'thr_rwy_limit_longitude'=> $request['thr_rwy_limit_longitude_header1'],
                'thr_rwy_limit_elevation'=> $request['thr_rwy_limit_elevation_header1'],
            ]);

            Header::create([
                'runway_id'              => $runway->id,
                'name'                   => $request['nameHeader2'],
                'bearing'                => $request['bearing2'],
                'threshold_latitude'     => $request['threshold_latitude_header2'],
                'threshold_longitude'    => $request['threshold_longitude_header2'],
                'threshold_elevation'    => $request['threshold_elevation_header2'],
                'thr_rwy_limit_latitude' => $request['thr_rwy_limit_latitude_header2'],
                'thr_rwy_limit_longitude'=> $request['thr_rwy_limit_longitude_header2'],
                'thr_rwy_limit_elevation'=> $request['thr_rwy_limit_elevation_header2'],
            ]);

            $stretchBase = [
                'subject_id'                  => $runway->id,
                'order'                       => 0,
                'name'                        => 'A',
                'start_thr'                   => 1,
                'end_thr'                     => 1,
                'distance_to_rwy_limit_start' => 0,
                'distance_to_rwy_limit_end'   => $request['length'],
                'start_lat'                   => $request['threshold_latitude_header1'],
                'start_lon'                   => $request['threshold_longitude_header1'],
                'start_elevation'             => $request['threshold_elevation_header1'],
                'end_lat'                     => $request['threshold_latitude_header2'],
                'end_lon'                     => $request['threshold_longitude_header2'],
                'end_elevation'               => $request['threshold_elevation_header2'],
            ];

            foreach ([5, 1, 3, 4] as $stretchType) {
                Stretch::create(array_merge($stretchBase, ['stretch_type' => $stretchType]));
            }

            $airport = Airport::find($request['airport_id_hidden']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id_hidden'], "New airside system: Runway '{$runway->name}' at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($runway->load('headers'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/runways/{runway}',
        summary: 'Get runway data with headers and map coordinates',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runway data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Runway $runway): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildRunwayPayload($runway));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/runways/{runway}/edit-data',
        summary: 'Get runway data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runway with countries, compositions and header coordinates'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Runway $runway): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json(array_merge(
            $this->buildRunwayPayload($runway),
            [
                'countries'    => Country::all(),
                'compositions' => RunwayComposition::all(),
            ]
        ));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/runways/{runway}',
        summary: 'Update runway details and elevation profile',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runway updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Runway $runway): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'airport_id_hidden' => 'required',
            'name'              => [
                'required',
                Rule::unique('runways')->ignore($runway->id)->where(fn($q) => $q->where('airport_id', $request['airport_id'])),
            ],
            'length'         => 'required',
            'width'          => 'nullable|numeric|between:0,999999.99',
            'composition_id' => 'required',
        ]);

        try {
            $this->storeRwyProfile($request, $runway);

            $changes = [];
            if ($runway->name !== $request['name'])
                $changes[] = "Name: '{$runway->name}' → '{$request['name']}'";
            if ((string) $runway->length !== (string) $request['length'])
                $changes[] = "Length: '{$runway->length}' → '{$request['length']}'";
            if ((string) $runway->width !== (string) $request['width'])
                $changes[] = "Width: '{$runway->width}' → '{$request['width']}'";

            $runway->airport_id     = $request['airport_id_hidden'];
            $runway->name           = $request['name'];
            $runway->length         = $request['length'];
            $runway->width          = $request['width'];
            $runway->composition_id = $request['composition_id'];
            $runway->save();

            if (!empty($changes)) {
                $airport = Airport::find($request['airport_id_hidden']);
                ActivityLog::log('update', 'Airport', (int) $request['airport_id_hidden'], "Updated airside system: Runway '{$runway->name}' at airport '{$airport->name}' ({$airport->icao_code}): " . implode(', ', $changes));
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($runway->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/runways/{runway}',
        summary: 'Delete a runway and its headers',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runway deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Runway $runway): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $airport = Airport::find($runway->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $runway->airport_id, "Deleted airside system: Runway '{$runway->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            Header::where('runway_id', $runway->id)->delete();
            $runway->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Runway deleted successfully.']);
    }

    // ── Runways in airport ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/runways',
        summary: 'Get all runways for an airport',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of runways'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getRunwaysInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json($airport->runways);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/runways/operation',
        summary: 'Get runways for an airport in operation creation context',
        security: [['bearerAuth' => []]],
        tags: ['Runways'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of runways'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getRunwaysInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        return response()->json($airport->runways);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildRunwayPayload(Runway $runway): array
    {
        $headers = Header::where('runway_id', $runway->id)->get();

        $reference_marker_airport_lat = null;
        $reference_marker_airport_lng = null;
        $location_header1      = null;
        $location_thr_limit_1  = null;
        $location_header2      = null;
        $location_thr_limit_2  = null;

        if (!$headers->isEmpty() && $headers->count() >= 2) {
            $location_header1 = ['lat' => $headers[0]->threshold_latitude, 'lng' => $headers[0]->threshold_longitude];
            $location_header2 = ['lat' => $headers[1]->threshold_latitude, 'lng' => $headers[1]->threshold_longitude];

            $location_thr_limit_1 = ($headers[0]->thr_rwy_limit_latitude !== null && $headers[0]->thr_rwy_limit_longitude !== null)
                ? ['lat' => $headers[0]->thr_rwy_limit_latitude, 'lng' => $headers[0]->thr_rwy_limit_longitude]
                : null;

            $location_thr_limit_2 = ($headers[1]->thr_rwy_limit_latitude !== null && $headers[1]->thr_rwy_limit_longitude !== null)
                ? ['lat' => $headers[1]->thr_rwy_limit_latitude, 'lng' => $headers[1]->thr_rwy_limit_longitude]
                : null;

            $reference_marker_airport_lat = ($headers[0]->threshold_latitude + $headers[1]->threshold_latitude) / 2;
            $reference_marker_airport_lng = ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2;
        }

        return [
            'runway'                       => $runway,
            'headers'                      => $headers,
            'reference_marker_airport_lat' => $reference_marker_airport_lat,
            'reference_marker_airport_lng' => $reference_marker_airport_lng,
            'location_header1'             => $location_header1,
            'location_thr_limit_1'         => $location_thr_limit_1,
            'location_header2'             => $location_header2,
            'location_thr_limit_2'         => $location_thr_limit_2,
        ];
    }

    public function storeRwyProfile(Request $request, Runway $runway): void
    {
        if ($runway->vertices->count() !== 0) {
            $runway->vertices->each(fn($vert) => $vert->delete());
        }

        $firstHeader = $runway->headers->sortBy('bearing')->first();

        $inputs = collect($request->request)->except(['_method', '_token', 'airport_id_hidden', 'name', 'length', 'composition_id']);
        $inputS = $inputs->filter(fn($value, $name) =>
            Str::startsWith($name, 'inputCopy') || Str::startsWith($name, 'inputThr')
        );

        $latitudeFirstHeader  = $firstHeader->thr_rwy_limit_latitude ?? $firstHeader->threshold_latitude;
        $longitudeFirstHeader = $firstHeader->thr_rwy_limit_longitude ?? $firstHeader->threshold_longitude;
        $bearingFirstHeader   = $firstHeader->bearing;
        $realSize             = $inputS->count() / 2;

        for ($i = 1; $i <= $realSize; $i++) {
            $intervalo    = 'inputCopy' . $i;
            $theElevation = 'inputThr' . $i;
            $coords       = $this->getLatitudeLongitude($latitudeFirstHeader, $longitudeFirstHeader, $inputS[$intervalo], $bearingFirstHeader);

            RwyElevationProfile::create([
                'rwy_id'             => $runway->id,
                'distance_rwy_limit' => $inputS[$intervalo],
                'elevation'          => $inputS[$theElevation],
                'latitude'           => $coords->get('latitude'),
                'longitude'          => $coords->get('longitude'),
            ]);
        }
    }

    public function getLatitudeLongitude(float $latitudeFirstHeader, float $longitudeFirstHeader, float $intervalo, float $bearingFirstHeader): Collection
    {
        $earthRadius  = 6378.14;
        $newdistance  = $intervalo / 1000;
        $radLat       = $latitudeFirstHeader * (M_PI / 180);
        $radLon       = $longitudeFirstHeader * (M_PI / 180);
        $radBearing   = $bearingFirstHeader * (M_PI / 180);

        $latitude  = asin(sin($radLat) * cos($newdistance / $earthRadius) + cos($radLat) * sin($newdistance / $earthRadius) * cos($radBearing));
        $longitude = $radLon + atan2(sin($radBearing) * sin($newdistance / $earthRadius) * cos($radLat), cos($newdistance / $earthRadius) - sin($radLat) * sin($latitude));

        return collect([
            'latitude'  => $latitude * (180 / M_PI),
            'longitude' => $longitude * (180 / M_PI),
        ]);
    }
}
