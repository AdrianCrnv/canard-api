<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Country;
use App\Models\Parameter;
use App\Models\Reference;
use App\Models\TaskType;
use App\Models\Vor;
use App\Models\VorChannel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class VorController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors',
        summary: 'Get paginated list of VORs with optional country filter',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated VORs with filter metadata'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('navaid_view');

        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        $vors_ids = DB::table('operator_airport')
            ->where('operator_id', $user->operator_id)
            ->where('subject_type_id', 5)
            ->pluck('subject_id')
            ->unique()
            ->toArray();

        $given_country = $request->input('country') ?: null;

        if ($user->hasRole('admin')) {
            $vors    = $this->filterMasterAdmin($given_country);
            $allVors = Vor::with('country')->orderBy('name', 'asc')->get();
        } elseif ($permissions->contains('name', 'airport_view')) {
            $vors    = $this->filterMasterNoAdmin($vors_ids, $given_country);
            $allVors = $user->operator->vors()->with('country')->orderBy('name', 'asc')->get();
        } else {
            $vors    = Vor::with(['country', 'airport', 'channel'])->whereRaw('0 = 1');
            $allVors = collect();
        }

        $operatorCountry   = $user->operator->country ?? null;
        $filterCountries   = $this->countrySelect($vors->get());
        $allCountryOptions = $this->countrySelectOrdered($allVors, $operatorCountry);

        $paginated = $vors->sortable(['name' => 'asc'])->paginate(50);

        return response()->json([
            'vors'              => $paginated,
            'allCountryOptions' => $allCountryOptions,
            'filterCountries'   => $filterCountries,
            'given_country'     => $given_country,
        ]);
    }

    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors/form-data',
        summary: 'Get data needed to build the create VOR form',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        responses: [
            new OA\Response(response: 200, description: 'Channels, countries and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('navaid_create');

        $vor = new Vor();

        return response()->json([
            'channels'   => VorChannel::all(),
            'countries'  => Country::all(),
            'task_types' => $this->prepareVorParameters($vor, 1),
            'parameters' => $this->prepareVorParameters($vor),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/vors',
        summary: 'Create a new VOR',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        responses: [
            new OA\Response(response: 201, description: 'VOR created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('navaid_create');

        $request->validate([
            'name'                 => 'required|unique:vors',
            'code'                 => 'required|max:3',
            'channel_id'           => 'required',
            'country_id'           => 'required',
            'latitude'             => 'required|numeric|between:-90,90',
            'longitude'            => 'required|numeric|between:-180,180',
            'elevation'            => 'required|numeric|between:0,9999.999',
            'ref_radial'           => 'required|numeric|between:1, 360',
            'magnetic_declination' => 'required|numeric|between:-180,180',
            'declination_date'     => 'required|date',
            'vor_type'             => 'nullable|numeric',
            'location'             => 'required',
            'airport'              => 'requiredIf:location,===,terminal|numeric',
            'yesDme'               => 'sometimes|required',
            'dmeLatitude'          => 'required_with:yesDme|numeric|between:-90,90',
            'dmeLongitude'         => 'required_with:yesDme|numeric|between:-180,180',
            'dmeElevation'         => 'required_with:yesDme|numeric|between:0,9999.999',
        ]);

        try {
            $enroute = strcmp($request['location'], 'enroute') === 0;

            $vor = Vor::create([
                'name'                       => $request['name'],
                'code'                       => $request['code'],
                'channel_id'                 => $request['channel_id'],
                'country_id'                 => $request['country_id'],
                'latitude'                   => $request['latitude'],
                'longitude'                  => $request['longitude'],
                'elevation'                  => $request['elevation'],
                'ref_radial'                 => $request['ref_radial'],
                'alt_ellipsoid_coord_offset' => $request['alt_ellipsoid_coord_offset'],
                'magnetic_declination'       => $request['magnetic_declination'],
                'declination_date'           => Carbon::parse($request['declination_date']),
                'vor_type'                   => $request['vor_type'],
                'enroute'                    => $enroute,
                'dme_latitude'               => $request['dmeLatitude'] ?? null,
                'dme_longitude'              => $request['dmeLongitude'] ?? null,
                'dme_elevation'              => $request['dmeElevation'] ?? null,
                'airport_id'                 => $enroute ? null : $request['airport'],
            ]);

            $this->storeVorParameters($request, $vor);

            if ($request['location'] === 'terminal') {
                $operatorIds = DB::table('operator_airport')
                    ->where('subject_id', $request['airport'])
                    ->where('subject_type_id', 5)
                    ->pluck('operator_id')
                    ->unique();

                foreach ($operatorIds as $operatorId) {
                    $this->saveTerminalVorOperator($vor, $operatorId);
                }
            }

            DB::table('operator_airport')->insert([
                'operator_id'     => Auth::user()->operator_id,
                'subject_id'      => $vor->id,
                'subject_type_id' => 5,
            ]);

            if (Auth::user()->operator_id != 1) {
                DB::table('operator_airport')->insert([
                    'operator_id'     => 1,
                    'subject_id'      => $vor->id,
                    'subject_type_id' => 4,
                ]);
            }

            if ($vor->airport_id) {
                $airport = Airport::find($vor->airport_id);
                ActivityLog::log('create', 'Airport', (int) $vor->airport_id, "New system: VOR '{$vor->name}' ({$vor->code}) at airport '{$airport->name}' ({$airport->icao_code})");
            } else {
                ActivityLog::log('create', 'VOR', $vor->id, "New system: VOR '{$vor->name}' ({$vor->code}) [enroute]");
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'VOR', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($vor->load('channel', 'country', 'airport'), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors/{vor}',
        summary: 'Get VOR data with parameters and references',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Vor $vor): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json($this->buildVorPayload($vor));
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors/{vor}/edit-data',
        summary: 'Get VOR data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR with channels, countries and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Vor $vor): JsonResponse
    {
        $this->authorize('navaid_edit');

        return response()->json($this->buildVorPayload($vor));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/vors/{vor}',
        summary: 'Update a VOR',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Vor $vor): JsonResponse
    {
        $this->authorize('navaid_edit');

        $request->validate([
            'name'                 => 'required|unique:vors,name,' . $vor->id,
            'code'                 => 'required|max:3',
            'channel_id'           => 'required',
            'country_id'           => 'required',
            'latitude'             => 'required|numeric|between:-90,90',
            'longitude'            => 'required|numeric|between:-180,180',
            'elevation'            => 'required|numeric|between:0,9999.999',
            'ref_radial'           => 'required|numeric|between:1, 360',
            'magnetic_declination' => 'required|numeric|between:-180,180',
            'declination_date'     => 'required|date',
            'vor_type'             => 'nullable|numeric',
            'airport'              => 'requiredIf:location,===,terminal|numeric',
            'yesDme'               => 'sometimes|required',
            'dmeLatitude'          => 'required_with:yesDme|numeric|between:-90,90',
            'dmeLongitude'         => 'required_with:yesDme|numeric|between:-180,180',
            'dmeElevation'         => 'required_with:yesDme|numeric|between:0,9999.999',
        ]);

        try {
            $enroute = strcmp($request['location'], 'enroute') === 0 ? 1 : 0;

            $changes = [];
            if ($vor->name !== $request['name']) $changes[] = "Name: '{$vor->name}' → '{$request['name']}'";
            if ($vor->code !== $request['code']) $changes[] = "Code: '{$vor->code}' → '{$request['code']}'";

            $vor->name                       = $request['name'];
            $vor->code                       = $request['code'];
            $vor->channel_id                 = $request['channel_id'];
            $vor->country_id                 = $request['country_id'];
            $vor->latitude                   = $request['latitude'];
            $vor->longitude                  = $request['longitude'];
            $vor->elevation                  = $request['elevation'];
            $vor->ref_radial                 = $request['ref_radial'];
            $vor->alt_ellipsoid_coord_offset = $request['alt_ellipsoid_coord_offset'];
            $vor->magnetic_declination       = $request['magnetic_declination'];
            $vor->declination_date           = Carbon::parse($request['declination_date']);
            $vor->vor_type                   = $request['vor_type'];
            $vor->enroute                    = $enroute;
            $vor->airport_id                 = $enroute ? null : $request['airport'];
            $vor->dme_latitude               = $request['dmeLatitude'] ?? null;
            $vor->dme_longitude              = $request['dmeLongitude'] ?? null;
            $vor->dme_elevation              = $request['dmeElevation'] ?? null;
            $vor->save();

            $this->updateVorParameters($request, $vor);

            if ($vor->airport_id) {
                $airport     = Airport::find($vor->airport_id);
                $description = "Updated system: VOR '{$vor->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                    . (!empty($changes) ? ': ' . implode(', ', $changes) : '');
                ActivityLog::log('update', 'Airport', (int) $vor->airport_id, $description);
            } else {
                $description = "Updated system: VOR '{$vor->name}' [enroute]"
                    . (!empty($changes) ? ': ' . implode(', ', $changes) : '');
                ActivityLog::log('update', 'VOR', $vor->id, $description);
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'VOR', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($vor->fresh()->load('channel', 'country', 'airport'));
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/vors/{vor}',
        summary: 'Delete a VOR',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Vor $vor): JsonResponse
    {
        $this->authorize('navaid_delete');

        try {
            if ($vor->airport_id) {
                $airport = Airport::find($vor->airport_id);
                ActivityLog::log('delete', 'Airport', (int) $vor->airport_id, "Deleted system: VOR '{$vor->name}' ({$vor->code}) from airport '{$airport->name}' ({$airport->icao_code})");
            } else {
                ActivityLog::log('delete', 'VOR', $vor->id, "Deleted system: VOR '{$vor->name}' ({$vor->code}) [enroute]");
            }

            $vor->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'VOR', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'VOR deleted successfully.']);
    }

    // ── Utility endpoints ─────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/vors/{vor}/magnetic-declination',
        summary: 'Update magnetic declination for a VOR',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR updated or not found'),
        ]
    )]
    public function updateMagneticDeclination(Request $request): JsonResponse
    {
        $id                    = $request->input('id');
        $newMagneticDeclination = $request->input('newMagnetic');

        $newVor = tap(Vor::where('id', $id))->update([
            'magnetic_declination' => $newMagneticDeclination,
            'declination_date'     => Carbon::now(),
        ])->first();

        if ($newVor !== null) {
            return response()->json(['message' => true, 'vorUpdated' => $newVor]);
        }

        return response()->json(['message' => false]);
    }

    #[OA\Get(
        path: '/api/vors/code-search',
        summary: 'Search VORs by code or name for autocomplete',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'term', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Matching VORs'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getCode(Request $request): JsonResponse
    {
        $this->authorize('airport_view');

        $add  = Auth::user()->can('airport_edit') ? '/edit' : '/show';
        $term = $request->get('term');

        $data = Vor::where(function ($query) use ($term) {
            $query->where('code', 'LIKE', "%{$term}%")
                  ->orWhere('name', 'LIKE', "%{$term}%");
        })->orderBy('name', 'asc')->get();

        $results = $data->map(fn($v) => [
            'value' => $v->code . ' - ' . $v->name,
            'label' => $v->code . ' - ' . $v->name,
            'link'  => 'vors/' . $v->id . $add,
        ])->values()->toArray();

        return response()->json($results);
    }

    #[OA\Get(
        path: '/api/countries/{country}/vors',
        summary: 'Get all VORs for a country',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of VORs'),
        ]
    )]
    public function getVorsInCountry(Country $country): JsonResponse
    {
        return response()->json(Vor::where('country_id', $country->id)->get());
    }

    #[OA\Get(
        path: '/api/countries/{country}/airports',
        summary: 'Get all airports for a country',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of airports'),
        ]
    )]
    public function getJsonAirports(Country $country): JsonResponse
    {
        return response()->json($country->airports);
    }

    #[OA\Get(
        path: '/api/countries/{country}/vors/operation',
        summary: 'Get VORs for a country in operation creation context',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of VORs'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getVorsInCountryOperation(Country $country): JsonResponse
    {
        $this->authorize('operation_create');

        if (Auth::user()->hasRole('admin')) {
            $vors = Vor::where('country_id', $country->id)->orderBy('name')->get();
        } else {
            $vors_ids = DB::table('operator_airport')
                ->where('operator_id', Auth::user()->operator_id)
                ->where('subject_type_id', 5)
                ->pluck('subject_id')
                ->unique()
                ->toArray();

            $vors = Vor::whereIn('id', $vors_ids)
                ->where('country_id', $country->id)
                ->orderBy('name')
                ->get();
        }

        return response()->json($vors);
    }

    #[OA\Get(
        path: '/api/vors/{vor}/json',
        summary: 'Get raw VOR object',
        security: [['bearerAuth' => []]],
        tags: ['VORs'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR object'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getJsonVor(Vor $vor): JsonResponse
    {
        return response()->json($vor);
    }

    // ── Parameter helpers ─────────────────────────────────────────────────────

    public function prepareVorParameters(Vor $vor, ?int $opt = null): array
    {
        $vor_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', 7)->get();

        if ($opt) {
            return $vor_task_type_id->map(fn($t) => TaskType::find($t->task_type_id))->filter()->values()->all();
        }

        $parameters = [];
        foreach ($vor_task_type_id as $vor_task_type) {
            $parameter_type_rows = DB::table('parameter_type_task_type')->where('task_type_id', $vor_task_type->task_type_id)->get();
            foreach ($parameter_type_rows as $parameter_type) {
                $parameters[] = [
                    'task_type'      => TaskType::find($parameter_type->task_type_id),
                    'parameter_type' => DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first(),
                    'value'          => Parameter::where([
                        'parameter_type_id' => $parameter_type->parameter_type_id,
                        'subject_id'        => $vor->id,
                        'task_type_id'      => $parameter_type->task_type_id,
                    ])->first(),
                ];
            }
        }

        return $parameters;
    }

    public function storeVorParameters(Request $request, Vor $vor): void
    {
        $skip = ['_method', '_token', 'name', 'code', 'channel_id', 'country_id', 'vor_type',
                 'latitude', 'longitude', 'elevation', 'ref_radial', 'dme',
                 'magnetic_declination', 'alt_ellipsoid_coord_offset', 'declination_date',
                 'angular_velocity_degrees', 'lineal_velocity', 'angular_velocity_rad', 'orbit_time',
                 'airport', 'location', 'dmeLatitude', 'dmeLongitude', 'dmeElevation', 'dmeChannel', 'yesDme'];

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skip)) continue;

            $ids    = explode('-', $key);
            $exists = Parameter::where(['subject_id' => $vor->id, 'parameter_type_id' => $ids[0], 'task_type_id' => $ids[1]])->first();
            if ($exists) $exists->delete();

            Parameter::create([
                'subject_type_id'   => 5,
                'subject_id'        => $vor->id,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $ids[1],
                'value'             => $request[$key],
            ]);
        }
    }

    public function updateVorParameters(Request $request, Vor $vor): void
    {
        $skip = ['_method', '_token', 'name', 'code', 'channel_id', 'country_id', 'vor_type',
                 'latitude', 'longitude', 'elevation', 'ref_radial', 'dme',
                 'magnetic_declination', 'alt_ellipsoid_coord_offset', 'declination_date',
                 'angular_velocity_degrees', 'lineal_velocity', 'angular_velocity_rad', 'orbit_time',
                 'airport', 'location', 'dmeLatitude', 'dmeLongitude', 'dmeElevation', 'dmeChannel', 'yesDme'];

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skip)) continue;

            $ids    = explode('-', $key);
            $exists = Parameter::where(['subject_id' => $vor->id, 'parameter_type_id' => $ids[0], 'task_type_id' => $ids[1]])->first();
            if ($exists) $exists->delete();

            Parameter::create([
                'subject_type_id'   => 5,
                'subject_id'        => $vor->id,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $ids[1],
                'value'             => $request[$key],
            ]);
        }
    }

    public function saveTerminalVorOperator(Vor $vor, int $operator_id): void
    {
        DB::table('operator_airport')->insert([
            'operator_id'     => $operator_id,
            'subject_id'      => $vor->id,
            'subject_type_id' => 5,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildVorPayload(Vor $vor): array
    {
        return [
            'vor'        => $vor,
            'channels'   => VorChannel::all(),
            'countries'  => Country::all(),
            'task_types' => $this->prepareVorParameters($vor, 1),
            'parameters' => $this->prepareVorParameters($vor),
            'references' => Reference::where('subject_id', $vor->id)->where('subject_type_id', 5)->get(),
        ];
    }

    private function countrySelectOrdered($vors, $operatorCountry = null): array
    {
        $countriesAllVor = $vors->pluck('country.name')->unique()->sort()->values()->toArray();

        if ($operatorCountry && ($key = array_search($operatorCountry->name, $countriesAllVor)) !== false) {
            array_splice($countriesAllVor, $key, 1);
            array_unshift($countriesAllVor, $operatorCountry->name);
        }

        $result = [];
        foreach ($countriesAllVor as $country) {
            $result[$country] = $vors->where('country.name', $country)->count();
        }

        return $result;
    }

    private function countrySelect($vors): array
    {
        $names       = $vors->map(fn($v) => $v->country->name)->toArray();
        $repetition  = array_count_values($names);
        ksort($repetition);
        return $repetition;
    }

    private function filterMasterAdmin(?string $given_country)
    {
        $query = Vor::with(['country', 'airport', 'channel']);
        if ($given_country !== null) {
            $ids = $this->countryFilter(Vor::with('country')->get(), $given_country);
            $query->whereIn('id', $ids);
        }
        return $query;
    }

    private function filterMasterNoAdmin(array $vors_ids, ?string $given_country)
    {
        $query = Vor::with(['country', 'airport', 'channel'])->whereIn('id', $vors_ids);
        if ($given_country !== null) {
            $ids = $this->countryFilter(Vor::whereIn('id', $vors_ids)->with('country')->get(), $given_country);
            $query->whereIn('id', $ids);
        }
        return $query;
    }

    private function countryFilter($vors, string $given_country): array
    {
        return $vors->filter(fn($v) => strcmp($given_country, $v->country->name) === 0)->pluck('id')->toArray();
    }
}
