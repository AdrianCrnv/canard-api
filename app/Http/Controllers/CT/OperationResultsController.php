<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Models\AcMaintImage;
use App\Models\AerodromeBeacon;
use App\Models\AerodromeBeaconVideo;
use App\Models\Camera;
use App\Models\Drone;
use App\Models\FlightTurnImage;
use App\Models\LightsImage;
use App\Models\LightsProcessingJob;
use App\Models\LightsVideo;
use App\Models\MarkingsProcessingJob;
use App\Models\MarkingsVideo;
use App\Models\MeasurementAls;
use App\Models\MeasurementPapiAngularCoverage;
use App\Models\MeasurementPapiUnitLocation;
use App\Models\MeasurementPapiVerticalAngle;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\PapiLight;
use App\Models\ResultAcMaint;
use App\Models\ResultFlightTurn;
use App\Models\ResultFod;
use App\Models\ResultPapiAngularCoverage;
use App\Models\ResultPapiUnitLocation;
use App\Models\ResultPapiVerticalAngle;
use App\Models\ResultPci;
use App\Models\ResultsAls;
use App\Models\ResultsBeacon;
use App\Models\ResultsFodParams;
use App\Models\ResultsIlsGlidePath;
use App\Models\ResultsIlsLocalizer;
use App\Models\ResultsPciParams;
use App\Models\ResultsRwyLights;
use App\Models\ResultsRwyMarkings;
use App\Models\ResultsTxyLights;
use App\Models\ResultsVor;
use App\Models\ResultsWdi;
use App\Models\Task;
use App\Models\Wdi;
use App\Models\WdiFile;
use App\Services\ExifMetadataService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use App\Http\Controllers\MarkingsProcessingController;
use OpenApi\Attributes as OA;

class OperationResultsController extends Controller
{
    public function __construct(
        private readonly OperationMediaController $mediaController
    ) {}

    #[OA\Post(
        path: '/api/ct/operations/{operation}/results',
        summary: 'Guarda los resultados de una operación según su tipo',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations Results'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'ct_version', type: 'string'),
                    new OA\Property(property: 'status_id', type: 'integer'),
                    new OA\Property(property: 'tasks', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resultados guardados correctamente'),
            new OA\Response(response: 400, description: 'Error al guardar o tipo de operación no soportado'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function saveOperationResults(Request $request, Operation $operation): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        // Check if the user is an administrator
        if ($user->hasRole('admin') == false) {
            if ($operation->operator != $user->operator) {
                return response()->json(['error' => 'Permission denied'], 403);
            }
        }

        if ($permissions->contains('name', 'operation_edit')) {

            $data = $request->json()->all();

            $operation             = Operation::where('id', $data['id'])->first();
            $operation->ct_version = $data['ct_version'];
            $operation->save();

            $this->mediaController->deleteMediaIds($operation);

            // Inicializar variables por defecto
            $message = 'Operation type not supported';
            $code    = 400;

            switch ($operation->type_id) {
                case 2: // PAPI calibration - Left side
                case 3: // PAPI calibration - Right side
                case 4: // PAPI calibration - Both sides
                    $resultsSaved = $this->savePapiOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 5: // Localizer ground inspection
                    $resultsSaved = $this->saveLocalizerOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 6: // Glide path ground inspection
                    $resultsSaved = $this->saveGlidePathOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 7: // Vor inspection
                    $resultsSaved = $this->saveVorOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 8: // Als inspection
                    $resultsSaved = $this->saveAlsOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 9: // Pci
                    try {
                        $this->createFlightMetaPCI($operation, $data);
                        $message = 'Flight Meta generated and saved correctly';
                        $code    = 200;
                    } catch (\Exception $e) {
                        $message = 'Error: ' . $e->getMessage();
                        $code    = 400;
                    }
                    break;

                case 10: // Runway lighting inspection
                    $resultsSaved = $this->saveRwyLightOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 11: // Taxiway lighting inspection
                    $resultsSaved = $this->saveTxyLightOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 16: // FOD inspection
                    try {
                        $this->createFlightMetaFOD($operation, $data);
                        $message = 'Flight Meta generated and saved correctly';
                        $code    = 200;
                    } catch (\Exception $e) {
                        $message = 'Error: ' . $e->getMessage();
                        $code    = 400;
                    }
                    break;

                case 19: // AcMaint inspection
                    $resultsSaved = $this->saveAcMaintOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 20: // FT
                    $resultsSaved = $this->saveFtOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 21: // Aerodrome Beacon
                    $resultsSaved = $this->saveAerodromeBeaconOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 22: // Runway Markings inspection
                    $resultsSaved = $this->saveRwyMarkingsOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                case 23: // WDI
                    $resultsSaved = $this->saveWdiOperation($operation, $data);
                    $message      = $resultsSaved ? 'Results saved correctly' : 'Error: The results could not be saved';
                    $code         = $resultsSaved ? 200 : 400;
                    break;

                default:
                    Log::warning("Unsupported operation type: {$operation->type_id} for operation ID: {$operation->id}");
                    $message = 'Operation type not supported';
                    $code    = 400;
                    break;
            }

            $this->mediaController->scriptMediaS3New();

            return response()->json(['message' => $message], $code);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    // =========================================================================
    //  PAPI
    // =========================================================================

    private function savePapiOperation(Operation $operation, array $results): bool
    {
        $saved = false;

        if (isset($results['tasks'])) {
            $operationStatusId = $results['status_id'] ?? null;

            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    switch ($task['type_id'] ?? null) {
                        case 2: // PAPI Unit Location
                            ResultPapiUnitLocation::where('task_id', $taskId)->delete();
                            $this->parsePapiUnitLocationResults($task);
                            $this->updatePapiLightsLocation($task, $results['airport'] ?? []);
                            break;

                        case 5: // PAPI Vertical Angle
                            ResultPapiVerticalAngle::where('task_id', $taskId)->delete();
                            $this->parsePapiVerticalAngleResults($task);
                            break;

                        case 6: // PAPI Angular Coverage
                            ResultPapiAngularCoverage::where('task_id', $taskId)->delete();
                            $this->parsePapiAngularCoverageResults($task);
                            break;
                    }

                    $dbTask = Task::find($taskId);

                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4) ? 3 : $taskStatusId; // In the Android App, status 4 is "Completed and uploaded", equivalent to 3 in the platform
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $saved = true;
                }
            }

            $statusId           = ($operationStatusId == 5) ? 3 : $operationStatusId; // In the Android App, status 5 is "Completed and uploaded", equivalent to 3 in the platform
            $operation->status_id = $statusId;
            $operation->save();
        }

        return $saved;
    }

