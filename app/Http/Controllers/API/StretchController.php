<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Header;
use App\Models\Runway;
use App\Models\Stretch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StretchController extends Controller
{
    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/runways/{runway}/stretches/{stretch_type}',
        summary: 'Get stretches and map data for a runway/stretch-type combination',
        security: [['bearerAuth' => []]],
        tags: ['Stretches'],
        parameters: [
            new OA\Parameter(name: 'runway',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'stretch_type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stretches with runway and header data'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(Runway $runway, int $stretch_type): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildPayload($runway, $stretch_type));
    }

    // ── Edit data ─────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/runways/{runway}/stretches/{stretch_type}/edit-data',
        summary: 'Get stretches and form data for editing a runway stretch type',
        security: [['bearerAuth' => []]],
        tags: ['Stretches'],
        parameters: [
            new OA\Parameter(name: 'runway',       in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'stretch_type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stretches with runway and header data'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function edit(Runway $runway, int $stretch_type): JsonResponse
    {
        $this->authorize('airport_edit');

        return response()->json($this->buildPayload($runway, $stretch_type));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/stretches',
        summary: 'Replace all stretches of a given type for a runway',
        security: [['bearerAuth' => []]],
        tags: ['Stretches'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['json'],
                properties: [
                    new OA\Property(property: 'json', type: 'string', description: 'JSON-encoded array of stretch objects'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stretches saved'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Invalid JSON payload'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $jsonDef = json_decode($request->json);

        if (!$jsonDef || !is_array($jsonDef) || count($jsonDef) === 0) {
            return response()->json(['message' => 'Invalid JSON payload.'], 422);
        }

        Stretch::where('subject_id', $jsonDef[0]->subject_id)
            ->where('stretch_type', $jsonDef[0]->stretch_type)
            ->delete();

        foreach ($jsonDef as $item) {
            Stretch::create([
                'stretch_type'                => $item->stretch_type,
                'subject_id'                  => $item->subject_id,
                'order'                       => $item->order,
                'name'                        => $item->name,
                'start_thr'                   => $item->start_thr,
                'end_thr'                     => $item->end_thr,
                'distance_to_rwy_limit_start' => $item->distance_to_rwy_limit_start,
                'distance_to_rwy_limit_end'   => $item->distance_to_rwy_limit_end,
                'start_lat'                   => $item->start_lat,
                'start_lon'                   => $item->start_lon,
                'start_elevation'             => $item->start_elevation,
                'end_lat'                     => $item->end_lat,
                'end_lon'                     => $item->end_lon,
                'end_elevation'               => $item->end_elevation,
            ]);
        }

        return response()->json(['message' => 'Stretches saved successfully.'], 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildPayload(Runway $runway, int $stretch_type): array
    {
        $headers = Header::where('runway_id', $runway->id)->get()->sortBy('bearing')->values();

        $location_header1     = ['lat' => $headers[0]->threshold_latitude,    'lng' => $headers[0]->threshold_longitude];
        $location_header2     = ['lat' => $headers[1]->threshold_latitude,    'lng' => $headers[1]->threshold_longitude];
        $location_thr_limit_1 = ($headers[0]->thr_rwy_limit_latitude !== null && $headers[0]->thr_rwy_limit_longitude !== null)
            ? ['lat' => $headers[0]->thr_rwy_limit_latitude, 'lng' => $headers[0]->thr_rwy_limit_longitude]
            : null;
        $location_thr_limit_2 = ($headers[1]->thr_rwy_limit_latitude !== null && $headers[1]->thr_rwy_limit_longitude !== null)
            ? ['lat' => $headers[1]->thr_rwy_limit_latitude, 'lng' => $headers[1]->thr_rwy_limit_longitude]
            : null;

        return [
            'runway'                       => $runway,
            'airport'                      => Airport::find($runway->airport_id),
            'stretches'                    => Stretch::where('subject_id', $runway->id)->where('stretch_type', $stretch_type)->get(),
            'headers'                      => $headers,
            'stretch_type'                 => $stretch_type,
            'diagramUrl'                   => $runway->getFirstMediaUrl('rwy_lights_diagram'),
            'reference_marker_airport_lat' => ($headers[0]->threshold_latitude + $headers[1]->threshold_latitude) / 2,
            'reference_marker_airport_lng' => ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2,
            'location_header1'             => $location_header1,
            'location_header2'             => $location_header2,
            'location_thr_limit_1'         => $location_thr_limit_1,
            'location_thr_limit_2'         => $location_thr_limit_2,
        ];
    }
}
