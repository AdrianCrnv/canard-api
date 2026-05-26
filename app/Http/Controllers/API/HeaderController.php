<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\Airport;
use App\Country;
use App\Header;
use App\Runway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class HeaderController extends Controller
{
    #[OA\Get(
        path: '/api/runways/{runway}/headers/create',
        summary: 'Get data needed to create a new Header (countries, airport, runway)',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Creation form data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function create(Runway $runway): JsonResponse
    {
        $this->authorize('airport_create');

        return response()->json([
            'runway'    => $runway,
            'airport'   => $runway->airport,
            'countries' => Country::getCountriesWithAirports(),
        ]);
    }

    #[OA\Post(
        path: '/api/headers',
        summary: 'Create a new Header',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        responses: [
            new OA\Response(response: 201, description: 'Header created'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error or duplicate name'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        if (!$request['bearing']) {
            $request['bearing'] = 0;
        }

        $request->validate([
            'name'                        => 'required',
            'bearing'                     => 'required|numeric|between:0,360',
            'threshold_latitude'          => 'required|numeric|between:-90,90',
            'threshold_longitude'         => 'required|numeric|between:-180,180',
            'ort_threshold_elevation'     => 'required|numeric|between:0,9999.999',
            'thr_rwy_limit_latitude'      => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:-90,90',
            'thr_rwy_limit_longitude'     => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:-180,180',
            'ort_thr_rwy_limit_elevation' => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:0,9999.999',
        ]);

        $existingHeader = DB::table('headers')
            ->join('runways', 'headers.runway_id', '=', 'runways.id')
            ->where('headers.name', $request['name'])
            ->where('runways.airport_id', $request['airport_id'])
            ->exists();

        if ($existingHeader) {
            Runway::where('id', $request['runway_id'])->delete();

            throw ValidationException::withMessages([
                'name' => 'There is already a gateway with the same name at this airport.',
            ]);
        }

        try {
            $newHeader = Header::create([
                'runway_id'                   => $request['runway_id'],
                'name'                        => $request['name'],
                'bearing'                     => $request['bearing'],
                'threshold_latitude'          => $request['threshold_latitude'],
                'threshold_longitude'         => $request['threshold_longitude'],
                'ort_threshold_elevation'     => $request['ort_threshold_elevation'],
                'thr_rwy_limit_latitude'      => $request['thr_rwy_limit_latitude'],
                'thr_rwy_limit_longitude'     => $request['thr_rwy_limit_longitude'],
                'ort_thr_rwy_limit_elevation' => $request['ort_thr_rwy_limit_elevation'],
            ]);

            if ($request['update_opposite']) {
                $oppositeHeader = $newHeader->getOpposite();
                if ($oppositeHeader) {
                    $oppositeHeader->bearing = $request['bearing_opposite'];
                    $oppositeHeader->save();
                }
            }

            $airport = Airport::find($request['airport_id']);
            $runway  = Runway::find($request['runway_id']);
            ActivityLog::log('create', 'Airport', $request['airport_id'],
                "New header '{$request['name']}' created on runway '" . ($runway?->name ?? $request['runway_id']) . "' at airport '" . ($airport?->icao ?? $request['airport_id']) . "' by '" . Auth::user()->name . "'");

            return response()->json(['message' => 'Header created', 'header' => $newHeader], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', $request['airport_id'], "Error creating header: " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while creating the header.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/headers/{header}',
        summary: 'Get Header details (read-only)',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Header data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function show(Header $header): JsonResponse
    {
        $this->authorize('airport_edit');

        $runway  = Runway::find($header->runway_id);
        $airport = $runway->airport;

        return response()->json([
            'header'    => $header,
            'runway'    => $runway,
            'airport'   => $airport,
            'countries' => Country::getCountriesWithAirports(),
        ]);
    }

    #[OA\Get(
        path: '/api/headers/{header}/edit',
        summary: 'Get Header data for editing',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Edit form data returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function edit(Header $header): JsonResponse
    {
        $this->authorize('airport_edit');

        $runway  = Runway::find($header->runway_id);
        $airport = $runway->airport;

        return response()->json([
            'header'    => $header,
            'runway'    => $runway,
            'airport'   => $airport,
            'countries' => Country::getCountriesWithAirports(),
        ]);
    }

    #[OA\Put(
        path: '/api/headers/{header}',
        summary: 'Update a Header',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Header updated'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation error or duplicate name'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function update(Request $request, Header $header): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'name'                        => 'required',
            'bearing'                     => 'required|numeric|between:0,360',
            'threshold_latitude'          => 'required|numeric|between:-90,90',
            'threshold_longitude'         => 'required|numeric|between:-180,180',
            'ort_threshold_elevation'     => 'required|numeric|between:0,9999.999',
            'thr_rwy_limit_latitude'      => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:-90,90',
            'thr_rwy_limit_longitude'     => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:-180,180',
            'ort_thr_rwy_limit_elevation' => 'required_if:has_displaced_threshold,"true"|nullable|numeric|between:0,9999.999',
        ]);

        $existingHeader = DB::table('headers')
            ->join('runways', 'headers.runway_id', '=', 'runways.id')
            ->where('headers.name', $request['name'])
            ->where('runways.airport_id', $header->runway->airport->id)
            ->where('headers.id', '!=', $header->id)
            ->exists();

        if ($existingHeader) {
            throw ValidationException::withMessages([
                'name' => 'There is already a gateway with the same name at this airport.',
            ]);
        }

        $airport_id     = $header->runway->airport->id;
        $runway         = Runway::find($header->runway_id);
        $oppositeHeader = $header->getOpposite();

        $before = [
            'name'                   => $header->name,
            'bearing'                => $header->bearing,
            'threshold_latitude'     => $header->threshold_latitude,
            'threshold_longitude'    => $header->threshold_longitude,
            'threshold_elevation'    => $header->threshold_elevation,
            'thr_rwy_limit_latitude' => $header->thr_rwy_limit_latitude,
            'thr_rwy_limit_longitude' => $header->thr_rwy_limit_longitude,
            'thr_rwy_limit_elevation' => $header->thr_rwy_limit_elevation,
            'runway_length'          => $runway?->length,
            'opposite_bearing'       => $oppositeHeader?->bearing,
        ];

        try {
            $header->name                     = $request['name'];
            $header->bearing                  = $request['bearing'];
            $header->threshold_latitude       = $request['threshold_latitude'];
            $header->threshold_longitude      = $request['threshold_longitude'];
            $header->threshold_elevation      = $request['ort_threshold_elevation'];
            $header->thr_rwy_limit_latitude   = $request['thr_rwy_limit_latitude'];
            $header->thr_rwy_limit_longitude  = $request['thr_rwy_limit_longitude'];
            $header->thr_rwy_limit_elevation  = $request['ort_thr_rwy_limit_elevation'];
            $header->save();

            if ($oppositeHeader) {
                $oppositeHeader->bearing = $request['bearing_opposite'];
                $oppositeHeader->save();
            }

            if ($runway) {
                $runway->length = $request['length'];
                $runway->save();
            }

            $after = [
                'name'                   => $header->name,
                'bearing'                => $header->bearing,
                'threshold_latitude'     => $header->threshold_latitude,
                'threshold_longitude'    => $header->threshold_longitude,
                'threshold_elevation'    => $header->threshold_elevation,
                'thr_rwy_limit_latitude' => $header->thr_rwy_limit_latitude,
                'thr_rwy_limit_longitude' => $header->thr_rwy_limit_longitude,
                'thr_rwy_limit_elevation' => $header->thr_rwy_limit_elevation,
                'runway_length'          => $runway?->length,
                'opposite_bearing'       => $oppositeHeader?->bearing,
            ];

            $changes = [];
            foreach ($after as $field => $newVal) {
                if ((string) $before[$field] !== (string) $newVal) {
                    $changes[] = "$field: '{$before[$field]}' → '$newVal'";
                }
            }

            $description = "Header '{$header->name}' updated at airport '" . ($header->runway->airport->icao ?? $airport_id) . "' by '" . Auth::user()->name . "'";
            if (!empty($changes)) {
                $description .= '. Changes: ' . implode(', ', $changes);
            }

            ActivityLog::log('update', 'Airport', $airport_id, $description);

            return response()->json(['message' => 'Header updated', 'header' => $header]);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', $airport_id, "Error updating header: " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while updating the header.'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/headers/{header}',
        summary: 'Delete a Header',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Header deleted'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Unexpected error'),
        ]
    )]
    public function destroy(Header $header): JsonResponse
    {
        $this->authorize('airport_delete');

        $headerName  = $header->name;
        $airport     = $header->runway->airport;
        $airport_id  = $airport->id;
        $airportIcao = $airport->icao ?? $airport_id;

        try {
            $header->delete();
            ActivityLog::log('delete', 'Airport', $airport_id,
                "Header '{$headerName}' deleted at airport '{$airportIcao}' by '" . Auth::user()->name . "'");

            return response()->json(['message' => 'Header deleted']);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', $airport_id, "Error deleting header '{$headerName}': " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while deleting the header.'], 500);
        }
    }

    // =========================================================================
    // QUERY ENDPOINTS
    // =========================================================================

    #[OA\Get(
        path: '/api/runways/{runway}/headers',
        summary: 'Get all Headers for a Runway',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'runway', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersInRunway(Runway $runway): JsonResponse
    {
        $this->authorize('airport_view');
        return response()->json($runway->headers);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers',
        summary: 'Get all Headers for an Airport',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $headers = [];
        foreach ($airport->runways as $runway) {
            foreach ($runway->headers as $header) {
                $headers[] = $header;
            }
        }

        return response()->json($headers);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-papis',
        summary: 'Get Headers that have PAPIs for an Airport',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with PAPIs returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithPapisInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');
        return response()->json($this->filterHeadersByRelation($airport, 'papis', true));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-papis/operation',
        summary: 'Get Headers that have PAPIs for an Airport (operation context)',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with PAPIs returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithPapisInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');
        return response()->json($this->filterHeadersByRelation($airport, 'papis', true));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-ils',
        summary: 'Get Headers that have ILS for an Airport',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with ILS returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithIlsInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');
        return response()->json($this->filterHeadersByRelation($airport, 'ils'));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-ils/operation',
        summary: 'Get Headers that have ILS for an Airport (operation context)',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with ILS returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithIlsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');
        return response()->json($this->filterHeadersByRelation($airport, 'ils'));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-als',
        summary: 'Get Headers that have ALS for an Airport',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with ALS returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithAlsInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');
        return response()->json($this->filterHeadersByRelation($airport, 'als'));
    }

    #[OA\Get(
        path: '/api/airports/{airport}/headers/with-als/operation',
        summary: 'Get Headers that have ALS for an Airport (operation context)',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Headers with ALS returned'),
            new OA\Response(response: 403, description: 'Unauthorized'),
        ]
    )]
    public function getHeadersWithAlsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');
        return response()->json($this->filterHeadersByRelation($airport, 'als'));
    }

    #[OA\Get(
        path: '/api/headers/{headerId}/with-runway',
        summary: 'Get a Header with its associated Runway',
        security: [['bearerAuth' => []]],
        tags: ['Header'],
        parameters: [
            new OA\Parameter(name: 'headerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Header and Runway returned'),
            new OA\Response(response: 404, description: 'Header not found'),
        ]
    )]
    public function getHeader($headerId): JsonResponse
    {
        $header = Header::find($headerId);

        if (!$header) {
            return response()->json(['error' => 'Header not found'], 404);
        }

        $runway = Runway::find($header->runway_id);

        return response()->json(['header' => $header, 'runway' => $runway]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Filter headers in an airport by the presence of a relation.
     * $useCount = true for hasMany (papis), false for hasOne (ils, als).
     */
    private function filterHeadersByRelation(Airport $airport, string $relation, bool $useCount = false): array
    {
        $headers = [];

        foreach ($airport->runways as $runway) {
            foreach ($runway->headers as $header) {
                $has = $useCount ? $header->{$relation}->count() > 0 : (bool) $header->{$relation};
                if ($has) {
                    $headers[] = $header;
                }
            }
        }

        return $headers;
    }
}
