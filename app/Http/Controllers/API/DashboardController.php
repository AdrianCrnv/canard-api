<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Operation;
use App\Maintenance;
use App\Drone;
use App\Airport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use App\CompanyUser;
use App\CompanyOperation;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: '/api/dashboard',
        summary: 'Get dashboard data for the authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard data'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $operations      = collect();
        $maintenances    = collect();
        $operationIds    = collect();

        if ($user->hasRole('admin')) {

            $operations = Operation::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->get();

            $maintenances = Maintenance::whereNotIn('status_id', [4])->whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->get();

        } elseif ($user->hasRole('company')) {

            $companyUser = CompanyUser::where('user_id', Auth::id())->first();

            if ($companyUser) {
                $company_id   = $companyUser->company_id;
                $operationIds = CompanyOperation::where('company_id', $company_id)->pluck('operation_id');
                $operations   = Operation::whereIn('id', $operationIds)->orderBy('id')->get();
            }

            $maintenances = Maintenance::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->get();

        } elseif (!$user->hasRole('PNA manager')) {

            $operations = Operation::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->where('operator_id', $user->operator_id)->get();

            $maintainerIds = $user->operator->maintainers()->pluck('id')->toArray();
            $maintenances  = Maintenance::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->whereIn('technician_id', $maintainerIds)->get();

            $operationIds = $operations->pluck('id');

        } else {
            // PNA manager
            $operations = Operation::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->where('operator_id', $user->operator_id)->get();

            $maintenances = Maintenance::whereBetween('execution_date', [
                Carbon::now()->subMonths(24),
                Carbon::now()->addMonths(12),
            ])->whereIn('technician_id', $user->operator->maintainers())->get();

            $operationIds = $operations->pluck('id');
        }

        File::cleanDirectory(storage_path('app/platform/temp2'));

        if ($user->hasRole('admin')) {
            return $this->buildAdminResponse();
        }

        return $this->buildOperatorResponse($user, $operations, $operationIds);
    }

    // ---------------------------------------------------------------
    // Admin dashboard response
    // ---------------------------------------------------------------
    private function buildAdminResponse(): JsonResponse
    {
        $nextOperations = Operation::whereIn('status_id', [1, 2])
            ->orderByDesc('execution_date')->take(5)->get();

        $lastOperations = Operation::whereIn('status_id', [3])
            ->orderByDesc('execution_date')->take(5)->get();

        $activeDronesByType = DB::table('drone_types')
            ->leftJoin('drones', 'drone_types.id', '=', 'drones.type_id')
            ->where('drones.status_id', '=', 1)
            ->select('drone_types.name', DB::raw('COUNT(drones.id) as count'))
            ->groupBy('drone_types.name')
            ->get();

        $dronesMaintenances = Maintenance::where('subject_type', [0])
            ->sortable(['execution_date' => 'desc'])
            ->paginate(50);

        $dronesId = $dronesMaintenances->pluck('subject_id')->toArray();
        $dronesWithMaintenances = Drone::whereIn('id', $dronesId)->get();

        $allSystemsAndCount = $this->buildSystemsCount(Auth::user()->operator_id);
        $airports           = $this->buildAirportsList(Auth::user()->operator_id);

        $allOps           = Operation::where('status_id', 3)->where('is_demo', 0)->where('is_test', 0)->get();
        $allOpsOperations = $this->getType($allOps, false);
        array_multisort($allOpsOperations[0], $allOpsOperations[1]);

        [$valuesPapiLights, $lightsToPapi] = $this->buildPapiCharts(null);

        return response()->json([
            'airports'           => $airports,
            'dronesMaintenances' => $dronesMaintenances,
            'dronesWithMaintenances' => $dronesWithMaintenances,
            'nextOperations'     => $this->mapOperations($nextOperations),
            'lastOperations'     => $this->mapOperations($lastOperations),
            'allOpsOperations'   => $allOpsOperations,
            'allSystemsAndCount' => $allSystemsAndCount,
            'valuesPapiLights'   => $valuesPapiLights,
            'lightsToPapi'       => $lightsToPapi,
            'activeDronesByType' => $activeDronesByType,
        ]);
    }

    // ---------------------------------------------------------------
    // Operator / company / PNA dashboard response
    // ---------------------------------------------------------------
    private function buildOperatorResponse($user, $operations, $operationIds): JsonResponse
    {
        $isCompany = $user->hasRole('company');

        if ($isCompany) {
            $nextOperations = Operation::whereIn('id', $operationIds)
                ->whereIn('status_id', [1, 2])->take(5)->orderBy('execution_date')->get();

            $lastOperations = Operation::whereIn('id', $operationIds)
                ->where('status_id', 3)->take(5)->orderByDesc('execution_date')->get();

            $taskIds = DB::table('tasks')->whereIn('operation_id', $operationIds)->pluck('id')->toArray();
        } else {
            $nextOperations = Operation::whereIn('status_id', [1, 2])
                ->where('operator_id', $user->operator_id)->take(5)->get()->sortBy('execution_date')->values();

            $lastOperations = Operation::where('status_id', [3])
                ->where('operator_id', $user->operator_id)->take(5)->get()->sortByDesc('execution_date')->values();

            $taskIds = DB::table('tasks')->whereIn('operation_id', $operationIds)->pluck('id')->toArray();
        }

        $activeDronesByType = DB::table('drone_types')
            ->leftJoin('drones', 'drone_types.id', '=', 'drones.type_id')
            ->where('drones.status_id', '=', 1)
            ->where('operator_id', $user->operator_id)
            ->select('drone_types.name', DB::raw('COUNT(drones.id) as count'))
            ->groupBy('drone_types.name')
            ->get();

        $dronesOperator = Drone::where('operator_id', $user->operator_id)->get();
        $dronesOpId     = $dronesOperator->pluck('id')->toArray();

        $dronesMaintenances = Maintenance::whereIn('subject_id', $dronesOpId)->get();
        foreach ($dronesMaintenances as $dm) {
            $dronesOpId[] = $dm->subject_id;
        }
        $dronesWithMaintenances = Drone::whereIn('id', $dronesOpId)->get();

        $allSystemsAndCount = $this->buildSystemsCount($user->operator_id);
        $airports           = $this->buildAirportsList($user->operator_id);

        if ($isCompany) {
            $allOps = Operation::whereIn('id', $operationIds)
                ->where('status_id', 3)->where('is_demo', 0)->where('is_test', 0)->get();
        } else {
            $allOps = Operation::where('operator_id', $user->operator_id)
                ->where('status_id', 3)->where('is_demo', 0)->where('is_test', 0)->get();
        }

        $allOpsOperations = $this->getType($allOps, false);
        array_multisort($allOpsOperations[0], $allOpsOperations[1]);

        [$valuesPapiLights, $lightsToPapi] = $this->buildPapiChartsForTasks($taskIds);

        return response()->json([
            'airports'               => $airports,
            'dronesMaintenances'     => $dronesMaintenances,
            'dronesWithMaintenances' => $dronesWithMaintenances,
            'nextOperations'         => $this->mapOperations($nextOperations),
            'lastOperations'         => $this->mapOperations($lastOperations),
            'allOpsOperations'       => $allOpsOperations,
            'allSystemsAndCount'     => $allSystemsAndCount,
            'valuesPapiLights'       => $valuesPapiLights,
            'lightsToPapi'           => $lightsToPapi,
            'activeDronesByType'     => $activeDronesByType,
        ]);
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    protected function getType($operations, bool $boolean): array
    {
        $types          = [];
        $count_per_type = [];
        $papi_ops       = [2, 3, 4];
        $pci_ops        = [9, 13, 14];
        $lighting_ops   = [10, 11, 12];
        $ils_ops        = [5, 6];

        foreach ($operations as $operation) {
            if (in_array($operation->type->id, $papi_ops)) {
                $type = 'PAPI';
            } elseif (in_array($operation->type->id, $pci_ops)) {
                $type = 'PCI';
            } elseif (in_array($operation->type->id, $lighting_ops)) {
                $type = 'Lights';
            } elseif (in_array($operation->type->id, $ils_ops)) {
                $type = 'ILS';
            } else {
                $type = $operation->type->name;
            }

            if (in_array($type, $types)) {
                $key = array_search($type, $types);
                $count_per_type[$key] += 1;
            } else {
                $types[]          = $type;
                $count_per_type[] = 1;
            }
        }

        return [$types, $count_per_type];
    }

    protected function buildSystemsCount(int $operatorId): array
    {
        $allSystemsAndCount = [];

        $vors = DB::table('operator_airport')
            ->where('operator_id', $operatorId)
            ->where('subject_type_id', 5)->get();

        if ($vors->count()) {
            $allSystemsAndCount['VOR'] = $vors->count();
        }

        return $allSystemsAndCount;
    }

    protected function buildAirportsList(int $operatorId): \Illuminate\Database\Eloquent\Collection
    {
        $airports_ids = [];

        $airports_to_operator = DB::table('operator_airport')
            ->where('operator_id', $operatorId)
            ->where('subject_type_id', 4)->get();

        foreach ($airports_to_operator as $airport) {
            if (!in_array($airport->subject_id, $airports_ids)) {
                $airports_ids[] = $airport->subject_id;
            }
        }

        return Airport::with([
            'runways.headers.papis',
            'runways.headers.als',
            'runways.headers.ils',
            'taxiways',
        ])->whereIn('id', $airports_ids)->get();
    }

    protected function buildInfrastructureCounts($airports, array &$allSystemsAndCount): void
    {
        $rwy_count = $twy_count = $papi_count = $als_count = $ils_count = 0;

        foreach ($airports as $airport) {
            if ($airport->runways->count()) {
                $rwy_count += $airport->runways->count();
                foreach ($airport->runways as $runway) {
                    foreach ($runway->headers as $header) {
                        if ($header->papis->isNotEmpty()) $papi_count++;
                        if (isset($header->als))          $als_count++;
                        if (isset($header->ils))          $ils_count++;
                    }
                }
            }
            if ($airport->taxiways->count()) {
                $twy_count += $airport->taxiways->count();
            }
        }

        if ($rwy_count)  $allSystemsAndCount['RWY']  = $rwy_count;
        if ($papi_count) $allSystemsAndCount['PAPI'] = $papi_count;
        if ($als_count)  $allSystemsAndCount['ALS']  = $als_count;
        if ($ils_count)  $allSystemsAndCount['ILS']  = $ils_count;
        if ($twy_count)  $allSystemsAndCount['TWY']  = $twy_count;
    }

    // Admin PAPI charts (no task filter)
    private function buildPapiCharts(?int $operatorId): array
    {
        $currentYear = date('Y');

        $resultsPapi = DB::table('results_papi_vertical_angle')
            ->selectRaw('papi_id, task_id, light_id, COUNT(*) as cantidad, YEAR(created_at) as year')
            ->whereBetween('created_at', [($currentYear - 4) . '-01-01', $currentYear . '-12-31'])
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

        return [$valuesPapiLights, $lightsToPapi];
    }

    // Operator / company PAPI charts (filtered by taskIds)
    private function buildPapiChartsForTasks(array $taskIds): array
    {
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
            $key = $r->year . '_' . $r->papi_id . '_' . $r->task_id;
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

        return [$valuesPapiLights, $lightsToPapi];
    }

    protected function mapOperations($operations): array
    {
        return $operations->map(fn($op) => [
            'id'             => $op->id,
            'type'           => $op->type->name,
            'type_id'        => $op->type_id,
            'subject'        => $op->getAirport() ? $op->getAirport()->name : $op->getLocationName(),
            'airport'        => $op->getAirport() ? $op->getAirport()->name : $op->getLocationName(),
            'country'        => $op->getcountry() ? $op->getcountry()->name : '-',
            'execution_date' => Carbon::parse($op->execution_date)->toFormattedDateString(),
        ])->values()->toArray();
    }
}
