<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\Drone;
use App\Item;
use App\ItemType;
use App\Maintenance;
use App\MaintenanceStatus;
use App\MaintenanceType;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use OpenApi\Attributes as OA;

class MaintenanceController extends Controller
{
    #[OA\Get(
        path: '/api/maintenances',
        summary: 'Listar mantenimientos filtrados por rol del usuario',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de mantenimientos de drones e items'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('maintenance_view');

        $user = Auth::user();
        $viewAllMaintenance = false;

        foreach ($user->roles as $role) {
            if ($role->id == 6) {
                $viewAllMaintenance = true;
                break;
            }
        }

        if ($viewAllMaintenance) {
            $dronesMaintenances = Maintenance::where('subject_type', 0)
                ->sortable(['execution_date' => 'desc'])
                ->paginate(50);

            $dronesWithMaintenances = Drone::whereIn(
                'id',
                $dronesMaintenances->pluck('subject_id')
            )->get();

            $itemsMaintenances = Maintenance::whereNotIn('subject_type', [0])
                ->sortable(['execution_date' => 'desc'])
                ->paginate(50);

            $itemsWithMaintenances = Item::whereIn(
                'id',
                $itemsMaintenances->pluck('subject_id')
            )->get();

        } else {
            $operatorId = $user->operator_id;

            $dronesOpIds = Drone::where('operator_id', $operatorId)->pluck('id');
            $dronesMaintenances = Maintenance::whereIn('subject_id', $dronesOpIds)->get();
            $dronesWithMaintenances = Drone::whereIn(
                'id',
                $dronesMaintenances->pluck('subject_id')->merge($dronesOpIds)->unique()
            )->get();

            $itemsOpIds = Item::where('operator_id', $operatorId)->pluck('id');
            $itemsMaintenances = Maintenance::whereIn('subject_id', $itemsOpIds)->get();
            $itemsWithMaintenances = Item::whereIn(
                'id',
                $itemsMaintenances->pluck('subject_id')->merge($itemsOpIds)->unique()
            )->get();
        }

