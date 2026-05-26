<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Apron;
use App\Models\Country;
use App\Models\Header;
use App\Models\MarkerPoints;
use App\Models\Parameter;
use App\Models\Runway;
use App\Models\RunwayComposition;
use App\Models\System;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ApronController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aprons/create',
        summary: 'Obtiene los datos necesarios para crear un apron en un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos del formulario de creación'),
            new OA\Response(response: 403, description: 'Sin permiso airport_create'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('airport_create');

        $countries    = Country::getCountriesWithAirports();
        $airports     = Airport::all();
        $compositions = RunwayComposition::all();

        $apron             = new Apron();
        $apron->airport_id = $airport->id;

        $marker_point             = new MarkerPoints();
        $marker_point->subject_id = $apron->id;

        $runway            = Runway::where('airport_id', $apron->airport_id)->get();
        $header            = Header::where('runway_id', $runway[0]->id)->get();
        $airport_aprons    = Apron::where('airport_id', $apron->airport_id)->get();
        $aprons_count      = count($airport_aprons);
        $airport_aprons_ids = Apron::where('airport_id', $apron->airport_id)->get('id');
        $markers           = MarkerPoints::whereIn('subject_id', $airport_aprons_ids)->where('subject_type_id', 6)->get();

        $header_threshold_lat_1 = $header[0]->threshold_latitude;
        $header_threshold_lng_1 = $header[0]->threshold_longitude;
        $header_threshold_lat_2 = $header[1]->threshold_latitude;
        $header_threshold_lng_2 = $header[1]->threshold_longitude;

        $reference_marker_airport_lat = (($header_threshold_lat_1 + $header_threshold_lat_2) / 2);
        $reference_marker_airport_lng = (($header_threshold_lng_1 + $header_threshold_lng_2) / 2);

        $task_types = $this->prepareApronParameters($apron, 1);
        $parameters = $this->prepareApronParameters($apron);

        return response()->json([
            'apron'                        => $apron,
            'airport'                      => $airport,
            'airport_aprons'               => $airport_aprons,
            'aprons_count'                 => $aprons_count,
            'markers'                      => $markers,
            'parameters'                   => $parameters,
            'task_types'                   => $task_types,
            'countries'                    => $countries,
            'airports'                     => $airports,
            'compositions'                 => $compositions,
            'reference_marker_airport_lat' => $reference_marker_airport_lat,
            'reference_marker_airport_lng' => $reference_marker_airport_lng,
        ], 200);
    }

    #[OA\Post(
        path: '/api/aprons',
        summary: 'Crea un nuevo apron y sus marcadores de polígono',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['airport_id', 'apron_name', 'composition_id'],
                properties: [
                    new OA\Property(property: 'airport_id',     type: 'integer'),
                    new OA\Property(property: 'apron_name',     type: 'string'),
                    new OA\Property(property: 'composition_id', type: 'integer'),
                    new OA\Property(property: 'path',           type: 'string', description: 'JSON array de coordenadas del polígono'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Apron creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear el apron'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $array_path = explode(",", $request['path']);

        $array_path_coords = [];
        $counter           = 0;

        foreach ($array_path as $key => $value) {
            if ($key % 2 == 0) {
                $array_path_coords[$counter][0] = $value;
            } else {
                $array_path_coords[$counter][1] = $value;
                $counter++;
            }
        }

        // ⚠️ Bug preexistente: se accede a $_POST directamente en lugar de usar $request
        $jsonDef = $_POST;

        $request->validate([
            'airport_id'     => 'required',
            'apron_name'     => 'required',
            'composition_id' => 'required',
        ]);

        try {
            $apron = Apron::create([
                'airport_id'     => $request['airport_id'],
                'name'           => $request['apron_name'],
                'composition_id' => $request['composition_id'],
            ]);

            $this->storeMarkers($jsonDef, $request, $apron->id);
            $this->storeApronParameters($request);

            $airport = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New airside system: Apron '{$apron->name}' at airport '{$airport->name}' ({$airport->icao_code})");

            return response()->json([
                'message' => 'Apron created successfully',
                'data'    => $apron,
            ], 201);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in store: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aprons',
        summary: 'Obtiene todos los aprons de un aeropuerto junto con sus marcadores y parámetros',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de aprons con datos de mapa y parámetros'),
            new OA\Response(response: 403, description: 'Sin permiso airport_view'),
        ]
    )]
    public function show(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $countries    = Country::getCountriesWithAirports();
        $airports     = Airport::all();
        $compositions = RunwayComposition::all();

        $apron             = new Apron();
        $apron->airport_id = $airport->id;

        $marker_point             = new MarkerPoints();
        $marker_point->subject_id = $apron->id;

        $runway             = Runway::where('airport_id', $apron->airport_id)->get();
        $header             = Header::where('runway_id', $runway[0]->id)->get();
        $airport_aprons     = Apron::where('airport_id', $apron->airport_id)->get();
        $airport_aprons_ids = Apron::where('airport_id', $apron->airport_id)->get('id');
        $markers            = MarkerPoints::whereIn('subject_id', $airport_aprons_ids)->where('subject_type_id', 6)->get();

        $header_threshold_lat_1 = $header[0]->threshold_latitude;
        $header_threshold_lng_1 = $header[0]->threshold_longitude;
        $header_threshold_lat_2 = $header[1]->threshold_latitude;
        $header_threshold_lng_2 = $header[1]->threshold_longitude;

        $reference_marker_airport_lat = (($header_threshold_lat_1 + $header_threshold_lat_2) / 2);
        $reference_marker_airport_lng = (($header_threshold_lng_1 + $header_threshold_lng_2) / 2);

        $task_types = $this->prepareApronParameters($apron, 1);
        $parameters = $this->prepareApronParameters($apron);

        return response()->json([
            'apron'                        => $apron,
            'airport_aprons'               => $airport_aprons,
            'markers'                      => $markers,
            'parameters'                   => $parameters,
            'task_types'                   => $task_types,
            'countries'                    => $countries,
            'airports'                     => $airports,
            'compositions'                 => $compositions,
            'reference_marker_airport_lat' => $reference_marker_airport_lat,
            'reference_marker_airport_lng' => $reference_marker_airport_lng,
            'aprons_count'                 => 0,
        ], 200);
    }

    #[OA\Put(
        path: '/api/aprons/{apron}',
        summary: 'Actualiza un apron existente y sus marcadores',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'apron', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'apron_name',     type: 'string'),
                    new OA\Property(property: 'composition_id', type: 'integer'),
                    new OA\Property(property: 'path',           type: 'string', description: 'JSON array de coordenadas (vacío para no actualizar)'),
                    new OA\Property(property: 'airport_id',     type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Apron actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_edit'),
            new OA\Response(response: 404, description: 'Apron no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el apron'),
        ]
    )]
    public function update(Request $request, Apron $apron): JsonResponse
    {
        $this->authorize('airport_edit');

        try {
            $before = [
                'Name'        => $apron->name,
                'Composition' => $apron->composition_id,
            ];

            $apron->name           = $request['apron_name'];
            $apron->composition_id = $request['composition_id'];
            $apron->save();

            $jsonDef = $request;
            if ($request['path'] != '') {
                $this->storeMarkers($jsonDef, $request, $apron->id);
            }
            $this->updateApronParameters($request);

            $after = [
                'Name'        => $apron->name,
                'Composition' => $apron->composition_id,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport     = Airport::find($request['airport_id']);
            $description = "Updated airside system: Apron '{$apron->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);

            return response()->json([
                'message' => 'Apron updated successfully',
                'data'    => $apron,
            ], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in update: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aprons/list',
        summary: 'Lista los aprons de un aeropuerto (requiere airport_view)',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de aprons del aeropuerto'),
            new OA\Response(response: 403, description: 'Sin permiso airport_view'),
        ]
    )]
    public function getApronsInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $aprons = [];
        foreach ($airport->aprons as $apron) {
            array_push($aprons, $apron);
        }

        return response()->json(['data' => $aprons], 200);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/aprons/operation',
        summary: 'Lista los aprons de un aeropuerto para crear operaciones (requiere operation_create)',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de aprons disponibles para la operación'),
            new OA\Response(response: 403, description: 'Sin permiso operation_create'),
        ]
    )]
    public function getApronsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        $aprons = [];
        foreach ($airport->aprons as $apron) {
            array_push($aprons, $apron);
        }

        return response()->json(['data' => $aprons], 200);
    }

    #[OA\Delete(
        path: '/api/aprons/{apron_id}',
        summary: 'Elimina un apron y sus marcadores de polígono',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'apron_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Apron eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_delete'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el apron'),
        ]
    )]
    public function destroy(int $apron_id): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $apron   = Apron::where('id', $apron_id)->first();
            $airport = Airport::find($apron->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $apron->airport_id, "Deleted airside system: Apron '{$apron->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            $this->destroyMarkers($apron);
            $apron->delete();

            return response()->json(['id' => $apron_id], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/aprons/{apron}/markers',
        summary: 'Elimina todos los marcadores de polígono de un apron',
        security: [['bearerAuth' => []]],
        tags: ['Aprons'],
        parameters: [
            new OA\Parameter(name: 'apron', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marcadores eliminados correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_delete'),
        ]
    )]
    public function destroyMarkers(Apron $apron): JsonResponse
    {
        $this->authorize('airport_delete');

        // ⚠️ Bug preexistente: destroyMarkers() requiere airport_delete, pero es llamado desde
        // storeMarkers() → store()/update() donde sólo se verifica airport_create/airport_edit.
        // Un usuario sin airport_delete fallará al crear/editar un apron.
        $apron_attr  = $apron['id'];
        MarkerPoints::where('subject_id', $apron_attr)->where('subject_type_id', 6)->delete();

        return response()->json(['message' => 'Markers deleted successfully'], 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * ⚠️ Bug preexistente en storeApronParameters/updateApronParameters:
     * La lógica de parámetros sólo procesa la clave '0' del request, hardcodea
     * parameter_type_id=11 y task_type_id=35, e ignora el resto de parámetros.
     * $newParam se asigna pero nunca se usa.
     */
    private function storeMarkers(mixed $jsonDef, Request $request, int $apron): void
    {
        $counterMarkers = count(json_decode($jsonDef['path']));
        $apron          = Apron::where('id', $apron)->first();

        $this->destroyMarkers($apron);

        for ($i = 0; $i < $counterMarkers - 1; $i++) {
            MarkerPoints::create([
                'subject_id'      => $apron->id,
                'order'           => $i + 1,
                'lat'             => json_decode($jsonDef['path'])[$i][0],
                'lng'             => json_decode($jsonDef['path'])[$i][1],
                'height'          => json_decode($jsonDef['path'])[$i][2],
                'subject_type_id' => 6,
            ]);
        }
    }

    private function prepareApronParameters(Apron $apron, int $opt = null): array
    {
        $system             = System::where('name', 'Taxiway Pavement')->first();
        $apron_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system->id)->get();
        $task_types         = [];

        if ($opt) {
            foreach ($apron_task_type_id as $task_type) {
                $task_type_name = TaskType::where('id', $task_type->task_type_id)->first();
                array_push($task_types, $task_type_name);
            }
            return $task_types;
        }

        $parameters = [];
        foreach ($apron_task_type_id as $apron_task_type) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $apron_task_type->task_type_id)->get();
            foreach ($parameter_type_id as $parameter_type) {
                $name                  = TaskType::where('id', $parameter_type->task_type_id)->first();
                $parameter_type_id_name = DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first();
                $value                 = Parameter::where([
                    'parameter_type_id' => $parameter_type->parameter_type_id,
                    'subject_id'        => $apron->id,
                    'task_type_id'      => $parameter_type->task_type_id,
                ])->first();
                array_push($parameters, [
                    'task_type'      => $name,
                    'parameter_type' => $parameter_type_id_name,
                    'value'          => $value,
                ]);
            }
        }

        return $parameters;
    }

    private function storeApronParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key == '0') {
                $ids = explode("-", $key);

                $apron_id = Apron::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);

                // ⚠️ Bug preexistente: $ids se sobreescribe con valores hardcodeados
                $ids = [11, $apron_id, $request['0']];

                $exists = Parameter::where([
                    'subject_id'        => $request['apron_id'],
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => $ids[1],
                ])->first();

                if ($exists) {
                    $exists->delete();
                }

                $apron_id = Apron::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);

                // ⚠️ Bug preexistente: $newParam se asigna pero nunca se usa
                $newParam = Parameter::create([
                    'subject_type_id'   => 6,
                    'subject_id'        => $apron_id['id'],
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => 35,
                    'value'             => $request['0'],
                ]);
            }
        }
    }

    private function updateApronParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key == '0') {
                $ids = explode("-", $key);

                $apron_id = Apron::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);
                $apron_id = intval($apron_id['id']);

                // ⚠️ Bug preexistente: $ids se sobreescribe con valores hardcodeados
                $ids = [11, $apron_id, $request['0']];

                $exists = Parameter::where('subject_id', $ids[1])
                    ->where('parameter_type_id', $ids[0])
                    ->where('task_type_id', '35')
                    ->first();

                if ($exists) {
                    $exists->delete();
                }

                // ⚠️ Bug preexistente: $newParam se asigna pero nunca se usa
                $newParam = Parameter::create([
                    'subject_type_id'   => 6,
                    'subject_id'        => $apron_id,
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => 35,
                    'value'             => $request['0'],
                ]);
            }
        }
    }
}
