<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Aircraft;
use App\Models\AircraftPartAircraft;
use App\Models\AircraftParts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class AircraftPartsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/aircraft-parts',
        summary: 'Lista todas las partes de aeronave. Permite filtrar por nombre (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
        parameters: [
            new OA\Parameter(name: 'part_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de partes'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $partName = $request->input('part_name');

        if ($request->isMethod('post') && $partName !== '') {
            $aircraftParts = AircraftParts::where('part_name', 'LIKE', '%' . $partName . '%')
                ->with('aircraftPartAircrafts')->get();
        } else {
            $aircraftParts = AircraftParts::with('aircraftPartAircrafts')->get();
        }

        return response()->json(['data' => $aircraftParts], 200);
    }

    #[OA\Get(
        path: '/api/aircraft-parts/create',
        summary: 'Obtiene los datos necesarios para crear una nueva parte de aeronave (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de creación'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function create(): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $aircrafts     = Aircraft::all();
        $aircraftParts = AircraftParts::all();

        return response()->json([
            'aircrafts'      => $aircrafts,
            'aircraft_parts' => $aircraftParts,
        ], 200);
    }

    #[OA\Get(
        path: '/api/aircraft-parts/search',
        summary: 'Busca partes de aeronave por nombre (autocompletado, solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
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

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $term    = $request->get('term');
        $results = [];

        // NOTA: AircraftPart no está definido en este scope (bug preexistente)
        $data = AircraftPart::where('part_name', 'LIKE', '%' . $term . '%')->get();

        foreach ($data as $result) {
            $results[] = [
                'value' => $result->part_name,
                'link'  => '/aircraft_parts/' . $result->id,
            ];
        }

        return response()->json($results);
    }

    #[OA\Post(
        path: '/api/aircraft-parts',
        summary: 'Crea una nueva parte de aeronave y la asocia a un aircraft (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['part_name', 'aircraft_id', 'coordinate_x', 'coordinate_y', 'coordinate_z', 'elevation_angle', 'azimut', 'distance'],
                properties: [
                    new OA\Property(property: 'part_name',       type: 'string',  maxLength: 255),
                    new OA\Property(property: 'aircraft_id',     type: 'integer'),
                    new OA\Property(property: 'coordinate_x',    type: 'number'),
                    new OA\Property(property: 'coordinate_y',    type: 'number'),
                    new OA\Property(property: 'coordinate_z',    type: 'number'),
                    new OA\Property(property: 'elevation_angle', type: 'number'),
                    new OA\Property(property: 'azimut',          type: 'number'),
                    new OA\Property(property: 'distance',        type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Parte creada correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'part_name'      => 'required|string|max:255',
            'aircraft_id'    => 'required|integer|exists:aircrafts,id',
            'coordinate_x'   => 'required|numeric',
            'coordinate_y'   => 'required|numeric',
            'coordinate_z'   => 'required|numeric',
            'elevation_angle'=> 'required|numeric',
            'azimut'         => 'required|numeric',
            'distance'       => 'required|numeric',
        ]);

        $aircraftPart = AircraftParts::create([
            'part_name' => $request->part_name,
        ]);

        // Crear la relación con la aeronave en AircraftPartAircraft
        AircraftPartAircraft::create([
            'aircraft_id'      => $request->aircraft_id,
            'aircraft_part_id' => $aircraftPart->id,
            'coordinate_x'     => $request->coordinate_x,
            'coordinate_y'     => $request->coordinate_y,
            'coordinate_z'     => $request->coordinate_z,
            'elevation_angle'  => $request->elevation_angle,
            'azimut'           => $request->azimut,
            'distance'         => $request->distance,
        ]);

        return response()->json([
            'message'       => 'Aircraft part created successfully',
            'aircraft_part' => $aircraftPart,
        ], 201);
    }

    #[OA\Get(
        path: '/api/aircraft-parts/{aircraftParts}',
        summary: 'Obtiene el detalle de una parte de aeronave (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
        parameters: [
            new OA\Parameter(name: 'aircraftParts', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la parte'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function show(AircraftParts $aircraftParts): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['data' => $aircraftParts], 200);
    }

    #[OA\Delete(
        path: '/api/aircraft-parts/{aircraftParts}',
        summary: 'Elimina una parte de aeronave y sus relaciones (solo admin)',
        security: [['bearerAuth' => []]],
        tags: ['Aircraft Parts'],
        parameters: [
            new OA\Parameter(name: 'aircraftParts', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Parte eliminada correctamente'),
            new OA\Response(response: 403, description: 'Solo administradores'),
        ]
    )]
    public function destroy(AircraftParts $aircraftParts): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // NOTA: $aircraftPart no está definido (bug preexistente, parámetro es $aircraftParts)
        $aircraftPart->aircraftPartAircrafts()->delete();
        $aircraftPart->delete();

        return response()->json(['message' => 'Aircraft part deleted successfully'], 200);
    }
}
