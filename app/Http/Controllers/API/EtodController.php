<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Country;
use App\Models\Etod;
use App\Models\EtodAreas;
use App\Models\Header;
use App\Models\MarkerPoints;
use App\Models\Parameter;
use App\Models\Runway;
use App\Models\System;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class EtodController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/airports/{airport}/etods/create',
        summary: 'Obtiene los datos necesarios para crear un ETOD en un aeropuerto',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
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

        $countries = Country::getCountriesWithAirports();
        $airports  = Airport::all();
        $areas     = EtodAreas::all();

        $etod             = new Etod();
        $etod->airport_id = $airport->id;

        $marker_point             = new MarkerPoints();
        $marker_point->subject_id = $etod->id;

        $runway            = Runway::where('airport_id', $etod->airport_id)->get();
        $header            = Header::where('runway_id', $runway[0]->id)->get();
        $airport_etods     = Etod::where('airport_id', $etod->airport_id)->get();
        $airport_etods_ids = Etod::where('airport_id', $etod->airport_id)->get('id');
        $markers           = MarkerPoints::whereIn('subject_id', $airport_etods_ids)->where('subject_type_id', 10)->get();
        $etod_count        = count($airport_etods);
        $etod_areas_order  = $this->orderEtodAreas($areas, $airport_etods);

        $header_threshold_lat_1 = $header[0]->threshold_latitude;
        $header_threshold_lng_1 = $header[0]->threshold_longitude;
        $header_threshold_lat_2 = $header[1]->threshold_latitude;
        $header_threshold_lng_2 = $header[1]->threshold_longitude;

        $reference_marker_airport_lat = (($header_threshold_lat_1 + $header_threshold_lat_2) / 2);
        $reference_marker_airport_lng = (($header_threshold_lng_1 + $header_threshold_lng_2) / 2);

        $task_types = $this->prepareEtodParameters($etod, 1);
        $parameters = $this->prepareEtodParameters($etod);

        return response()->json([
            'etod'                         => $etod,
            'airport'                      => $airport,
            'airport_etods'                => $airport_etods,
            'etod_count'                   => $etod_count,
            'markers'                      => $markers,
            'parameters'                   => $parameters,
            'task_types'                   => $task_types,
            'countries'                    => $countries,
            'airports'                     => $airports,
            'areas'                        => $areas,
            'etod_areas_order'             => $etod_areas_order,
            'reference_marker_airport_lat' => $reference_marker_airport_lat,
            'reference_marker_airport_lng' => $reference_marker_airport_lng,
        ], 200);
    }

    #[OA\Post(
        path: '/api/etods',
        summary: 'Crea un nuevo ETOD y sus marcadores de polígono',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['airport_id', 'etod_name', 'area_id'],
                properties: [
                    new OA\Property(property: 'airport_id', type: 'integer'),
                    new OA\Property(property: 'etod_name',  type: 'string'),
                    new OA\Property(property: 'area_id',    type: 'integer'),
                    new OA\Property(property: 'path',       type: 'string', description: 'JSON array de coordenadas del polígono'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'ETOD creado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_create'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error interno al crear el ETOD'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('airport_create');

        $array_path        = explode(",", $request['path']);
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
            'airport_id' => 'required',
            'etod_name'  => 'required',
            'area_id'    => 'required',
        ]);

        try {
            $etod = Etod::create([
                'airport_id' => $request['airport_id'],
                'name'       => $request['etod_name'],
                'area_id'    => $request['area_id'],
            ]);

            $this->storeMarkers($jsonDef, $request, $etod->id);
            $this->storeEtodParameters($request);

            $airport = Airport::find($request['airport_id']);
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New system: ETOD '{$etod->name}' at airport '{$airport->name}' ({$airport->icao_code})");

            return response()->json([
                'message' => 'ETOD created successfully.',
                'data'    => $etod,
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
        path: '/api/airports/{airport}/etods',
        summary: 'Obtiene todos los ETODs de un aeropuerto junto con marcadores y parámetros',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ETODs del aeropuerto con datos de mapa y parámetros'),
            new OA\Response(response: 403, description: 'Sin permiso airport_view'),
        ]
    )]
    public function show(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $countries = Country::getCountriesWithAirports();
        $airports  = Airport::all();
        $areas     = EtodAreas::all();

        $etod             = new Etod();
        $etod->airport_id = $airport->id;
        $etod_count       = 0;

        $marker_point             = new MarkerPoints();
        $marker_point->subject_id = $etod->id;

        $runway            = Runway::where('airport_id', $etod->airport_id)->get();
        $header            = Header::where('runway_id', $runway[0]->id)->get();
        $airport_etods     = Etod::where('airport_id', $etod->airport_id)->get();
        $airport_etods_ids = Etod::where('airport_id', $etod->airport_id)->get('id');
        $markers           = MarkerPoints::whereIn('subject_id', $airport_etods_ids)->where('subject_type_id', 10)->get();
        $etod_areas_order  = $this->orderEtodAreas($areas, $airport_etods);

        $header_threshold_lat_1 = $header[0]->threshold_latitude;
        $header_threshold_lng_1 = $header[0]->threshold_longitude;
        $header_threshold_lat_2 = $header[1]->threshold_latitude;
        $header_threshold_lng_2 = $header[1]->threshold_longitude;

        $reference_marker_airport_lat = (($header_threshold_lat_1 + $header_threshold_lat_2) / 2);
        $reference_marker_airport_lng = (($header_threshold_lng_1 + $header_threshold_lng_2) / 2);

        $task_types = $this->prepareEtodParameters($etod, 1);
        $parameters = $this->prepareEtodParameters($etod);

        return response()->json([
            'etod'                         => $etod,
            'airport_etods'                => $airport_etods,
            'etod_count'                   => $etod_count,
            'markers'                      => $markers,
            'parameters'                   => $parameters,
            'task_types'                   => $task_types,
            'countries'                    => $countries,
            'airports'                     => $airports,
            'areas'                        => $areas,
            'etod_areas_order'             => $etod_areas_order,
            'reference_marker_airport_lat' => $reference_marker_airport_lat,
            'reference_marker_airport_lng' => $reference_marker_airport_lng,
        ], 200);
    }

    #[OA\Put(
        path: '/api/etods/{etod}',
        summary: 'Actualiza un ETOD existente y sus marcadores',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'etod', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'etod_name',  type: 'string'),
                    new OA\Property(property: 'area_id',    type: 'integer'),
                    new OA\Property(property: 'path',       type: 'string', description: 'JSON array de coordenadas (vacío para no actualizar)'),
                    new OA\Property(property: 'airport_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'ETOD actualizado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_edit'),
            new OA\Response(response: 404, description: 'ETOD no encontrado'),
            new OA\Response(response: 500, description: 'Error interno al actualizar el ETOD'),
        ]
    )]
    public function update(Request $request, Etod $etod): JsonResponse
    {
        $this->authorize('airport_edit');

        try {
            $before = [
                'Name' => $etod->name,
                'Area' => $etod->area_id,
            ];

            $etod->name    = $request['etod_name'];
            $etod->area_id = $request['area_id'];
            $etod->save();

            $jsonDef = $request;
            if ($request['path'] != '') {
                $this->storeMarkers($jsonDef, $request, $etod->id);
            }
            $this->updateEtodParameters($request);

            $after = [
                'Name' => $etod->name,
                'Area' => $etod->area_id,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport     = Airport::find($request['airport_id']);
            $description = "Updated system: ETOD '{$etod->name}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);

            return response()->json([
                'message' => 'ETOD updated successfully.',
                'data'    => $etod,
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
        path: '/api/airports/{airport}/etods/list',
        summary: 'Lista los ETODs de un aeropuerto (requiere airport_view)',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de ETODs del aeropuerto'),
            new OA\Response(response: 403, description: 'Sin permiso airport_view'),
        ]
    )]
    public function getEtodsInAirport(Airport $airport): JsonResponse
    {
        $this->authorize('airport_view');

        $etods = [];
        foreach ($airport->etods as $etod) {
            array_push($etods, $etod);
        }

        return response()->json(['data' => $etods], 200);
    }

    #[OA\Get(
        path: '/api/airports/{airport}/etods/operation',
        summary: 'Lista los ETODs de un aeropuerto para crear operaciones (requiere operation_create)',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array de ETODs disponibles para la operación'),
            new OA\Response(response: 403, description: 'Sin permiso operation_create'),
        ]
    )]
    public function getEtodsInAirportOperation(Airport $airport): JsonResponse
    {
        $this->authorize('operation_create');

        $etods = [];
        foreach ($airport->etods as $etod) {
            array_push($etods, $etod);
        }

        return response()->json(['data' => $etods], 200);
    }

    #[OA\Delete(
        path: '/api/etods/{etod_id}',
        summary: 'Elimina un ETOD y sus marcadores de polígono',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'etod_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ETOD eliminado correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_delete'),
            new OA\Response(response: 500, description: 'Error interno al eliminar el ETOD'),
        ]
    )]
    public function destroy(int $etod_id): JsonResponse
    {
        $this->authorize('airport_delete');

        try {
            $etod    = Etod::where('id', $etod_id)->first();
            $airport = Airport::find($etod->airport_id);
            ActivityLog::log('delete', 'Airport', (int) $etod->airport_id, "Deleted system: ETOD '{$etod->name}' from airport '{$airport->name}' ({$airport->icao_code})");

            $this->destroyMarkers($etod);
            $etod->delete();

            return response()->json(['id' => $etod_id], 200);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Airport', null, 'Error in delete: ' . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/etods/{etod}/markers',
        summary: 'Elimina todos los marcadores de polígono de un ETOD',
        security: [['bearerAuth' => []]],
        tags: ['ETODs'],
        parameters: [
            new OA\Parameter(name: 'etod', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marcadores eliminados correctamente'),
            new OA\Response(response: 403, description: 'Sin permiso airport_delete'),
        ]
    )]
    public function destroyMarkers(Etod $etod): JsonResponse
    {
        $this->authorize('airport_delete');

        // ⚠️ Bug preexistente: destroyMarkers() requiere airport_delete pero es llamado desde
        // storeMarkers() → store()/update() donde sólo se verifica airport_create/airport_edit.
        $etod_attr = $etod['id'];
        MarkerPoints::where('subject_id', $etod_attr)->where('subject_type_id', 10)->delete();

        return response()->json(['message' => 'Markers deleted successfully.'], 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * ⚠️ Bug preexistente en storeEtodParameters/updateEtodParameters:
     * sólo procesa la clave '0', hardcodea parameter_type_id=11 y task_type_id=35,
     * y $newParam se asigna pero nunca se usa.
     */
    private function storeMarkers(mixed $jsonDef, Request $request, int $etod): void
    {
        $counterMarkers = count(json_decode($jsonDef['path']));
        $etod           = Etod::where('id', $etod)->first();

        $this->destroyMarkers($etod);

        for ($i = 0; $i < $counterMarkers - 1; $i++) {
            MarkerPoints::create([
                'subject_id'      => $etod->id,
                'order'           => $i + 1,
                'lat'             => json_decode($jsonDef['path'])[$i][0],
                'lng'             => json_decode($jsonDef['path'])[$i][1],
                'height'          => json_decode($jsonDef['path'])[$i][2],
                'subject_type_id' => 10,
            ]);
        }
    }

    private function orderEtodAreas(mixed $etod_areas, mixed $etods): array
    {
        $etods_array = [];
        foreach ($etods as $key => $etod) {
            $areaName = $etod_areas[$etod['area_id'] - 1]->name;
            if (!isset($etods_array[$areaName])) {
                $etods_array[$areaName] = [];
            }
            array_push($etods_array[$areaName], $etod);
        }
        return $etods_array;
    }

    private function prepareEtodParameters(Etod $etod, int $opt = null): array
    {
        $system            = System::where('name', 'Taxiway Pavement')->first();
        $etod_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system->id)->get();
        $task_types        = [];

        if ($opt) {
            foreach ($etod_task_type_id as $task_type) {
                $task_type_name = TaskType::where('id', $task_type->task_type_id)->first();
                array_push($task_types, $task_type_name);
            }
            return $task_types;
        }

        $parameters = [];
        foreach ($etod_task_type_id as $etod_task_type) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $etod_task_type->task_type_id)->get();
            foreach ($parameter_type_id as $parameter_type) {
                $name                  = TaskType::where('id', $parameter_type->task_type_id)->first();
                $parameter_type_id_name = DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first();
                $value                 = Parameter::where([
                    'parameter_type_id' => $parameter_type->parameter_type_id,
                    'subject_id'        => $etod->id,
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

    private function storeEtodParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key == '0') {
                $ids = explode("-", $key);

                $etod_id = Etod::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);

                // ⚠️ Bug preexistente: $ids se sobreescribe con valores hardcodeados
                $ids = [11, $etod_id, $request['0']];

                $exists = Parameter::where([
                    'subject_id'        => $request['etod_id'],
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => $ids[1],
                ])->first();

                if ($exists) {
                    $exists->delete();
                }

                $etod_id = Etod::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);

                // ⚠️ Bug preexistente: $newParam se asigna pero nunca se usa
                $newParam = Parameter::create([
                    'subject_type_id'   => 10,
                    'subject_id'        => $etod_id['id'],
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => 35,
                    'value'             => $request['0'],
                ]);
            }
        }
    }

    private function updateEtodParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if ($key == '0') {
                $ids = explode("-", $key);

                $etod_id = Etod::where('airport_id', $request['airport_id'])
                    ->where('name', $request['name'])
                    ->first(['id']);
                $etod_id = intval($etod_id['id']);

                // ⚠️ Bug preexistente: $ids se sobreescribe con valores hardcodeados
                $ids = [11, $etod_id, $request['0']];

                $exists = Parameter::where('subject_id', $ids[1])
                    ->where('parameter_type_id', $ids[0])
                    ->where('task_type_id', '35')
                    ->first();

                if ($exists) {
                    $exists->delete();
                }

                // ⚠️ Bug preexistente: $newParam se asigna pero nunca se usa
                $newParam = Parameter::create([
                    'subject_type_id'   => 10,
                    'subject_id'        => $etod_id,
                    'parameter_type_id' => $ids[0],
                    'task_type_id'      => 35,
                    'value'             => $request['0'],
                ]);
            }
        }
    }
}
