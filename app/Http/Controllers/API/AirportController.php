<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\AirportAmbit;
use App\Models\AirportManager;
use App\Models\Client;
use App\Models\CompanyAirport;
use App\Models\CompanyOperation;
use App\Models\CompanyUser;
use App\Models\Country;
use App\Models\EtodAreas;
use App\Models\MarkerPoints;
use App\Models\Operation;
use App\Models\Reference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class AirportController extends Controller
{
    public function __construct(
        private readonly AirportFilterController $filter
    ) {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airports',
        summary: 'Lista los aeropuertos accesibles para el usuario autenticado con filtros opcionales',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'country',  in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operator', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de aeropuertos'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function index(Request $request, ?string $given_country = null, ?string $given_operator = null): JsonResponse
    {
        if ($this->authorize('airport_view') || Auth::user()->hasRole('company')) {
            $airports_ids = [];

            $airports_to_operator = DB::table('operator_airport')
                ->where('operator_id', Auth::user()->operator_id)
                ->where('subject_type_id', 4)->get();

            foreach ($airports_to_operator as $airport) {
                if (!in_array($airport->subject_id, $airports_ids)) {
                    array_push($airports_ids, $airport->subject_id);
                }
            }

            $airportsQuery = Airport::whereIn('id', $airports_ids)->sortable(['name' => 'asc']);

            if (isset($request) && $request->country != '') {
                $given_country = $request->country;
            }
            if (isset($request) && $request->operator != '') {
                $given_operator = $request->operator;
            }

            if (Auth::user()->hasRole('admin')) {
                $airportsQuery = Airport::query();
                $allAirports   = Airport::all();
                $airportsQuery = $this->filter->filterMasterAdmin($airportsQuery, $airports_ids, $given_country, $given_operator);
            } elseif (Auth::user()->hasRole('company')) {
                $companyUser = CompanyUser::where('user_id', Auth::id())->first();
                if ($companyUser) {
                    $companyAirports = CompanyAirport::where('company_id', $companyUser->company_id)
                        ->pluck('airport_id')->toArray();
                    $airportsQuery = Airport::whereIn('id', $companyAirports)->orderBy('name', 'asc');
                }
            } elseif (Auth::user()->can('airport_view')) {
                $airportsQuery = Auth::user()->operator->airports();
                $allAirports   = Auth::user()->operator->airports();
                $airportsQuery = $this->filter->filterMasterNoAdmin($airportsQuery, $airports_ids, $given_country, $given_operator);
            }

            if (Auth::user()->hasRole('company')) {
                $filterCountries    = [];
                $allCountryOptions  = $this->filter->countrySelect($airportsQuery->get());
                $allOperatorOptions = [];
                $filterOperator     = [];
                $airports           = $airportsQuery->paginate(50);
            } else {
                $filterCountries    = $this->filter->countrySelect($airportsQuery);
                $allCountryOptions  = $this->filter->countrySelect($allAirports, true);
                $filterOperator     = $this->filter->operatorSelect($airportsQuery);
                ksort($filterOperator);
                $allOperatorOptions = $this->filter->operatorSelect($allAirports, true);
                $airports           = $airportsQuery->paginate(50);
            }

            return response()->json([
                'data'                 => $airports,
                'all_country_options'  => $allCountryOptions,
                'filter_countries'     => $filterCountries,
                'all_operator_options' => $allOperatorOptions,
                'filter_operator'      => $filterOperator,
                'given_country'        => $given_country,
                'given_operator'       => $given_operator,
            ], 200);
        }
    }

    #[OA\Get(
        path: '/api/airports/search',
        summary: 'Busca aeropuertos por nombre (autocompletado)',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'term', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resultados de búsqueda'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getNames(Request $request): JsonResponse
    {
        $this->authorize('airport_view');

        $airports_ids = [];

        if (Auth::user()->hasRole('company')) {
            $company  = DB::table('company_user')->where('user_id', Auth::user()->id)->get();
            $companyId = $company[0]->company_id;
            $airports_ids = DB::table('company_airports')
                ->where('company_id', $companyId)
                ->pluck('airport_id');
        } else {
            $airports_to_operator = DB::table('operator_airport')
                ->where('operator_id', Auth::user()->operator_id)
                ->where('subject_type_id', 4)->get();

            foreach ($airports_to_operator as $airport) {
                if (!in_array($airport->subject_id, $airports_ids)) {
                    array_push($airports_ids, $airport->subject_id);
                }
            }
        }

        $term    = $request->get('term');
        $results = [];

        $data = Airport::whereIn('id', $airports_ids)
            ->where('name', 'LIKE', "%$term%")->get();

        foreach ($data as $result) {
            $results[] = [
                'value' => $result->name,
                'link'  => '/airports/' . $result->id,
                'id'    => $result->id,
                'icao'  => $result->icao_code ?? '—',
            ];
        }

        return response()->json($results);
    }

    #[OA\Get(
        path: '/api/airports/create',
        summary: 'Obtiene los datos necesarios para crear un nuevo aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('airport_create');

        $countries = $this->filter->getSortedCountries(Auth::user()->operator->country_id ?? null);
        $managers  = AirportManager::all();
        $ambits    = AirportAmbit::all();

        return response()->json([
            'countries' => $countries,
            'managers'  => $managers,
            'ambits'    => $ambits,
        ], 200);
    }

    #[OA\Post(
        path: '/api/airports',
        summary: 'Crea un nuevo aeropuerto y lo asocia al operador del usuario',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'country_id', 'ambit_id', 'manager_id', 'alt_ellipsoid_coord_offset'],
                properties: [
                    new OA\Property(property: 'name',                        type: 'string'),
                    new OA\Property(property: 'iata_code',                   type: 'string', nullable: true),
                    new OA\Property(property: 'icao_code',                   type: 'string', nullable: true),
                    new OA\Property(property: 'country_id',                  type: 'integer'),
                    new OA\Property(property: 'ambit_id',                    type: 'integer'),
                    new OA\Property(property: 'manager_id',                  type: 'integer'),
                    new OA\Property(property: 'active',                      type: 'string', enum: ['on', 'off']),
                    new OA\Property(property: 'alt_ellipsoid_coord_offset',  type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Aeropuerto creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'name'                       => 'required|unique:airports',
            'iata_code'                  => 'nullable|unique:airports|size:3',
            'icao_code'                  => 'nullable|unique:airports|size:4',
            'country_id'                 => 'required',
            'ambit_id'                   => 'required',
            'manager_id'                 => 'required',
            'alt_ellipsoid_coord_offset' => 'required',
        ]);

        try {
            $airport = Airport::create([
                'name'                       => $request['name'],
                'iata_code'                  => $request['iata_code'],
                'icao_code'                  => $request['icao_code'],
                'country_id'                 => $request['country_id'],
                'ambit_id'                   => $request['ambit_id'],
                'manager_id'                 => $request['manager_id'],
                'active'                     => $request['active'] == 'on' ? 1 : 0,
                'alt_ellipsoid_coord_offset' => $request['alt_ellipsoid_coord_offset'],
            ]);

            DB::table('operator_airport')->insert([
                'operator_id'     => Auth::user()->operator_id,
                'subject_id'      => $airport->id,
                'subject_type_id' => 4,
            ]);

            if (Auth::user()->operator_id != 1) { // if operator isn't ITE
                DB::table('operator_airport')->insert([
                    'operator_id'     => 1,
                    'subject_id'      => $airport->id,
                    'subject_type_id' => 4,
                ]);
            }

            ActivityLog::log('create', 'Airport', $airport->id,
                "Created airport '{$airport->name}' ({$airport->icao_code})"
            );

            return response()->json([
                'message' => 'Airport created successfully',
                'airport' => $airport,
            ], 201);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}',
        summary: 'Obtiene el detalle completo de un aeropuerto con parámetros, operaciones y elementos asociados',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del aeropuerto'),
            new OA\Response(response: 403, description: 'Sin permiso o aeropuerto no asignado'),
        ]
    )]
    public function show(Airport $airport): JsonResponse
    {
        $airportOperations = collect();

        if ($this->authorize('airport_view') || Auth::user()->hasRole('company')) {

            if (Auth::user()->hasRole('company')) {
                $company = DB::table('company_user')->where('user_id', Auth::user()->id)->first();

                if (!$company) {
                    return response()->json(['message' => 'This action is unauthorized'], 403);
                }

                $companyAirports = DB::table('company_airports')
                    ->where('company_id', $company->company_id)->pluck('airport_id');

                if (!$companyAirports->contains($airport->id)) {
                    return response()->json(['message' => 'This action is unauthorized'], 403);
                }
            }

            $countries  = Country::all();
            $managers   = AirportManager::all();
            $ambits     = AirportAmbit::all();
            $paramsAirport = $airport->parameters()->sort();

            // RUNWAYS
            $paramsRunway = collect();
            foreach ($airport->runways as $runway) {
                foreach ($runway->parameters() as $parameter) {
                    $paramsRunway->push($parameter);
                }
            }
            $paramsRunway = $paramsRunway->sort();

            $paramsHeader = collect();
            foreach ($airport->runways as $runway) {
                foreach ($runway->headers as $header) {
                    foreach ($header->parameters() as $parameter) {
                        $paramsHeader->push($parameter);
                    }
                }
            }
            $paramsHeader = $paramsHeader->sort();

            // TAXIWAYS
            $paramsTaxiway = collect();
            foreach ($airport->taxiways as $taxiway) {
                foreach ($taxiway->parameters() as $parameter) {
                    $paramsTaxiway->push([
                        'id'         => $parameter->id,
                        'taxiway_id' => $taxiway->id,
                        'value'      => $parameter->value,
                        'created_at' => $parameter->created_at,
                        'updated_at' => $parameter->updated_at,
                    ]);
                }
            }
            $paramsTaxiway = $paramsTaxiway->sort();

            $references = Reference::where('subject_id', $airport->id)
                ->where('subject_type_id', 4)->get();

            $txy_ids = [];
            foreach ($airport->taxiways as $taxiway) {
                array_push($txy_ids, $taxiway->id);
            }
            $txy_markers = MarkerPoints::whereIn('subject_id', $txy_ids)
                ->where('subject_type_id', 8)->get();

            // APRONS
            $paramsApron = collect();
            $apron_ids   = [];
            foreach ($airport->aprons as $apron) {
                array_push($apron_ids, $apron->id);
            }
            $apron_markers = MarkerPoints::whereIn('subject_id', $apron_ids)
                ->where('subject_type_id', 6)->get();
            foreach ($apron_markers as $marker) {
                $paramsApron->push($marker);
            }

            // SURVEILLANCE
            $paramsSvllc = collect();
            $svllc_ids   = [];
            foreach ($airport->surveillances as $surveillance) {
                array_push($svllc_ids, $surveillance->id);
            }
            $svllc_markers = MarkerPoints::whereIn('subject_id', $svllc_ids)
                ->where('subject_type_id', 9)->get();
            foreach ($svllc_markers as $marker) {
                $paramsSvllc->push($marker);
            }

            // ETOD
            $etod_areas       = EtodAreas::all();
            $etod_areas_order = $this->filter->orderEtodAreas($etod_areas, $airport->etods);

            // OPERATIONS
            $runwayIds = $airport->runways->pluck('id');
            $standIds  = $airport->stands->pluck('id');
            $headerIds = collect();
            foreach ($airport->runways as $runway) {
                $headerIds = $headerIds->merge($runway->headers->pluck('id'));
            }
            $vorIds = collect();
            if ($airport->vor) {
                $vorIds->push($airport->vor->id);
            }

            $subjectFilter = function ($q) use ($airport, $runwayIds, $headerIds, $vorIds, $standIds) {
                $q->where(function ($sq) use ($airport) {
                    $sq->whereIn('type_id', function ($tq) {
                        $tq->select('id')->from('operation_types')
                            ->whereIn('subject_type_id', [4, 6, 8, 9, 10, 11, 13]);
                    })->where('subject_id', $airport->id);
                });

                if ($runwayIds->isNotEmpty()) {
                    $q->orWhere(function ($sq) use ($runwayIds) {
                        $sq->whereIn('type_id', function ($tq) {
                            $tq->select('id')->from('operation_types')->where('subject_type_id', 3);
                        })->whereIn('subject_id', $runwayIds);
                    });
                }

                if ($headerIds->isNotEmpty()) {
                    $q->orWhere(function ($sq) use ($headerIds) {
                        $sq->whereIn('type_id', function ($tq) {
                            $tq->select('id')->from('operation_types')->whereIn('subject_type_id', [1, 2]);
                        })->whereIn('subject_id', $headerIds);
                    });
                }

                if ($vorIds->isNotEmpty()) {
                    $q->orWhere(function ($sq) use ($vorIds) {
                        $sq->whereIn('type_id', function ($tq) {
                            $tq->select('id')->from('operation_types')->where('subject_type_id', 5);
                        })->whereIn('subject_id', $vorIds);
                    });
                }

                if ($standIds->isNotEmpty()) {
                    $q->orWhere(function ($sq) use ($standIds) {
                        $sq->whereIn('type_id', function ($tq) {
                            $tq->select('id')->from('operation_types')->where('subject_type_id', 12);
                        })->whereIn('subject_id', $standIds);
                    });
                }
            };

            if (Auth::user()->hasRole('admin')) {
                $operationsQuery = Operation::with(['type', 'status', 'operator', 'pilot'])
                    ->where($subjectFilter);

            } elseif (Auth::user()->hasRole('company')) {
                $companyUser = CompanyUser::where('user_id', Auth::id())->first();
                if ($companyUser) {
                    $allowedOpIds    = CompanyOperation::where('company_id', $companyUser->company_id)->pluck('operation_id');
                    $operationsQuery = Operation::with(['type', 'status', 'operator', 'pilot'])
                        ->where($subjectFilter)
                        ->whereIn('operations.id', $allowedOpIds);
                } else {
                    $operationsQuery = Operation::whereRaw('1 = 0');
                }
            } else {
                $operationsQuery = Operation::with(['type', 'status', 'operator', 'pilot'])
                    ->where('operations.operator_id', Auth::user()->operator_id)
                    ->where($subjectFilter);

                $permissions = Auth::user()->getAllPermissions();
                if (!$permissions->contains('name', 'operation_view')) {
                    $categoryIds = [];
                    if ($permissions->contains('name', 'op_view_visual')) $categoryIds[] = 1;
                    if ($permissions->contains('name', 'op_view_radio'))  $categoryIds[] = 2;
                    if ($permissions->contains('name', 'op_view_pci'))    $categoryIds[] = 3;

                    if (!empty($categoryIds)) {
                        $typeIds = DB::table('operation_types')
                            ->whereIn('category_id', $categoryIds)->pluck('id');
                        $operationsQuery->whereIn('operations.type_id', $typeIds);
                    } else {
                        $operationsQuery->whereRaw('1 = 0');
                    }
                }
            }

            $airportOperations = $operationsQuery->orderBy('execution_date', 'desc')->paginate(20);

            return response()->json([
                'airport'           => $airport,
                'countries'         => $countries,
                'managers'          => $managers,
                'ambits'            => $ambits,
                'params_airport'    => $paramsAirport,
                'params_runway'     => $paramsRunway,
                'params_header'     => $paramsHeader,
                'params_taxiway'    => $paramsTaxiway,
                'references'        => $references,
                'etod_areas_order'  => $etod_areas_order,
                'txy_markers'       => $txy_markers,
                'svllc_markers'     => $svllc_markers,
                'params_apron'      => $paramsApron,
                'params_svllc'      => $paramsSvllc,
                'airport_operations'=> $airportOperations,
            ], 200);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}/edit',
        summary: 'Obtiene los datos de un aeropuerto para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de edición'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function edit(Airport $airport): JsonResponse
    {
        $this->authorize('airport_edit');

        $check_operator_airport = DB::table('operator_airport')
            ->where('operator_id', Auth::user()->operator_id)
            ->where('subject_type_id', 4)
            ->where('subject_id', $airport->id)
            ->first();

        if ($check_operator_airport === null) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $countries  = Country::all();
        $managers   = AirportManager::all();
        $ambits     = AirportAmbit::all();
        $clients    = Client::where('country_id', $airport->country_id)->orderBy('name')->get();
        $client_ids = $airport->clients()->pluck('company.id')->map(fn ($id) => (int) $id)->toArray();

        return response()->json([
            'airport'    => $airport,
            'countries'  => $countries,
            'managers'   => $managers,
            'ambits'     => $ambits,
            'clients'    => $clients,
            'client_ids' => $client_ids,
        ], 200);
    }

    #[OA\Put(
        path: '/api/airports/{airport}',
        summary: 'Actualiza los datos de un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Aeropuerto actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function update(Request $request, Airport $airport): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'name'                       => 'required|unique:airports,name,' . $airport->id,
            'iata_code'                  => 'nullable|size:3|unique:airports,iata_code,' . $airport->id,
            'icao_code'                  => 'nullable|size:4|unique:airports,icao_code,' . $airport->id,
            'country_id'                 => 'required',
            'ambit_id'                   => 'required',
            'manager_id'                 => 'required',
            'alt_ellipsoid_coord_offset' => 'required',
            'client_id'                  => 'nullable|array',
        ]);

        try {
            $changes   = [];
            $newActive = $request['active'] == 'on' ? 1 : 0;

            if ($airport->name !== $request['name'])                          $changes[] = "Name: '{$airport->name}' → '{$request['name']}'";
            if ($airport->iata_code !== $request['iata_code'])                $changes[] = "IATA: '{$airport->iata_code}' → '{$request['iata_code']}'";
            if ($airport->icao_code !== $request['icao_code'])                $changes[] = "ICAO: '{$airport->icao_code}' → '{$request['icao_code']}'";
            if ((string) $airport->country_id !== (string) $request['country_id']) $changes[] = "Country changed";
            if ((int) $airport->active !== $newActive)                        $changes[] = "Active: '" . ($airport->active ? 'yes' : 'no') . "' → '" . ($newActive ? 'yes' : 'no') . "'";

            $airport->name                       = $request['name'];
            $airport->iata_code                  = $request['iata_code'];
            $airport->icao_code                  = $request['icao_code'];
            $airport->country_id                 = $request['country_id'];
            $airport->ambit_id                   = $request['ambit_id'];
            $airport->manager_id                 = $request['manager_id'];
            $airport->active                     = $newActive;
            $airport->alt_ellipsoid_coord_offset = $request['alt_ellipsoid_coord_offset'];
            $airport->save();

            if (!empty($changes)) {
                ActivityLog::log('update', 'Airport', $airport->id,
                    "Updated airport '{$airport->name}' ({$airport->icao_code}): " . implode(', ', $changes)
                );
            }

            return response()->json([
                'message' => 'Airport updated successfully',
                'airport' => $airport,
            ], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/airports/{airport}',
        summary: 'Elimina un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Aeropuerto eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function destroy(Airport $airport): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $check_operator_airport = DB::table('operator_airport')
                ->where('operator_id', Auth::user()->operator_id)
                ->where('subject_type_id', 4)
                ->where('subject_id', $airport->id)
                ->first();

            if ($check_operator_airport === null) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }

            ActivityLog::log('delete', 'Airport', $airport->id,
                "Deleted airport '{$airport->name}' ({$airport->icao_code})"
            );

            $airport->delete();

            return response()->json(['message' => 'Airport deleted successfully'], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/countries/{country}/airports',
        summary: 'Lista los aeropuertos de un país',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de aeropuertos'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getAirportsInCountry(Country $country): mixed
    {
        $this->authorize('airport_view');

        return Airport::where('country_id', $country->id)->orderBy('name')->get();
    }

    #[OA\Get(
        path: '/api/countries/{country}/airports/operation',
        summary: 'Lista los aeropuertos de un país accesibles para crear operaciones',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de aeropuertos'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getAirportsInCountryOperation(Country $country): mixed
    {
        $this->authorize('operation_create');

        if (Auth::user()->hasRole('admin')) {
            return Airport::where('country_id', $country->id)->orderBy('name')->get();
        }

        $airports_ids = [];
        $airports_to_operator = DB::table('operator_airport')
            ->where('operator_id', Auth::user()->operator_id)
            ->where('subject_type_id', 4)->get();

        foreach ($airports_to_operator as $airport) {
            if (!in_array($airport->subject_id, $airports_ids)) {
                array_push($airports_ids, $airport->subject_id);
            }
        }

        return Airport::whereIn('id', $airports_ids)
            ->where('country_id', $country->id)
            ->orderBy('name')->get();
    }

    #[OA\Get(
        path: '/api/airports/{airport}/data',
        summary: 'Devuelve el modelo completo de un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Airport'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del aeropuerto'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function returnAirport(Airport $airport): mixed
    {
        $this->authorize('airport_view');

        return $airport;
    }

    public function indexReferences(Request $request): void
    {
        // Sin implementación
    }
}
