<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Client;
use App\Models\Country;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ClientController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/clients',
        summary: 'Lista paginada de clientes con búsqueda opcional. Si se pasa "autocomplete" devuelve sugerencias de nombres',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'search',       in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Filtra por nombre (prefijo)'),
            new OA\Parameter(name: 'autocomplete',  in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Devuelve hasta 10 nombres que contengan el término'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de clientes o array de sugerencias para autocomplete'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('autocomplete')) {
            $results = Client::where('name', 'like', '%' . $request->autocomplete . '%')
                ->orderBy('name')
                ->limit(10)
                ->pluck('name');

            return response()->json($results);
        }

        $query = Client::sortable(['name' => 'asc']);

        if ($request->filled('search')) {
            $query->where('name', 'like', $request->search . '%');
        }

        $clients = $query->paginate(50)->withQueryString();

        return response()->json($clients, 200);
    }

    #[OA\Get(
        path: '/api/clients/create',
        summary: 'Obtiene los datos necesarios para crear un cliente (países y aeropuertos)',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        responses: [
            new OA\Response(response: 200, description: 'Lista de países disponibles'),
        ]
    )]
    public function create(): JsonResponse
    {
        $countries = Country::orderBy('name')->get();
        $airports  = collect();

        return response()->json([
            'countries' => $countries,
            'airports'  => $airports,
        ], 200);
    }

    #[OA\Post(
        path: '/api/clients',
        summary: 'Crea un nuevo cliente y sincroniza sus aeropuertos',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'country_id'],
                properties: [
                    new OA\Property(property: 'name',       type: 'string'),
                    new OA\Property(property: 'country_id', type: 'integer'),
                    new OA\Property(property: 'airport_id', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cliente creado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'       => 'required|unique:company',
            'country_id' => 'required',
            'airport_id' => 'nullable|array',
        ]);

        $client = Client::create([
            'name'       => $request->name,
            'country_id' => $request->country_id,
        ]);

        $client->airports()->sync($request->input('airport_id', []));

        return response()->json([
            'message' => 'Client created successfully.',
            'data'    => $client,
        ], 201);
    }

    #[OA\Get(
        path: '/api/clients/{client}/edit',
        summary: 'Obtiene los datos de un cliente para su edición, incluyendo países, aeropuertos y usuarios paginados',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'client',    in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'email'])),
            new OA\Parameter(name: 'user_dir',  in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'user_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del cliente con países, aeropuertos y usuarios'),
            new OA\Response(response: 404, description: 'Cliente no encontrado'),
        ]
    )]
    public function edit(Client $client): JsonResponse
    {
        $countries   = Country::orderBy('name')->get();
        $airport_ids = $client->airports()->pluck('company_airports.airport_id')
            ->map(fn ($id) => (int) $id)->toArray();
        $airports = Airport::with('country')->orderBy('name')->get();

        $userSortCol = in_array(request('user_sort'), ['name', 'email']) ? request('user_sort') : 'name';
        $userSortDir = request('user_dir') === 'desc' ? 'desc' : 'asc';

        $users = DB::table('company_user')
            ->where('company_id', $client->id)
            ->join('users', 'company_user.user_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy($userSortCol, $userSortDir)
            ->paginate(15, ['*'], 'user_page');

        return response()->json([
            'client'      => $client,
            'countries'   => $countries,
            'airports'    => $airports,
            'airport_ids' => $airport_ids,
            'users'       => $users,
        ], 200);
    }

    #[OA\Put(
        path: '/api/clients/{client}',
        summary: 'Actualiza un cliente, sincroniza sus aeropuertos y elimina usuarios desvinculados',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'country_id'],
                properties: [
                    new OA\Property(property: 'name',       type: 'string'),
                    new OA\Property(property: 'country_id', type: 'integer'),
                    new OA\Property(property: 'airport_id', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                    new OA\Property(property: 'user_id',    type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cliente actualizado correctamente'),
            new OA\Response(response: 404, description: 'Cliente no encontrado'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function update(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'name'       => 'required|unique:company,name,' . $client->id,
            'country_id' => 'required',
            'airport_id' => 'nullable|array',
            'user_id'    => 'nullable|array',
        ]);

        $client->name       = $request->name;
        $client->country_id = $request->country_id;
        $client->save();

        $airportIds = array_unique(
            array_filter(array_map('intval', $request->input('airport_id', [])), fn ($id) => $id > 0)
        );
        $client->airports()->sync($airportIds);

        $userIds = array_unique(
            array_filter(array_map('intval', $request->input('user_id', [])), fn ($id) => $id > 0)
        );
        DB::table('company_user')
            ->where('company_id', $client->id)
            ->whereNotIn('user_id', $userIds)
            ->delete();

        return response()->json([
            'message' => 'Client updated successfully.',
            'data'    => $client,
        ], 200);
    }

    #[OA\Delete(
        path: '/api/clients/{client}',
        summary: 'Elimina un cliente y desvincula todos sus aeropuertos',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cliente eliminado correctamente'),
            new OA\Response(response: 404, description: 'Cliente no encontrado'),
        ]
    )]
    public function destroy(Client $client): JsonResponse
    {
        $client->airports()->detach();
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully.'], 200);
    }

    #[OA\Delete(
        path: '/api/clients/{client}/users/{user}',
        summary: 'Elimina la relación entre un usuario y un cliente',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user',   in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuario desvinculado del cliente correctamente'),
            new OA\Response(response: 404, description: 'Cliente o usuario no encontrado'),
        ]
    )]
    public function removeUser(Client $client, User $user): JsonResponse
    {
        DB::table('company_user')
            ->where('company_id', $client->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['message' => 'User removed from client successfully.'], 200);
    }

    #[OA\Get(
        path: '/api/clients/{client}',
        summary: 'Obtiene el detalle de un cliente con sus aeropuertos y usuarios paginados',
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(name: 'client',       in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'airport_sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'icao_code', 'country'])),
            new OA\Parameter(name: 'airport_dir',  in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'user_sort',    in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'email'])),
            new OA\Parameter(name: 'user_dir',     in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'airport_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_page',    in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del cliente con aeropuertos y usuarios paginados'),
            new OA\Response(response: 404, description: 'Cliente no encontrado'),
        ]
    )]
    public function show(Client $client): JsonResponse
    {
        $airport_ids = $client->airports()->pluck('company_airports.airport_id')
            ->map(fn ($id) => (int) $id)->toArray();

        $sortCol = in_array(request('airport_sort'), ['name', 'icao_code', 'country']) ? request('airport_sort') : 'name';
        $sortDir = request('airport_dir') === 'desc' ? 'desc' : 'asc';

        $airports = Airport::with('country')
            ->whereIn('airports.id', $airport_ids)
            ->when($sortCol === 'country', function ($q) use ($sortDir) {
                return $q->join('countries', 'airports.country_id', '=', 'countries.id')
                    ->orderBy('countries.name', $sortDir)
                    ->select('airports.*');
            }, function ($q) use ($sortCol, $sortDir) {
                return $q->orderBy($sortCol, $sortDir);
            })
            ->paginate(15, ['*'], 'airport_page');

        $userSortCol = in_array(request('user_sort'), ['name', 'email']) ? request('user_sort') : 'name';
        $userSortDir = request('user_dir') === 'desc' ? 'desc' : 'asc';

        $users = DB::table('company_user')
            ->where('company_id', $client->id)
            ->join('users', 'company_user.user_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy($userSortCol, $userSortDir)
            ->paginate(15, ['*'], 'user_page');

        $countries = Country::orderBy('name')->get();

        return response()->json([
            'client'      => $client,
            'countries'   => $countries,
            'airports'    => $airports,
            'airport_ids' => $airport_ids,
            'users'       => $users,
        ], 200);
    }
}
