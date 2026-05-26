<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Header;
use App\Ils;
use App\IlsCategory;
use App\IlsChannel;
use App\TaskType;
use App\Parameter;
use App\Airport;
use App\Runway;
use App\Stretch;
use App\RwyElevationProfile;
use App\Papi;
use App\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class IlsController extends Controller
{
    #[OA\Get(
        path: '/api/ils',
        summary: 'List all ILS systems',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        responses: [
            new OA\Response(response: 200, description: 'List of ILS systems'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(Ils::all());
    }

    #[OA\Get(
        path: '/api/ils/{ils}',
        summary: 'Get ILS system details',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'ils', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ILS system data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Ils $ils): JsonResponse
    {
        $this->authorize('navaid_view');

        $header   = $ils->header;
        $airport  = Airport::where('id', $header->runway->airport->id)->first();
        $stretches = Stretch::where('subject_id', $ils->id)->get();

        $task_types_glide = $this->prepareGlideParameters($header, 4, 1);
        $parameters_glide = $this->prepareGlideParameters($header, 4);
        $task_types       = $this->prepareLocalizerParameters($header, 3, 1);
        $parameters       = $this->prepareLocalizerParameters($header, 3);

        return response()->json([
            'ils'              => $ils,
            'header'           => $header,
            'airport'          => $airport,
            'stretches'        => $stretches,
            'categories'       => IlsCategory::all(),
            'channels'         => IlsChannel::all(),
            'task_types_glide' => $task_types_glide,
            'parameters_glide' => $parameters_glide,
            'task_types'       => $task_types,
            'parameters'       => $parameters,
        ]);
    }

    #[OA\Post(
        path: '/api/ils',
        summary: 'Create a new ILS system',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        responses: [
            new OA\Response(response: 201, description: 'ILS created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('navaid_create');

        $request->validate([
            'header_id'                      => 'required|unique:ils',
            'category_id'                    => 'required',
            'channel_id'                     => 'required',
            'loc_antenna_latitude'           => 'required|numeric|between:-90,90',
            'loc_antenna_longitude'          => 'required|numeric|between:-180,180',
            'ort_loc_antenna_elevation'      => 'required|numeric|between:0,9999.999',
            'gp_angle'                       => 'required|numeric|between:0,99.99',
            'gp_antenna_latitude'            => 'required|numeric|between:-90,90',
            'gp_antenna_longitude'           => 'required|numeric|between:-180,180',
            'ort_gp_antenna_elevation'       => 'required|numeric|between:0,9999.999',
            'gp_signal_origin_latitude'      => 'required|numeric|between:-90,90',
            'gp_signal_origin_longitude'     => 'required|numeric|between:-180,180',
            'ort_gp_signal_origin_elevation' => 'required|numeric|between:0,9999.999',
            'frequency_type'                 => 'nullable|numeric',
            'distanceThrLoc'                 => 'required|numeric',
            'dme'                            => 'sometimes|required',
            'dmeLatitude'                    => 'required_with:dme|numeric|between:-90,90',
            'dmeLongitude'                   => 'required_with:dme|numeric|between:-180,180',
            'dmeElevation'                   => 'required_with:dme|numeric|between:0,9999.999',
        ]);

        try {
            $this->storeParameters($request);

            $ils = Ils::create([
                'header_id'                  => $request['header_id'],
                'category_id'                => $request['category_id'],
                'channel_id'                 => $request['channel_id'],
                'loc_antenna_latitude'       => $request['loc_antenna_latitude'],
                'loc_antenna_longitude'      => $request['loc_antenna_longitude'],
                'loc_antenna_elevation'      => $request['ort_loc_antenna_elevation'],
                'nominal_width'              => $request['nominalWidth'],
                'gp_angle'                   => $request['gp_angle'],
                'gp_antenna_latitude'        => $request['gp_antenna_latitude'],
                'gp_antenna_longitude'       => $request['gp_antenna_longitude'],
                'gp_antenna_elevation'       => $request['ort_gp_antenna_elevation'],
                'gp_signal_origin_latitude'  => $request['gp_signal_origin_latitude'],
                'gp_signal_origin_longitude' => $request['gp_signal_origin_longitude'],
                'gp_signal_origin_elevation' => $request['ort_gp_signal_origin_elevation'],
                'frequency_type'             => $request['frequency_type'],
                'thr_loc'                    => $request['distanceThrLoc'],
                'dme_latitude'               => $request['dmeLatitude'],
                'dme_longitude'              => $request['dmeLongitude'],
                'dme_elevation'              => $request['dmeElevation'],
                'point_a'                    => $request['pointA'],
                'point_b'                    => $request['pointB'],
                'point_c'                    => $request['pointC'],
                'point_d'                    => $request['pointD'],
                'point_e'                    => $request['pointE'],
            ]);

            $this->saveIlsStretches(
                $ils,
                $request['stretches'],
                $ils->header->threshold_latitude,
                $ils->header->threshold_longitude,
                $request->loc_antenna_latitude,
                $request->loc_antenna_longitude
            );

            $airport    = Airport::find($request['airport_id']);
            $headerName = Header::find($request['header_id'])->name ?? 'Header #' . $request['header_id'];
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New system: ILS '{$headerName}' at airport '{$airport->name}' ({$airport->icao_code})");

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($ils->load(['header', 'category', 'channel']), 201);
    }

    #[OA\Put(
        path: '/api/ils/{ils}',
        summary: 'Update an ILS system',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'ils', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ILS updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, Ils $ils): JsonResponse
    {
        $this->authorize('navaid_edit');

        $request->validate([
            'header_id'                      => 'required|unique:ils,header_id,' . $ils->id,
            'category_id'                    => 'required',
            'channel_id'                     => 'required',
            'loc_antenna_latitude'           => 'required|numeric|between:-90,90',
            'loc_antenna_longitude'          => 'required|numeric|between:-180,180',
            'ort_loc_antenna_elevation'      => 'required|numeric|between:0,9999.999',
            'gp_angle'                       => 'required|numeric|between:0,99.99',
            'gp_antenna_latitude'            => 'required|numeric|between:-90,90',
            'gp_antenna_longitude'           => 'required|numeric|between:-180,180',
            'ort_gp_antenna_elevation'       => 'required|numeric|between:0,9999.999',
            'gp_signal_origin_latitude'      => 'required|numeric|between:-90,90',
            'gp_signal_origin_longitude'     => 'required|numeric|between:-180,180',
            'ort_gp_signal_origin_elevation' => 'required|numeric|between:0,9999.999',
            'frequency_type'                 => 'nullable|numeric',
            'distanceThrLoc'                 => 'required|numeric',
            'dme'                            => 'sometimes|required',
            'dmeLatitude'                    => 'required_with:dme|numeric|between:-90,90',
            'dmeLongitude'                   => 'required_with:dme|numeric|between:-180,180',
            'dmeElevation'                   => 'required_with:dme|numeric|between:0,9999.999',
        ]);

        try {
            $before = [
                'Category'         => $ils->category_id,
                'Channel'          => $ils->channel_id,
                'GP angle'         => $ils->gp_angle,
                'Nominal width'    => $ils->nominal_width,
                'LOC lat'          => $ils->loc_antenna_latitude,
                'LOC lng'          => $ils->loc_antenna_longitude,
                'LOC elevation'    => $ils->loc_antenna_elevation,
                'Thr-LOC distance' => $ils->thr_loc,
            ];

            $this->saveIlsStretches(
                $ils,
                $request['stretches'],
                $ils->header->threshold_latitude,
                $ils->header->threshold_longitude,
                $request->loc_antenna_latitude,
                $request->loc_antenna_longitude
            );
            $this->updateParameters($request);

            $ils->header_id                  = $request['header_id'];
            $ils->category_id                = $request['category_id'];
            $ils->channel_id                 = $request['channel_id'];
            $ils->loc_antenna_latitude       = $request['loc_antenna_latitude'];
            $ils->loc_antenna_longitude      = $request['loc_antenna_longitude'];
            $ils->loc_antenna_elevation      = $request['ort_loc_antenna_elevation'];
            $ils->gp_angle                   = $request['gp_angle'];
            $ils->nominal_width              = $request['nominalWidth'];
            $ils->gp_antenna_latitude        = $request['gp_antenna_latitude'];
            $ils->gp_antenna_longitude       = $request['gp_antenna_longitude'];
            $ils->gp_antenna_elevation       = $request['ort_gp_antenna_elevation'];
            $ils->gp_signal_origin_latitude  = $request['gp_signal_origin_latitude'];
            $ils->gp_signal_origin_longitude = $request['gp_signal_origin_longitude'];
            $ils->gp_signal_origin_elevation = $request['ort_gp_signal_origin_elevation'];
            $ils->frequency_type             = $request['frequency_type'];
            $ils->thr_loc                    = $request['distanceThrLoc'];
            $ils->dme_latitude               = $request['dmeLatitude'];
            $ils->dme_longitude              = $request['dmeLongitude'];
            $ils->dme_elevation              = $request['dmeElevation'];
            $ils->point_a                    = $request['pointA'];
            $ils->point_b                    = $request['pointB'];
            $ils->point_c                    = $request['pointC'];
            $ils->point_d                    = $request['pointD'];
            $ils->point_e                    = $request['pointE'];
            $ils->save();

            Papi::where('header_id', $ils->header_id)->update(['glide_path_angle' => $ils->gp_angle]);

            $after = [
                'Category'         => $ils->category_id,
                'Channel'          => $ils->channel_id,
                'GP angle'         => $ils->gp_angle,
                'Nominal width'    => $ils->nominal_width,
                'LOC lat'          => $ils->loc_antenna_latitude,
                'LOC lng'          => $ils->loc_antenna_longitude,
                'LOC elevation'    => $ils->loc_antenna_elevation,
                'Thr-LOC distance' => $ils->thr_loc,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport    = Airport::find($request['airport_id']);
            $headerName = $ils->header->name ?? 'Header #' . $ils->header_id;
            $description = "Updated system: ILS '{$headerName}' at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Operation', (int) $request['airport_id'], $description);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($ils->load(['header', 'category', 'channel']));
    }

    #[OA\Delete(
        path: '/api/ils/{ils}',
        summary: 'Delete an ILS system',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'ils', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ILS deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Ils $ils): JsonResponse
    {
        $this->authorize('navaid_delete');

        try {
            $airportId  = $ils->header->runway->airport_id;
            $airport    = Airport::find($airportId);
            $headerName = $ils->header->name ?? 'Header #' . $ils->header_id;
            ActivityLog::log('delete', 'Airport', (int) $airportId, "Deleted system: ILS '{$headerName}' from airport '{$airport->name}' ({$airport->icao_code})");

            $ils->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'ILS deleted successfully']);
    }

    #[OA\Get(
        path: '/api/headers/{header}/ils',
        summary: 'Get ILS systems for a header',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ILS systems for the header'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getIlsInHeader(\App\Header $header): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(
            Ils::where('header_id', $header->id)->with('channel', 'header')->get()
        );
    }

    // ---------------------------------------------------------------
    // Protected helpers — used by store/update and subclasses
    // ---------------------------------------------------------------

    protected function prepareGlideParameters($header, int $id, $opt = null): array
    {
        $task_types = [];
        $parameters = [];
        $task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $id)->get();

        $glideTaskTypeIds = [17, 18, 19, 23, 44];

        if ($opt) {
            foreach ($task_type_id as $ttId) {
                if (in_array($ttId->task_type_id, $glideTaskTypeIds)) {
                    $task_types[] = TaskType::where('id', $ttId->task_type_id)->first();
                }
            }
            return $task_types;
        }

        foreach ($task_type_id as $ttId) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $ttId->task_type_id)->get();
            foreach ($parameter_type_id as $pt) {
                if (!in_array($pt->task_type_id, $glideTaskTypeIds)) continue;
                $task_type            = TaskType::where('id', $pt->task_type_id)->first();
                $parameter_type_name  = DB::table('parameter_types')->where('id', $pt->parameter_type_id)->first();
                $value = $header
                    ? Parameter::where(['parameter_type_id' => $pt->parameter_type_id, 'subject_id' => $header->id, 'task_type_id' => $pt->task_type_id])->first()
                    : null;
                $parameters[] = ['task_type' => $task_type, 'parameter_type' => $parameter_type_name, 'value' => $value];
            }
        }

        return $parameters;
    }

    protected function prepareLocalizerParameters($header, int $id, $opt = null): array
    {
        $task_types = [];
        $parameters = [];
        $task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $id)->get();

        $locTaskTypeIds = [8, 9, 10, 14, 42];

        if ($opt) {
            foreach ($task_type_id as $ttId) {
                if (in_array($ttId->task_type_id, $locTaskTypeIds)) {
                    $task_types[] = TaskType::where('id', $ttId->task_type_id)->first();
                }
            }
            return $task_types;
        }

        foreach ($task_type_id as $ttId) {
            $parameter_type_id = DB::table('parameter_type_task_type')->where('task_type_id', $ttId->task_type_id)->get();
            foreach ($parameter_type_id as $pt) {
                if (!in_array($pt->task_type_id, $locTaskTypeIds)) continue;
                $task_type           = TaskType::where('id', $pt->task_type_id)->first();
                $parameter_type_name = DB::table('parameter_types')->where('id', $pt->parameter_type_id)->first();
                $value = $header
                    ? Parameter::where(['parameter_type_id' => $pt->parameter_type_id, 'subject_id' => $header->id, 'task_type_id' => $pt->task_type_id])->first()
                    : null;
                $parameters[] = ['task_type' => $task_type, 'parameter_type' => $parameter_type_name, 'value' => $value];
            }
        }

        return $parameters;
    }

    protected function storeParameters(Request $request): void
    {
        $localizer_course        = [8];
        $localizer_course_alarm  = [10, 11];
        $localizer_width         = [9, 12, 13];
        $localizer_clearance     = [14];
        $localizer_flutuation    = [42];
        $glide_angle             = [17];
        $glide_angle_alarm       = [19, 20];
        $glide_width             = [18, 21, 22];
        $glide_flutuation        = [23];
        $glide_transverse        = [44];

        $skipKeys = $this->getSkipKeys(false);

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skipKeys)) continue;

            $ids = explode('-', $key);

            $exists = Parameter::where(['subject_id' => $request['header_id'], 'parameter_type_id' => $ids[0], 'task_type_id' => $ids[1]])->first();
            $exists_2 = $this->getAlarmExists($request, $ids, false);

            if ($exists)    $exists->delete();
            elseif ($exists_2) $exists_2->delete();

            $this->dispatchParameterCreate($request, $key, $ids, compact(
                'localizer_course', 'localizer_course_alarm', 'localizer_width',
                'localizer_clearance', 'localizer_flutuation', 'glide_angle',
                'glide_angle_alarm', 'glide_width', 'glide_flutuation', 'glide_transverse'
            ));
        }
    }

    protected function updateParameters(Request $request): void
    {
        $localizer_course        = [8];
        $localizer_course_alarm  = [10, 11];
        $localizer_width         = [9, 12, 13];
        $localizer_clearance     = [14];
        $localizer_flutuation    = [42];
        $glide_angle             = [17];
        $glide_angle_alarm       = [19, 20];
        $glide_width             = [18, 21, 22];
        $glide_flutuation        = [23];
        $glide_transverse        = [44];

        $skipKeys = $this->getSkipKeys(true);

        foreach ($request->request as $key => $value) {
            if (in_array($key, $skipKeys)) continue;

            $ids = explode('-', $key);

            $this->dispatchParameterUpdate($request, $key, $ids, compact(
                'localizer_course', 'localizer_course_alarm', 'localizer_width',
                'localizer_clearance', 'localizer_flutuation', 'glide_angle',
                'glide_angle_alarm', 'glide_width', 'glide_flutuation', 'glide_transverse'
            ));
        }
    }

    protected function parameterCreate(Request $request, string $key, array $arr, array $ids): void
    {
        foreach ($arr as $id) {
            Parameter::create([
                'subject_type_id'   => 1,
                'subject_id'        => $request['header_id'],
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
            if ($ids[0] == 10) {
                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => 11,
                    'task_type_id'      => $id,
                    'value'             => $request[$key],
                ]);
            } elseif ($ids[0] == 19) {
                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => 20,
                    'task_type_id'      => $id,
                    'value'             => $request[$key],
                ]);
            }
        }
    }

    protected function parameterUpdate(Request $request, string $key, array $arr, array $ids): void
    {
        foreach ($arr as $id) {
            $exists   = Parameter::where(['subject_id' => $request['header_id'], 'parameter_type_id' => $ids[0], 'task_type_id' => $id])->first();
            $exists_2 = $this->getAlarmExists($request, $ids, false);

            if ($exists)    $exists->delete();
            elseif ($exists_2) $exists_2->delete();

            Parameter::create([
                'subject_type_id'   => 1,
                'subject_id'        => $request['header_id'],
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $id,
                'value'             => $request[$key],
            ]);
            if ($ids[0] == 10) {
                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => 11,
                    'task_type_id'      => $id,
                    'value'             => $request[$key],
                ]);
            } elseif ($ids[0] == 19) {
                Parameter::create([
                    'subject_type_id'   => 1,
                    'subject_id'        => $request['header_id'],
                    'parameter_type_id' => 20,
                    'task_type_id'      => $id,
                    'value'             => $request[$key],
                ]);
            }
        }
    }

    protected function saveIlsStretches(Ils $ils, $stretches, $thrLat, $thrLon, $locLat, $locLon): void
    {
        $stretchesOld = Stretch::where('subject_id', $ils->id)->get();
        $stretchesOld->each(fn($s) => $s->delete());

        $bearing = $this->bearing_umbral_a_loc($thrLat, $thrLon, $locLat, $locLon);

        $haversineMeters = function ($lat1, $lon1, $lat2, $lon2) {
            $R  = 6371000.0;
            $φ1 = $this->toRad($lat1); $φ2 = $this->toRad($lat2);
            $dφ = $φ2 - $φ1;          $dλ = $this->toRad($lon2 - $lon1);
            $a  = sin($dφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($dλ / 2) ** 2;
            return 2 * $R * asin(min(1.0, sqrt($a)));
        };

        $signalOriginDistance = -$haversineMeters(
            $thrLat, $thrLon,
            floatval($ils->gp_signal_origin_latitude),
            floatval($ils->gp_signal_origin_longitude)
        );

        DB::transaction(function () use ($ils, $stretches, $bearing, $thrLat, $thrLon, $signalOriginDistance) {
            foreach ($stretches as $s) {
                $name    = isset($s['name'])   ? (string) $s['name']    : '';
                $start_m = isset($s['start'])  ? floatval($s['start'])  : 0.0;
                $end_m   = isset($s['end'])    ? floatval($s['end'])    : 0.0;
                $order   = isset($s['order'])  ? intval($s['order'])    : 0;
                $enable  = isset($s['enable']) ? (intval($s['enable']) ? 1 : 0) : 0;

                [$startLat, $startLon] = $this->latlon_en_distancia($thrLat, $thrLon, $bearing, $start_m);
                [$endLat,   $endLon]   = $this->latlon_en_distancia($thrLat, $thrLon, $bearing, $end_m);

                $gpValid = ($signalOriginDistance < $start_m || $signalOriginDistance < $end_m) ? 1 : 0;

                Stretch::create([
                    'stretch_type'                => 2,
                    'subject_id'                  => $ils->id,
                    'order'                       => $order,
                    'gp_valid'                    => $gpValid,
                    'enable'                      => $enable,
                    'name'                        => $name,
                    'start_thr'                   => 0,
                    'end_thr'                     => 0,
                    'distance_to_rwy_limit_start' => $start_m,
                    'distance_to_rwy_limit_end'   => $end_m,
                    'start_lat'                   => $startLat,
                    'start_lon'                   => $startLon,
                    'start_elevation'             => 0,
                    'end_lat'                     => $endLat,
                    'end_lon'                     => $endLon,
                    'end_elevation'               => 0,
                ]);
            }
        });
    }

    // ---------------------------------------------------------------
    // Geodetic helpers
    // ---------------------------------------------------------------

    protected function toRad(float $deg): float { return $deg * M_PI / 180.0; }
    protected function toDeg(float $rad): float { return $rad * 180.0 / M_PI; }

    protected function bearing_umbral_a_loc(float $thrLat, float $thrLon, float $locLat, float $locLon): float
    {
        $φ1 = $this->toRad($thrLat); $φ2 = $this->toRad($locLat);
        $Δλ = $this->toRad($locLon - $thrLon);
        $y  = sin($Δλ) * cos($φ2);
        $x  = cos($φ1) * sin($φ2) - sin($φ1) * cos($φ2) * cos($Δλ);
        return fmod(($this->toDeg(atan2($y, $x)) + 360.0), 360.0);
    }

    protected function latlon_en_distancia(float $thrLat, float $thrLon, float $bearing_deg, float $dist_m): array
    {
        $R    = 6378137.0;
        $δ    = $dist_m / $R;
        $θ    = $this->toRad($bearing_deg);
        $φ1   = $this->toRad($thrLat);
        $λ1   = $this->toRad($thrLon);
        $sinφ1 = sin($φ1); $cosφ1 = cos($φ1);
        $sinδ  = sin($δ);   $cosδ  = cos($δ);

        $sinφ2 = $sinφ1 * $cosδ + $cosφ1 * $sinδ * cos($θ);
        $φ2    = asin($sinφ2);
        $y     = sin($θ) * $sinδ * $cosφ1;
        $x     = $cosδ - $sinφ1 * $sinφ2;
        $λ2    = $λ1 + atan2($y, $x);

        return [
            $this->toDeg($φ2),
            fmod(($this->toDeg($λ2) + 540.0), 360.0) - 180.0,
        ];
    }

    protected function getDistanceBetweenTwoPoint(float $lat1, float $long1, float $lat2, float $long2): int
    {
        $earthRadius = 6378.14;
        $dlat        = $this->degressToRadians($lat2 - $lat1);
        $dlong       = $this->degressToRadians($long1 - $long2);
        $lat1        = $this->degressToRadians($lat1);
        $lat2        = $this->degressToRadians($lat2);
        $a           = sin($dlat / 2) * sin($dlat / 2) + sin($dlong / 2) * sin($dlong / 2) * cos($lat1) * cos($lat2);
        $c           = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return (int) ($earthRadius * $c * 1000);
    }

    protected function degressToRadians(float $degress): float
    {
        return $degress * M_PI / 180;
    }

    protected function getLatitudeLongitude(float $latitudeFirstHeader, float $longitudeFirstHeader, float $intervalo, float $bearingFirstHeader): Collection
    {
        $earthRadius    = 6378.14;
        $newdistance    = $intervalo / 1000;
        $radianLatitude = $latitudeFirstHeader * (M_PI / 180);
        $radianLongitude = $longitudeFirstHeader * (M_PI / 180);
        $radianBearing  = $bearingFirstHeader * (M_PI / 180);

        $latitude  = asin(sin($radianLatitude) * cos($newdistance / $earthRadius) + cos($radianLatitude) * sin($newdistance / $earthRadius) * cos($radianBearing));
        $longitude = $radianLongitude + atan2(sin($radianBearing) * sin($newdistance / $earthRadius) * cos($radianLatitude), cos($newdistance / $earthRadius) - sin($radianLatitude) * sin($latitude));

        return collect([
            'latitude'  => $latitude  * (180 / M_PI),
            'longitude' => $longitude * (180 / M_PI),
        ]);
    }

    // ---------------------------------------------------------------
    // Private helpers for parameter dispatch
    // ---------------------------------------------------------------

    private function getSkipKeys(bool $isUpdate): array
    {
        $base = [
            '_token', 'airport_id', 'header_id', 'category_id', 'channel_id',
            'loc_antenna_latitude', 'loc_antenna_longitude', 'ort_loc_antenna_elevation',
            'gp_antenna_latitude', 'gp_antenna_longitude', 'ort_gp_antenna_elevation',
            'gp_signal_origin_latitude', 'gp_signal_origin_longitude', 'ort_gp_signal_origin_elevation',
            'gp_angle', 'opt_action', 'frequency_type', 'distanceThrLoc', 'nominalWidth',
            'dme', 'dmeLatitude', 'dmeLongitude', 'dmeElevation',
            'pointA', 'pointB', 'pointC', 'pointD', 'pointE',
            'selectRunway', 'stretches',
        ];

        if ($isUpdate) {
            $base[] = '_method';
        }

        return $base;
    }

    private function getAlarmExists(Request $request, array $ids, bool $isUpdate): mixed
    {
        if ($ids[1] == 10) {
            return Parameter::where(['subject_id' => $request['header_id'], 'parameter_type_id' => $ids[0], 'task_type_id' => 11])->first();
        }
        if ($ids[1] == 19) {
            return Parameter::where(['subject_id' => $request['header_id'], 'parameter_type_id' => $ids[0], 'task_type_id' => 20])->first();
        }
        return false;
    }

    private function dispatchParameterCreate(Request $request, string $key, array $ids, array $groups): void
    {
        $map = [
            '8' => 'localizer_course', '10' => 'localizer_course_alarm', '9' => 'localizer_width',
            '14' => 'localizer_clearance', '42' => 'localizer_flutuation', '17' => 'glide_angle',
            '19' => 'glide_angle_alarm', '18' => 'glide_width', '23' => 'glide_flutuation', '44' => 'glide_transverse',
        ];
        if (isset($map[$ids[1]])) {
            $this->parameterCreate($request, $key, $groups[$map[$ids[1]]], $ids);
        }
    }

    private function dispatchParameterUpdate(Request $request, string $key, array $ids, array $groups): void
    {
        $map = [
            '8' => 'localizer_course', '10' => 'localizer_course_alarm', '9' => 'localizer_width',
            '14' => 'localizer_clearance', '42' => 'localizer_flutuation', '17' => 'glide_angle',
            '19' => 'glide_angle_alarm', '18' => 'glide_width', '23' => 'glide_flutuation', '44' => 'glide_transverse',
        ];
        if (isset($map[$ids[1]])) {
            $this->parameterUpdate($request, $key, $groups[$map[$ids[1]]], $ids);
        }
    }
}
