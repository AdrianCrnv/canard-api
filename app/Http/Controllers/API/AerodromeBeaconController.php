<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AerodromeBeacon;
use App\Models\Airport;
use App\Models\Header;
use App\Models\OperationFiles;
use App\Models\Parameter;
use App\Models\ResultsBeacon;
use App\Models\Runway;
use App\Models\Task;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AerodromeBeaconController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aerodrome-beacons/create',
        summary: 'Obtiene los datos necesarios para crear un nuevo Aerodrome Beacon',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $runways = Runway::where('airport_id', $airport->id)->get();

        $headers = Header::where('runway_id', $runways->first()->id)
            ->orderBy('id', 'desc')
            ->get();

        $init_lat1 = $headers->first()->threshold_latitude;
        $init_lng1 = $headers->first()->threshold_longitude;
        $init_lat2 = $headers->last()->threshold_latitude;
        $init_lng2 = $headers->last()->threshold_longitude;
        $init_lat  = ($init_lat1 + $init_lat2) / 2;
        $init_lng  = ($init_lng1 + $init_lng2) / 2;

        $beacon             = new AerodromeBeacon;
        $beacon->airport_id = $airport->id;

        $beacon_marker_airport     = $this->getCenterMapLatLng($airport);
        $parameters_beacon         = $this->prepareBeaconParameters($beacon, 10);
        $task_types                = $this->prepareBeaconParameters($beacon, 10, 1);
        $all_beacons               = $airport->aerodromeBeacons;

        return response()->json([
            'airport'                    => $airport,
            'beacon'                     => $beacon,
            'all_beacons'                => $all_beacons,
            'task_types'                 => $task_types,
            'parameters_beacon'          => $parameters_beacon,
            'beacon_marker_airport_lat'  => $beacon_marker_airport[0],
            'beacon_marker_airport_lng'  => $beacon_marker_airport[1],
            'init_lat'                   => $init_lat,
            'init_lng'                   => $init_lng,
        ], 200);
    }

    #[OA\Post(
        path: '/api/airports/{airport}/aerodrome-beacons',
        summary: 'Crea un nuevo Aerodrome Beacon para un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['airport_id', 'name', 'coordinate_latitude', 'coordinate_longitude', 'coordinate_altitude'],
                properties: [
                    new OA\Property(property: 'airport_id',            type: 'integer'),
                    new OA\Property(property: 'name',                  type: 'string', maxLength: 255),
                    new OA\Property(property: 'coordinate_latitude',   type: 'number'),
                    new OA\Property(property: 'coordinate_longitude',  type: 'number'),
                    new OA\Property(property: 'coordinate_altitude',   type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Beacon creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function store(Request $request, Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        try {
            $beacon = AerodromeBeacon::create([
                'airport_id'           => $request['airport_id'],
                'name'                 => $request['name'],
                'coordinate_latitude'  => $request['coordinate_latitude'],
                'coordinate_longitude' => $request['coordinate_longitude'],
                'coordinate_altitude'  => $request['coordinate_altitude'],
            ]);

            $this->storeParameters($request, $beacon->id);

            $airportModel = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'],
                "New system: Aerodrome Beacon '{$beacon->name}' at airport '{$airportModel->name}' ({$airportModel->icao_code})"
            );

            return response()->json([
                'message' => 'Aerodrome Beacon created successfully',
                'beacon'  => $beacon,
            ], 201);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/aerodrome-beacons/{beacon}',
        summary: 'Obtiene el detalle de un Aerodrome Beacon',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'beacon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del beacon'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function show(AerodromeBeacon $beacon): JsonResponse
    {
        $this->authorize('airport_edit');

        $airport               = $beacon->airport;
        $beacon_marker_airport = $this->getCenterMapLatLng($airport);
        $task_types            = $this->prepareBeaconParameters($beacon, 10, 1);
        $parameters_beacon     = $this->prepareBeaconParameters($beacon, 10);

        return response()->json([
            'beacon'                    => $beacon,
            'airport'                   => $airport,
            'task_types'                => $task_types,
            'parameters_beacon'         => $parameters_beacon,
            'beacon_marker_airport_lat' => $beacon_marker_airport[0],
            'beacon_marker_airport_lng' => $beacon_marker_airport[1],
            'read_only'                 => true,
        ], 200);
    }

    #[OA\Get(
        path: '/api/aerodrome-beacons/{beacon}/edit',
        summary: 'Obtiene los datos de un Aerodrome Beacon para su edición',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'beacon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos para el formulario de edición'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function edit(AerodromeBeacon $beacon): JsonResponse
    {
        $this->authorize('airport_edit');

        $airport               = $beacon->airport;
        $all_beacons           = $airport->aerodromeBeacons;
        $beacon_marker_airport = $this->getCenterMapLatLng($airport);
        $task_types            = $this->prepareBeaconParameters($beacon, 10, 1);
        $parameters_beacon     = $this->prepareBeaconParameters($beacon, 10);

        return response()->json([
            'beacon'                    => $beacon,
            'all_beacons'               => $all_beacons,
            'airport'                   => $airport,
            'task_types'                => $task_types,
            'parameters_beacon'         => $parameters_beacon,
            'beacon_marker_airport_lat' => $beacon_marker_airport[0],
            'beacon_marker_airport_lng' => $beacon_marker_airport[1],
            'read_only'                 => false,
            'show_delete'               => true,
        ], 200);
    }

    #[OA\Put(
        path: '/api/aerodrome-beacons/{beacon}',
        summary: 'Actualiza los datos de un Aerodrome Beacon',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'beacon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['airport_id', 'name', 'coordinate_latitude', 'coordinate_longitude', 'coordinate_altitude'],
                properties: [
                    new OA\Property(property: 'airport_id',            type: 'integer'),
                    new OA\Property(property: 'name',                  type: 'string', maxLength: 255),
                    new OA\Property(property: 'coordinate_latitude',   type: 'number'),
                    new OA\Property(property: 'coordinate_longitude',  type: 'number'),
                    new OA\Property(property: 'coordinate_altitude',   type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Beacon actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function update(Request $request, AerodromeBeacon $beacon): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'airport_id'           => 'required|exists:airports,id',
            'name'                 => 'required|string|max:255',
            'coordinate_latitude'  => 'required|numeric|between:-90,90',
            'coordinate_longitude' => 'required|numeric|between:-180,180',
            'coordinate_altitude'  => 'required|numeric|between:0,9999.999',
        ]);

        try {
            $before = [
                'Name'      => $beacon->name,
                'Latitude'  => $beacon->coordinate_latitude,
                'Longitude' => $beacon->coordinate_longitude,
                'Altitude'  => $beacon->coordinate_altitude,
            ];

            $beacon->airport_id           = $request['airport_id'];
            $beacon->name                 = $request['name'];
            $beacon->coordinate_latitude  = $request['coordinate_latitude'];
            $beacon->coordinate_longitude = $request['coordinate_longitude'];
            $beacon->coordinate_altitude  = $request['coordinate_altitude'];
            $beacon->save();

            $this->updateParameters($request, $beacon->id);

            $after = [
                'Name'      => $beacon->name,
                'Latitude'  => $beacon->coordinate_latitude,
                'Longitude' => $beacon->coordinate_longitude,
                'Altitude'  => $beacon->coordinate_altitude,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airportModel = Airport::find($request['airport_id']);
            $description  = "Updated system: Aerodrome Beacon '{$beacon->name}' at airport '{$airportModel->name}' ({$airportModel->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');

            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);

            return response()->json([
                'message' => 'Aerodrome Beacon updated successfully',
                'beacon'  => $beacon,
            ], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Delete(
        path: '/api/aerodrome-beacons/{beacon}',
        summary: 'Elimina un Aerodrome Beacon',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'beacon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Beacon eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 500, description: 'Error inesperado'),
        ]
    )]
    public function destroy(AerodromeBeacon $beacon): JsonResponse
    {
        $this->authorize('airport_delete');

        $check_operator_airport = DB::table('operator_airport')
            ->where('operator_id', Auth::user()->operator_id)
            ->where('subject_type_id', 4)
            ->where('subject_id', $beacon->airport_id)
            ->first();

        if ($check_operator_airport === null) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        try {
            $airportModel = Airport::find($beacon->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $beacon->airport_id,
                "Deleted system: Aerodrome Beacon '{$beacon->name}' from airport '{$airportModel->name}' ({$airportModel->icao_code})"
            );

            $beacon->delete();

            return response()->json(['message' => 'Aerodrome Beacon deleted successfully'], 200);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    #[OA\Get(
        path: '/api/tasks/{taskId}/aerodrome-beacon/files',
        summary: 'Lista los archivos de una tarea de Aerodrome Beacon con sus URLs en S3',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de archivos'),
        ]
    )]
    public function getTaskFiles(int $taskId): JsonResponse
    {
        $files     = OperationFiles::where('task_id', $taskId)->get();
        $filesData = [];

        $operation  = Task::find($taskId)->operation;
        $folderPath = "AerodromeBeacon/{$operation->id}/$taskId";
        $allFiles   = Storage::disk('s3')->allFiles($folderPath);

        foreach ($files as $file) {
            $matchingFile = collect($allFiles)->first(fn ($f) => Str::endsWith($f, "/{$file->file_name}"));

            if ($matchingFile) {
                $filesData[] = [
                    'id'   => $file->id,
                    'name' => $file->file_name,
                    'size' => $file->size,
                    'type' => 'AerodromeBeacon',
                    'date' => $file->created_at->format('Y-m-d H:i'),
                    'path' => $matchingFile,
                ];
            }
        }

        return response()->json($filesData);
    }

    #[OA\Get(
        path: '/api/tasks/{taskId}/aerodrome-beacon/files/{fileName}',
        summary: 'Devuelve la URL temporal de un archivo de Aerodrome Beacon en S3',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'taskId',   in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'fileName', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'URL temporal del archivo'),
            new OA\Response(response: 404, description: 'Archivo no encontrado'),
        ]
    )]
    public function viewFile(int $taskId, string $fileName): JsonResponse
    {
        $operation  = Task::find($taskId)->operation;
        $folderPath = "AerodromeBeacon/{$operation->id}/$taskId";

        $files      = Storage::disk('s3')->files($folderPath);
        $subfolders = Storage::disk('s3')->directories($folderPath);

        foreach ($subfolders as $subfolder) {
            $files = array_merge($files, Storage::disk('s3')->files($subfolder));
        }

        $matchingFile = collect($files)->first(fn ($file) => Str::endsWith($file, "/$fileName"));

        if ($matchingFile) {
            $fileUrl = Storage::disk('s3')->temporaryUrl($matchingFile, now()->addMinutes(5));
            return response()->json(['url' => $fileUrl], 200);
        }

        return response()->json(['error' => 'File not found'], 404);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aerodrome-beacons/operation',
        summary: 'Lista los Aerodrome Beacons de un aeropuerto en contexto de creación de operación',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de beacons'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getBeaconsInAirportOperation(Airport $airport): mixed
    {
        $this->authorize('operation_create');

        return $airport->aerodromeBeacons;
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aerodrome-beacons',
        summary: 'Lista los Aerodrome Beacons de un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Aerodrome Beacon'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de beacons'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function getBeaconsInAirport(Airport $airport): mixed
    {
        $this->authorize('airport_view');

        return $airport->aerodromeBeacons;
    }

    // =========================================================================
    //  Helpers privados
    // =========================================================================

    public function getCenterMapLatLng(Airport $airport): array
    {
        $runway = Runway::where('airport_id', $airport->id)->first();
        if (!$runway) {
            return [$airport->latitude ?? 40.4168, $airport->longitude ?? -3.7038];
        }

        $headers = Header::where('runway_id', $runway->id)->get();
        if ($headers->count() < 2) {
            return [$airport->latitude ?? 40.4168, $airport->longitude ?? -3.7038];
        }

        $headers = $headers->sortBy('bearing');

        $reference_marker_airport_lat = ($headers[0]->threshold_latitude  + $headers[1]->threshold_latitude)  / 2;
        $reference_marker_airport_lng = ($headers[0]->threshold_longitude + $headers[1]->threshold_longitude) / 2;

        return [$reference_marker_airport_lat, $reference_marker_airport_lng];
    }

    public function prepareBeaconParameters(AerodromeBeacon $beacon, int $id, ?int $opt = null): array
    {
        $system_id   = $id;
        $task_types  = [];
        $parameters  = [];

        $task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system_id)->get();

        if ($opt) {
            foreach ($task_type_id as $id) {
                $task_type_name = TaskType::where('id', $id->task_type_id)->first();
                array_push($task_types, $task_type_name);
            }
            return $task_types;
        }

        foreach ($task_type_id as $id) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $id->task_type_id)->get();
            foreach ($parameter_type_id as $parameter_type) {
                $task_type            = TaskType::where('id', $parameter_type->task_type_id)->first();
                $parameter_type_id_name = DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first();
                $value = Parameter::where([
                    'parameter_type_id' => $parameter_type->parameter_type_id,
                    'subject_id'        => $beacon->id,
                    'task_type_id'      => $parameter_type->task_type_id,
                ])->first();

                array_push($parameters, [
                    'task_type'      => $task_type,
                    'parameter_type' => $parameter_type_id_name,
                    'value'          => $value,
                ]);
            }
        }

        return $parameters;
    }

    private function storeParameters(Request $request, int $beaconId): void
    {
        $arr = [48];
        foreach ($request->all() as $key => $value) {
            if (!in_array($key, ['_token', 'airport_id', 'name', 'coordinate_latitude', 'coordinate_longitude', 'coordinate_altitude'])) {
                $ids = explode("-", $key);

                $exists = Parameter::where([
                    'subject_id'        => $beaconId,
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => $ids[1],
                ])->first();

                if ($exists) {
                    $exists->delete();
                }

                $this->parameterCreate($request, $key, $arr, $ids, $beaconId);
            }
        }
    }

    private function parameterCreate(Request $request, string $key, array $arr, array $ids, int $beaconId): void
    {
        foreach ($arr as $id) {
            Parameter::create([
                'subject_type_id'   => 13, // AerodromeBeacon
                'subject_id'        => $beaconId,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
        }
    }

    private function updateParameters(Request $request, int $beaconId): void
    {
        $arr = [48];
        foreach ($request->all() as $key => $value) {
            if (!in_array($key, ['_method', '_token', 'airport_id', 'name', 'coordinate_latitude', 'coordinate_longitude', 'coordinate_altitude'])) {
                $ids = explode("-", $key);
                $this->parameterUpdate($request, $key, $arr, $ids, $beaconId);
            }
        }
    }

    private function parameterUpdate(Request $request, string $key, array $arr, array $ids, int $beaconId): void
    {
        foreach ($arr as $id) {
            $exists = Parameter::where([
                'subject_id'        => $beaconId,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
            ])->first();

            if ($exists) {
                $exists->delete();
            }

            Parameter::create([
                'subject_type_id'   => 13,
                'subject_id'        => $beaconId,
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
        }
    }
}
