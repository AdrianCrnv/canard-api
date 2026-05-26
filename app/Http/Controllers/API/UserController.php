<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/users',
        summary: 'Get paginated list of users with optional filters',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'name',     in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status',   in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated users with filter metadata'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('user_view');

        if (Auth::user()->hasRole('company')) {
            return response()->json(['message' => 'You cannot access user management.'], 403);
        }

        $given_operator = $request->input('operator') ?: null;
        $given_name     = $request->input('name')     ?: null;
        $given_status   = $request->input('status')   ?: 'active';

        $usersAll = User::with('operator');

        $usersForFilter = $this->masterFilter(User::with('operator')->get(), $given_operator, $given_name, $given_status);

        if (Auth::user()->hasRole('admin')) {
            $usersClean = $usersForFilter->get();
        } else {
            $baseClean  = User::with('operator')->where('operator_id', Auth::user()->operator->id);
            $usersClean = $this->masterFilter($baseClean->get(), $given_operator, $given_name, $given_status)->get();
        }

        $operator    = $this->operatorSelect($usersForFilter);
        $operatorAll = $this->operatorSelect($usersAll);
        $status      = $this->statusSelect($usersForFilter);
        $statusAll   = $this->statusSelect($usersAll);

        $users = $usersForFilter->orderBy('name', 'asc')->paginate(50);

        return response()->json([
            'users'           => $users,
            'usersClean'      => $usersClean,
            'operators'       => $operator,
            'operatorsAll'    => $operatorAll,
            'statuses'        => $status,
            'statusesAll'     => $statusAll,
            'given_operator'  => $given_operator,
            'given_name'      => $given_name,
            'given_status'    => $given_status,
        ]);
    }

    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/users/form-data',
        summary: 'Get data needed to build the create user form',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OA\Response(response: 200, description: 'Operators, roles and clients'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('user_create');

        return response()->json([
            'operators' => Operator::all(),
            'roles'     => Role::all(),
            'userRoles' => Auth::user()->getRoleNames()->toArray(),
            'clients'   => Company::all(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/users',
        summary: 'Create a new user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('user_create');

        $request->validate([
            'name'        => 'required',
            'operator_id' => 'nullable',
            'email'       => 'required|email|unique:users',
            'password'    => 'required|string|min:8',
            'roles'       => 'required',
        ]);

        try {
            $newUser = User::create([
                'name'        => $request['name'],
                'email'       => $request['email'],
                'password'    => Hash::make($request['password']),
                'operator_id' => $request['operator_id'],
                'is_active'   => true,
            ]);

            if ($request->filled('client_id')) {
                $newUser->assignRole(Role::where('name', 'company')->first());
                DB::table('company_user')->insert([
                    'user_id'    => $newUser->id,
                    'company_id' => $request->input('client_id'),
                ]);
            } else {
                $newUser->assignRole($request['roles']);
            }

            ActivityLog::log('create', 'User', $newUser->id, "New user '{$newUser->name}' ({$newUser->email})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'User', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($newUser->load('operator'), 201);
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/users/{user}/edit-data',
        summary: 'Get user data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User with operators, roles and clients'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(User $user): JsonResponse
    {
        $this->authorize('user_edit');

        $userCompany   = DB::table('company_user')->where('user_id', $user->id)->first();
        $userCompanyId = $userCompany?->company_id;

        return response()->json([
            'user'          => $user,
            'operators'     => Operator::all(),
            'roles'         => Role::all(),
            'userRoles'     => $user->getRoleNames()->toArray(),
            'clients'       => Company::all(),
            'userCompanyId' => $userCompanyId,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/users/{user}',
        summary: 'Update a user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('user_edit');

        if ($request->filled('client_id')) {
            $request->merge(['operator_id' => null]);
        }

        $request->validate([
            'name'        => 'required',
            'operator_id' => 'nullable',
            'email'       => 'required|unique:users,email,' . $user->id,
            'roles'       => 'required',
        ]);

        try {
            $before = [
                'Name'     => $user->name,
                'Email'    => $user->email,
                'Operator' => optional($user->operator)->name ?? $user->operator_id,
                'Active'   => $user->is_active ? 'Yes' : 'No',
                'Roles'    => $user->getRoleNames()->sort()->values()->implode(', '),
            ];

            $user->name        = $request['name'];
            $user->email       = $request['email'];
            $user->operator_id = $request['operator_id'];
            $user->is_active   = $request['is_active'] ? true : false;

            if ($user->is_active && $user->operator && $user->operator->is_active == false) {
                $user->operator->is_active = true;
                $user->operator->save();
            }

            if ($user->id == Auth::id()) {
                $user->is_active = true;
            }

            $passwordChanged = false;
            if ($request['password']) {
                $request->validate([
                    'password' => 'required|string|min:8',
                ]);
                $user->password  = Hash::make($request['password']);
                $passwordChanged = true;
            }

            $user->save();

            if ($request->filled('client_id')) {
                $user->syncRoles(Role::where('name', 'company')->first());
                DB::table('company_user')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['user_id' => $user->id, 'company_id' => $request->input('client_id')]
                );
            } else {
                $user->syncRoles($request['roles']);
                DB::table('company_user')->where('user_id', $user->id)->delete();
            }

            $user->load('operator');
            $after = [
                'Name'     => $user->name,
                'Email'    => $user->email,
                'Operator' => optional($user->operator)->name ?? $user->operator_id,
                'Active'   => $user->is_active ? 'Yes' : 'No',
                'Roles'    => $user->getRoleNames()->sort()->values()->implode(', '),
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }
            if ($passwordChanged) {
                $changes[] = 'Password has been changed';
            }

            $description = "Updated user '{$user->name}' ({$user->email})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'User', $user->id, $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'User', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($user->fresh()->load('operator'));
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/users/{user}',
        summary: 'Delete a user if they have no related operations or maintenances',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User deleted or blocked due to related records'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('user_delete');

        $hasOperations = DB::table('operations')
            ->where('pilot_id', $user->id)
            ->orWhere('technician_id', $user->id)
            ->exists();

        $hasMaintenances = DB::table('maintenances')
            ->where('technician_id', $user->id)
            ->exists();

        if ($hasOperations || $hasMaintenances) {
            return response()->json([
                'message' => 'User cannot be deleted because they have related records in operations or maintenances.',
            ], 200);
        }

        try {
            $userName  = $user->name;
            $userEmail = $user->email;
            $userId    = $user->id;
            $user->delete();
            ActivityLog::log('delete', 'User', $userId, "Deleted user '{$userName}' ({$userEmail})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'User', null, 'Error in destroy: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'User deleted successfully.']);
    }

    // ── Name autocomplete ─────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/users/names',
        summary: 'Search user names for autocomplete',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'data', in: 'query', required: true,  schema: new OA\Schema(type: 'string', description: 'JSON array of user objects with id')),
            new OA\Parameter(name: 'term', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Matching user names'),
        ]
    )]
    public function getNames(Request $request): JsonResponse
    {
        $idUsers = array_column(json_decode($request->data, true) ?? [], 'id');
        $users   = User::whereIn('id', $idUsers)->get();

        $nameRequest = $request->term;
        $opInNames   = $users->pluck('name')->unique()->values()->toArray();

        $results = array_values(array_filter($opInNames, fn($name) => stripos($name, $nameRequest) !== false));

        return response()->json($results);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function operatorSelect($users): array
    {
        $operatorNames = [];
        foreach ($users->get() as $user) {
            if ($user->operator) {
                $operatorNames[] = $user->operator->name;
            }
        }
        return array_count_values($operatorNames);
    }

    private function statusSelect($users): array
    {
        $statusName = [];
        foreach ($users->get() as $user) {
            $statusName[] = $user->is_active;
        }
        $repetition  = array_count_values($statusName);
        $changeNames = [];
        foreach ($repetition as $key => $value) {
            $changeNames[$key ? 'active' : 'inactive'] = $value;
        }
        return $changeNames;
    }

    private function operatorFilter($users, string $given_operator): array
    {
        $idOperators = [];
        foreach ($users as $user) {
            if ($user->operator && strcmp($given_operator, $user->operator->name) === 0) {
                $idOperators[] = $user->operator->id;
            }
        }
        return $idOperators;
    }

    private function nameFilter($users, string $given_name): array
    {
        $idName      = [];
        $idNameEmpty = [];
        foreach ($users as $user) {
            if (strcmp($given_name, $user->name) === 0) {
                $idName[] = $user->id;
            } else {
                $idNameEmpty[] = $user->id;
            }
        }
        return count($idName) > 0 ? $idName : $idNameEmpty;
    }

    private function statusFilter($users, string $given_status): array
    {
        $idUser = [];
        foreach ($users as $user) {
            $active = (int) $user->is_active;
            if ($given_status === 'active' && $active === 1) {
                $idUser[] = $user->id;
            } elseif ($given_status === 'inactive' && $active === 0) {
                $idUser[] = $user->id;
            } elseif ($given_status === 'all') {
                $idUser[] = $user->id;
            }
        }
        return $idUser;
    }

    private function masterFilter($usersM, ?string $given_operator, ?string $given_name, ?string $given_status)
    {
        return User::with('operator')
            ->where(function ($query) use ($usersM, $given_operator) {
                if ($given_operator !== null) {
                    $query->whereIn('operator_id', $this->operatorFilter($usersM, $given_operator));
                }
            })
            ->where(function ($query) use ($usersM, $given_name) {
                if ($given_name !== null) {
                    $query->whereIn('id', $this->nameFilter($usersM, $given_name));
                }
            })
            ->where(function ($query) use ($usersM, $given_status) {
                if ($given_status !== null) {
                    $query->whereIn('id', $this->statusFilter($usersM, $given_status));
                }
            });
    }
}
