<?php

namespace App\Http\Controllers\API;

use App\Events\DronePositionUpdated;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Drone;
use App\Models\DroneStatus;
use App\Models\DroneType;
use App\Models\Maintenance;
use App\Models\Operator;
use App\Models\OperationType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class DroneController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Verifica que el usuario pertenezca al mismo operador que el drone.
     * ⚠️ abort(403) se mantiene intencionalmente: al ser método privado no puede
     * devolver un JsonResponse directamente; Laravel lo transforma en 403 JSON
     * gracias al handler de excepciones de la API.
     */
    private function checkUserAllowed(Drone $drone): void
    {
        $user = Auth::user();

        if ($user->hasRole('admin')) {
            return;
        }

        if ($drone->operator_id !== $user->operator_id) {
            abort(403);
        }
    }

    #[OA\Get(
        path: '/api/drones',
        summary: 'Lista paginada de drones con filtros por operador y estado',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status',   in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'Operational')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de drones con opciones de filtro'),
            new OA\Response(response: 403, description: 'Sin permiso drone_view'),
        ]
    )]
    public function index(Request $request, ?string $given_operator = null, string $given_status = 'Operational'): JsonResponse
    {
        $this->authorize('drone_view');

        if (isset($request) && $request->operator != '') {
            $given_operator = $request->operator;
        }

        if (isset($request) && $request->status != '') {
            $given_status = $request->status;
        }

        if (isset($request) && strcmp($request->status, 'all') === 0) {
            $given_status = $request->status;
        }

        $dronesAll = Drone::get();

        if (Auth::user()->hasRole('admin')) {
            $drones = $this->masterFilter($dronesAll, $given_operator, $given_status);
        } else {
            $drones = Drone::where('operator_id', Auth::user()->operator_id)->whereIn('status_id', [1, 2]);
        }

        $operator    = $this->operatorSelect($drones);
        $allOperator = $this->operatorSelect($dronesAll, true);
        $status      = $this->statusSelect($drones);
        $allStatus   = $this->statusSelect($dronesAll, true);

        $drones = $drones->sortable(['id' => 'desc'])->paginate(50);

        return response()->json([
            'data'           => $drones,
            'operator'       => $operator,
            'all_operator'   => $allOperator,
            'status'         => $status,
            'all_status'     => $allStatus,
            'given_operator' => $given_operator,
            'given_status'   => $given_status,
        ], 200);
    }

    #[OA\Get(
        path: '/api/drones/create',
        summary: 'Obtiene los datos necesarios para crear un drone (operadores, tipos, estados, tipos de operación)',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso drone_create'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('drone_create');

        if (Auth::user()->hasRole('admin')) {
            $operators = Operator::all();
        } else {
            $operators = Operator::find(Auth::user()->operator_id);
        }

        $types          = DroneType::all();
        $statuses       = DroneStatus::all();
        $operationTypes = OperationType::where('visible', 1)->orderBy('name')->get();

        return response()->json([
            'operators'       => $operators,
            'types'           => $types,
            'statuses'        => $statuses,
            'operation_types' => $operationTypes,
        ], 200);
    }

    #[OA\Post(
        path: '/api/drones',
        summary: 'Crea un nuevo drone y sincroniza sus tipos de operación',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type_id', 'operator_id', 'status_id', 'serial_number'],
                properties: [
                    new OA\Property(property: 'name',               type: 'string'),
                    new OA\Property(property: 'description',        type: 'string',  nullable: true),
                    new OA\Property(property: 'type_id',            type: 'integer'),
                    new OA\Property(property: 'operator_id',        type: 'integer'),
                    new OA\Property(property: 'status_id',          type: 'integer'),
                    new OA\Property(property: 'serial_number',      type: 'string'),
                    new OA\Property(property: 'operation_type_ids', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Drone creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear el drone'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('drone_create');

        $request->validate([
            'name'          => 'required|unique:drones',
            'type_id'       => 'required',
            'operator_id'   => 'required',
            'status_id'     => 'required',
            'serial_number' => 'required|unique:drones',
        ]);

        $decommission_date = ($request['status_id'] == 3) ? Carbon::now() : null;
        $op_id             = Auth::user()->hasRole('admin') ? $request['operator_id'] : Auth::user()->operator_id;

        try {
            $new_uas = Drone::create([
                'name'              => $request['name'],
                'description'       => $request['description'],
                'type_id'           => $request['type_id'],
                'operator_id'       => $op_id,
                'serial_number'     => $request['serial_number'],
                'commission_date'   => Carbon::now(),
                'decommission_date' => $decommission_date,
                'status_id'         => $request['status_id'],
            ]);

            if ($request->has('operation_type_ids')) {
                $new_uas->operationTypes()->sync($request->input('operation_type_ids'));
            }

            ActivityLog::log('create', 'UAS', $new_uas->id, "New UAS '{$new_uas->name}' (S/N: {$new_uas->serial_number})");

            return response()->json([
                'message' => 'Drone created successfully.',
                'data'    => $new_uas,
            ], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/operators/{operator}/drones',
        summary: 'Lista los drones activos (status_id=1) de un operador',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'operator', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de drones activos del operador'),
        ]
    )]
    public function getDronesFromOperator(Operator $operator): JsonResponse
    {
        return response()->json(
            Drone::where('operator_id', $operator->id)->where('status_id', 1)->get()
        );
    }

    #[OA\Get(
        path: '/api/drones/{drone}',
        summary: 'Obtiene el detalle de un drone con su próximo mantenimiento y el historial completo',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'drone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del drone con mantenimientos'),
            new OA\Response(response: 403, description: 'Sin permiso drone_view o drone de otro operador'),
            new OA\Response(response: 404, description: 'Drone no encontrado'),
        ]
    )]
    public function show(Drone $drone): JsonResponse
    {
        $this->authorize('drone_view');
        $this->checkUserAllowed($drone);

        $allMaintenances = Maintenance::where('subject_type', [0])->where('subject_id', $drone->id)->get();
        $nextMaintenance = Maintenance::where('subject_type', [0])
            ->where('subject_id', $drone->id)
            ->whereDate('execution_date', '>=', Carbon::now('Europe/Madrid'))
            ->first();

        return response()->json([
            'drone'            => $drone,
            'next_maintenance' => $nextMaintenance,
            'all_maintenances' => $allMaintenances,
        ], 200);
    }

    #[OA\Get(
        path: '/api/drones/{drone}/edit',
        summary: 'Obtiene los datos de un drone para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'drone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del drone con operadores, tipos, estados y tipos de operación'),
            new OA\Response(response: 403, description: 'Sin permiso drone_edit o drone de otro operador'),
            new OA\Response(response: 404, description: 'Drone no encontrado'),
        ]
    )]
    public function edit(Drone $drone): JsonResponse
    {
        $this->authorize('drone_edit');
        $this->checkUserAllowed($drone);

        if (Auth::user()->hasRole('admin')) {
            $operators = Operator::all();
        } else {
            $operators = Operator::find(Auth::user()->operator_id);
        }

        $types             = DroneType::all();
        $statuses          = DroneStatus::all();
        $operationTypes    = OperationType::where('visible', 1)->orderBy('name')->get();
        $selectedOpTypeIds = $drone->operationTypes->pluck('id')->toArray();

        return response()->json([
            'drone'               => $drone,
            'operators'           => $operators,
            'types'               => $types,
            'statuses'            => $statuses,
            'operation_types'     => $operationTypes,
            'selected_op_type_ids' => $selectedOpTypeIds,
        ], 200);
    }

    #[OA\Put(
        path: '/api/drones/{drone}',
        summary: 'Actualiza un drone, gestiona mantenimientos por cambio de estado y sincroniza tipos de operación',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'drone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type_id', 'serial_number', 'operator_id', 'status_id'],
                properties: [
                    new OA\Property(property: 'name',               type: 'string'),
                    new OA\Property(property: 'description',        type: 'string',  nullable: true),
                    new OA\Property(property: 'type_id',            type: 'integer'),
                    new OA\Property(property: 'serial_number',      type: 'string'),
                    new OA\Property(property: 'operator_id',        type: 'integer'),
                    new OA\Property(property: 'status_id',          type: 'integer'),
                    new OA\Property(property: 'operation_type_ids', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Drone actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_edit'),
            new OA\Response(response: 404, description: 'Drone no encontrado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el drone'),
        ]
    )]
    public function update(Request $request, Drone $drone): JsonResponse
    {
        $this->authorize('drone_edit');

        $request->validate([
            'name'          => 'required|unique:drones,name,' . $drone->id,
            'type_id'       => 'required',
            'serial_number' => 'required|unique:drones,serial_number,' . $drone->id,
            'operator_id'   => 'required',
            'status_id'     => 'required',
        ]);

        $decommission_date = ($request['status_id'] == 3) ? Carbon::now() : null;
        $op_id             = Auth::user()->hasRole('admin') ? $request['operator_id'] : Auth::user()->operator_id;
        $old_status_id     = $drone->status_id;

        try {
            $before = [
                'Name'              => $drone->name,
                'Description'       => $drone->description,
                'Type'              => $drone->type->name ?? $drone->type_id,
                'Serial number'     => $drone->serial_number,
                'Operator'          => $drone->operator->name ?? $drone->operator_id,
                'Status'            => $drone->status->name ?? $drone->status_id,
                'Decommission date' => $drone->decommission_date,
            ];

            $drone->name              = $request['name'];
            $drone->description       = $request['description'];
            $drone->type_id           = $request['type_id'];
            $drone->serial_number     = $request['serial_number'];
            $drone->operator_id       = $op_id;
            $drone->decommission_date = $decommission_date;
            $drone->status_id         = $request['status_id'];

            if ($old_status_id != $request['status_id']) {
                if ($request['status_id'] == 3) {
                    $drone_mtns = Maintenance::where('status_id', 1)
                        ->where('subject_type', 0)
                        ->where('subject_id', $drone->id)
                        ->get();

                    foreach ($drone_mtns as $drone_mtn) {
                        $drone_mtn->status_id = 4;
                        $drone_mtn->save();
                    }
                } elseif ($request['status_id'] == 1) {
                    $drone_mtn = Maintenance::where('status_id', 4)
                        ->where('subject_type', 0)
                        ->where('subject_id', $drone->id)
                        ->orderByDesc('execution_date')
                        ->first();

                    $drone_mtn->status_id = 1;
                    $drone_mtn->save();
                }
            }

            $beforeOpTypes = $drone->operationTypes->pluck('name')->sort()->values()->toArray();

            $drone->save();
            $drone->operationTypes()->sync($request->input('operation_type_ids', []));
            $drone->load('type', 'operator', 'status', 'operationTypes');

            $afterOpTypes = $drone->operationTypes->pluck('name')->sort()->values()->toArray();
            $after        = [
                'Name'              => $drone->name,
                'Description'       => $drone->description,
                'Type'              => $drone->type->name ?? $drone->type_id,
                'Serial number'     => $drone->serial_number,
                'Operator'          => $drone->operator->name ?? $drone->operator_id,
                'Status'            => $drone->status->name ?? $drone->status_id,
                'Decommission date' => $drone->decommission_date,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $addedOpTypes   = array_diff($afterOpTypes, $beforeOpTypes);
            $removedOpTypes = array_diff($beforeOpTypes, $afterOpTypes);
            if (!empty($addedOpTypes)) {
                $changes[] = "Operation types added: " . implode(', ', $addedOpTypes);
            }
            if (!empty($removedOpTypes)) {
                $changes[] = "Operation types removed: " . implode(', ', $removedOpTypes);
            }

            $description = "Updated UAS '{$drone->name}' (S/N: {$drone->serial_number})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'UAS', $drone->id, $description);

            return response()->json([
                'message' => 'Drone updated successfully.',
                'data'    => $drone,
            ], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/drones/{drone}',
        summary: 'Elimina un drone',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'drone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Drone eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_delete o drone de otro operador'),
            new OA\Response(response: 404, description: 'Drone no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el drone'),
        ]
    )]
    public function destroy(Drone $drone): JsonResponse
    {
        $this->authorize('drone_delete');
        $this->checkUserAllowed($drone);

        try {
            $name   = $drone->name;
            $serial = $drone->serial_number;
            $id     = $drone->id;

            $drone->delete();

            ActivityLog::log('delete', 'UAS', $id, "Deleted UAS '{$name}' (S/N: {$serial})");

            return response()->json(['message' => 'Drone deleted successfully.'], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/drones/position/{environment}/{operationId}',
        summary: 'Recibe y difunde en tiempo real la posición de un drone',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'environment',  in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId',  in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['lat', 'lng'],
                properties: [
                    new OA\Property(property: 'lat',           type: 'number',  minimum: -90,  maximum: 90),
                    new OA\Property(property: 'lng',           type: 'number',  minimum: -180, maximum: 180),
                    new OA\Property(property: 'heading',       type: 'integer', minimum: 0,    maximum: 360,  nullable: true),
                    new OA\Property(property: 'altitude',      type: 'number',  nullable: true),
                    new OA\Property(property: 'speed',         type: 'number',  minimum: 0,    nullable: true),
                    new OA\Property(property: 'battery_level', type: 'integer', minimum: 0,    maximum: 100,  nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Posición recibida y broadcast enviado'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function updatePosition(Request $request, string $environment, int $operationId): JsonResponse
    {
        $validated = $request->validate([
            'lat'           => 'required|numeric|between:-90,90',
            'lng'           => 'required|numeric|between:-180,180',
            'heading'       => 'integer|between:0,360',
            'altitude'      => 'numeric',
            'speed'         => 'numeric|min:0',
            'battery_level' => 'integer|between:0,100',
        ]);

        broadcast(new DronePositionUpdated($operationId, $environment, $validated));

        return response()->json(['status' => 'received']);
    }

    #[OA\Post(
        path: '/api/drones/telemetry/{environment}/{operationId}',
        summary: 'Recibe datos de telemetría adicionales de un drone (batería, señal, temperatura)',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'environment', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'battery_level',    type: 'integer', minimum: 0, maximum: 100, nullable: true),
                    new OA\Property(property: 'signal_strength',  type: 'integer', minimum: 0, maximum: 100, nullable: true),
                    new OA\Property(property: 'temperature',      type: 'number',  nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Telemetría recibida'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function updateTelemetry(Request $request, string $environment, int $operationId): JsonResponse
    {
        $validated = $request->validate([
            'battery_level'   => 'integer|between:0,100',
            'signal_strength' => 'integer|between:0,100',
            'temperature'     => 'numeric',
        ]);

        Log::info("Drone telemetry received", [
            'environment'  => $environment,
            'operation_id' => $operationId,
            'data'         => $validated,
        ]);

        return response()->json(['status' => 'received']);
    }

    #[OA\Get(
        path: '/api/operators/{operator}/drones/by-operation-type/{operationTypeId}',
        summary: 'Lista los drones activos de un operador filtrados por tipo de operación',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'operator',        in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'operationTypeId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de drones activos del operador con el tipo de operación indicado'),
        ]
    )]
    public function getDronesByOperationType(Operator $operator, int $operationTypeId): JsonResponse
    {
        $drones = Drone::where('operator_id', $operator->id)
            ->where('status_id', 1)
            ->whereHas('operationTypes', function ($q) use ($operationTypeId) {
                $q->where('operation_type_id', $operationTypeId);
            })
            ->get();

        return response()->json($drones);
    }

    #[OA\Get(
        path: '/api/drones/from-operator/{operatorId}/by-operation-type/{operationTypeId}',
        summary: 'Lista los drones de un operador (por ID) filtrados por tipo de operación',
        security: [['bearerAuth' => []]],
        tags: ['Drones'],
        parameters: [
            new OA\Parameter(name: 'operatorId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'operationTypeId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de drones (id y name) del operador con el tipo de operación indicado'),
        ]
    )]
    public function fromOperatorByOperationType(int $operatorId, int $operationTypeId): JsonResponse
    {
        $drones = Drone::where('operator_id', $operatorId)
            ->whereHas('operationTypes', function ($q) use ($operationTypeId) {
                $q->where('operation_type_id', $operationTypeId);
            })
            ->get(['id', 'name']);

        return response()->json($drones);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function operatorSelect(mixed $drones, bool $allOperator = false): array
    {
        $FilterOperator = $allOperator ? $drones : $drones->get();
        $operatorNames  = [];

        foreach ($FilterOperator as $name) {
            $operatorNames[] = $name->operator->name;
        }

        return array_count_values($operatorNames);
    }

    private function statusSelect(mixed $drones, bool $allStatus = false): array
    {
        $FilterStatus = $allStatus ? $drones : $drones->get();
        $statusNames  = [];

        foreach ($FilterStatus as $name) {
            $statusNames[] = $name->status->name;
        }

        return array_count_values($statusNames);
    }

    private function operatorFilter(mixed $drones, string $given_operator): array
    {
        $idOperators = [];

        foreach ($drones as $dron) {
            if (strcmp($given_operator, $dron->operator->name) === 0) {
                $idOperators[] = $dron->operator->id;
            }
        }

        return $idOperators;
    }

    private function statusFilter(mixed $drones, string $given_status): array
    {
        $idStatus = [];

        foreach ($drones as $dron) {
            if (strcmp($given_status, $dron->status->name) === 0) {
                $idStatus[] = $dron->status->id;
            }
        }

        return $idStatus;
    }

    private function masterFilter(mixed $drones, ?string $given_operator, ?string $given_status): \Illuminate\Database\Eloquent\Builder
    {
        return Drone::where(function ($query) use ($drones, $given_operator) {
            if ($given_operator != null) {
                $idOperators = $this->operatorFilter($drones, $given_operator);
                $query->whereIn('operator_id', $idOperators);
            }
        })->where(function ($query) use ($drones, $given_status) {
            if ($given_status != null && strcmp($given_status, 'all') !== 0) {
                $idstatus = $this->statusFilter($drones, $given_status);
                $query->whereIn('status_id', $idstatus);
            }
        });
    }
}
