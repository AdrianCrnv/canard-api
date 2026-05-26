<?php

namespace App\Http\Controllers\Api;

use App\Operation;
use App\Maintenance;
use App\Drone;
use App\Airport;
use App\Operator;
use App\CompanyOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class DashboardFilterController extends DashboardController
{
    #[OA\Get(
        path: '/api/dashboard/operator/{operator_id}',
        summary: 'Get dashboard data filtered by operator (admin only)',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'operator_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard data for the operator'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Operator not found'),
        ]
    )]
    public function filterByOperator(int $operator_id): JsonResponse
    {
        if (!Auth::user()->hasRole('admin')) {
            abort(403);
        }

        $operator = Operator::findOrFail($operator_id);

        $nextOperations = Operation::whereIn('status_id', [1, 2])
            ->where('operator_id', $operator_id)
            ->orderByDesc('execution_date')->take(5)->get();

        $lastOperations = Operation::whereIn('status_id', [3])
            ->where('operator_id', $operator_id)
            ->orderByDesc('execution_date')->take(5)->get();

        $activeDronesByType = DB::table('drone_types')
            ->leftJoin('drones', 'drone_types.id', '=', 'drones.type_id')
            ->where('drones.status_id', '=', 1)
            ->where('drones.operator_id', $operator_id)
            ->select('drone_types.name', DB::raw('COUNT(drones.id) as count'))
            ->groupBy('drone_types.name')
            ->get();

        $dronesOpId         = Drone::where('operator_id', $operator_id)->pluck('id')->toArray();
        $dronesMaintenances = Maintenance::whereIn('subject_id', $dronesOpId)
            ->sortable(['execution_date' => 'desc'])->paginate(50);
        $dronesWithMaintenances = Drone::whereIn('id', $dronesOpId)->get();

        $allSystemsAndCount = $this->buildSystemsCount($operator_id);
        $airports           = $this->buildAirportsList($operator_id);
        $this->buildInfrastructureCounts($airports, $allSystemsAndCount);

        $allOps           = Operation::where('operator_id', $operator_id)
            ->where('status_id', 3)->where('is_demo', 0)->where('is_test', 0)->get();
        $allOpsOperations = $this->getType($allOps, false);
        array_multisort($allOpsOperations[0], $allOpsOperations[1]);

        $operatorTaskIds = DB::table('tasks')
            ->join('operations', 'tasks.operation_id', '=', 'operations.id')
            ->where('operations.operator_id', $operator_id)
            ->where('operations.is_demo', 0)
            ->where('operations.is_test', 0)
            ->pluck('tasks.id')->toArray();

        $currentYear = date('Y');

        $resultsPapi = DB::table('results_papi_vertical_angle')
            ->selectRaw('papi_id, task_id, light_id, COUNT(*) as cantidad, YEAR(created_at) as year')
            ->whereBetween('created_at', [($currentYear - 4) . '-01-01', $currentYear . '-12-31'])
            ->whereIn('task_id', $operatorTaskIds)
            ->groupBy('papi_id', 'task_id', 'light_id', 'year')
            ->get();

        $valuesPapiLights = [];
        foreach ($resultsPapi as $r) {
            $year = $r->year; $cantidad = $r->cantidad;
            if (!isset($valuesPapiLights[$year])) $valuesPapiLights[$year] = [];
            isset($valuesPapiLights[$year][$cantidad])
                ? $valuesPapiLights[$year][$cantidad]++
                : $valuesPapiLights[$year][$cantidad] = 1;
        }

        $resultsLightsPapi = DB::table('results_papi_vertical_angle')
            ->selectRaw('task_id, COUNT(*) as cantidad, YEAR(created_at) as year')
            ->whereBetween('created_at', [($currentYear - 4) . '-01-01', $currentYear . '-12-31'])
            ->whereIn('task_id', $operatorTaskIds)
            ->groupBy('task_id', 'year')
            ->get();

        $lightsToPapi = [];
        foreach ($resultsLightsPapi as $r) {
            $year = $r->year; $cantidad = $r->cantidad;
            if (!isset($lightsToPapi[$year])) $lightsToPapi[$year] = array_fill(0, 5, 0);
            if ($cantidad === 4)     $lightsToPapi[$year][0]++;
            elseif ($cantidad === 5) $lightsToPapi[$year][1]++;
            elseif ($cantidad === 6) $lightsToPapi[$year][2]++;
            elseif ($cantidad === 7) $lightsToPapi[$year][3]++;
            elseif ($cantidad >= 8)  $lightsToPapi[$year][4]++;
        }

        return response()->json([
            'operator'               => $operator->name,
            'airports'               => $airports,
            'dronesMaintenances'     => $dronesMaintenances,
            'dronesWithMaintenances' => $dronesWithMaintenances,
            'nextOperations'         => $this->mapOperations($nextOperations),
            'lastOperations'         => $this->mapOperations($lastOperations),
            'activeDronesByType'     => $activeDronesByType,
            'allSystemsAndCount'     => $allSystemsAndCount,
            'allOpsOperations'       => $allOpsOperations,
            'valuesPapiLights'       => $valuesPapiLights,
            'lightsToPapi'           => $lightsToPapi,
        ]);
    }

    #[OA\Get(
        path: '/api/dashboard/company/{company_id}',
        summary: 'Get dashboard data filtered by company (admin only)',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'company_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard data for the company'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Company not found'),
        ]
    )]
    public function filterByCompany(int $company_id): JsonResponse
    {
        if (!Auth::user()->hasRole('admin')) {
            abort(403);
        }

        $company = \App\Company::findOrFail($company_id);

        $operationIds = CompanyOperation::where('company_id', $company_id)
            ->pluck('operation_id')->toArray();

        $operations = Operation::with('type')
            ->whereIn('id', $operationIds)
            ->whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->get();

        $data           = $this->buildDashboardDataForOperations($operations);
        $data['company'] = $company->name;

        return response()->json($data);
    }

    #[OA\Get(
        path: '/api/dashboard/operator/{operator_id}/company/{company_id}',
        summary: 'Get dashboard data filtered by operator and company (admin only)',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'operator_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'company_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard data for operator + company'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Operator or company not found'),
        ]
    )]
    public function filterByBoth(int $operator_id, int $company_id): JsonResponse
    {
        if (!Auth::user()->hasRole('admin')) {
            abort(403);
        }

        Operator::findOrFail($operator_id);
        \App\Company::findOrFail($company_id);

        $companyOpIds = CompanyOperation::where('company_id', $company_id)
            ->pluck('operation_id')->toArray();

        $operations = Operation::with('type')
            ->whereIn('id', $companyOpIds)
            ->where('operator_id', $operator_id)
            ->whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->get();

        $data = $this->buildDashboardDataForOperations($operations, $operator_id);

        return response()->json($data);
    }

    // ---------------------------------------------------------------
    // Shared builder for company/both filters
    // ---------------------------------------------------------------
    private function buildDashboardDataForOperations($operations, ?int $operator_id = null): array
    {
        $nextOperations = $operations->whereIn('status_id', [1, 2])
            ->sortByDesc('execution_date')->take(5)->values();
        $lastOperations = $operations->where('status_id', 3)
            ->sortByDesc('execution_date')->take(5)->values();

        $allOps           = $operations->where('status_id', 3)->where('is_demo', 0)->where('is_test', 0);
        $allOpsOperations = $this->getType($allOps, false);
        array_multisort($allOpsOperations[0], $allOpsOperations[1]);

        $operationIds = $operations->pluck('id')->toArray();
        $taskIds      = DB::table('tasks')->whereIn('operation_id', $operationIds)->pluck('id')->toArray();

        $currentYear = date('Y');

        $resultsPapi = DB::table('results_papi_vertical_angle')
            ->selectRaw('papi_id, task_id, light_id, COUNT(*) as cantidad, YEAR(created_at) as year')
            ->whereBetween('created_at', [($currentYear - 4) . '-01-01', $currentYear . '-12-31'])
            ->whereIn('task_id', $taskIds)
            ->groupBy('papi_id', 'task_id', 'light_id', 'year')
            ->get();

        $valuesPapiLights = [];
        foreach ($resultsPapi as $r) {
            $year = $r->year; $cantidad = $r->cantidad;
            if (!isset($valuesPapiLights[$year])) $valuesPapiLights[$year] = [];
            isset($valuesPapiLights[$year][$cantidad])
                ? $valuesPapiLights[$year][$cantidad]++
                : $valuesPapiLights[$year][$cantidad] = 1;
        }

        $resultsLightsPapi = DB::table('results_papi_vertical_angle')
            ->selectRaw('papi_id, task_id, light_id, COUNT(*) as cantidad, COUNT(DISTINCT light_id) as cantidad_lights, YEAR(created_at) as year')
            ->whereBetween('created_at', [($currentYear - 4) . '-01-01', $currentYear . '-12-31'])
            ->whereIn('task_id', $taskIds)
            ->groupBy('papi_id', 'task_id', 'light_id', 'year')
            ->get();

        $papiIntentosMap = [];
        foreach ($resultsLightsPapi as $r) {
            $intentos = $r->cantidad - 1;
            $key      = $r->year . '_' . $r->papi_id . '_' . $r->task_id;
            if (!isset($papiIntentosMap[$key])) {
                $papiIntentosMap[$key] = ['year' => $r->year, 'max_intentos' => 0];
            }
            if ($intentos > $papiIntentosMap[$key]['max_intentos']) {
                $papiIntentosMap[$key]['max_intentos'] = $intentos;
            }
        }

        $lightsToPapi = [];
        foreach ($papiIntentosMap as $d) {
            $year = $d['year'];
            $idx  = min($d['max_intentos'], 4);
            if (!isset($lightsToPapi[$year])) $lightsToPapi[$year] = array_fill(0, 5, 0);
            $lightsToPapi[$year][$idx]++;
        }

        // Drones
        $dronesOpIds = $operator_id
            ? [$operator_id]
            : $operations->pluck('operator_id')->unique()->filter()->toArray();

        $activeDronesByType = DB::table('drone_types')
            ->leftJoin('drones', 'drone_types.id', '=', 'drones.type_id')
            ->where('drones.status_id', 1)
            ->whereIn('drones.operator_id', $dronesOpIds)
            ->select('drone_types.name', DB::raw('COUNT(drones.id) as count'))
            ->groupBy('drone_types.name')
            ->get();

        // Systems & Infrastructure
        $allSystemsAndCount = [];
        $airports_ids       = [];

        foreach ($dronesOpIds as $opId) {
            $vors = DB::table('operator_airport')
                ->where('operator_id', $opId)->where('subject_type_id', 5)->get();
            if ($vors->count()) {
                $allSystemsAndCount['VOR'] = ($allSystemsAndCount['VOR'] ?? 0) + $vors->count();
            }
            $aps = DB::table('operator_airport')
                ->where('operator_id', $opId)->where('subject_type_id', 4)->get();
            foreach ($aps as $ap) {
                if (!in_array($ap->subject_id, $airports_ids)) {
                    $airports_ids[] = $ap->subject_id;
                }
            }
        }

        $airports = Airport::with([
            'runways.headers.papis',
            'runways.headers.als',
            'runways.headers.ils',
            'taxiways',
        ])->whereIn('id', $airports_ids)->get();

        $this->buildInfrastructureCounts($airports, $allSystemsAndCount);

        return [
            'airports'               => $airports,
            'nextOperations'         => $this->mapOperations($nextOperations),
            'lastOperations'         => $this->mapOperations($lastOperations),
            'activeDronesByType'     => $activeDronesByType,
            'allSystemsAndCount'     => $allSystemsAndCount,
            'allOpsOperations'       => $allOpsOperations,
            'valuesPapiLights'       => $valuesPapiLights,
            'lightsToPapi'           => $lightsToPapi,
        ];
    }
}
