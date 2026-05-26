<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Country;
use App\Models\Operator;
use App\Models\User;
use App\Models\Vor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class OperatorController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators',
        summary: 'List operators with optional filters',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'page',   in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of operators'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('operator_view');

        $query = Operator::orderBy('name', 'asc');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->active === 'active' ? 1 : 0);
        }

        return response()->json($query->paginate(50));
    }

    // ── Autocomplete ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/autocomplete',
        summary: 'Autocomplete operator names',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'term', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array of label/value pairs'),
        ]
    )]
    public function autocomplete(Request $request): JsonResponse
    {
        $results = Operator::where('name', 'like', '%' . $request->term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn($op) => ['label' => $op->name, 'value' => $op->name]);

        return response()->json($results);
    }

    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/form-data',
        summary: 'Get data needed to build the create operator form',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        responses: [
            new OA\Response(response: 200, description: 'Countries, airports, VORs and clients'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('operator_create');

        return response()->json([
            'countries' => Country::orderBy('name')->get(),
            'airports'  => Airport::orderBy('name')->get(),
            'vors'      => Vor::where('enroute', 1)->orderBy('name')->get(),
            'clients'   => Company::orderBy('name')->get(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/operators',
        summary: 'Create a new operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'country_id', 'airport_id'],
                    properties: [
                        new OA\Property(property: 'name',       type: 'string'),
                        new OA\Property(property: 'country_id', type: 'integer'),
                        new OA\Property(property: 'airport_id', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'vor_id',     type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'client_id',  type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'photo',      type: 'string', format: 'binary'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operator created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('operator_create');

        $request->validate([
            'name'       => 'required|unique:operators',
            'country_id' => 'required',
            'photo'      => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'airport_id' => 'required',
        ]);

        try {
            $operator = Operator::create([
                'name'       => $request['name'],
                'country_id' => $request['country_id'],
                'is_active'  => 1,
            ]);

            $this->saveVorsOperator($request['vor_id'], $operator['id']);
            $this->saveAirportsOperator($request['airport_id'], $operator['id']);
            $this->saveClientsOperator($request->input('client_id', []), $operator['id']);

            if ($request->file('photo')) {
                $operator->addMediaFromRequest('photo')->toMediaCollection('operator_header', 'public');
                $path           = asset($operator->getMedia('operator_header')->last()->getUrl());
                $bar_location   = strpos($path, 'public') + 6;
                $operator->photo = substr($path, $bar_location);
            }

            $operator->save();

            ActivityLog::log('create', 'Operator', $operator->id, "Created operator '{$operator->name}'");

            if (!empty($request['airport_id'])) {
                $names = Airport::whereIn('id', $request['airport_id'])->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added airports to operator '{$operator->name}': {$names}");
            }
            if (!empty($request['vor_id'])) {
                $names = Vor::whereIn('id', $request['vor_id'])->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added VORs to operator '{$operator->name}': {$names}");
            }
            $clientIds = $request->input('client_id', []);
            if (!empty($clientIds)) {
                $names = Company::whereIn('id', $clientIds)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added clients to operator '{$operator->name}': {$names}");
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operator', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($operator->fresh(), 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/{operator}',
        summary: 'Get a single operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operator data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Operator $operator): JsonResponse
    {
        $this->authorize('operator_view');

        return response()->json($operator);
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/{operator}/edit-data',
        summary: 'Get operator data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operator with countries, airports, VORs and clients'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Operator $operator): JsonResponse
    {
        $this->authorize('operator_edit');

        $airports_ids = DB::table('operator_airport')
            ->where('subject_type_id', 4)
            ->where('operator_id', $operator->id)
            ->pluck('subject_id')
            ->toArray();

        $vors_ids = DB::table('operator_airport')
            ->where('subject_type_id', 5)
            ->where('operator_id', $operator->id)
            ->pluck('subject_id')
            ->toArray();

        return response()->json([
            'operator'            => $operator,
            'countries'           => Country::orderBy('name')->get(),
            'airports'            => Airport::orderBy('name')->get(),
            'vors'                => Vor::where('enroute', 1)->orderBy('name')->get(),
            'clients'             => Company::orderBy('name')->get(),
            'airports_ids'        => $airports_ids,
            'vors_ids'            => $vors_ids,
            'clients_ids'         => $operator->clients()->pluck('company.id')->toArray(),
            'airports_to_operator' => Airport::whereIn('id', $airports_ids)->get(),
            'vors_to_operator'    => Vor::whereIn('id', $vors_ids)->get(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/operators/{operator}',
        summary: 'Update an existing operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operator updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Operator $operator): JsonResponse
    {
        $this->authorize('operator_edit');

        $request->validate([
            'name'       => 'required|unique:operators,name,' . $operator->id,
            'country_id' => 'required',
            'photo'      => 'nullable|mimes:jpeg,jpg,png|max:2048',
        ]);

        try {
            $changes = [];
            if ($operator->name !== $request['name'])
                $changes[] = "Name: '{$operator->name}' → '{$request['name']}'";
            if ((string) $operator->country_id !== (string) $request['country_id'])
                $changes[] = "Country: '{$operator->country_id}' → '{$request['country_id']}'";

            $oldActive = (bool) $operator->is_active;
            $newActive = (bool) $request['is_active'];
            if ($oldActive !== $newActive)
                $changes[] = "Active: '" . ($oldActive ? 'yes' : 'no') . "' → '" . ($newActive ? 'yes' : 'no') . "'";

            $operator->name       = $request['name'];
            $operator->country_id = $request['country_id'];
            $operator->is_active  = $newActive;

            if (!$operator->is_active) {
                User::where('operator_id', $operator->id)->update(['is_active' => false]);
            }

            if ($request->file('photo')) {
                $operator->clearMediaCollection('operator_header');
                $operator->addMediaFromRequest('photo')->toMediaCollection('operator_header', 'public');
                $path            = asset($operator->getMedia('operator_header')->last()->getUrl());
                $bar_location    = strpos($path, 'public') + 6;
                $operator->photo = substr($path, $bar_location);
                $changes[]       = 'Photo updated';
            }

            $oldAirportIds = DB::table('operator_airport')->where('operator_id', $operator->id)->where('subject_type_id', 4)->pluck('subject_id')->toArray();
            $oldVorIds     = DB::table('operator_airport')->where('operator_id', $operator->id)->where('subject_type_id', 5)->pluck('subject_id')->toArray();
            $oldClientIds  = $operator->clients()->pluck('company.id')->toArray();
            $newAirportIds = array_map('intval', $request['airport_id'] ?? []);
            $newVorIds     = array_map('intval', $request['vor_id'] ?? []);
            $newClientIds  = array_map('intval', $request->input('client_id', []));

            $this->saveVorsOperator($request['vor_id'], $operator['id']);
            $this->saveAirportsOperator($request['airport_id'], $operator['id']);
            $this->saveClientsOperator($request->input('client_id', []), $operator['id']);

            $operator->save();

            if (!empty($changes)) {
                ActivityLog::log('update', 'Operator', $operator->id, "Updated operator '{$operator->name}': " . implode(', ', $changes));
            }

            $addedAirports   = array_diff($newAirportIds, $oldAirportIds);
            $removedAirports = array_diff($oldAirportIds, $newAirportIds);
            $addedVors       = array_diff($newVorIds, $oldVorIds);
            $removedVors     = array_diff($oldVorIds, $newVorIds);
            $addedClients    = array_diff($newClientIds, $oldClientIds);
            $removedClients  = array_diff($oldClientIds, $newClientIds);

            if (!empty($addedAirports)) {
                $names = Airport::whereIn('id', $addedAirports)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added airports to operator '{$operator->name}': {$names}");
            }
            if (!empty($removedAirports)) {
                $names = Airport::whereIn('id', $removedAirports)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Removed airports from operator '{$operator->name}': {$names}");
            }
            if (!empty($addedVors)) {
                $names = Vor::whereIn('id', $addedVors)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added VORs to operator '{$operator->name}': {$names}");
            }
            if (!empty($removedVors)) {
                $names = Vor::whereIn('id', $removedVors)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Removed VORs from operator '{$operator->name}': {$names}");
            }
            if (!empty($addedClients)) {
                $names = Company::whereIn('id', $addedClients)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Added clients to operator '{$operator->name}': {$names}");
            }
            if (!empty($removedClients)) {
                $names = Company::whereIn('id', $removedClients)->pluck('name')->implode(', ');
                ActivityLog::log('update', 'Operator', $operator->id, "Removed clients from operator '{$operator->name}': {$names}");
            }
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operator', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($operator->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/operators/{operator}',
        summary: 'Delete or deactivate an operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operator deleted or deactivated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Operator $operator): JsonResponse
    {
        $this->authorize('operator_delete');

        try {
            // Must be inactive before deletion
            if ($operator->is_active) {
                $operator->is_active = false;
                $operator->save();

                return response()->json([
                    'message' => 'The operator has been marked as inactive. Set it inactive before deleting it.',
                    'status'  => 'deactivated',
                ], 200);
            }

            $hasUsers      = User::where('operator_id', $operator->id)->exists();
            $hasDrones     = DB::table('drones')->where('operator_id', $operator->id)->exists();
            $hasOperations = DB::table('operations')->where('operator_id', $operator->id)->exists();

            $hasAirports = DB::table('operator_airport')
                ->where('operator_id', $operator->id)
                ->where('subject_type_id', 4)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('airports')
                        ->whereColumn('airports.id', 'operator_airport.subject_id');
                })->exists();

            $hasVors = DB::table('operator_airport')
                ->where('operator_id', $operator->id)
                ->where('subject_type_id', 5)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('vors')
                        ->whereColumn('vors.id', 'operator_airport.subject_id');
                })->exists();

            if ($hasUsers || $hasDrones || $hasOperations || $hasAirports || $hasVors) {
                $operator->is_active = false;
                $operator->save();

                if ($hasUsers) {
                    User::where('operator_id', $operator->id)->update(['is_active' => false]);
                }

                ActivityLog::log('delete', 'Operator', $operator->id, "Attempted delete of operator '{$operator->name}' — deactivated due to assigned elements");

                return response()->json([
                    'message' => 'The operator has assigned elements and has been marked as inactive.',
                    'status'  => 'deactivated',
                ], 200);
            }

            // No dependencies: clean up and hard-delete
            ActivityLog::log('delete', 'Operator', $operator->id, "Deleted operator '{$operator->name}'");

            User::where('operator_id', $operator->id)->delete();
            DB::table('operator_airport')->where('operator_id', $operator->id)->delete();
            $operator->clearMediaCollection('operator_header');
            $operator->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operator', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Operator deleted successfully.', 'status' => 'deleted']);
    }

    // ── Pilots ────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/{operator}/pilots',
        summary: 'Get pilots belonging to an operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of pilots'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getPilots(Operator $operator): JsonResponse
    {
        return response()->json($operator->pilots());
    }

    // ── Technicians ───────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operators/{operator}/technicians',
        summary: 'Get active technicians belonging to an operator',
        security: [['bearerAuth' => []]],
        tags: ['Operators'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of active technicians'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getTechnicians(Operator $operator): JsonResponse
    {
        return response()->json($operator->activeTechnicians());
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    public function saveAirportsOperator($airports, $operator_id): void
    {
        DB::table('operator_airport')
            ->where('operator_id', $operator_id)
            ->where('subject_type_id', 4)
            ->delete();

        if ($airports !== null) {
            foreach ($airports as $airport_id) {
                DB::table('operator_airport')->insert([
                    'operator_id'     => $operator_id,
                    'subject_id'      => $airport_id,
                    'subject_type_id' => 4,
                ]);
                $vor = Vor::where('airport_id', $airport_id)->first();
                if ($vor !== null) {
                    $this->saveTerminalVorOperator($vor, $operator_id);
                }
            }
        }
    }

    public function saveVorsOperator($vors, $operator_id): void
    {
        DB::table('operator_airport')
            ->where('operator_id', $operator_id)
            ->where('subject_type_id', 5)
            ->delete();

        if ($vors !== null) {
            foreach ($vors as $vor_id) {
                DB::table('operator_airport')->insert([
                    'operator_id'     => $operator_id,
                    'subject_id'      => $vor_id,
                    'subject_type_id' => 5,
                ]);
            }
        }
    }

    public function saveTerminalVorOperator($vor, $operator_id): void
    {
        DB::table('operator_airport')->insert([
            'operator_id'     => $operator_id,
            'subject_id'      => $vor->id,
            'subject_type_id' => 5,
        ]);
    }

    private function saveClientsOperator($clients, $operator_id): void
    {
        DB::table('operator_company')->where('operator_id', $operator_id)->delete();

        if (!empty($clients)) {
            foreach ($clients as $company_id) {
                DB::table('operator_company')->insert([
                    'operator_id' => $operator_id,
                    'company_id'  => $company_id,
                ]);
            }
        }
    }
}
