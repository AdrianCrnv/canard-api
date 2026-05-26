<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Aircraft;
use App\Models\AircraftDimension;
use App\Models\AircraftPartAircraft;
use App\Models\AircraftParts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class AircraftController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/aircrafts',
        summary: 'Lista todos los aircrafts (solo admin). Permite filtrar por fabricante',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'manufacturer', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de aircrafts'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $manufacturer = $request->input('manufacturer');

        // Si se envía manufacturer, filtrar por él
        if ($request->isMethod('post') && $manufacturer !== '') {
            $aircrafts = Aircraft::where('manufacturer', $manufacturer)->with('dimensions')->get();
        } else {
            $aircrafts = Aircraft::with('dimensions')->get();
        }

        return response()->json(['data' => $aircrafts], 200);
    }

    #[OA\Get(
        path: '/api/aircrafts/create',
        summary: 'Obtiene los datos necesarios para crear un nuevo aircraft (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de creación'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function create(): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasRole('admin') == false) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $aircraftParts   = AircraftParts::all();
        $aircraftPartIds = [];

        return response()->json([
            'aircraft_parts'   => $aircraftParts,
            'aircraft_part_ids'=> $aircraftPartIds,
        ], 200);
    }

    #[OA\Get(
        path: '/api/aircrafts/search',
        summary: 'Busca aircrafts por modelo (autocompletado)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'term', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resultados de búsqueda'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function getNames(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasRole('admin') == false) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $term    = $request->get('term');
        $results = [];

        $data = Aircraft::where('model', 'LIKE', '%' . $term . '%')->get();

        foreach ($data as $result) {
            $results[] = [
                'value' => $result->model,
                'link'  => '/aircrafts/' . $result->id,
            ];
        }

        return response()->json($results);
    }

    #[OA\Post(
        path: '/api/aircrafts',
        summary: 'Crea un nuevo aircraft con sus dimensiones y partes (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['model', 'manufacturer', 'parts'],
                properties: [
                    new OA\Property(property: 'model',        type: 'string', maxLength: 200),
                    new OA\Property(property: 'manufacturer', type: 'string', maxLength: 250),
                    new OA\Property(property: 'parts',        type: 'array', items: new OA\Items(type: 'integer')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Aircraft creado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'model'        => 'required|string|max:200',
            'manufacturer' => 'required|string|max:250',
            'parts'        => 'required|array',
            'parts.*'      => 'integer|exists:aircraft_parts,id',
        ]);

        try {
            $aircraft = Aircraft::create([
                'model'        => $request->model,
                'manufacturer' => $request->manufacturer,
            ]);

            AircraftDimension::create([
                'aircraft_id'                                       => $aircraft->id,
                'nose_to_noselandinggear'                           => $request->nose_to_noselandinggear,
                'aircraft_length'                                   => $request->aircraft_length,
                'vertical_stabilizer_top_height_overground'         => $request->vertical_stabilizer_top_height_overground,
                'wingtip_to_nose'                                   => $request->wingtip_to_nose,
                'fuselage_width'                                    => $request->fuselage_width,
                'wingtip_to_fuselage_center'                        => $request->wingtip_to_fuselage_center,
                'wingapex_to_nose'                                  => $request->wingapex_to_nose,
                'fuselage_top_height_overground'                    => $request->fuselage_top_height_overground,
                'wingapex_chord'                                    => $request->wingapex_chord,
                'fuselage_height'                                   => $request->fuselage_height,
                'horizontal_stabilizer_tip_height_overground'       => $request->horizontal_stabilizer_tip_height_overground,
                'tail_height_over_ground'                           => $request->tail_height_over_ground,
                'hs_leading_edge_sweep_angle'                       => $request->hs_leading_edge_sweep_angle,
                'hs_trailing_edge_sweep_angle'                      => $request->hs_trailing_edge_sweep_angle,
                'hs_leading_edge_root_to_fuselage_maximum_width'    => $request->hs_leading_edge_root_to_fuselage_maximum_width,
                'nose_landing_gear_to_tail'                         => $request->nose_landing_gear_to_tail,
                'hs_tip_width'                                      => $request->hs_tip_width,
                'hs_root_height_over_ground'                        => $request->hs_root_height_over_ground,
                'hs_leading_edge_length'                            => $request->hs_leading_edge_length,
                'hs_trailing_edge_length'                           => $request->hs_trailing_edge_length,
                'nose_landing_gear_to_hs_leading_edge_root'         => $request->nose_landing_gear_to_hs_leading_edge_root,
            ]);

            if ($request->has('parts')) {
                foreach ($request->parts as $partId) {
                    AircraftPartAircraft::create([
                        'aircraft_id'      => $aircraft->id,
                        'aircraft_part_id' => $partId,
                        'coordinate_x'     => $request->input("coordinate_x.$partId", null),
                        'coordinate_y'     => $request->input("coordinate_y.$partId", null),
                        'coordinate_z'     => $request->input("coordinate_z.$partId", null),
                        'elevation_angle'  => $request->input("elevation_angle.$partId", null),
                        'azimut'           => $request->input("azimut.$partId", null),
                        'distance'         => $request->input("distance.$partId", null),
                        'height'           => $request->input("height.$partId", null),
                    ]);
                }
            }

            ActivityLog::log('create', 'Aircraft', $aircraft->id,
                "New aircraft: '{$aircraft->manufacturer} {$aircraft->model}'"
            );

            return response()->json([
                'message'  => 'Aircraft created successfully',
                'aircraft' => $aircraft,
            ], 201);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Aircraft', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/aircrafts/{aircraft}',
        summary: 'Obtiene el detalle de un aircraft con sus partes (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'aircraft', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del aircraft'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function show(Aircraft $aircraft): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasRole('admin') == false) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $aircraftParts         = AircraftParts::all();
        $aircraftPartsAircraft = $aircraft->parts;
        $aircraftPartIds       = $aircraftPartsAircraft->pluck('aircraft_part_id')->toArray();

        return response()->json([
            'aircraft'          => $aircraft,
            'aircraft_parts'    => $aircraftParts,
            'aircraft_part_ids' => $aircraftPartIds,
        ], 200);
    }

    #[OA\Put(
        path: '/api/aircrafts/{aircraft}',
        summary: 'Actualiza las dimensiones y partes de un aircraft (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'aircraft', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Aircraft actualizado correctamente'),
            new OA\Response(response: 403, description: 'Solo administradores'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function update(Request $request, Aircraft $aircraft): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $dimensions = $aircraft->dimensions ?? new AircraftDimension();

            $before = [
                'Nose to nose landing gear'                         => $dimensions->nose_to_noselandinggear,
                'Aircraft length'                                   => $dimensions->aircraft_length,
                'Vertical stabilizer top height overground'         => $dimensions->vertical_stabilizer_top_height_overground,
                'Wingtip to nose'                                   => $dimensions->wingtip_to_nose,
                'Fuselage width'                                    => $dimensions->fuselage_width,
                'Wingtip to fuselage center'                        => $dimensions->wingtip_to_fuselage_center,
                'Wingapex to nose'                                  => $dimensions->wingapex_to_nose,
                'Fuselage top height overground'                    => $dimensions->fuselage_top_height_overground,
                'Wingapex chord'                                    => $dimensions->wingapex_chord,
                'Fuselage height'                                   => $dimensions->fuselage_height,
                'Horizontal stabilizer tip height overground'       => $dimensions->horizontal_stabilizer_tip_height_overground,
                'Tail height over ground'                           => $dimensions->tail_height_over_ground,
                'HS leading edge sweep angle'                       => $dimensions->hs_leading_edge_sweep_angle,
                'HS trailing edge sweep angle'                      => $dimensions->hs_trailing_edge_sweep_angle,
                'HS leading edge root to fuselage maximum width'    => $dimensions->hs_leading_edge_root_to_fuselage_maximum_width,
                'Nose landing gear to tail'                         => $dimensions->nose_landing_gear_to_tail,
                'HS tip width'                                      => $dimensions->hs_tip_width,
                'HS root height over ground'                        => $dimensions->hs_root_height_over_ground,
                'HS leading edge length'                            => $dimensions->hs_leading_edge_length,
                'HS trailing edge length'                           => $dimensions->hs_trailing_edge_length,
                'Nose landing gear to HS leading edge root'         => $dimensions->nose_landing_gear_to_hs_leading_edge_root,
            ];

            $dimensions->nose_to_noselandinggear                        = $request->input('nose_to_noselandinggear');
            $dimensions->aircraft_length                                = $request->input('aircraft_length');
            $dimensions->vertical_stabilizer_top_height_overground     = $request->input('vertical_stabilizer_top_height_overground');
            $dimensions->wingtip_to_nose                                = $request->input('wingtip_to_nose');
            $dimensions->fuselage_width                                 = $request->input('fuselage_width');
            $dimensions->wingtip_to_fuselage_center                    = $request->input('wingtip_to_fuselage_center');
            $dimensions->wingapex_to_nose                               = $request->input('wingapex_to_nose');
            $dimensions->fuselage_top_height_overground                 = $request->input('fuselage_top_height_overground');
            $dimensions->wingapex_chord                                 = $request->input('wingapex_chord');
            $dimensions->fuselage_height                                = $request->input('fuselage_height');
            $dimensions->horizontal_stabilizer_tip_height_overground   = $request->input('horizontal_stabilizer_tip_height_overground');
            $dimensions->tail_height_over_ground                       = $request->input('tail_height_over_ground');
            $dimensions->hs_leading_edge_sweep_angle                   = $request->input('hs_leading_edge_sweep_angle');
            $dimensions->hs_trailing_edge_sweep_angle                  = $request->input('hs_trailing_edge_sweep_angle');
            $dimensions->hs_leading_edge_root_to_fuselage_maximum_width = $request->input('hs_leading_edge_root_to_fuselage_maximum_width');
            $dimensions->nose_landing_gear_to_tail                     = $request->input('nose_landing_gear_to_tail');
            $dimensions->hs_tip_width                                  = $request->input('hs_tip_width');
            $dimensions->hs_root_height_over_ground                    = $request->input('hs_root_height_over_ground');
            $dimensions->hs_leading_edge_length                        = $request->input('hs_leading_edge_length');
            $dimensions->hs_trailing_edge_length                       = $request->input('hs_trailing_edge_length');
            $dimensions->nose_landing_gear_to_hs_leading_edge_root    = $request->input('nose_landing_gear_to_hs_leading_edge_root');

            // Asigna el id del avión a las dimensiones si se está creando
            if (!$dimensions->exists) {
                $dimensions->aircraft_id = $aircraft->id;
            }

            $dimensions->save();

            $after = [
                'Nose to nose landing gear'                         => $dimensions->nose_to_noselandinggear,
                'Aircraft length'                                   => $dimensions->aircraft_length,
                'Vertical stabilizer top height overground'         => $dimensions->vertical_stabilizer_top_height_overground,
                'Wingtip to nose'                                   => $dimensions->wingtip_to_nose,
                'Fuselage width'                                    => $dimensions->fuselage_width,
                'Wingtip to fuselage center'                        => $dimensions->wingtip_to_fuselage_center,
                'Wingapex to nose'                                  => $dimensions->wingapex_to_nose,
                'Fuselage top height overground'                    => $dimensions->fuselage_top_height_overground,
                'Wingapex chord'                                    => $dimensions->wingapex_chord,
                'Fuselage height'                                   => $dimensions->fuselage_height,
                'Horizontal stabilizer tip height overground'       => $dimensions->horizontal_stabilizer_tip_height_overground,
                'Tail height over ground'                           => $dimensions->tail_height_over_ground,
                'HS leading edge sweep angle'                       => $dimensions->hs_leading_edge_sweep_angle,
                'HS trailing edge sweep angle'                      => $dimensions->hs_trailing_edge_sweep_angle,
                'HS leading edge root to fuselage maximum width'    => $dimensions->hs_leading_edge_root_to_fuselage_maximum_width,
                'Nose landing gear to tail'                         => $dimensions->nose_landing_gear_to_tail,
                'HS tip width'                                      => $dimensions->hs_tip_width,
                'HS root height over ground'                        => $dimensions->hs_root_height_over_ground,
                'HS leading edge length'                            => $dimensions->hs_leading_edge_length,
                'HS trailing edge length'                           => $dimensions->hs_trailing_edge_length,
                'Nose landing gear to HS leading edge root'         => $dimensions->nose_landing_gear_to_hs_leading_edge_root,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            // PARTS: reemplazar todas las partes seleccionadas
            $selectedParts = $request->input('parts', []);
            AircraftPartAircraft::where('aircraft_id', $aircraft->id)->delete();

            foreach ($selectedParts as $partId) {
                AircraftPartAircraft::create([
                    'aircraft_id'      => $aircraft->id,
                    'aircraft_part_id' => $partId,
                ]);
            }

            $description = "Updated aircraft: '{$aircraft->manufacturer} {$aircraft->model}'"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Aircraft', $aircraft->id, $description);

            return response()->json([
                'message'  => 'Aircraft updated successfully',
                'aircraft' => $aircraft->fresh(['dimensions']),
            ], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Aircraft', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/aircrafts/{aircraft}',
        summary: 'Elimina un aircraft y sus dimensiones (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'aircraft', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Aircraft eliminado correctamente'),
            new OA\Response(response: 403, description: 'Solo administradores'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function destroy(Aircraft $aircraft): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $name       = "{$aircraft->manufacturer} {$aircraft->model}";
            $aircraftId = $aircraft->id;

            // Eliminar los registros relacionados en AircraftDimension
            $aircraft->dimensions()->delete();
            $aircraft->delete();

            ActivityLog::log('delete', 'Aircraft', $aircraftId, "Deleted aircraft: '{$name}'");

            return response()->json(['message' => 'Aircraft deleted successfully'], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Aircraft', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/aircrafts/{aircraft}/parts',
        summary: 'Lista las partes asociadas a un aircraft (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'aircraft', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Partes del aircraft'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function parts(Aircraft $aircraft): JsonResponse
    {
        $user = Auth::user();

        if ($user->hasRole('admin') == false) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'aircraft'       => $aircraft,
            'aircraft_parts' => $aircraft->parts,
        ], 200);
    }

    #[OA\Get(
        path: '/api/aircrafts/{aircraftId}/parts/{partId}/edit',
        summary: 'Obtiene los datos de una parte de un aircraft para su edición (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        parameters: [
            new OA\Parameter(name: 'aircraftId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'partId',     in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos de la parte para edición'),
            new OA\Response(response: 403, description: 'Solo administradores'),
            new OA\Response(response: 404, description: 'Aircraft o parte no encontrados'),
        ]
    )]
    public function editParts(int $aircraftId, int $partId): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $aircraft     = Aircraft::findOrFail($aircraftId);
        $part         = $aircraft->parts()->findOrFail($partId);
        $partAircraft = AircraftPartAircraft::where('aircraft_id', $aircraftId)
            ->where('aircraft_part_id', $partId)
            ->first();

        return response()->json([
            'aircraft'     => $aircraft,
            'part'         => $part,
            'part_aircraft'=> $partAircraft,
            'part_id'      => $partId,
        ], 200);
    }

    #[OA\Put(
        path: '/api/aircrafts/parts/update',
        summary: 'Actualiza las coordenadas y parámetros de una parte de aircraft (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['partId'],
                properties: [
                    new OA\Property(property: 'partId',          type: 'integer'),
                    new OA\Property(property: 'coordinate_x',    type: 'number', nullable: true),
                    new OA\Property(property: 'coordinate_y',    type: 'number', nullable: true),
                    new OA\Property(property: 'coordinate_z',    type: 'number', nullable: true),
                    new OA\Property(property: 'elevation_angle', type: 'number', nullable: true),
                    new OA\Property(property: 'azimut',          type: 'number', nullable: true),
                    new OA\Property(property: 'distance',        type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Parte actualizada correctamente'),
            new OA\Response(response: 403, description: 'Solo administradores'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function updatePartsAircraft(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'partId'          => 'numeric',
            'coordinate_x'    => 'nullable|numeric',
            'coordinate_y'    => 'nullable|numeric',
            'coordinate_z'    => 'nullable|numeric',
            'elevation_angle' => 'nullable|numeric',
            'azimut'          => 'nullable|numeric',
            'distance'        => 'nullable|numeric',
        ]);

        $partAircraft = AircraftPartAircraft::find($request->input('partId'));

        if ($partAircraft) {
            $partAircraft->update([
                $partAircraft->coordinate_x    = $request->input('coordinate_x'),
                $partAircraft->coordinate_y    = $request->input('coordinate_y'),
                $partAircraft->coordinate_z    = $request->input('coordinate_z'),
                $partAircraft->elevation_angle = $request->input('elevation_angle'),
                $partAircraft->azimut          = $request->input('azimut'),
                $partAircraft->distance        = $request->input('distance'),
            ]);
            $aircraftId = $partAircraft->aircraft_id;
        }

        return response()->json([
            'message'    => 'Part updated successfully',
            'aircraft_id'=> $aircraftId ?? null,
        ], 200);
    }
}
