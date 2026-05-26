<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Drone;
use App\Models\DroneType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class DroneTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/drones/types',
        summary: 'Lista paginada de tipos de drone. Los no-admin sólo ven los tipos usados por sus drones',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de tipos de drone'),
            new OA\Response(response: 403, description: 'Sin permiso drone_view'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('drone_view');

        if (Auth::user()->hasRole('admin')) {
            $types = DroneType::orderBy('name', 'asc')->paginate(50);
        } else {
            $drones = Drone::where('operator_id', Auth::user()->operator_id)->sortable(['id' => 'desc'])->get('type_id');
            $types  = DroneType::whereIn('id', $drones)->orderBy('name', 'asc')->paginate(50);
        }

        return response()->json($types, 200);
    }

    #[OA\Get(
        path: '/api/drones/types/create',
        summary: 'Indica que el usuario puede crear un tipo de drone (autorización)',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        responses: [
            new OA\Response(response: 200, description: 'Autorización correcta para crear'),
            new OA\Response(response: 403, description: 'Sin permiso drone_create'),
        ]
    )]
    public function create(): JsonResponse
    {
        $this->authorize('drone_create');

        return response()->json(['message' => 'Authorized to create drone type'], 200);
    }

    #[OA\Post(
        path: '/api/drones/types',
        summary: 'Crea un nuevo tipo de drone con diagrama y foto opcionales',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'identifier', 'camera_offset_x', 'camera_offset_y', 'camera_offset_z', 'ils_offset_x', 'ils_offset_y', 'ils_offset_z', 'maintenance_period', 'lifetime'],
                properties: [
                    new OA\Property(property: 'name',               type: 'string'),
                    new OA\Property(property: 'identifier',         type: 'string'),
                    new OA\Property(property: 'description',        type: 'string',  nullable: true),
                    new OA\Property(property: 'camera_offset_x',    type: 'number'),
                    new OA\Property(property: 'camera_offset_y',    type: 'number'),
                    new OA\Property(property: 'camera_offset_z',    type: 'number'),
                    new OA\Property(property: 'ils_offset_x',       type: 'number'),
                    new OA\Property(property: 'ils_offset_y',       type: 'number'),
                    new OA\Property(property: 'ils_offset_z',       type: 'number'),
                    new OA\Property(property: 'maintenance_period', type: 'number'),
                    new OA\Property(property: 'lifetime',           type: 'number'),
                    new OA\Property(property: 'diagram',            type: 'string',  format: 'binary', nullable: true),
                    new OA\Property(property: 'photo',              type: 'string',  format: 'binary', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tipo de drone creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear el tipo de drone'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('drone_create');

        $request->validate([
            'name'               => 'required|unique:drone_types',
            'identifier'         => 'required|unique:drone_types',
            'camera_offset_x'    => 'required|numeric',
            'camera_offset_y'    => 'required|numeric',
            'camera_offset_z'    => 'required|numeric',
            'ils_offset_x'       => 'required|numeric',
            'ils_offset_y'       => 'required|numeric',
            'ils_offset_z'       => 'required|numeric',
            'maintenance_period' => 'required|numeric',
            'lifetime'           => 'required|numeric',
            'diagram'            => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'photo'              => 'nullable|mimes:jpeg,jpg,png|max:2048',
        ]);

        try {
            $droneType = DroneType::create([
                'name'               => $request['name'],
                'identifier'         => $request['identifier'],
                'description'        => $request['description'],
                'camera_offset_x'    => $request['camera_offset_x'],
                'camera_offset_y'    => $request['camera_offset_y'],
                'camera_offset_z'    => $request['camera_offset_z'],
                'ils_offset_x'       => $request['ils_offset_x'],
                'ils_offset_y'       => $request['ils_offset_y'],
                'ils_offset_z'       => $request['ils_offset_z'],
                'maintenance_period' => $request['maintenance_period'],
                'lifetime'           => $request['lifetime'],
            ]);

            if ($request->file('diagram')) {
                $droneType->addMediaFromRequest('diagram')->toMediaCollection('drone_type_diagrams', 'public');
            }

            if ($request->file('photo')) {
                $droneType->addMediaFromRequest('photo')->toMediaCollection('drone_type_photos', 'public');
            }

            ActivityLog::log('create', 'UAS Type', $droneType->id, "New UAS type '{$droneType->name}' ({$droneType->identifier})");

            return response()->json([
                'message' => 'Drone type created successfully.',
                'data'    => $droneType,
            ], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS Type', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/drones/types/{type}/edit',
        summary: 'Obtiene los datos de un tipo de drone para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del tipo de drone'),
            new OA\Response(response: 403, description: 'Sin permiso drone_edit'),
            new OA\Response(response: 404, description: 'Tipo de drone no encontrado'),
        ]
    )]
    public function edit(DroneType $type): JsonResponse
    {
        $this->authorize('drone_edit');

        return response()->json(['data' => $type], 200);
    }

    #[OA\Put(
        path: '/api/drones/types/{type}',
        summary: 'Actualiza un tipo de drone, reemplazando diagrama y foto si se proporcionan',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'identifier', 'camera_offset_x', 'camera_offset_y', 'camera_offset_z', 'ils_offset_x', 'ils_offset_y', 'ils_offset_z', 'maintenance_period', 'lifetime'],
                properties: [
                    new OA\Property(property: 'name',               type: 'string'),
                    new OA\Property(property: 'identifier',         type: 'string'),
                    new OA\Property(property: 'description',        type: 'string',  nullable: true),
                    new OA\Property(property: 'camera_offset_x',    type: 'number'),
                    new OA\Property(property: 'camera_offset_y',    type: 'number'),
                    new OA\Property(property: 'camera_offset_z',    type: 'number'),
                    new OA\Property(property: 'ils_offset_x',       type: 'number'),
                    new OA\Property(property: 'ils_offset_y',       type: 'number'),
                    new OA\Property(property: 'ils_offset_z',       type: 'number'),
                    new OA\Property(property: 'maintenance_period', type: 'number'),
                    new OA\Property(property: 'lifetime',           type: 'number'),
                    new OA\Property(property: 'diagram',            type: 'string',  format: 'binary', nullable: true),
                    new OA\Property(property: 'photo',              type: 'string',  format: 'binary', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tipo de drone actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_edit'),
            new OA\Response(response: 404, description: 'Tipo de drone no encontrado'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el tipo de drone'),
        ]
    )]
    public function update(Request $request, DroneType $type): JsonResponse
    {
        $this->authorize('drone_edit');

        $request->validate([
            'name'               => 'required|unique:drone_types,name,' . $type->id,
            'identifier'         => 'required|unique:drone_types,name,' . $type->id,
            'camera_offset_x'    => 'required|numeric',
            'camera_offset_y'    => 'required|numeric',
            'camera_offset_z'    => 'required|numeric',
            'ils_offset_x'       => 'required|numeric',
            'ils_offset_y'       => 'required|numeric',
            'ils_offset_z'       => 'required|numeric',
            'maintenance_period' => 'required|numeric',
            'lifetime'           => 'required|numeric',
            'diagram'            => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'photo'              => 'nullable|mimes:jpeg,jpg,png|max:2048',
        ]);

        try {
            $before = [
                'Name'               => $type->name,
                'Identifier'         => $type->identifier,
                'Description'        => $type->description,
                'Camera offset X'    => $type->camera_offset_x,
                'Camera offset Y'    => $type->camera_offset_y,
                'Camera offset Z'    => $type->camera_offset_z,
                'ILS offset X'       => $type->ils_offset_x,
                'ILS offset Y'       => $type->ils_offset_y,
                'ILS offset Z'       => $type->ils_offset_z,
                'Maintenance period' => $type->maintenance_period,
                'Lifetime'           => $type->lifetime,
            ];

            $type->name               = $request['name'];
            $type->identifier         = $request['identifier'];
            $type->description        = $request['description'];
            $type->camera_offset_x    = $request['camera_offset_x'];
            $type->camera_offset_y    = $request['camera_offset_y'];
            $type->camera_offset_z    = $request['camera_offset_z'];
            $type->ils_offset_x       = $request['ils_offset_x'];
            $type->ils_offset_y       = $request['ils_offset_y'];
            $type->ils_offset_z       = $request['ils_offset_z'];
            $type->maintenance_period = $request['maintenance_period'];
            $type->lifetime           = $request['lifetime'];
            $type->save();

            if ($request->file('diagram')) {
                $type->clearMediaCollection('drone_type_diagrams');
                $type->addMediaFromRequest('diagram')->toMediaCollection('drone_type_diagrams', 'public');
            }

            if ($request->file('photo')) {
                $type->clearMediaCollection('drone_type_photos');
                $type->addMediaFromRequest('photo')->toMediaCollection('drone_type_photos', 'public');
            }

            $after = [
                'Name'               => $type->name,
                'Identifier'         => $type->identifier,
                'Description'        => $type->description,
                'Camera offset X'    => $type->camera_offset_x,
                'Camera offset Y'    => $type->camera_offset_y,
                'Camera offset Z'    => $type->camera_offset_z,
                'ILS offset X'       => $type->ils_offset_x,
                'ILS offset Y'       => $type->ils_offset_y,
                'ILS offset Z'       => $type->ils_offset_z,
                'Maintenance period' => $type->maintenance_period,
                'Lifetime'           => $type->lifetime,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }
            if ($request->file('diagram')) $changes[] = 'Diagram: updated';
            if ($request->file('photo'))   $changes[] = 'Photo: updated';

            $description = "Updated UAS type '{$type->name}' ({$type->identifier})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'UAS Type', $type->id, $description);

            return response()->json([
                'message' => 'Drone type updated successfully.',
                'data'    => $type,
            ], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS Type', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/drones/types/{type}',
        summary: 'Elimina un tipo de drone',
        security: [['bearerAuth' => []]],
        tags: ['Drone Types'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tipo de drone eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso drone_delete'),
            new OA\Response(response: 404, description: 'Tipo de drone no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el tipo de drone'),
        ]
    )]
    public function destroy(DroneType $type): JsonResponse
    {
        $this->authorize('drone_delete');

        try {
            $type->delete();

            ActivityLog::log('delete', 'UAS Type', $type->id, "Deleted UAS type '{$type->name}' ({$type->identifier})");

            return response()->json(['message' => 'Drone type deleted successfully.'], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'UAS Type', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
