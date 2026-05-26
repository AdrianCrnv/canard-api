<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Airport;
use App\Models\Header;
use App\Models\Ils;
use App\Models\Papi;
use App\Models\PapiLight;
use App\Models\PapiLightPosition;
use App\Models\PapiSide;
use App\Models\PapiType;
use App\Models\Parameter;
use App\Models\ParameterType;
use App\Models\Runway;
use App\Models\System;
use App\Models\TaskType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class PapiController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/papis',
        summary: 'List all PAPIs',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        responses: [
            new OA\Response(response: 200, description: 'List of PAPIs'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(Papi::all());
    }

    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/airports/{airport}/papis/form-data',
        summary: 'Get data needed to build the create PAPI form for an airport',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'airport', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Runways, sides, types and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function create(Airport $airport): JsonResponse
    {
        $this->authorize('navaid_create');

        $runways    = Runway::where('airport_id', $airport->id)->get();
        $sides      = PapiSide::all();
        $types      = PapiType::all();
        $task_types = $this->preparePapiParameters(null, 1);
        $parameters = $this->preparePapiParameters(null);

        return response()->json([
            'airport'    => $airport,
            'runways'    => $runways,
            'sides'      => $sides,
            'types'      => $types,
            'task_types' => $task_types,
            'parameters' => $parameters,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/papis',
        summary: 'Create a new PAPI or APAPI',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['header_id', 'side_id', 'type_id', 'meht', 'glide_path_angle', 'airport_id'],
                properties: [
                    new OA\Property(property: 'header_id',        type: 'integer'),
                    new OA\Property(property: 'side_id',          type: 'integer'),
                    new OA\Property(property: 'type_id',          type: 'integer'),
                    new OA\Property(property: 'meht',             type: 'number'),
                    new OA\Property(property: 'glide_path_angle', type: 'number'),
                    new OA\Property(property: 'airport_id',       type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'PAPI created'),
            new OA\Response(response: 409, description: 'Duplicate PAPI for this header/side'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('navaid_create');

        $request->validate([
            'header_id'        => 'required',
            'side_id'          => 'required',
            'type_id'          => 'required',
            'meht'             => 'required|numeric|between:0,999.99',
            'glide_path_angle' => 'required|numeric|between:0,99.99',
        ]);

        $existingPapi = Papi::where('header_id', $request->header_id)
            ->where('side_id', $request->side_id)
            ->first();

        if ($existingPapi) {
            return response()->json(['message' => 'Ya existe un PAPI con este encabezado.'], 409);
        }

        try {
            $this->storePapiParameters($request);

            if ($request['side_id'] == 3) {
                $request['side_id'] = 1;
                $leftPapi = $this->createPapi($request, true);
                $request['side_id'] = 2;
                $rightPapi = $this->createPapi($request, true);
                $created = [$leftPapi, $rightPapi];
            } else {
                $created = $this->createPapi($request, false);
            }

            $airport  = Airport::find($request['airport_id']);
            $papiType = $request['type_id'] == 1 ? 'PAPI' : 'APAPI';
            ActivityLog::log('create', 'Airport', (int) $request['airport_id'], "New system: {$papiType} at airport '{$airport->name}' ({$airport->icao_code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($created, 201);
    }

    // ── PAPIs in header ───────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/headers/{header}/papis',
        summary: 'Get PAPIs with their lights for a specific header',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'header', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PAPIs with lights'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getPapisInHeader(Header $header): JsonResponse
    {
        $this->authorize('navaid_view');

        return response()->json(
            Papi::with('lights')->where('header_id', $header->id)->get()
        );
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/papis/{papi}',
        summary: 'Get a single PAPI with its parameters',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'papi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PAPI data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Papi $papi): JsonResponse
    {
        $this->authorize('navaid_view');

        $header     = $papi->header;
        $sides      = PapiSide::all();
        $types      = PapiType::all();
        $task_types = $this->preparePapiParameters($header, 1);
        $parameters = $this->preparePapiParameters($header);
        $existingPapiSides = [];

        foreach ($header->papis as $existing_papi) {
            if ($existing_papi->id != $papi->id) {
                $existingPapiSides[] = $existing_papi->side->id;
            }
        }

        return response()->json([
            'papi'              => $papi,
            'header'            => $header,
            'airport'           => $header->runway->airport,
            'sides'             => $sides,
            'types'             => $types,
            'existingPapiSides' => $existingPapiSides,
            'task_types'        => $task_types,
            'parameters'        => $parameters,
        ]);
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/papis/{papi}/edit-data',
        summary: 'Get PAPI data and related lists for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'papi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PAPI with sides, types and parameters'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Papi $papi): JsonResponse
    {
        $this->authorize('navaid_edit');

        $header            = $papi->header;
        $sides             = PapiSide::all();
        $types             = PapiType::all();
        $existingPapiSides = [];
        $oppositePapi      = [];
        $task_types        = $this->preparePapiParameters($header, 1);
        $parameters        = $this->preparePapiParameters($header);

        foreach ($header->papis as $existing_papi) {
            if ($existing_papi->id != $papi->id) {
                $existingPapiSides[] = $existing_papi->side->id;
                $oppositePapi[]      = $existing_papi;
            }
        }

        return response()->json([
            'papi'              => $papi,
            'header'            => $header,
            'airport'           => $header->runway->airport,
            'sides'             => $sides,
            'types'             => $types,
            'existingPapiSides' => $existingPapiSides,
            'oppositePapi'      => $oppositePapi,
            'task_types'        => $task_types,
            'parameters'        => $parameters,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/papis/{papi}',
        summary: 'Update an existing PAPI',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'papi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PAPI updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Papi $papi): JsonResponse
    {
        $this->authorize('navaid_edit');

        $request->validate([
            'header_id'        => 'required',
            'side_id'          => 'required',
            'type_id'          => 'required',
            'meht'             => 'required|numeric|between:0,999.99',
            'glide_path_angle' => 'required|numeric|between:0,99.99',
        ]);

        try {
            if ($papi->type_id != intval($request['type_id'])) {
                if (intval($request['type_id']) == 1) {
                    $lightPositions = PapiLightPosition::all();
                    foreach ($lightPositions as $position) {
                        if ($position->id != 1 && $position->id != 2) {
                            PapiLight::create([
                                'papi_id'     => $papi->id,
                                'position_id' => $position->id,
                                'latitude'    => null,
                                'longitude'   => null,
                                'elevation'   => null,
                                'created_at'  => Carbon::now(),
                                'updated_at'  => Carbon::now(),
                            ]);
                        }
                    }
                } elseif ($request['type_id'] == 2) {
                    PapiLight::where('papi_id', $papi->id)->where('position_id', 3)->delete();
                    PapiLight::where('papi_id', $papi->id)->where('position_id', 4)->delete();
                } else {
                    return response()->json(['message' => 'Unknown PAPI type.'], 422);
                }
            }

            $this->updatePapiParameters($request);

            $papis = Papi::where('header_id', $papi->header_id)->get();

            if ($request['side_id'] == 1 || $request['side_id'] == 2) {
                if ($papi->both_sides == false) {
                    foreach ($papis as $currentPapi) {
                        if ($papis->count() <= 1) {
                            $currentPapi->side_id = $request['side_id'];
                        } else {
                            $currentPapi->enabled = ($currentPapi->side_id == $request['side_id']);
                        }
                        $currentPapi->save();
                    }
                } else {
                    foreach ($papis as $currentPapi) {
                        if ($currentPapi->side_id != $request['side_id']) {
                            $currentPapi->enabled = false;
                        }
                        $currentPapi->both_sides = false;
                        $currentPapi->save();
                    }
                }
            } elseif ($request['side_id'] == 3) {
                $opposite_side_id = $papi->side_id == 1 ? 2 : 1;

                if ($papi->both_sides == false) {
                    foreach ($papis as $currentPapi) {
                        if ($papis->count() <= 1) {
                            $oppositePapi = Papi::create([
                                'header_id'        => $request['header_id'],
                                'side_id'          => $opposite_side_id,
                                'type_id'          => $request['type_id'],
                                'meht'             => $request['meht'],
                                'glide_path_angle' => $request['glide_path_angle'],
                                'both_sides'       => true,
                                'enabled'          => true,
                            ]);
                            $lightPositions = PapiLightPosition::all();
                            foreach ($lightPositions as $position) {
                                PapiLight::firstOrCreate([
                                    'papi_id'     => $oppositePapi->id,
                                    'position_id' => $position->id,
                                    'latitude'    => null,
                                    'longitude'   => null,
                                    'elevation'   => null,
                                    'created_at'  => now(),
                                    'updated_at'  => now(),
                                ]);
                            }
                        } else {
                            $currentPapi->enabled    = true;
                            $currentPapi->both_sides = true;
                        }
                        $currentPapi->save();
                    }
                } else {
                    $oppositePapi = Papi::where('header_id', $papi->header_id)
                        ->where('id', '!=', $papi->id)
                        ->first();

                    $oppositePapi->header_id        = $request['header_id'];
                    $oppositePapi->type_id          = $request['type_id'];
                    $oppositePapi->meht             = $request['meht'];
                    $oppositePapi->glide_path_angle = $request['glide_path_angle'];
                    $oppositePapi->save();
                }

                $papi->both_sides = true;
            }

            $before = [
                'Type'             => $papi->type_id,
                'MEHT'             => $papi->meht,
                'Glide path angle' => $papi->glide_path_angle,
            ];

            $papi->header_id        = $request['header_id'];
            $papi->type_id          = $request['type_id'];
            $papi->meht             = $request['meht'];
            $papi->glide_path_angle = $request['glide_path_angle'];
            $papi->save();

            Ils::where('header_id', $papi->header_id)->update(['gp_angle' => $papi->glide_path_angle]);

            $after = [
                'Type'             => $papi->type_id,
                'MEHT'             => $papi->meht,
                'Glide path angle' => $papi->glide_path_angle,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $airport  = Airport::find($request['airport_id']);
            $papiType = $request['type_id'] == 1 ? 'PAPI' : 'APAPI';
            $description = "Updated system: {$papiType} #{$papi->id} at airport '{$airport->name}' ({$airport->icao_code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Airport', (int) $request['airport_id'], $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($papi->fresh());
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/papis/{papi}',
        summary: 'Delete a PAPI',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'papi', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PAPI deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(Papi $papi): JsonResponse
    {
        $this->authorize('navaid_delete');

        try {
            $airport  = Airport::find($papi->header->runway->airport_id);
            $papiType = $papi->type_id == 1 ? 'PAPI' : 'APAPI';
            ActivityLog::log('delete', 'Airport', (int) $papi->header->runway->airport_id, "Deleted system: {$papiType} #{$papi->id} from airport '{$airport->name}' ({$airport->icao_code})");

            $papi->delete();
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'PAPI deleted successfully.']);
    }

    // ── Update missing params (maintenance) ───────────────────────────────────

    #[OA\Get(
        path: '/api/papis/{id}/update-params',
        summary: 'Backfill missing PAPI parameters with default values',
        security: [['bearerAuth' => []]],
        tags: ['PAPIs'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inserted parameter rows'),
            new OA\Response(response: 404, description: 'PAPI not found'),
        ]
    )]
    public function updateNewParamsPAPI(int $id): JsonResponse
    {
        $currentTimestamp = Carbon::now();
        $papi             = Papi::findOrFail($id);

        $arrayParamsPapi = DB::table('task_types as ttyy')
            ->select('prtk.parameter_type_id')
            ->join('parameter_type_task_type as prtk', 'prtk.task_type_id', '=', 'ttyy.id')
            ->where('ttyy.name', 'LIKE', 'PAPI%')
            ->distinct()
            ->pluck('prtk.parameter_type_id')
            ->toArray();

        $parametrosPAPI = DB::table('task_types as ttyy')
            ->select('prtk.parameter_type_id', 'ttyy.id as task_type_id')
            ->join('parameter_type_task_type as prtk', 'prtk.task_type_id', '=', 'ttyy.id')
            ->where('ttyy.name', 'LIKE', 'PAPI%')
            ->distinct()
            ->get();

        $resultadosPAPI = DB::table('parameters as pa')
            ->select(
                DB::raw('1 as subject_type_id'),
                DB::raw($papi->header_id . ' as subject_id'),
                'prtk.parameter_type_id',
                'tt.id as task_type_id',
                'pa.value',
                'pa.created_at',
                'pa.updated_at'
            )
            ->join('parameter_type_task_type as prtk', 'prtk.parameter_type_id', '=', 'pa.parameter_type_id')
            ->join('task_types as tt', 'tt.id', '=', 'pa.task_type_id')
            ->where('pa.subject_type_id', 1)
            ->where('pa.subject_id', $papi->header_id)
            ->whereIn('prtk.parameter_type_id', $arrayParamsPapi)
            ->orderBy('pa.parameter_type_id', 'ASC')
            ->distinct()
            ->get();

        $incompleteds = $parametrosPAPI->filter(function ($parametro) use ($resultadosPAPI) {
            return !$resultadosPAPI->contains(function ($resultado) use ($parametro) {
                return $resultado->parameter_type_id == $parametro->parameter_type_id
                    && $resultado->task_type_id == $parametro->task_type_id;
            });
        });

        $incompleteds = $incompleteds->map(function ($item) use ($papi) {
            $valor = 0;
            if ($item->parameter_type_id == 2 && $item->task_type_id == 5) $valor = 450;
            elseif ($item->parameter_type_id == 2 && $item->task_type_id == 6) $valor = 450;
            elseif ($item->parameter_type_id == 3 && $item->task_type_id == 6) $valor = 15;
            elseif ($item->parameter_type_id == 4 && $item->task_type_id == 6) $valor = 15;

            $item->subject_type_id = 1;
            $item->subject_id      = $papi->header_id;
            $item->value           = $valor;
            return $item;
        });

        $incompleteds->each(function ($incompleted) use ($currentTimestamp) {
            DB::insert(
                'INSERT INTO parameters (parameter_type_id, task_type_id, subject_type_id, subject_id, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $incompleted->parameter_type_id,
                    $incompleted->task_type_id,
                    $incompleted->subject_type_id,
                    $incompleted->subject_id,
                    $incompleted->value,
                    $currentTimestamp,
                    $currentTimestamp,
                ]
            );
        });

        return response()->json(['inserted' => $incompleteds->values()]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function createPapi($params, bool $both_sides): Papi
    {
        if ($params['type_id'] == 1) {
            $lightPositions = PapiLightPosition::all();
        } elseif ($params['type_id'] == 2) {
            $lightPositions = PapiLightPosition::take(2)->get();
        } else {
            abort(422, 'Unknown PAPI type.');
        }

        $newPapi = Papi::create([
            'header_id'        => $params['header_id'],
            'side_id'          => $params['side_id'],
            'type_id'          => $params['type_id'],
            'meht'             => $params['meht'],
            'glide_path_angle' => $params['glide_path_angle'],
            'both_sides'       => $both_sides,
            'enabled'          => true,
        ]);

        foreach ($lightPositions as $position) {
            PapiLight::create([
                'papi_id'     => $newPapi->id,
                'position_id' => $position->id,
                'latitude'    => null,
                'longitude'   => null,
                'elevation'   => null,
                'created_at'  => Carbon::now(),
                'updated_at'  => Carbon::now(),
            ]);
        }

        return $newPapi;
    }

    public function preparePapiParameters($header, $opt = null): array
    {
        $system            = System::where('name', 'PAPI')->first();
        $papi_task_type_id = DB::table('systems_id_task_type_id')->where('system_id', $system->id)->get();

        if ($opt) {
            $task_types = [];
            foreach ($papi_task_type_id as $task_type) {
                $task_types[] = TaskType::where('id', $task_type->task_type_id)->first();
            }
            return $task_types;
        }

        $parameters = [];
        foreach ($papi_task_type_id as $papi_task_type) {
            $parameter_type_rows = DB::table('parameter_type_task_type')
                ->where('task_type_id', $papi_task_type->task_type_id)
                ->get();

            foreach ($parameter_type_rows as $parameter_type) {
                $name                  = TaskType::where('id', $parameter_type->task_type_id)->first();
                $parameter_type_record = DB::table('parameter_types')->where('id', $parameter_type->parameter_type_id)->first();
                $value                 = $header
                    ? Parameter::where([
                        'parameter_type_id' => $parameter_type->parameter_type_id,
                        'subject_id'        => $header->id,
                        'task_type_id'      => $parameter_type->task_type_id,
                    ])->first()
                    : null;

                $parameters[] = [
                    'task_type'      => $name,
                    'parameter_type' => $parameter_type_record,
                    'value'          => $value,
                ];
            }
        }

        return $parameters;
    }

    public function storePapiParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if (in_array($key, ['_token', 'airport_id', 'header_id', 'side_id', 'type_id', 'meht', 'glide_path_angle', 'runwayCreate'])) {
                continue;
            }

            $ids    = explode('-', $key);
            $exists = Parameter::where([
                'subject_id'        => $request['header_id'],
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $ids[1],
            ])->first();

            if ($exists) {
                $exists->delete();
            }

            Parameter::create([
                'subject_type_id'   => 1,
                'subject_id'        => $request['header_id'],
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $ids[1],
                'value'             => $request[$key],
            ]);
        }
    }

    public function updatePapiParameters(Request $request): void
    {
        foreach ($request->request as $key => $value) {
            if (in_array($key, ['_token', 'airport_id', 'header_id', 'side_id', 'type_id', 'meht', 'glide_path_angle', '_method'])) {
                continue;
            }

            $ids    = explode('-', $key);
            $exists = Parameter::find($ids[2]);

            if ($exists) {
                $exists->delete();
            }

            Parameter::create([
                'subject_type_id'   => 1,
                'subject_id'        => $request['header_id'],
                'parameter_type_id' => $ids[0],
                'task_type_id'      => $ids[1],
                'value'             => $request[$key],
            ]);
        }
    }
}