        return response()->json([
            'drones_maintenances'    => $dronesMaintenances,
            'items_maintenances'     => $itemsMaintenances,
            'drones_with_maintenances' => $dronesWithMaintenances,
            'items_with_maintenances'  => $itemsWithMaintenances,
        ]);
    }

    #[OA\Get(
        path: '/api/maintenances/form-data',
        summary: 'Obtener datos necesarios para el formulario de creación de mantenimiento',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('maintenance_create');

        $maintenance_types = MaintenanceType::orderBy('name')->get();
        $statuses          = MaintenanceStatus::all();
        $technicians       = User::role('maintenance')->where('is_active', 1)->get();

        if (Auth::user()->hasRole('admin')) {
            $drones = Drone::orderBy('name')->whereNotIn('status_id', [3])->get();
        } else {
            $drones = Drone::where('operator_id', Auth::user()->operator_id)->orderBy('name')->get();
        }

        $items_operator = Item::where('operator_id', Auth::user()->operator_id)->get();

        $item_types_id = $items_operator->pluck('type_id')->unique()->values();
        $item_types    = ItemType::whereIn('id', $item_types_id)->where('maintenance', 1)->get();

        return response()->json([
            'drones'            => $drones,
            'maintenance_types' => $maintenance_types,
            'statuses'          => $statuses,
            'technicians'       => $technicians,
            'items_operator'    => $items_operator,
            'item_types'        => $item_types,
        ]);
    }

    #[OA\Get(
        path: '/api/maintenances/items-by-type/{itemTypeId}',
        summary: 'Obtener items disponibles para un tipo de item dado',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'itemTypeId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de items del tipo indicado'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
        ]
    )]
    public function getItemsToItemTypes(int $itemTypeId): JsonResponse
    {
        $this->authorize('maintenance_create');

        if (Auth::user()->hasRole('admin')) {
            $itemList = Item::where('type_id', $itemTypeId)->whereNotIn('status_id', [2])->get();
        } else {
            $itemList = Item::where('type_id', $itemTypeId)->where('operator_id', Auth::user()->operator_id)->get();
        }

        return response()->json($itemList);
    }

    #[OA\Post(
        path: '/api/maintenances',
        summary: 'Crear un nuevo mantenimiento',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        responses: [
            new OA\Response(response: 201, description: 'Mantenimiento creado correctamente'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('maintenance_create');

        if ($request->execution_date) {
            $request->merge(['execution_date' => Carbon::parse($request->execution_date)]);
        }

        $request->validate([
            'type_id'        => 'required',
            'subject_type'   => 'required',
            'uas'            => 'required_if:subject_type,0',
            'items'          => 'required_unless:subject_type,0',
            'technician_id'  => 'required',
            'execution_date' => 'required|date',
        ]);

        $subject_id = $request->subject_type == 0 ? $request->uas : $request->items;

        try {
            $maintenance = Maintenance::create([
                'type_id'        => $request->type_id,
                'subject_type'   => $request->subject_type,
                'subject_id'     => $subject_id,
                'technician_id'  => $request->technician_id,
                'execution_date' => $request->execution_date,
                'observations'   => $request->observations, // BUG fix: era $request['execution_date']
                'status_id'      => 1,
            ]);

            $technicianName = $maintenance->technician->name ?? $maintenance->technician_id;

            if ($maintenance->subject_type == 0) {
                $subject      = Drone::find($maintenance->subject_id);
                $subjectLabel = "UAS '{$subject->name}' (#{$maintenance->subject_id})";
            } else {
                $subject      = Item::find($maintenance->subject_id);
                $subjectLabel = "item '{$subject->type->name}' S/N: {$subject->serial_number} (#{$maintenance->subject_id})";
            }

            ActivityLog::log(
                'create',
                'Maintenance',
                $maintenance->id,
                "New maintenance '{$maintenance->type->name}' for {$subjectLabel} scheduled on {$maintenance->execution_date} for technician '{$technicianName}'"
            );

            return response()->json([
                'success'     => true,
                'message'     => 'Maintenance created successfully',
                'maintenance' => $maintenance,
            ], 201);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Maintenance', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/maintenances/{maintenance}/edit-data',
        summary: 'Obtener datos necesarios para el formulario de edición de un mantenimiento',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'maintenance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de edición'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 404, description: 'Mantenimiento no encontrado'),
        ]
    )]
    public function edit(Maintenance $maintenance): JsonResponse
    {
        $this->authorize('maintenance_edit');

        $drones            = Drone::orderBy('name')->get();
        $maintenance_types = MaintenanceType::orderBy('name')->get();
        $statuses          = MaintenanceStatus::all();
        $technicians       = User::role('maintenance')->where('is_active', 1)->get();
        $item              = Item::where('id', $maintenance->subject_id)->first();
        $files             = Media::where('collection_name', 'maintenance')
                                ->where('model_id', $maintenance->id)
                                ->orderBy('created_at', 'desc')
                                ->paginate(50);

        $addDay = $addWeek = $addMonth = $addYear = 0;

        if ($maintenance->subject_type != 0) {
            $maintenance_subject_type = ItemType::find($maintenance->subject_type);
            $mtn_value    = $maintenance_subject_type->mtn_value;
            $mtn_unit_id  = $maintenance_subject_type->mtn_unit_id;

            match ($mtn_unit_id) {
                1 => $addDay   = $mtn_value,
                2 => $addWeek  = $mtn_value,
                3 => $addMonth = $mtn_value,
                4 => $addYear  = $mtn_value,
                default => null,
            };
        } else {
            $addYear = 1;
        }

        $new_date = Carbon::parse($maintenance->execution_date)
            ->addDays($addDay)
            ->addWeeks($addWeek)
            ->addMonths($addMonth)
            ->addYears($addYear)
            ->toFormattedDateString();

        $items_operator = Item::where('operator_id', Auth::user()->operator_id)->get();
        $item_types_id  = $items_operator->pluck('type_id')->unique()->values();
        $item_types     = ItemType::whereIn('id', $item_types_id)->where('maintenance', 1)->get();

        return response()->json([
            'maintenance'       => $maintenance,
            'drones'            => $drones,
            'maintenance_types' => $maintenance_types,
            'statuses'          => $statuses,
            'technicians'       => $technicians,
            'item'              => $item,
            'observations'      => $maintenance->observations,
            'files'             => $files,
            'item_types'        => $item_types,
            'item_types_id'     => $item_types_id,
            'items_operator'    => $items_operator,
            'new_date'          => $new_date,
        ]);
    }

    #[OA\Put(
        path: '/api/maintenances/{maintenance}',
        summary: 'Actualizar un mantenimiento existente',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'maintenance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mantenimiento actualizado correctamente'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function update(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorize('maintenance_edit');

        if ($request->execution_date) {
            $request->merge(['execution_date' => Carbon::parse($request->execution_date)]);
        }

        $request->validate([
            'technician_id'  => 'required',
            'execution_date' => 'required|date',
        ]);

        try {
            $before = [
                'Technician'     => $maintenance->technician->name ?? $maintenance->technician_id,
                'Execution date' => $maintenance->execution_date,
                'Status'         => $maintenance->status->name ?? $maintenance->status_id,
                'Observations'   => $maintenance->observations,
            ];

            $maintenance->technician_id  = $request->technician_id;
            $maintenance->execution_date = $request->execution_date;
            $maintenance->status_id      = $request->status_id;
            $maintenance->observations   = $request->observations;
            $maintenance->save();
            $maintenance->load('technician', 'status');

            $after = [
                'Technician'     => $maintenance->technician->name ?? $maintenance->technician_id,
                'Execution date' => $maintenance->execution_date,
                'Status'         => $maintenance->status->name ?? $maintenance->status_id,
                'Observations'   => $maintenance->observations,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $description = "Updated maintenance '{$maintenance->type->name}' #{$maintenance->id}"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');

            ActivityLog::log('update', 'Maintenance', $maintenance->id, $description);

            return response()->json([
                'success'     => true,
                'message'     => 'Maintenance updated successfully',
                'maintenance' => $maintenance,
            ]);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Maintenance', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/maintenances/{maintenance}',
        summary: 'Eliminar un mantenimiento y sus archivos adjuntos',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'maintenance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mantenimiento eliminado correctamente'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function destroy(Maintenance $maintenance): JsonResponse
    {
        $this->authorize('maintenance_delete');

        try {
            $fileMtns = Media::where('collection_name', 'maintenance')
                ->where('model_id', $maintenance->id)
                ->get();

            foreach ($fileMtns as $fileMtn) {
                $fileMtn->delete();
            }

            $maintenance->delete();

            ActivityLog::log(
                'delete',
                'Maintenance',
                $maintenance->id,
                "Deleted maintenance '{$maintenance->type->name}' #{$maintenance->id}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Maintenance deleted successfully',
            ]);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Maintenance', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }

    #[OA\Put(
        path: '/api/maintenances/{maintenance}/complete-and-reschedule',
        summary: 'Cerrar el mantenimiento actual y crear el siguiente programado',
        security: [['bearerAuth' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'maintenance', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Mantenimiento actualizado y nuevo creado'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function updateAndStore(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorize('maintenance_edit');

        if ($request->execution_date) {
            $request->merge(['execution_date' => Carbon::parse($request->execution_date)]);
        }

        $request->validate([
            'technician_id'  => 'required',
            'execution_date' => 'required|date',
        ]);

        $maintenance->technician_id  = $request->technician_id;
        $maintenance->execution_date = $request->execution_date;
        $maintenance->status_id      = $request->status_id;
        $maintenance->observations   = $request->observations;
        $maintenance->save();

        if ($request->dateModal) {
            $request->merge(['dateModal' => Carbon::parse($request->dateModal)]);
        }

        $next = Maintenance::create([
            'type_id'        => $maintenance->type_id,
            'subject_type'   => $maintenance->subject_type,
            'subject_id'     => $maintenance->subject_id,
            'technician_id'  => $maintenance->technician_id,
            'execution_date' => $request->dateModal,
            'observations'   => $maintenance->observations,
            'status_id'      => 1,
        ]);

        return response()->json([
            'success'          => true,
            'message'          => 'Maintenance updated and next one scheduled',
            'maintenance'      => $maintenance,
            'next_maintenance' => $next,
        ], 201);
    }
}
