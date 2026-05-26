<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Company;
use App\Models\Country;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;

class CompanyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/companies',
        summary: 'Lista paginada de usuarios/compañías con filtros opcionales',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(name: 'given_operator', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'given_name',     in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'given_status',   in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'active')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de usuarios con filtros aplicados'),
            new OA\Response(response: 403, description: 'Sin permiso user_view'),
        ]
    )]
    public function index(Request $request, ?string $given_operator = null, ?string $given_name = null, string $given_status = 'active'): JsonResponse
    {
        $this->authorize('user_view');

        $users      = User::with('operator');
        $usersClean = '';

        if (Auth::user()->hasRole('admin')) {
            $usersClean = $users;
        } else {
            $usersClean = User::with('operator')->where('operator_id', Auth::user()->operator->id);
            $usersClean = $this->masterFilter($usersClean->get(), $given_operator, $given_name, $given_status);
        }

        $users = $users->orderBy('name', 'asc')->paginate(50);

        return response()->json([
            'data'           => $users,
            'users_clean'    => $usersClean,
            'given_operator' => $given_operator,
            'given_name'     => $given_name,
            'given_status'   => $given_status,
        ], 200);
    }

    #[OA\Get(
        path: '/api/companies/create',
        summary: 'Obtiene los datos necesarios para crear una compañía (aeropuertos, operadores, roles)',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $countries = Country::all();
        $airports  = Airport::all();
        $operators = Operator::all();
        $roles     = Role::all();
        $userRoles = Auth::user()->getRoleNames()->toArray();

        return response()->json([
            'countries'  => $countries,
            'airports'   => $airports,
            'operators'  => $operators,
            'roles'      => $roles,
            'user_roles' => $userRoles,
        ], 200);
    }

    #[OA\Post(
        path: '/api/companies',
        summary: 'Crea una nueva compañía y asocia sus aeropuertos',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'is_active'],
                properties: [
                    new OA\Property(property: 'name',        type: 'string',  maxLength: 255),
                    new OA\Property(property: 'email',       type: 'string',  format: 'email'),
                    new OA\Property(property: 'password',    type: 'string',  minLength: 8),
                    new OA\Property(property: 'is_active',   type: 'boolean'),
                    new OA\Property(property: 'airport_ids', type: 'array',   items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Compañía creada correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:company,email',
            'password'      => 'required|string|min:8',
            'is_active'     => 'required|boolean',
            'airport_ids'   => 'array',
            'airport_ids.*' => 'exists:airports,id',
        ]);

        $company = Company::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => bcrypt($validated['password']),
            'is_active' => $validated['is_active'],
        ]);

        if (!empty($validated['airport_ids'])) {
            $company->airports()->attach($validated['airport_ids']);
        }

        return response()->json([
            'message' => 'Company created successfully.',
            'data'    => $company,
        ], 201);
    }

    #[OA\Get(
        path: '/api/companies/{company}/edit',
        summary: 'Obtiene los datos de una compañía para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(name: 'company', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos de la compañía y aeropuertos disponibles'),
            new OA\Response(response: 404, description: 'Compañía no encontrada'),
        ]
    )]
    public function edit(Company $company): JsonResponse
    {
        $airports = Airport::all();

        return response()->json([
            'company'  => $company,
            'airports' => $airports,
        ], 200);
    }

    #[OA\Put(
        path: '/api/companies/{company}',
        summary: 'Actualiza una compañía y sincroniza sus aeropuertos',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(name: 'company', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'is_active'],
                properties: [
                    new OA\Property(property: 'name',        type: 'string',  maxLength: 255),
                    new OA\Property(property: 'email',       type: 'string',  format: 'email'),
                    new OA\Property(property: 'password',    type: 'string',  minLength: 8, nullable: true),
                    new OA\Property(property: 'is_active',   type: 'boolean'),
                    new OA\Property(property: 'airport_ids', type: 'array',   items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Compañía actualizada correctamente'),
            new OA\Response(response: 404, description: 'Compañía no encontrada'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:company,email,' . $company->id,
            'password'      => 'nullable|string|min:8',
            'is_active'     => 'required|boolean',
            'airport_ids'   => 'array',
            'airport_ids.*' => 'exists:airports,id',
        ]);

        $company->update([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => $validated['password'] ? bcrypt($validated['password']) : $company->password,
            'is_active' => $validated['is_active'],
        ]);

        if (isset($validated['airport_ids'])) {
            $company->airports()->sync($validated['airport_ids']);
        }

        return response()->json([
            'message' => 'Company updated successfully.',
            'data'    => $company,
        ], 200);
    }

    #[OA\Delete(
        path: '/api/companies/{company}',
        summary: 'Elimina una compañía y desvincula todos sus aeropuertos',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(name: 'company', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Compañía eliminada correctamente'),
            new OA\Response(response: 404, description: 'Compañía no encontrada'),
        ]
    )]
    public function destroy(Company $company): JsonResponse
    {
        $company->airports()->detach();
        $company->delete();

        return response()->json(['message' => 'Company deleted successfully.'], 200);
    }

    #[OA\Get(
        path: '/api/companies/airports-by-country/{country_id}',
        summary: 'Lista los aeropuertos de un país ordenados por nombre',
        security: [['bearerAuth' => []]],
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(name: 'country_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de aeropuertos del país'),
        ]
    )]
    public function getAirportsByCountry(int $country_id): JsonResponse
    {
        $airports = Airport::where('country_id', $country_id)->orderBy('name')->get();

        return response()->json($airports);
    }
}
