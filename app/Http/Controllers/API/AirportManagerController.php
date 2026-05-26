<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AirportManager;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class AirportManagerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airport-managers',
        summary: 'Lista paginada de gestores de aeropuerto con filtro opcional por país',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        parameters: [
            new OA\Parameter(name: 'given_country', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page',          in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de gestores'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $given_country = $request->input('given_country');

        $managers = AirportManager::with('country')
            ->when($given_country, function ($query) use ($given_country) {
                $query->whereHas('country', fn ($q) => $q->where('name', $given_country));
            })
            ->paginate(10);

        $filterCountries = Country::orderBy('name')->pluck('name')->toArray();

        return response()->json([
            'data'            => $managers,
            'filterCountries' => $filterCountries,
            'given_country'   => $given_country,
        ], 200);
    }

    #[OA\Get(
        path: '/api/airport-managers/create',
        summary: 'Obtiene los datos necesarios para crear un gestor de aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        responses: [
            new OA\Response(response: 200, description: 'Países disponibles para el formulario'),
        ]
    )]
    public function create(): JsonResponse
    {
        $countries = Country::orderBy('name')->get();

        return response()->json([
            'countries' => $countries,
        ], 200);
    }

    #[OA\Post(
        path: '/api/airport-managers',
        summary: 'Crea un nuevo gestor de aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'country_id'],
                properties: [
                    new OA\Property(property: 'name',       type: 'string',  maxLength: 255),
                    new OA\Property(property: 'country_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Gestor creado correctamente'),
            new OA\Response(response: 500, description: 'Error interno al crear el gestor'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        try {
            $manager = AirportManager::create($request->all());

            ActivityLog::create([
                'user_id'     => Auth::id(),
                'action'      => 'create',
                'entity_type' => 'AirportManager',
                'entity_id'   => $manager->id,
            ]);

            return response()->json([
                'message' => 'Airport manager created successfully',
                'data'    => $manager,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating airport manager',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/airport-managers/{airportManager}',
        summary: 'Obtiene los datos de un gestor de aeropuerto para edición',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        parameters: [
            new OA\Parameter(name: 'airportManager', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del gestor y países disponibles'),
            new OA\Response(response: 404, description: 'Gestor no encontrado'),
        ]
    )]
    public function edit(AirportManager $airportManager): JsonResponse
    {
        $countries = Country::orderBy('name')->get();

        return response()->json([
            'data'      => $airportManager,
            'countries' => $countries,
        ], 200);
    }

    #[OA\Put(
        path: '/api/airport-managers/{airportManager}',
        summary: 'Actualiza un gestor de aeropuerto existente',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        parameters: [
            new OA\Parameter(name: 'airportManager', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name',       type: 'string',  maxLength: 255),
                    new OA\Property(property: 'country_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Gestor actualizado correctamente'),
            new OA\Response(response: 404, description: 'Gestor no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el gestor'),
        ]
    )]
    public function update(Request $request, AirportManager $airportManager): JsonResponse
    {
        try {
            $airportManager->update($request->all());

            ActivityLog::create([
                'user_id'     => Auth::id(),
                'action'      => 'update',
                'entity_type' => 'AirportManager',
                'entity_id'   => $airportManager->id,
            ]);

            return response()->json([
                'message' => 'Airport manager updated successfully',
                'data'    => $airportManager,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating airport manager',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/airport-managers/{airportManager}',
        summary: 'Elimina un gestor de aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport Managers'],
        parameters: [
            new OA\Parameter(name: 'airportManager', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Gestor eliminado correctamente'),
            new OA\Response(response: 404, description: 'Gestor no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el gestor'),
        ]
    )]
    public function destroy(AirportManager $airportManager): JsonResponse
    {
        try {
            $airportManager->delete();

            ActivityLog::create([
                'user_id'     => Auth::id(),
                'action'      => 'delete',
                'entity_type' => 'AirportManager',
                'entity_id'   => $airportManager->id,
            ]);

            return response()->json([
                'message' => 'Airport manager deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