    private function parsePapiUnitLocationResults(array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['papi_unit_locations_data'][0] ?? [];
        $taskId  = $task['id'] ?? null;
        $papiId  = $task['subject_id'] ?? null;

        if (!$results) return;

        $res = new ResultPapiUnitLocation([
            'task_id'            => $taskId,
            'papi_id'            => $papiId,
            'distance_a'         => $results['distance_a'] ?? null,
            'distance_b'         => $results['distance_b'] ?? null,
            'distance_c'         => $results['distance_c'] ?? null,
            'distance_d'         => $results['distance_d'] ?? null,
            'horizontality'      => $results['horizontality'] ?? null,
            'is_location_valid'  => $results['is_location_valid'] ?? null,
        ]);
        $res->save();

        foreach ($results['measurements'] ?? [] as $measurement) {
            $m = new MeasurementPapiUnitLocation([
                'result_id'   => $res->id,
                'light_id'    => $measurement['light_id'] ?? null,
                'latitude'    => $measurement['latitude'] ?? null,
                'longitude'   => $measurement['longitude'] ?? null,
                'elevation'   => $measurement['altitude'] ?? null,
                'offset_x'    => $measurement['offset_x'] ?? null,
                'offset_y'    => $measurement['offset_y'] ?? null,
                'offset_z'    => $measurement['offset_z'] ?? null,
                'fix_type_id' => $this->getGpsFixTypeId($measurement['fix_type'] ?? null),
                'date'        => $measurement['date'] ?? null,
            ]);
            $m->save();
        }
    }

