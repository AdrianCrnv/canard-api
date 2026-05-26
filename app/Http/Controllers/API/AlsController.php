<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Als;
use App\Models\AlsBar;
use App\Models\AlsBarColor;
use App\Models\AlsBarType;
use App\Models\Header;
use App\Models\Parameter;
use App\Models\Runway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AlsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/als',
        summary: 'Lista todos los sistemas ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        responses: [
            new OA\Response(response: 200, description: 'Listado de sistemas ALS'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_view'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(['data' => Als::all()], 200);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/als/create',
        summary: 'Obtiene los datos necesarios para crear un sistema ALS en un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_create'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('navaid_create');

        $runways                    = Runway::where('airport_id', $airport->id)->get();
        $alt_ellipsoid_coord_offset = $airport->alt_ellipsoid_coord_offset;
        $types                      = AlsBarType::all();
        $colors                     = AlsBarColor::all();

        return response()->json([
            'airport'                     => $airport,
            'runways'                     => $runways,
            'types'                       => $types,
            'colors'                      => $colors,
            'alt_ellipsoid_coord_offset'  => $alt_ellipsoid_coord_offset,
        ], 200);
    }

    #[OA\Post(
        path: '/api/als',
        summary: 'Crea un nuevo sistema ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['header_id', 'length', 'decision_bar_distance', 'flashing_light_count', 'threshold_light_count', 'threshold_distance_to_papi', 'contact_point_elevation', 'distance_to_als', 'velocity', 'bars_data', 'height_low', 'height_mid', 'height_high'],
                properties: [
                    new OA\Property(property: 'header_id',                  type: 'integer'),
                    new OA\Property(property: 'length',                     type: 'number',  minimum: 1),
                    new OA\Property(property: 'decision_bar_distance',      type: 'number',  minimum: 1,   maximum: 32767),
                    new OA\Property(property: 'flashing_light_count',       type: 'number',  minimum: 0),
                    new OA\Property(property: 'threshold_light_count',      type: 'number',  minimum: 1,   maximum: 127),
                    new OA\Property(property: 'threshold_distance_to_papi', type: 'number',  minimum: 1,   maximum: 32767),
                    new OA\Property(property: 'contact_point_elevation',    type: 'number',  minimum: 0),
                    new OA\Property(property: 'distance_to_als',            type: 'number',  minimum: 50,  maximum: 1000),
                    new OA\Property(property: 'velocity',                   type: 'number',  minimum: 1,   maximum: 10),
                    new OA\Property(property: 'height_low',                 type: 'number',  minimum: 0),
                    new OA\Property(property: 'height_mid',                 type: 'number',  minimum: 0),
                    new OA\Property(property: 'height_high',                type: 'number',  minimum: 0),
                    new OA\Property(property: 'bars_data',                  type: 'string',  description: 'JSON array of bar objects'),
                    new OA\Property(property: 'diagram',                    type: 'string',  format: 'binary', nullable: true),
                    new OA\Property(property: 'airport_id',                 type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sistema ALS creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear el sistema ALS'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('navaid_create');

        $request->validate([
            'header_id'                  => 'required|unique:als',
            'length'                     => 'required|numeric|min:1',
            'decision_bar_distance'      => 'required|numeric|min:1|max:32767',
            'flashing_light_count'       => 'required|numeric|min:0',
            'threshold_light_count'      => 'required|numeric|min:1|max:127',
            'threshold_distance_to_papi' => 'required|numeric|min:1|max:32767',
            'contact_point_elevation'    => 'required|numeric|min:0',
            'diagram'                    => 'nullable|mimes:jpeg,jpg,png|max:15360',
            'distance_to_als'            => 'required|numeric|min:50|max:1000',
            'velocity'                   => 'required|numeric|min:1|max:10',
            'bars_data'                  => 'required',
            'height_low'                 => 'required|numeric|min:0',
            'height_mid'                 => 'required|numeric|min:0',
            'height_high'                => 'required|numeric|min:0',
        ], [
            'bars_data.required' => 'The ALS system must have bars',
        ]);

        try {
            $this->storeAlsParameters($request);

            $newAls = Als::create([
                'header_id'                  => $request['header_id'],
                'length'                     => $request['length'],
                'decision_bar_distance'      => $request['decision_bar_distance'],
                'flashing_light_count'       => $request['flashing_light_count'],
                'threshold_light_count'      => $request['threshold_light_count'],
                'threshold_distance_to_papi' => $request['threshold_distance_to_papi'],
                'contact_point_elevation'    => $request['contact_point_elevation'],
            ]);

            if ($request->file('diagram')) {
                $newAls->addMediaFromRequest('diagram')->toMediaCollection('als_diagram', 'public');
            }

            $bars = json_decode($request['bars_data'], true);
            foreach ($bars as $bar) {
                for ($i = 0; $i < $bar['bar_count']; $i++) {
                    AlsBar::create([
                        'als_id'      => $newAls->id,
                        'type_id'     => $bar['type_id'],
                        'color_id'    => $bar['color_id'],
                        'light_count' => $bar['light_count'],
                    ]);
                }
            }

            $airport    = Airport::find($request['airport_id']);
            $headerName = Header::find($request['header_id'])->name ?? 'Header #' . $request['header_id'];
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New system: ALS '{$headerName}' at airport '{$airport->name}' ({$airport->icao_code})");

            return response()->json([
                'message' => 'ALS created successfully',
                'data'    => $newAls,
            ], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/headers/{header}/als',
        summary: 'Lista todos los sistemas ALS de una cabecera',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de sistemas ALS de la cabecera'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_view'),
        ]
    )]
    public function getAlsInHeader(Header $header): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(['data' => Als::where('header_id', $header->id)->get()], 200);
    }

    #[OA\Get(
        path: '/api/als/{als}/edit',
        summary: 'Obtiene los datos de un sistema ALS para su edición',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'als', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del sistema ALS'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_edit'),
            new OA\Response(response: 404, description: 'ALS no encontrado'),
        ]
    )]
    public function edit(Als $als): JsonResponse
    {
        $this->authorize('navaid_edit');

        return response()->json($this->buildAlsPayload($als), 200);
    }

    #[OA\Get(
        path: '/api/als/{als}',
        summary: 'Obtiene el detalle de un sistema ALS',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'als', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del sistema ALS'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_view'),
            new OA\Response(response: 404, description: 'ALS no encontrado'),
        ]
    )]
    public function show(Als $als): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json($this->buildAlsPayload($als), 200);
    }

    #[OA\Put(
        path: '/api/als/{als}',
        summary: 'Actualiza un sistema ALS existente',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'als', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['header_id', 'length', 'decision_bar_distance', 'flashing_light_count', 'threshold_light_count', 'threshold_distance_to_papi', 'contact_point_elevation', 'distance_to_als', 'bars_data', 'height_low', 'height_mid', 'height_high'],
                properties: [
                    new OA\Property(property: 'header_id',                  type: 'integer'),
                    new OA\Property(property: 'length',                     type: 'number',  minimum: 1),
                    new OA\Property(property: 'decision_bar_distance',      type: 'number',  minimum: 1,  maximum: 32767),
                    new OA\Property(property: 'flashing_light_count',       type: 'number',  minimum: 0),
                    new OA\Property(property: 'threshold_light_count',      type: 'number',  minimum: 1,  maximum: 127),
                    new OA\Property(property: 'threshold_distance_to_papi', type: 'number',  minimum: 1,  maximum: 32767),
                    new OA\Property(property: 'contact_point_elevation',    type: 'number',  minimum: 0),
                    new OA\Property(property: 'distance_to_als',            type: 'number',  minimum: 50, maximum: 1000),
                    new OA\Property(property: 'height_low',                 type: 'number',  minimum: 0),
                    new OA\Property(property: 'height_mid',                 type: 'number',  minimum: 0),
                    new OA\Property(property: 'height_high',                type: 'number',  minimum: 0),
                    new OA\Property(property: 'bars_data',                  type: 'string',  description: 'JSON array of bar objects'),
                    new OA\Property(property: 'diagram',                    type: 'string',  format: 'binary', nullable: true),
                    new OA\Property(property: 'airport_id',                 type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sistema ALS actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_edit'),
            new OA\Response(response: 404, description: 'ALS no encontrado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el sistema ALS'),
        ]
    )]
    public function update(Request $request, Als $als): JsonResponse
    {
        $this->authorize('navaid_edit');

        $request->validate([
            'header_id'                  => 'required|unique:als,header_id,' . $als->id,
            'length'                     => 'required|numeric|min:1',
            'decision_bar_distance'      => 'required|numeric|min:1|max:32767',
            'flashing_light_count'       => 'required|numeric|min:0',
            'threshold_light_count'      => 'required|numeric|min:1|max:127',
            'threshold_distance_to_papi' => 'required|numeric|min:1|max:32767',
            'contact_point_elevation'    => 'required|numeric|min:0',
            'diagram'                    => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'distance_to_als'            => 'required|numeric|min:50|max:1000',
            'bars_data'                  => 'required',
            'height_low'                 => 'required|numeric|min:0',
            'height_mid'                 => 'required|numeric|min:0',
            'height_high'                => 'required|numeric|min:0',
        ], [
            'bars_data.required' => 'The ALS system must have bars',
        ]);

        try {
            $before = [
                'Length'                  => $als->length,
                'Decision bar distance'   => $als->decision_bar_distance,
                'Flashing light count'    => $als->flashing_light_count,
                'Threshold light count'   => $als->threshold_light_count,
                'Threshold dist. to PAPI' => $als->threshold_distance_to_papi,
                'Contact point elevation' => $als->contact_point_elevation,
            ];

            $this->updateAlsParameter($request);

            $als->header_id                  = $request['header_id'];
            $als->length                     = $request['length'];
            $als->decision_bar_distance      = $request['decision_bar_distance'];
            $als->flashing_light_count       = $request['flashing_light_count'];
            $als->threshold_light_count      = $request['threshold_light_count'];
            $als->threshold_distance_to_papi = $request['threshold_distance_to_papi'];
            $als->contact_point_elevation    = $request['contact_point_elevation'];
            $als->save();

            if ($request->file('diagram')) {
                $als->clearMediaCollection('als_diagram');
                $als->addMediaFromRequest('diagram')->toMediaCollection('als_diagram', 'public');
            }

            $newBars = json_decode($request['bars_data'], true);
            $als->bars()->delete();
            foreach ($newBars as $bar) {
                for ($i = 0; $i < $bar['bar_count']; $i++) {
                    AlsBar::create([
                        'als_id'      => $als->id,
                        'type_id'     => $bar['type_id'],
                        'color_id'    => $bar['color_id'],
                        'light_count' => $bar['light_count'],
                    ]);
                }
            }

            $after = [
                'Length'                  => $als->length,
                'Decision bar distance'   => $als->decision_bar_distance,
                'Flashing light count'    => $als->flashing_light_count,
                'Threshold light count'   => $als->threshold_light_count,
                'Threshold dist. to PAPI' => $als->threshold_distance_to_papi,
                'Contact point elevation' => $als->contact_point_elevation,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport    = Airport::find($request['airport_id']);
            $headerName = $als->header->name ?? 'Header #' . $als->header_id;
            $description = "Updated system: ALS '{$headerName}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Operation', (int) $request['airport_id'], $description);

            return response()->json([
                'message' => 'ALS updated successfully',
                'data'    => $als,
            ], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/als/{als}',
        summary: 'Elimina un sistema ALS y sus recursos asociados',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'als', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sistema ALS eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso navaid_delete'),
            new OA\Response(response: 404, description: 'ALS no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el sistema ALS'),
        ]
    )]
    public function destroy(Als $als): JsonResponse
    {
        $this->authorize('navaid_delete');

        try {
            $airportId  = $als->header->runway->airport_id;
            $airport    = Airport::find($airportId);
            $headerName = $als->header->name ?? 'Header #' . $als->header_id;
            ActivityLog::log('delete', 'Airport', (int) $airportId, "Deleted system: ALS '{$headerName}' from airport '{$airport->name}' ({$airport->icao_code})");

            $als->clearMediaCollection('als_diagram');
            $als->bars()->delete();
            $als->delete();

            return response()->json(['message' => 'ALS deleted successfully'], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/als/update-papi-contact-point',
        summary: 'Actualiza el contact point elevation del sistema ALS asociado a una cabecera',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['newPc', 'id'],
                properties: [
                    new OA\Property(property: 'newPc', type: 'number'),
                    new OA\Property(property: 'id',    type: 'integer', description: 'header_id'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Contact point actualizado o no encontrado'),
        ]
    )]
    public function updatePapiContactPoint(Request $request): JsonResponse
    {
        $newPapiContactPoint = $request->input('newPc');
        $id                  = $request->input('id');

        $newAlsPc = tap(Als::where('header_id', $id))->update(['contact_point_elevation' => $newPapiContactPoint])->first();

        if ($newAlsPc !== null) {
            return response()->json(['message' => true, 'alsPcUpdated' => $newAlsPc->toJson()], 200);
        }

        return response()->json(['message' => false]);
    }

    #[OA\Get(
        path: '/api/headers/{header}/als/json',
        summary: 'Obtiene el sistema ALS de una cabecera en formato JSON',
        security: [['bearerAuth' => []]],
        tags: ['ALS'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del sistema ALS'),
            new OA\Response(response: 404, description: 'ALS no encontrado para esta cabecera'),
        ]
    )]
    public function getJsonAls(Header $header): JsonResponse
    {
        $als = Als::where('header_id', $header->id)->first();

        if ($als === null) {
            return response()->json(null, 404);
        }

        return response()->json($als);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the shared payload for edit() and show().
     */
    private function buildAlsPayload(Als $als): array
    {
        $header     = $als->header;
        $airport    = $header->runway->airport;
        $bars       = $als->bars()->get();
        $types      = AlsBarType::all();
        $colors     = AlsBarColor::all();
        $diagramUrl = $als->getFirstMediaUrl('als_diagram');
        $parameters = $this->editAlsParameter($header);

        $jsonBars = [];
        foreach ($bars as $bar) {
            $jsonBars[] = [
                'type_id'     => $bar->type_id,
                'type_name'   => AlsBarType::find($bar->type_id)->name,
                'color_id'    => $bar->color_id,
                'color_name'  => AlsBarColor::find($bar->color_id)->name,
                'light_count' => $bar->light_count,
                'bar_count'   => 1,
            ];
        }

        return [
            'als'                        => $als,
            'header'                     => $header,
            'airport'                    => $airport,
            'bars'                       => $bars,
            'types'                      => $types,
            'colors'                     => $colors,
            'diagram_url'                => $diagramUrl,
            'parameters'                 => $parameters,
            'bars_data'                  => $jsonBars,
            'alt_ellipsoid_coord_offset' => $airport->alt_ellipsoid_coord_offset,
        ];
    }

    private function storeAlsParameters(Request $request): void
    {
        $system_id = 2;
        $als_tasks = DB::table('systems_id_task_type_id')->where('system_id', $system_id)->get();

        foreach ($als_tasks as $value) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $value->task_type_id)->get();

            foreach ($parameter_type_id as $id) {
                $exists = Parameter::where([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => $id->parameter_type_id,
                    'task_type_id'      => $id->task_type_id,
                    'value'             => match ((int) $id->parameter_type_id) {
                        7       => $request['distance_to_als'],
                        19      => $request['height_low'],
                        20      => $request['height_mid'],
                        21      => $request['height_high'],
                        default => $request['velocity'],
                    },
                ]);

                if ($exists) {
                    $exists->delete();
                }

                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => $id->parameter_type_id,
                    'task_type_id'      => $id->task_type_id,
                    'value'             => match ((int) $id->parameter_type_id) {
                        7       => $request['distance_to_als'],
                        19      => $request['height_low'],
                        20      => $request['height_mid'],
                        21      => $request['height_high'],
                        default => $request['velocity'],
                    },
                ]);
            }
        }
    }

    private function editAlsParameter(Header $header): \Illuminate\Support\Collection
    {
        $system_id = 2;
        $als_tasks = DB::table('systems_id_task_type_id')->where('system_id', $system_id)->get();
        $parameters = collect([]);

        foreach ($als_tasks as $value) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $value->task_type_id)->get();

            foreach ($parameter_type_id as $id) {
                $parameter = Parameter::where([
                    'subject_id'        => $header->id,
                    'parameter_type_id' => $id->parameter_type_id,
                    'task_type_id'      => $id->task_type_id,
                ])->first();

                $parameters->push($parameter);
            }
        }

        $allParameters = $parameters->filter()->sortBy('parameter_type_id')->chunk(4);
        $parameters    = collect([]);

        $allParameters->each(function ($parameter, $position) use ($parameters) {
            $parameters->push($parameter[$position]);
        });

        return $parameters;
    }

    private function updateAlsParameter(Request $request): void
    {
        $system_id = 2;
        $als_tasks = DB::table('systems_id_task_type_id')->where('system_id', $system_id)->get();

        foreach ($als_tasks as $value) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $value->task_type_id)->get();

            foreach ($parameter_type_id as $id) {
                $exists = Parameter::where([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => $id->parameter_type_id,
                    'task_type_id'      => $id->task_type_id,
                ]);

                if ($exists) {
                    $exists->delete();
                }

                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => $id->parameter_type_id,
                    'task_type_id'      => $id->task_type_id,
                    'value'             => match ((int) $id->parameter_type_id) {
                        7       => $request['distance_to_als'],
                        19      => $request['height_low'],
                        20      => $request['height_mid'],
                        21      => $request['height_high'],
                        default => $request['velocity'],
                    },
                ]);
            }
        }
    }
}
