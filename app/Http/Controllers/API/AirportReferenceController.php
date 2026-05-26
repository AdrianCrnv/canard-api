<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Header;
use App\Models\Reference;
use App\Models\Runway;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class AirportReferenceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airports/{airport}/references/create',
        summary: 'Obtiene los datos necesarios para crear una referencia en un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport References'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso airport_create'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $reference            = new Reference();
        $reference->subject_id = $airport->id;

        $centerLatLng = $this->getCenterMapLatLng($airport);

        return response()->json([
            'reference'                      => $reference,
            'airport'                        => $airport,
            'reference_marker_airport_lat'   => $centerLatLng[0],
            'reference_marker_airport_lng'   => $centerLatLng[1],
        ], 200);
    }

    #[OA\Post(
        path: '/api/airports/{airport}/references',
        summary: 'Crea una nueva referencia para un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport References'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'reference_latitude', 'reference_longitude', 'ort_reference_elevation'],
                properties: [
                    new OA\Property(property: 'name',                    type: 'string',  maxLength: 255),
                    new OA\Property(property: 'description',             type: 'string',  maxLength: 255, nullable: true),
                    new OA\Property(property: 'reference_latitude',      type: 'number',  minimum: -90,   maximum: 90),
                    new OA\Property(property: 'reference_longitude',     type: 'number',  minimum: -180,  maximum: 180),
                    new OA\Property(property: 'ort_reference_elevation', type: 'number',  minimum: 0,     maximum: 9999.999),
                    new OA\Property(property: 'survey_date',             type: 'string',  format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Referencia creada correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear la referencia'),
        ]
    )]
    public function store(Request $request, Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'name' => [
                'required',
                Rule::unique('references')->where(function ($query) {
                    $query->where('subject_id', request('airport_id'))
                        ->where('subject_type_id', 4);
                }),
            ],
            'description'             => 'nullable|string|max:255',
            'reference_latitude'      => 'required|numeric|between:-90,90',
            'reference_longitude'     => 'required|numeric|between:-180,180',
            'ort_reference_elevation' => 'required|numeric|between:0,9999.999',
            'survey_date'             => 'nullable|date',
        ]);

        if ($request['survey_date']) {
            $request['survey_date'] = Carbon::parse($request['survey_date']);
        }

        try {
            $reference = Reference::create([
                'subject_id'          => $request['subject_id'],
                'subject_type_id'     => 4,
                'name'                => $request['name'],
                'description'         => $request['description'],
                'reference_latitude'  => $request['reference_latitude'],
                'reference_longitude' => $request['reference_longitude'],
                'reference_elevation' => $request['ort_reference_elevation'],
                'survey_date'         => $request['survey_date'],
            ]);

            ActivityLog::log('create', 'Airport', (int) $request['subject_id'], "New reference '{$request['name']}' at airport #{$request['subject_id']}");

            return response()->json([
                'message' => 'Reference created successfully',
                'data'    => $reference,
            ], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'message' => 'Ha ocurrido un error inesperado. Por favor, inténtalo de nuevo.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/references/{reference}/edit',
        summary: 'Obtiene los datos de una referencia para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Airport References'],
        parameters: [
            new OA\Parameter(name: 'reference', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos de la referencia y aeropuerto asociado'),
            new OA\Response(response: 403, description: 'Sin permiso airport_edit'),
            new OA\Response(response: 404, description: 'Referencia no encontrada'),
        ]
    )]
    public function edit(Reference $reference): JsonResponse
    {
        $this->authorize('airport_edit');

        $airport = Airport::where('id', $reference->subject_id)->first();

        return response()->json([
            'reference'                    => $reference,
            'airport'                      => $airport,
            'reference_marker_airport_lat' => $reference->reference_latitude,
            'reference_marker_airport_lng' => $reference->reference_longitude,
        ], 200);
    }

    #[OA\Put(
        path: '/api/references/{reference}',
        summary: 'Actualiza una referencia de aeropuerto existente',
        security: [['bearerAuth' => []]],
        tags: ['Airport References'],
        parameters: [
            new OA\Parameter(name: 'reference', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject_id', 'name', 'reference_latitude', 'reference_longitude', 'ort_reference_elevation'],
                properties: [
                    new OA\Property(property: 'subject_id',              type: 'integer'),
                    new OA\Property(property: 'name',                    type: 'string',  maxLength: 255),
                    new OA\Property(property: 'description',             type: 'string',  maxLength: 255, nullable: true),
                    new OA\Property(property: 'reference_latitude',      type: 'number',  minimum: -90,   maximum: 90),
                    new OA\Property(property: 'reference_longitude',     type: 'number',  minimum: -180,  maximum: 180),
                    new OA\Property(property: 'ort_reference_elevation', type: 'number',  minimum: 0,     maximum: 9999.999),
                    new OA\Property(property: 'survey_date',             type: 'string',  format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Referencia actualizada correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_edit'),
            new OA\Response(response: 404, description: 'Referencia no encontrada'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al actualizar la referencia'),
        ]
    )]
    public function update(Request $request, Reference $reference): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'subject_id' => 'required',
            'name'       => [
                'required',
                Rule::unique('references')->ignore($reference->id)->where(function ($query) {
                    $query->where('subject_id', request('subject_id'))
                        ->where('subject_type_id', 4);
                }),
            ],
            'description'             => 'nullable|string|max:255',
            'reference_latitude'      => 'required|numeric|between:-90,90',
            'reference_longitude'     => 'required|numeric|between:-180,180',
            'ort_reference_elevation' => 'required|numeric|between:0,9999.999',
            'survey_date'             => 'date',
        ]);

        if ($request['survey_date']) {
            $request['survey_date'] = Carbon::parse($request['survey_date']);
        }

        try {
            $before = [
                'Name'        => $reference->name,
                'Description' => $reference->description,
                'Latitude'    => $reference->reference_latitude,
                'Longitude'   => $reference->reference_longitude,
                'Elevation'   => $reference->reference_elevation,
                'Survey date' => $reference->survey_date,
            ];

            $reference->subject_id          = $request['subject_id'];
            $reference->name                = $request['name'];
            $reference->description         = $request['description'];
            $reference->reference_latitude  = $request['reference_latitude'];
            $reference->reference_longitude = $request['reference_longitude'];
            $reference->reference_elevation = $request['ort_reference_elevation'];
            $reference->survey_date         = $request['survey_date'];

            $reference->save();

            $after = [
                'Name'        => $reference->name,
                'Description' => $reference->description,
                'Latitude'    => $reference->reference_latitude,
                'Longitude'   => $reference->reference_longitude,
                'Elevation'   => $reference->reference_elevation,
                'Survey date' => $reference->survey_date,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $description = "Updated reference '{$reference->name}' at airport #{$request['subject_id']}"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['subject_id'], $description);

            return response()->json([
                'message' => 'Reference updated successfully',
                'data'    => $reference,
            ], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}/references',
        summary: 'Lista todas las referencias de un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport References'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de referencias del aeropuerto'),
            new OA\Response(response: 403, description: 'Sin permiso airport_view'),
        ]
    )]
    public function getReferencesInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $references = Reference::where('subject_id', $airport->id)
            ->where('subject_type_id', 4)
            ->get();

        return response()->json(['data' => $references], 200);
    }

    /**
     * Calcula el punto central del mapa basándose en los umbrales de la primera pista del aeropuerto.
     *
     * ⚠️ Bug preexistente: $sort_headers, $location_header1, $location_thr_limit_1,
     * $location_header2, $location_thr_limit_2 se calculan pero nunca se utilizan.
     */
    private function getCenterMapLatLng(Airport $airport): array
    {
        $runway  = Runway::where('airport_id', $airport->id)->first();
        $headers = Header::where('runway_id', $runway->id)->get();

        $headers = $headers->sortBy('bearing');

        // ⚠️ $sort_headers se construye pero nunca se utiliza
        $sort_headers = [];
        $i            = 0;
        foreach ($headers as $key => $header) {
            $sort_headers[$i] = $header;
            $i++;
        }

        $header_threshold_lat_1 = $headers[0]->threshold_latitude;
        $header_threshold_lng_1 = $headers[0]->threshold_longitude;
        // ⚠️ Variables JS-string no utilizadas (artefacto de la vista anterior)
        $location_header1   = "{lat: " . $header_threshold_lat_1 . ", lng: " . $header_threshold_lng_1 . "}";
        $thr_rwy_limit_lat_1 = $headers[0]->thr_rwy_limit_latitude;
        $thr_rwy_limit_lng_1 = $headers[0]->thr_rwy_limit_longitude;
        if ($thr_rwy_limit_lat_1 == null && $thr_rwy_limit_lng_1 == null) {
            $location_thr_limit_1 = "null";
        } else {
            $location_thr_limit_1 = "{lat: " . $thr_rwy_limit_lat_1 . ", lng: " . $thr_rwy_limit_lng_1 . "}";
        }

        $header_threshold_lat_2 = $headers[1]->threshold_latitude;
        $header_threshold_lng_2 = $headers[1]->threshold_longitude;
        $location_header2   = "{lat: " . $header_threshold_lat_2 . ", lng: " . $header_threshold_lng_2 . "}";
        $thr_rwy_limit_lat_2 = $headers[1]->thr_rwy_limit_latitude;
        $thr_rwy_limit_lng_2 = $headers[1]->thr_rwy_limit_longitude;
        if ($thr_rwy_limit_lat_2 == null && $thr_rwy_limit_lng_2 == null) {
            $location_thr_limit_2 = "null";
        } else {
            $location_thr_limit_2 = "{lat: " . $thr_rwy_limit_lat_2 . ", lng: " . $thr_rwy_limit_lng_2 . "}";
        }

        $reference_marker_airport_lat = ($header_threshold_lat_1 + $header_threshold_lat_2) / 2;
        $reference_marker_airport_lng = ($header_threshold_lng_1 + $header_threshold_lng_2) / 2;

        return [$reference_marker_airport_lat, $reference_marker_airport_lng];
    }
}