    private function parsePapiVerticalAngleResults(array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['papi_angle_sets'] ?? [];
        $taskId  = $task['id'] ?? null;
        $papiId  = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $res = new ResultPapiVerticalAngle([
                'task_id'         => $taskId,
                'papi_id'         => $papiId,
                'light_id'        => $result['light_id'] ?? null,
                'mean_angle_high' => $result['mean_angle_high'] ?? null,
                'mean_angle_low'  => $result['mean_angle_low'] ?? null,
                'observations'    => $result['observations'] ?? null,
                'set_number'      => $result['set_number'] ?? null,
                'is_valid_set'    => $result['is_valid_set'] ?? null,
            ]);
            $res->save();

            foreach ($result['measurements'] ?? [] as $measurement) {
                $m = new MeasurementPapiVerticalAngle([
                    'result_id'      => $res->id,
                    'angle_high'     => $measurement['angle_high'] ?? null,
                    'angle_low'      => $measurement['angle_low'] ?? null,
                    'latitude_high'  => $measurement['latitude_high'] ?? null,
                    'latitude_low'   => $measurement['latitude_low'] ?? null,
                    'longitude_high' => $measurement['longitude_high'] ?? null,
                    'longitude_low'  => $measurement['longitude_low'] ?? null,
                    'elevation_high' => $measurement['altitude_high'] ?? null,
                    'elevation_low'  => $measurement['altitude_low'] ?? null,
                    'fix_type_id'    => $this->getGpsFixTypeId($measurement['fix_type'] ?? null),
                    'is_enabled'     => $measurement['is_enabled'] ?? null,
                    'run_number'     => $measurement['run_number'] ?? null,
                    'date'           => $measurement['date'],
                ]);
                $m->save();
            }
        }
    }

    private function parsePapiAngularCoverageResults(array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results  = $task['results'][0]['papi_angle_sets'] ?? [];
        $taskId   = $task['id'] ?? null;
        $headerId = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $res = new ResultPapiAngularCoverage([
                'task_id'            => $taskId,
                'header_id'          => $headerId,
                'transition_type_id' => $this->getPapiTransitionId($result['light_name'] ?? null),
                'mean_angle'         => $result['mean_angle_angular'] ?? null,
                'observations'       => $result['observations'] ?? null,
                'set_number'         => $result['set_number'] ?? null,
                'is_valid_set'       => $result['is_valid_set'] ?? null,
            ]);
            $res->save();

            foreach ($result['measurements'] ?? [] as $measurement) {
                $m = new MeasurementPapiAngularCoverage([
                    'result_id'   => $res->id,
                    'angle'       => $measurement['angle_angular'] ?? null,
                    'latitude'    => $measurement['latitude_angular'] ?? null,
                    'longitude'   => $measurement['longitude_angular'] ?? null,
                    'elevation'   => $measurement['altitude_angular'] ?? null,
                    'fix_type_id' => $this->getGpsFixTypeId($measurement['fix_type'] ?? null),
                    'is_enabled'  => $measurement['is_enabled'] ?? null,
                    'run_number'  => $measurement['run_number'] ?? null,
                    'date'        => $measurement['date'] ?? null,
                ]);
                $m->save();
            }
        }
    }

    private function updatePapiLightsLocation(array $task, array $airport): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['papi_unit_locations_data'][0] ?? [];
        $papiId  = $task['subject_id'] ?? null;

        if (isset($results['is_location_valid']) && $results['is_location_valid'] && $papiId) {
            foreach ($airport['runways'] ?? [] as $runway) {
                foreach ($runway['headers'] ?? [] as $header) {
                    foreach ($header['papis'] ?? [] as $papi) {
                        if ($papi['id'] == $papiId) {
                            foreach ($papi['lights'] ?? [] as $light) {
                                $dbLight = PapiLight::find($light['id']);
                                if ($dbLight) {
                                    $dbLight->latitude  = $light['latitude_calc'] ?? null;
                                    $dbLight->longitude = $light['longitude_calc'] ?? null;
                                    $dbLight->elevation = $light['elevation_calc'] ?? null;
                                    $dbLight->save();
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // =========================================================================
    //  ILS LOCALIZER
    // =========================================================================

    private function saveLocalizerOperation(Operation $operation, array $results): bool
    {
        $saved = false;

        if (isset($results['tasks'])) {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $saved = true;

                    ResultsIlsLocalizer::where('task_id', $taskId)->delete();
                    $this->parseIlsLocalizerResults($operation, $task);
                }
            }
        }

        return $saved;
    }

    private function parseIlsLocalizerResults(Operation $operation, array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['loc_data'] ?? [];
        $taskId  = $task['id'] ?? null;
        $ilsId   = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $rawDataId = null;
            if ($result['has_raw_data'] == 1) {
                $rawData   = $result['raw_data'];
                $rawDataId = $this->mediaController->createMediaEntry($operation, $rawData);
            }

            $res = new ResultsIlsLocalizer([
                'task_id'                  => $taskId,
                'ils_id'                   => $ilsId,
                'angle_left075'            => $result['angle_left075'],
                'angle_left150'            => $result['angle_left150'],
                'angle_right075'           => $result['angle_right075'],
                'angle_right150'           => $result['angle_right150'],
                'average_ddm'              => $result['average_ddm'],
                'average_ddm_ua'           => $result['average_ddm_ua'],
                'average_sdm'              => $result['average_sdm'],
                'fix_type'                 => $result['fix_type'] ?? null,
                'is_valid_run'             => $result['is_valid_run'],
                'max_ddm'                  => $result['max_ddm'],
                'max_ddm_ua'               => $result['max_ddm_ua'],
                'min_ddm'                  => $result['min_ddm'],
                'min_ddm_ua'               => $result['min_ddm_ua'],
                'observations'             => $result['observations'] ?? null,
                'run_number'               => $result['run_number'],
                'angle_zero_cross'         => $result['angle_zero_cross'],
                'clearance_ddm_left'       => $result['clearance_ddm_left'],
                'clearance_ddm_right'      => $result['clearance_ddm_right'],
                'clearance_angle_left'     => $result['clearance_angle_left'],
                'clearance_angle_right'    => $result['clearance_angle_right'],
                'sensitivity_displacement' => $result['sensitivity_displacement'],
                'mean_rf_level'            => $result['mean_rf_level'],
                'mean_mod90'               => $result['mean_mod90'],
                'mean_mod150'              => $result['mean_mod150'],
                'mean_freq_sep'            => $result['mean_freq_sep'],
                'mean_freq_offset'         => $result['mean_freq_offset'],
                'has_raw_data'             => $result['has_raw_data'],
                'transmitter'              => $result['transmitter'],
                'average_ddm_str'          => $result['average_ddm_str'],
                'average_ddm_ua_str'       => $result['average_ddm_ua_str'],
                'average_sdm_str'          => $result['average_sdm_str'],
                'mean_mod150_str'          => $result['mean_mod150_str'],
                'mean_mod90_str'           => $result['mean_mod90_str'],
                'mean_freq_offset_str'     => $result['mean_freq_offset_str'],
                'mean_freq_sep_str'        => $result['mean_freq_sep_str'],
                'media_id'                 => $rawDataId,
                'flight_distance'          => $result['flight_distance'],
                'flight_time'              => $result['flight_time'],
            ]);
            $res->save();
        }
    }

    // =========================================================================
    //  ILS GLIDE PATH
    // =========================================================================

    private function saveGlidePathOperation(Operation $operation, array $results): bool
    {
        $saved = false;

        if (isset($results['tasks'])) {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $saved = true;

                    ResultsIlsGlidePath::where('task_id', $taskId)->delete();
                    $this->parseIlsGlidePathResults($operation, $task);
                }
            }
        }

        return $saved;
    }

    private function parseIlsGlidePathResults(Operation $operation, array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['gp_data'] ?? [];
        $taskId  = $task['id'] ?? null;
        $ilsId   = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $rawDataId = null;
            if ($result['has_raw_data'] == 1) {
                $rawData   = $result['raw_data'];
                $rawDataId = $this->mediaController->createMediaEntry($operation, $rawData);
            }

            if ($result['type_data'] != 'structure') {
                $res = new ResultsIlsGlidePath([
                    'task_id'                   => $taskId,
                    'ils_id'                    => $ilsId,
                    'angle_high075'             => $result['angle_high075'],
                    'angle_high150'             => $result['angle_high150'],
                    'angle_low075'              => $result['angle_low075'],
                    'angle_low150'              => $result['angle_low150'],
                    'average_ddm'               => $result['average_ddm'],
                    'average_ddm_ua'            => $result['average_ddm_ua'],
                    'average_sdm'               => $result['average_sdm'],
                    'fix_type'                  => $result['fix_type'] ?? null,
                    'is_valid_run'              => $result['is_valid_run'],
                    'max_ddm'                   => $result['max_ddm'],
                    'max_ddm_ua'                => $result['max_ddm_ua'],
                    'min_ddm'                   => $result['min_ddm'],
                    'min_ddm_ua'                => $result['min_ddm_ua'],
                    'observations'              => $result['observations'] ?? null,
                    'run_number'                => $result['run_number'],
                    'mean_glide_path_angle'     => $result['mean_glide_path_angle'],
                    'mean_glide_path_angle_str' => $result['mean_glide_path_angle_str'],
                    'angle_zero_cross'          => $result['angle_zero_cross'],
                    'sensitivity_displacement'  => $result['sensitivity_displacement'],
                    'mean_rf_level'             => $result['mean_rf_level'],
                    'mean_mod90'                => $result['mean_mod90'],
                    'mean_mod150'               => $result['mean_mod150'],
                    'mean_freq_sep'             => $result['mean_freq_sep'],
                    'mean_freq_offset'          => $result['mean_freq_offset'],
                    'has_raw_data'              => $result['has_raw_data'],
                    'transmitter'               => $result['transmitter'],
                    'average_ddm_str'           => $result['average_ddm_str'],
                    'average_ddm_ua_str'        => $result['average_ddm_ua_str'],
                    'average_sdm_str'           => $result['average_sdm_str'],
                    'mean_mod150_str'           => $result['mean_mod150_str'],
                    'mean_mod90_str'            => $result['mean_mod90_str'],
                    'mean_freq_offset_str'      => $result['mean_freq_offset_str'],
                    'mean_freq_sep_str'         => $result['mean_freq_sep_str'],
                    'media_id'                  => $rawDataId,
                    'flight_distance'           => $result['flight_distance'],
                    'flight_time'               => $result['flight_time'],
                ]);
                $res->save();
            }
        }
    }

    // =========================================================================
    //  ALS
    // =========================================================================

    public function saveAlsOperation(Operation $operation, array $results): bool
    {
        $saved = false;

        if (isset($results['tasks'])) {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $saved = true;

                    ResultsAls::where('task_id', $taskId)->delete();
                    $this->parseAlsResults($operation, $task);
                }
            }
        }

        return $saved;
    }

    public function parseAlsResults(Operation $operation, array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['als_data'] ?? [];
        $taskId  = $task['id'] ?? null;
        $alsId   = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $res = new ResultsAls([
                'task_id'      => $taskId,
                'als_id'       => $alsId,
                'observations' => $result['observations'] ?? null,
            ]);
            $res->save();

            foreach ($result['measurements'] ?? [] as $measurement) {
                $image_file = $measurement['image'];
                $image_name = $this->mediaController->createMediaEntry($operation, $image_file, 'ALS');

                $m = new MeasurementAls([
                    'result_id'              => $res->id,
                    'image_name'             => $image_name,
                    'is_measurement_valid'   => $measurement['is_measurement_valid'] ?? null,
                    'updated_at'             => !empty($measurement['last_modified']) ? Carbon::parse($measurement['last_modified']) : now(),
                    'measurement_number'     => $measurement['measurement_number'] ?? null,
                    'image_type'             => $measurement['type_id'] ?? null,
                ]);
                $m->save();
            }
        }
    }

    // =========================================================================
    //  VOR
    // =========================================================================

    private function saveVorOperation(Operation $operation, array $results): bool
    {
        $saved = false;

        if (isset($results['tasks'])) {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $saved = true;

                    ResultsVor::where('task_id', $taskId)->delete();
                    $this->parseVorResults($operation, $task);
                }
            }
        }

        return $saved;
    }

    private function parseVorResults(Operation $operation, array $task): void
    {
        // The [0] position at the arrays is a limitation of Realm in the Android App
        $results = $task['results'][0]['vor_data'] ?? [];
        $taskId  = $task['id'] ?? null;
        $vorId   = $task['subject_id'] ?? null;

        if (!$results) return;

        foreach ($results as $result) {
            $rawDataId = null;
            if ($result['has_raw_data'] == 1) {
                $rawData   = $result['raw_data'];
                $rawDataId = $this->mediaController->createMediaEntry($operation, $rawData);
            }

            $res = new ResultsVor([
                'task_id'                      => $taskId,
                'vor_id'                       => $vorId,
                'average_bearing'              => $result['average_bearing'],
                'average_bearing_error'        => $result['average_bearing_error'],
                'fix_type'                     => $result['fix_type'] ?? null,
                'has_raw_data'                 => $result['has_raw_data'],
                'is_valid_run'                 => $result['is_valid_run'],
                'orbit_radio'                  => $result['orbit_radio'],
                'over_vor'                     => $result['altitude_over_vor'],
                'pos_from'                     => $result['pos_from'],
                'pos_to'                       => $result['pos_to'],
                'mean_f30_hz_mod_depth'        => $result['mean_f30_hz_mod_depth'],
                'mean_f30_hz_mod_freq'         => $result['mean_f30_hz_mod_freq'],
                'mean_f9960_hz30_hz_mod_freq'  => $result['mean_f9960_hz30_hz_mod_freq'],
                'mean_f9960_hz_dev'            => $result['mean_f9960_hz_dev'],
                'mean_f9960_hz_freq_dev'       => $result['mean_f9960_hz_freq_dev'],
                'mean_f9960_hz_mod_depth'      => $result['mean_f9960_hz_mod_depth'],
                'mean_f9960_hz_sub_freq'       => $result['mean_f9960_hz_sub_freq'],
                'mean_field_strength'          => $result['mean_field_strength'],
                'observations'                 => $result['observations'] ?? null,
                'run_number'                   => $result['run_number'],
                'transmitter'                  => $result['transmitter'],
                'radial_number'                => $result['radial_number'],
                'bends'                        => round($result['bends'], 3),
                'bends_position'               => round($result['bends_position'], 3),
                'scalloping'                   => round($result['scalloping'], 3),
                'scalloping_position'          => round($result['scalloping_position'], 3),
                'initial_bearing'              => $result['initial_bearing'],
                'initial_record'               => $result['initial_record'],
                'media_id'                     => $rawDataId,
            ]);
            $res->save();
        }
    }

    // =========================================================================
    //  RUNWAY LIGHTS
    // =========================================================================

    public function saveRwyLightOperation(Operation $operation, array $results): mixed
    {
        $saved = false;

        try {
            foreach ($results['tasks'] ?? [] as $taskIndex => $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;
                $rwyId        = $results['subject_id'] ?? null;

                if ($taskId && !empty($task['results'])) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $result      = $task['results'][0];
                    $operationID = $operation->id;

                    foreach ($result['lights_rwy_runs'] ?? [] as $runIndex => $runData) {
                        $runNumber  = $runData['number'] ?? null;
                        $isSelected = $runData['is_selected'] ?? false;

                        // ===== Escanear subcarpetas (directions) dentro del run =====
                        $basePath    = "Lights/{$operationID}/{$taskId}/{$runNumber}";
                        $allDirFiles = Storage::disk('s3')->allFiles($basePath);

                        // Extraer directions únicas de las subcarpetas
                        $directions = [];
                        foreach ($allDirFiles as $filePath) {
                            $relative = substr($filePath, strlen($basePath) + 1);
                            $parts    = explode('/', $relative);
                            if (count($parts) > 1) {
                                $directions[$parts[0]] = true;
                            }
                        }
                        $directions = array_keys($directions);

                        Log::info('[saveRwyLights] Directions found', [
                            'run'              => $runNumber,
                            'directions'       => $directions,
                            'directions_count' => count($directions),
                        ]);

                        foreach ($directions as $dirIndex => $direction) {
                            $dirPath         = "{$basePath}/{$direction}";
                            $filesInDir      = Storage::disk('s3')->files($dirPath);
                            $videoPath       = null;
                            $srtPath         = null;
                            $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'mts', 'm4v'];

                            foreach ($filesInDir as $file) {
                                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                if (in_array($ext, $videoExtensions) && !$videoPath) {
                                    $videoPath = $file;
                                }
                                if ($ext === 'srt' && !$srtPath) {
                                    $srtPath = $file;
                                }
                            }

                            Log::info('[saveRwyLights] Video/SRT check', [
                                'run'         => $runNumber,
                                'direction'   => $direction,
                                'video_path'  => $videoPath,
                                'srt_path'    => $srtPath,
                                'video_found' => $videoPath !== null,
                                'srt_found'   => $srtPath !== null,
                            ]);

                            $existing = ResultsRwyLights::where('task_id', $taskId)
                                ->where('run', $runNumber)
                                ->where('side', $direction)
                                ->exists();

                            if (!$existing) {
                                $resultRwyLight = ResultsRwyLights::create([
                                    'task_id'      => $taskId,
                                    'rwy_id'       => $rwyId,
                                    'operation_id' => $operationID,
                                    'run'          => $runNumber,
                                    'side'         => $direction,
                                    'content_type' => 'video',
                                    'is_video'     => true,
                                    'is_valid'     => $isSelected ? 1 : 0,
                                ]);

                                $resultId = $resultRwyLight->id;

                                if ($srtPath) {
                                    LightsVideo::create([
                                        'result_rwy_light_id' => $resultId,
                                        'file_type'           => 'srt',
                                        'filename'            => basename($srtPath),
                                        'size_bytes'          => Storage::disk('s3')->size($srtPath),
                                    ]);
                                }

                                if ($videoPath) {
                                    LightsVideo::create([
                                        'result_rwy_light_id' => $resultId,
                                        'file_type'           => 'video',
                                        'filename'            => basename($videoPath),
                                        'size_bytes'          => Storage::disk('s3')->size($videoPath),
                                    ]);
                                }

                                if ($videoPath) {
                                    try {
                                        $srtSizeBytes   = $srtPath ? Storage::disk('s3')->size($srtPath) : null;
                                        $videoSizeBytes = Storage::disk('s3')->size($videoPath);

                                        $processingJob = LightsProcessingJob::create([
                                            'operation_id'        => $operationID,
                                            'task_id'             => $taskId,
                                            'runway_id'           => $rwyId,
                                            'run'                 => $runNumber,
                                            'side'                => $direction,
                                            'fly_speed'           => $runData['flight_speed'] ?? null,
                                            'objective_mpf'       => 30,
                                            'video_s3_path'       => $videoPath,
                                            'video_size_bytes'    => $videoSizeBytes,
                                            'srt_s3_path'         => $srtPath,
                                            'srt_size_bytes'      => $srtSizeBytes,
                                            'result_rwy_light_id' => $resultId,
                                            'status'              => LightsProcessingJob::STATUS_UPLOADING_VIDEO,
                                        ]);

                                        Log::info('[saveRwyLights] Processing job created', [
                                            'job_id'    => $processingJob->id,
                                            'direction' => $direction,
                                            'video'     => $videoPath,
                                        ]);

                                        $processingController = app(\App\Http\Controllers\LightsProcessingController::class);
                                        $extractionRequest    = new \Illuminate\Http\Request();
                                        $extractionRequest->merge(['job_id' => $processingJob->id]);
                                        $processingController->startFrameExtraction($extractionRequest);

                                        exec(sprintf(
                                            'php %s lights:poll-job %d > /dev/null 2>&1 &',
                                            base_path('artisan'),
                                            $processingJob->id
                                        ));

                                        Log::info('[saveRwyLights] Frame extraction launched', ['job_id' => $processingJob->id]);

                                    } catch (\Exception $e) {
                                        Log::error('[saveRwyLights] Error launching frame extraction', [
                                            'error'     => $e->getMessage(),
                                            'trace'     => $e->getTraceAsString(),
                                            'run'       => $runNumber,
                                            'direction' => $direction,
                                        ]);
                                    }
                                } else {
                                    Log::warning('[saveRwyLights] No video found - skipping frame extraction', [
                                        'run'       => $runNumber,
                                        'direction' => $direction,
                                    ]);
                                }

                            } else {
                                $existingResult = ResultsRwyLights::where('task_id', $taskId)
                                    ->where('run', $runNumber)
                                    ->where('side', $direction)
                                    ->first();

                                $existingResult->update(['is_valid' => $isSelected ? 1 : 0]);

                                if ($videoPath) {
                                    $hasVideo = LightsVideo::where('result_rwy_light_id', $existingResult->id)
                                        ->where('file_type', 'video')
                                        ->exists();

                                    if (!$hasVideo) {
                                        if ($srtPath) {
                                            LightsVideo::create([
                                                'result_rwy_light_id' => $existingResult->id,
                                                'file_type'           => 'srt',
                                                'filename'            => basename($srtPath),
                                                'size_bytes'          => Storage::disk('s3')->size($srtPath),
                                            ]);
                                        }

                                        LightsVideo::create([
                                            'result_rwy_light_id' => $existingResult->id,
                                            'file_type'           => 'video',
                                            'filename'            => basename($videoPath),
                                            'size_bytes'          => Storage::disk('s3')->size($videoPath),
                                        ]);

                                        try {
                                            $processingJob = LightsProcessingJob::create([
                                                'operation_id'        => $operationID,
                                                'task_id'             => $taskId,
                                                'runway_id'           => $rwyId,
                                                'run'                 => $runNumber,
                                                'side'                => $direction,
                                                'fly_speed'           => $runData['flight_speed'] ?? null,
                                                'objective_mpf'       => 30,
                                                'video_s3_path'       => $videoPath,
                                                'video_size_bytes'    => Storage::disk('s3')->size($videoPath),
                                                'srt_s3_path'         => $srtPath,
                                                'srt_size_bytes'      => $srtPath ? Storage::disk('s3')->size($srtPath) : null,
                                                'result_rwy_light_id' => $existingResult->id,
                                                'status'              => LightsProcessingJob::STATUS_UPLOADING_VIDEO,
                                            ]);

                                            $processingController = app(\App\Http\Controllers\LightsProcessingController::class);
                                            $extractionRequest    = new \Illuminate\Http\Request();
                                            $extractionRequest->merge(['job_id' => $processingJob->id]);
                                            $processingController->startFrameExtraction($extractionRequest);

                                            exec(sprintf(
                                                'php %s lights:poll-job %d > /dev/null 2>&1 &',
                                                base_path('artisan'),
                                                $processingJob->id
                                            ));

                                            Log::info('[saveRwyLights] Frame extraction launched (existing result)', [
                                                'job_id'    => $processingJob->id,
                                                'result_id' => $existingResult->id,
                                            ]);

                                        } catch (\Exception $e) {
                                            Log::error('[saveRwyLights] Error launching frame extraction (existing result)', [
                                                'error'     => $e->getMessage(),
                                                'trace'     => $e->getTraceAsString(),
                                                'run'       => $runNumber,
                                                'direction' => $direction,
                                            ]);
                                        }
                                    } else {
                                        Log::info('[saveRwyLights] Video already exists in DB - skipping', ['result_id' => $existingResult->id]);
                                    }
                                } else {
                                    Log::info('[saveRwyLights] No video on S3 for existing result', [
                                        'result_id' => $existingResult->id,
                                        'direction' => $direction,
                                    ]);
                                }
                            }
                        }
                    }
                    $saved = true;

                } else {
                    Log::warning('[saveRwyLights] Task SKIPPED - no taskId or empty results', [
                        'task_index' => $taskIndex,
                        'task_id'    => $taskId,
                        'has_results' => !empty($task['results']),
                    ]);
                }
            }

            return response()->json(['message' => 'Result and videos processed successfully']);

        } catch (\Exception $e) {
            Log::error('[saveRwyLights] ===== EXCEPTION =====', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  TAXIWAY LIGHTS
    // =========================================================================

    public function saveTxyLightOperation(Operation $operation, array $results): bool
    {
        return true;
    }

    // =========================================================================
    //  RUNWAY MARKINGS
    // =========================================================================

    public function saveRwyMarkingsOperation(Operation $operation, array $results): mixed
    {
        $saved = false;

        try {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;
                $rwyId        = $results['subject_id'] ?? null;

                if ($taskId && !empty($task['results'])) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $result      = $task['results'][0];
                    $operationID = $operation->id;

                    foreach ($result['markings_runs'] ?? [] as $runData) {
                        $runNumber   = $runData['number'] ?? null;
                        $isSelected  = $runData['is_selected'] ?? false;
                        $videoPath   = null;
                        $srtPath     = null;
                        $basePath    = "Markings/{$operationID}/{$taskId}/{$runNumber}";
                        $filesInRun  = Storage::disk('s3')->files($basePath);
                        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'mts', 'm4v'];

                        foreach ($filesInRun as $file) {
                            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                            if (in_array($ext, $videoExtensions) && !$videoPath) {
                                $videoPath = $file;
                            }
                            if ($ext === 'srt' && !$srtPath) {
                                $srtPath = $file;
                            }
                        }

                        Log::info('[saveRwyMarkings] Video/SRT check', [
                            'run'        => $runNumber,
                            'video_path' => $videoPath,
                            'srt_path'   => $srtPath,
                        ]);

                        $existing = ResultsRwyMarkings::where('task_id', $taskId)
                            ->where('run', $runNumber)
                            ->exists();

                        if (!$existing) {
                            $resultRwyMarking = ResultsRwyMarkings::create([
                                'task_id'      => $taskId,
                                'rwy_id'       => $rwyId,
                                'operation_id' => $operationID,
                                'run'          => $runNumber,
                                'is_valid'     => $isSelected ? 1 : 0,
                            ]);

                            $resultId  = $resultRwyMarking->id;
                            $srtRecord = null;

                            if ($srtPath) {
                                $srtRecord = MarkingsVideo::create([
                                    'result_rwy_marking_id' => $resultId,
                                    'file_type'             => 'srt',
                                    'filename'              => basename($srtPath),
                                    's3_path'               => $srtPath,
                                    'size_bytes'            => Storage::disk('s3')->size($srtPath),
                                ]);
                            }

                            if ($videoPath) {
                                MarkingsVideo::create([
                                    'result_rwy_marking_id' => $resultId,
                                    'file_type'             => 'video',
                                    'filename'              => basename($videoPath),
                                    's3_path'               => $videoPath,
                                    'size_bytes'            => Storage::disk('s3')->size($videoPath),
                                    'srt_id'                => $srtRecord?->id,
                                ]);
                            }

                            if ($videoPath) {
                                try {
                                    $srtSizeBytes   = $srtPath ? Storage::disk('s3')->size($srtPath) : null;
                                    $videoSizeBytes = Storage::disk('s3')->size($videoPath);

                                    $processingJob = MarkingsProcessingJob::create([
                                        'operation_id'          => $operationID,
                                        'task_id'               => $taskId,
                                        'runway_id'             => $rwyId,
                                        'run'                   => $runNumber,
                                        'fly_speed'             => $runData['flight_speed'] ?? null,
                                        'objective_mpf'         => 30,
                                        'video_s3_path'         => $videoPath,
                                        'video_size_bytes'      => $videoSizeBytes,
                                        'srt_s3_path'           => $srtPath,
                                        'srt_size_bytes'        => $srtSizeBytes,
                                        'result_rwy_marking_id' => $resultId,
                                        'status'                => MarkingsProcessingJob::STATUS_UPLOADING_VIDEO,
                                    ]);

                                    Log::info('[saveRwyMarkings] Processing job created', [
                                        'job_id' => $processingJob->id,
                                        'video'  => $videoPath,
                                    ]);

                                    $processingController = app(MarkingsProcessingController::class);
                                    $extractionRequest    = new \Illuminate\Http\Request();
                                    $extractionRequest->merge(['job_id' => $processingJob->id]);
                                    $processingController->startFrameExtraction($extractionRequest);

                                    exec(sprintf(
                                        'php %s markings:poll-job %d > /dev/null 2>&1 &',
                                        base_path('artisan'),
                                        $processingJob->id
                                    ));

                                    Log::info('[saveRwyMarkings] Frame extraction launched', ['job_id' => $processingJob->id]);

                                } catch (\Exception $e) {
                                    Log::error('[saveRwyMarkings] Error launching frame extraction', [
                                        'error' => $e->getMessage(),
                                        'run'   => $runNumber,
                                    ]);
                                }
                            }

                        } else {
                            $existingResult = ResultsRwyMarkings::where('task_id', $taskId)
                                ->where('run', $runNumber)
                                ->first();

                            $existingResult->update(['is_valid' => $isSelected ? 1 : 0]);

                            if ($videoPath) {
                                $hasVideo = MarkingsVideo::where('result_rwy_marking_id', $existingResult->id)
                                    ->where('file_type', 'video')
                                    ->exists();

                                if (!$hasVideo) {
                                    $srtRecord = null;
                                    if ($srtPath) {
                                        $srtRecord = MarkingsVideo::create([
                                            'result_rwy_marking_id' => $existingResult->id,
                                            'file_type'             => 'srt',
                                            'filename'              => basename($srtPath),
                                            's3_path'               => $srtPath,
                                            'size_bytes'            => Storage::disk('s3')->size($srtPath),
                                        ]);
                                    }

                                    MarkingsVideo::create([
                                        'result_rwy_marking_id' => $existingResult->id,
                                        'file_type'             => 'video',
                                        'filename'              => basename($videoPath),
                                        's3_path'               => $videoPath,
                                        'size_bytes'            => Storage::disk('s3')->size($videoPath),
                                        'srt_id'                => $srtRecord?->id,
                                    ]);

                                    try {
                                        $processingJob = MarkingsProcessingJob::create([
                                            'operation_id'          => $operationID,
                                            'task_id'               => $taskId,
                                            'runway_id'             => $rwyId,
                                            'run'                   => $runNumber,
                                            'fly_speed'             => $runData['flight_speed'] ?? null,
                                            'objective_mpf'         => 200,
                                            'video_s3_path'         => $videoPath,
                                            'video_size_bytes'      => Storage::disk('s3')->size($videoPath),
                                            'srt_s3_path'           => $srtPath,
                                            'srt_size_bytes'        => $srtPath ? Storage::disk('s3')->size($srtPath) : null,
                                            'result_rwy_marking_id' => $existingResult->id,
                                            'status'                => MarkingsProcessingJob::STATUS_UPLOADING_VIDEO,
                                        ]);

                                        $processingController = app(MarkingsProcessingController::class);
                                        $extractionRequest    = new \Illuminate\Http\Request();
                                        $extractionRequest->merge(['job_id' => $processingJob->id]);
                                        $processingController->startFrameExtraction($extractionRequest);

                                        exec(sprintf(
                                            'php %s markings:poll-job %d > /dev/null 2>&1 &',
                                            base_path('artisan'),
                                            $processingJob->id
                                        ));

                                        Log::info('[saveRwyMarkings] Frame extraction launched (existing result)', [
                                            'job_id'    => $processingJob->id,
                                            'result_id' => $existingResult->id,
                                        ]);

                                    } catch (\Exception $e) {
                                        Log::error('[saveRwyMarkings] Error launching frame extraction (existing result)', [
                                            'error' => $e->getMessage(),
                                            'run'   => $runNumber,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    $saved = true;
                }
            }

            return response()->json(['message' => 'Result and images created successfully']);

        } catch (\Exception $e) {
            Log::error('Error in saveRwyMarkingsOperation: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  AERODROME BEACON
    // =========================================================================

    public function saveAerodromeBeaconOperation(Operation $operation, array $results): mixed
    {
        $saved = false;
        try {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $airport      = $operation->getAirport();
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId && $airport) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $beacon = AerodromeBeacon::where('airport_id', $airport->id)->first();
                    if (!$beacon) {
                        return response()->json(['error' => 'Aerodrome Beacon not found for this airport'], 404);
                    }

                    $operationID = $operation->id;
                    $folderPath  = "AerodromeBeacon/{$operationID}/{$taskId}/1";
                    $imagesInS3  = Storage::disk('s3')->files($folderPath);

                    foreach ($imagesInS3 as $imagePath) {
                        $fileName      = basename($imagePath);
                        $fileExtension = pathinfo($imagePath, PATHINFO_EXTENSION);
                        $fileSize      = Storage::disk('s3')->size($imagePath);

                        OperationFiles::create([
                            'file_name'   => $fileName,
                            'description' => '',
                            'type'        => $fileExtension,
                            'size'        => $fileSize,
                            'task_id'     => $taskId,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                    }

                    ResultsBeacon::create([
                        'operation_id' => $operationID,
                        'task_id'      => $taskId,
                        'beacon_id'    => $beacon->id,
                    ]);

                    $saved = true;
                }
            }

            return response()->json(['message' => 'Aerodrome Beacon operation and files saved successfully']);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  WDI
    // =========================================================================

    public function saveWdiOperation(Operation $operation, array $results): mixed
    {
        $saved = false;

        try {
            foreach ($results['tasks'] ?? [] as $task) {
                $taskId       = $task['id'] ?? null;
                $taskStatusId = $task['status_id'] ?? null;
                $airport      = $operation->getAirport();

                if ($taskId && $airport) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }

                    $wdi = Wdi::where('airport_id', $airport->id)
                        ->where('name', $dbTask->description)
                        ->first();

                    if (!$wdi) {
                        return response()->json(['error' => 'WDI not found for this task'], 404);
                    }

                    $operationID = $operation->id;

                    foreach ($task['results'][0]['wdi_runs'] ?? [] as $runData) {
                        $runNumber  = $runData['number'] ?? null;
                        $isSelected = $runData['is_selected'] ?? false;

                        $existing = ResultsWdi::where('task_id', $taskId)
                            ->where('run', $runNumber)
                            ->first();

                        if (!$existing) {
                            $result = ResultsWdi::create([
                                'operation_id' => $operationID,
                                'task_id'      => $taskId,
                                'run'          => $runNumber,
                                'wdi_id'       => $wdi->id,
                                'is_valid'     => $isSelected ? 1 : 0,
                            ]);

                            $folderPath = "WDI/{$operationID}/{$taskId}/{$runNumber}";
                            $filesInS3  = Storage::disk('s3')->files($folderPath);

                            foreach ($filesInS3 as $filePath) {
                                WdiFile::create([
                                    's3_path'     => $filePath,
                                    'description' => '',
                                    'type'        => pathinfo($filePath, PATHINFO_EXTENSION),
                                    'size'        => Storage::disk('s3')->size($filePath),
                                    'result_id'   => $result->id,
                                ]);
                            }
                        } else {
                            $existing->update(['is_valid' => $isSelected ? 1 : 0]);
                        }
                    }

                    $saved = true;
                }
            }

            return response()->json(['message' => 'WDI operation and files saved successfully']);

        } catch (\Exception $e) {
            Log::error('[saveWdiOperation] Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  AC MAINT
    // =========================================================================

    public function saveAcMaintOperation(Operation $operation, array $results): mixed
    {
        try {
            foreach ($results['tasks'] as $task) {
                $taskId       = $task['id'];
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }
                }

                foreach ($task['results'] as $result) {
                    foreach ($result['ac_maint_runs'] as $run) {
                        $operationID  = $operation->id;
                        $runNumber    = $run['number'];
                        $s3FolderPath = "AcMaint/$operationID/$taskId/$runNumber";

                        if (!Storage::disk('s3')->exists($s3FolderPath)) {
                            return response()->json(['error' => 'Folder not found in S3'], 404);
                        }

                        $imagesInS3 = Storage::disk('s3')->files($s3FolderPath);

                        if (empty($imagesInS3)) {
                            return response()->json(['error' => 'No images found in the S3 folder'], 404);
                        }

                        $resultAc = ResultAcMaint::create([
                            'operation_id' => $operationID,
                            'task_id'      => $taskId,
                            'run'          => $runNumber,
                            'is_valid'     => $run['is_selected'],
                            'status'       => 'Unprocessed',
                            'process_uuid' => null,
                            'read_yaml'    => 0,
                        ]);

                        $resultId = $resultAc->id;

                        foreach ($imagesInS3 as $imagePath) {
                            $image = Storage::disk('s3')->get($imagePath);

                            $resizedImage = Image::make($image)->resize(null, 500, function ($constraint) {
                                $constraint->aspectRatio();
                                $constraint->upsize();
                            })->encode('jpg', 75);

                            $thumbnailPath = "$s3FolderPath/thumbnail/" . basename($imagePath);
                            Storage::disk('s3')->put($thumbnailPath, (string) $resizedImage);

                            AcMaintImage::create([
                                'ac_maint_id'    => $resultId,
                                'image_path'     => "/$imagePath",
                                'thumbnail_path' => "/$thumbnailPath",
                                'reviewed'       => 0,
                            ]);
                        }
                    }
                }
            }

            return response()->json(['message' => 'Result and images created successfully']);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  FLIGHT TURN
    // =========================================================================

    public function saveFtOperation(Operation $operation, array $results): mixed
    {
        try {
            foreach ($results['tasks'] as $task) {
                $taskId       = $task['id'];
                $taskStatusId = $task['status_id'] ?? null;

                if ($taskId) {
                    $dbTask = Task::find($taskId);
                    if ($dbTask) {
                        $statusId       = ($taskStatusId == 4 || $taskStatusId == 5) ? 3 : $taskStatusId;
                        $dbTask->status_id = $statusId;
                        $dbTask->save();
                    }
                }

                foreach ($task['results'] as $result) {
                    foreach ($result['flight_turn_runs'] as $run) {
                        $operationID  = $operation->id;
                        $runNumber    = $run['number'];
                        $s3FolderPath = "FlightTurn/$operationID/$taskId/$runNumber";

                        if (!Storage::disk('s3')->exists($s3FolderPath)) {
                            return response()->json(['error' => 'Folder not found in S3'], 404);
                        }

                        $imagesInS3 = Storage::disk('s3')->files($s3FolderPath);

                        if (empty($imagesInS3)) {
                            return response()->json(['error' => 'No images found in the S3 folder'], 404);
                        }

                        $resultFt = ResultFlightTurn::create([
                            'operation_id' => $operationID,
                            'task_id'      => $taskId,
                            'run'          => $runNumber,
                            'is_valid'     => $run['is_selected'],
                            'process_uuid' => null,
                            'read_yaml'    => 0,
                        ]);

                        $resultId = $resultFt->id;

                        foreach ($imagesInS3 as $imagePath) {
                            FlightTurnImage::create([
                                'ft_id'      => $resultId,
                                'image_path' => "/$imagePath",
                                'reviewed'   => 0,
                            ]);
                        }
                    }
                }
            }

            return response()->json(['message' => 'Result and images created successfully']);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    //  FOD / PCI - Flight Meta
    // =========================================================================

    private function createFlightMetaFOD(Operation $operation, array $data): mixed
    {
        foreach ($data['tasks'] as $task) {
            $taskId = $task['id'];

            foreach ($task['results'] as $result) {
                foreach ($result['fod_runs'] as $fod_run) {
                    $num_run    = $fod_run['number'];
                    $height     = $fod_run['height'];
                    $overlap    = $fod_run['overlap'];
                    $speed      = $fod_run['speed'];
                    $folderPath = "FOD/{$operation->id}/$taskId/$num_run";
                    $images     = Storage::disk('s3')->files($folderPath);

                    if (!empty($images)) {
                        $firstImage = $images[0];
                        try {
                            $exifService = new ExifMetadataService();
                            $metadata    = $exifService->extractFromS3($firstImage);
                        } catch (\Exception $e) {
                            return response()->json([
                                'error'   => 'Error al leer los metadatos de la imagen',
                                'message' => $e->getMessage(),
                            ], 500);
                        }

                        $drone       = Drone::where('id', $operation->drone_id)->first();
                        $aircraftName = $drone->name;
                        $location    = $metadata['location'];
                        $focalLength = $metadata['focal_length'] ?? 0;
                        $imageModel  = $metadata['camera_model'];
                        $cameraID    = Camera::where('model', 'LIKE', '%' . $imageModel . '%')->value('id');

                        if (!$cameraID) {
                            throw new \Exception("No se encontró una cámara con el modelo: {$imageModel}");
                        }

                        $resultsFodParams = ResultsFodParams::create([
                            'camera_id'      => $cameraID,
                            'focal_length'   => $focalLength,
                            'altitude'       => $height,
                            'patch_overlap'  => $overlap,
                            'capture_speed'  => $speed,
                        ]);

                        $flightMeta = [
                            "total_images_number" => count($images),
                            "date"                => isset($imageInfo['DateTimeOriginal']) ? $imageInfo['DateTimeOriginal'] : '',
                            "mission_name"        => $operation->id . '_' . $taskId . '_' . $num_run,
                            "mission_folder"      => $num_run,
                            "location"            => $location,
                            "aircraft"            => $aircraftName,
                            "camera"              => $imageModel,
                            "focal_length"        => $focalLength,
                            "altitude"            => $height,
                            "patch_overlap"       => $overlap,
                            "capture_speed"       => $speed,
                        ];

                        $jsonContent = json_encode($flightMeta, JSON_PRETTY_PRINT);
                        $filePath    = "$folderPath/flight_meta.json";
                        Storage::put($filePath, $jsonContent);
                        Storage::disk('s3')->put($filePath, $jsonContent);

                        $existRun = ResultFod::where('operation_id', $operation->id)
                            ->where('task_id', $taskId)
                            ->where('run', $num_run)
                            ->first();

                        if (!$existRun) {
                            ResultFod::create([
                                'operation_id' => $operation->id,
                                'task_id'      => $taskId,
                                'run'          => $num_run,
                                'images'       => count($images),
                                'status'       => 'Unprocessed',
                                'process_uuid' => null,
                                'params_id'    => $resultsFodParams->id,
                                'created_at'   => now(),
                                'updated_at'   => now(),
                            ]);
                        } else {
                            $existRun->update(['params_id' => $resultsFodParams->id]);
                        }
                    }
                }
            }
        }

        return response()->json(['message' => 'Result and images created successfully']);
    }

    private function createFlightMetaPCI(Operation $operation, array $data): void
    {
        foreach ($data['tasks'] as $task) {
            $taskId = $task['id'];

            foreach ($task['results'] as $result) {
                foreach ($result['pci_runs'] as $pci_run) {
                    $num_run    = $pci_run['number'];
                    $height     = $pci_run['height'];
                    $overlap    = $pci_run['overlap'];
                    $speed      = $pci_run['speed'];
                    $folderPath = "PCI/{$operation->id}/$taskId/$num_run";
                    $images     = Storage::disk('s3')->files($folderPath);

                    if (!empty($images)) {
                        $firstImage = $images[0];
                        try {
                            $exifService = new ExifMetadataService();
                            $metadata    = $exifService->extractFromS3($firstImage);
                        } catch (\Exception $e) {
                            return; // Silently return to match original behavior
                        }

                        $drone        = Drone::where('id', $operation->drone_id)->first();
                        $aircraftName = $drone->name;
                        $location     = $metadata['location'];
                        $focalLength  = $metadata['focal_length'] ?? 0;
                        $imageModel   = $metadata['camera_model'];
                        $cameraID     = Camera::where('model', 'LIKE', '%' . $imageModel . '%')->value('id');

                        if (!$cameraID) {
                            throw new \Exception("No se encontró una cámara con el modelo: {$imageModel}");
                        }

                        $resultsPciParams = ResultsPciParams::create([
                            'camera_id'     => $cameraID,
                            'focal_length'  => $focalLength,
                            'altitude'      => $height,
                            'patch_overlap' => $overlap,
                            'capture_speed' => $speed,
                        ]);

                        $flightMeta = [
                            "total_images_number" => count($images),
                            "date"                => $metadata['date_time'],
                            "mission_name"        => $operation->id . '_' . $taskId . '_' . $num_run,
                            "mission_folder"      => $num_run,
                            "location"            => $location,
                            "aircraft"            => $aircraftName,
                            "camera"              => $imageModel,
                            "focal_length"        => $focalLength,
                            "altitude"            => $height,
                            "patch_overlap"       => $overlap,
                            "capture_speed"       => $speed,
                        ];

                        $jsonContent = json_encode($flightMeta, JSON_PRETTY_PRINT);
                        $filePath    = "$folderPath/flight_meta.json";
                        Storage::put($filePath, $jsonContent);
                        Storage::disk('s3')->put($filePath, $jsonContent);

                        $existRun = ResultPci::where('operation_id', $operation->id)
                            ->where('task_id', $taskId)
                            ->where('run', $num_run)
                            ->first();

                        if (!$existRun) {
                            ResultPci::create([
                                'operation_id' => $operation->id,
                                'task_id'      => $taskId,
                                'run'          => $num_run,
                                'images'       => count($images),
                                'status'       => 'Unprocessed',
                                'process_uuid' => null,
                                'params_id'    => $resultsPciParams->id,
                                'created_at'   => now(),
                                'updated_at'   => now(),
                            ]);
                        } else {
                            $existRun->update(['params_id' => $resultsPciParams->id]);
                        }
                    }
                }
            }
        }
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function sortTaskDescriptions(array $descriptions): array
    {
        usort($descriptions, function ($a, $b) {
            preg_match('/([A-Z]+[A-Z\-]*)(\d+)/', $a, $matchesA);
            preg_match('/([A-Z]+[A-Z\-]*)(\d+)/', $b, $matchesB);

            if (empty($matchesA) || empty($matchesB)) {
                return strcmp($a, $b);
            }

            $letterComparison = strcmp($matchesA[1], $matchesB[1]);
            if ($letterComparison !== 0) {
                return $letterComparison;
            }

            return intval($matchesA[2]) - intval($matchesB[2]);
        });

        return $descriptions;
    }

    private function getGpsFixTypeId(?string $strName): int
    {
        return match ($strName) {
            'FLOAT' => 2,
            'FIX'   => 3,
            default => 1, // NO_RTK
        };
    }

    private function getPapiTransitionId(?string $strName): ?int
    {
        return match ($strName) {
            'leftL'  => 1,
            'leftR'  => 2,
            'rightL' => 3,
            'rightR' => 4,
            default  => null,
        };
    }
}
