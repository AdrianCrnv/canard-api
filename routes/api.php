<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AcMaintController;
use App\Http\Controllers\API\AerodromeBeaconController;
use App\Http\Controllers\API\AircraftController;
use App\Http\Controllers\API\AircraftPartsController;
use App\Http\Controllers\API\AirportController;
use App\Http\Controllers\API\AirportManagerController;
use App\Http\Controllers\API\AirportReferenceController;
use App\Http\Controllers\API\AlsController;
use App\Http\Controllers\API\AlsDetectionController;
use App\Http\Controllers\API\ApronController;
use App\Http\Controllers\API\CanardAIController;
use App\Http\Controllers\API\ClientController;
use App\Http\Controllers\API\CompanyController;
use App\Http\Controllers\API\CountryController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\DashboardFilterController;
use App\Http\Controllers\API\DroneController;
use App\Http\Controllers\API\DroneTypeController;
use App\Http\Controllers\API\EtodController;
use App\Http\Controllers\API\FileController;
use App\Http\Controllers\API\FlightTurnController;
use App\Http\Controllers\API\FloodlightTowerController;
use App\Http\Controllers\API\FodController;
use App\Http\Controllers\API\HeaderController;
use App\Http\Controllers\API\IlsController;
use App\Http\Controllers\API\IlsVisualizationController;
use App\Http\Controllers\API\ItemController;
use App\Http\Controllers\API\ItemProviderController;
use App\Http\Controllers\API\ItemTypeController;
use App\Http\Controllers\API\LightsController;
use App\Http\Controllers\API\LightsManualProjectionController;
use App\Http\Controllers\API\LightsProcessingController;
use App\Http\Controllers\API\LogController;
use App\Http\Controllers\API\MaintenanceController;
use App\Http\Controllers\API\MarkingsController;
use App\Http\Controllers\API\MarkingsDefectController;
use App\Http\Controllers\API\MarkingsImageController;
use App\Http\Controllers\API\MarkingsProcessingController;
use App\Http\Controllers\API\OperationFileConfirmController;
use App\Http\Controllers\API\OperationFileUploadController;

// ── Rutas públicas ──────────────────────────────────────────────────────────
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);

// ── Rutas protegidas ────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/me', [\App\Http\Controllers\Api\AuthController::class, 'me']);

    // ── FileController ──────────────────────────────────────────────────────
    Route::get('/files/check-chunk',                          [FileController::class, 'checkChunk']);
    Route::get('/files/operations/{operationId}/csv',         [FileController::class, 'downloadAllCSV']);
    Route::get('/files/maintenance/download/{fileId}',        [FileController::class, 'downloadMaintenanceFile']);
    Route::get('/files/temp-url/{id}',                        [FileController::class, 'getTempUrl']);
    Route::get('/files/operation/download',                   [FileController::class, 'downloadFile']);
    Route::get('/files/markings/download',                    [FileController::class, 'downloadMarkingsFile']);
    Route::get('/files/download/{mediaItem}',                 [FileController::class, 'download']);

    Route::post('/files/upload/{operation}',                  [FileController::class, 'upload']);
    Route::post('/files/direct-upload/{operation}',           [FileController::class, 'directUpload']);
    Route::post('/files/direct-upload-maintenance/{maintenance}', [FileController::class, 'directUploadMaintenance']);
    Route::post('/files/confirm-upload/{mediaItem}',          [FileController::class, 'confirmUpload']);
    Route::post('/files/maintenance/{maintenanceId}',         [FileController::class, 'uploadMaintenance']);
    Route::post('/files/operation/log-download',              [FileController::class, 'logDownload']);
    Route::post('/files/operation/register',                  [FileController::class, 'registerOperationFile']);

    Route::put('/files/description',                          [FileController::class, 'updateDescription']);
    Route::put('/files/maintenance/{id}',                     [FileController::class, 'updateMaintenanceFile']);
    Route::put('/files/{id}',                                 [FileController::class, 'update']);

    Route::delete('/files/temp/cleanup',                      [FileController::class, 'cleanupTempFiles']);
    Route::delete('/files/operation/delete',                  [FileController::class, 'deleteFile']);
    Route::delete('/files/{mediaItem}',                       [FileController::class, 'destroy']);

    // ── OperationFileUploadController ───────────────────────────────────────
    Route::post('/files/lights/upload',           [OperationFileUploadController::class, 'uploadLightsManualFile']);
    Route::post('/files/fod/upload',              [OperationFileUploadController::class, 'uploadFODManualFile']);
    Route::post('/files/acmaint/upload',          [OperationFileUploadController::class, 'uploadAcMaintManualFile']);
    Route::post('/files/flightturn/upload',       [OperationFileUploadController::class, 'uploadFlightTurnManualFile']);
    Route::post('/files/als/upload',              [OperationFileUploadController::class, 'uploadAlsManualFile']);
    Route::post('/files/papi/upload',             [OperationFileUploadController::class, 'uploadPAPIManualFile']);
    Route::post('/files/ils/upload',              [OperationFileUploadController::class, 'uploadIlsManualFile']);
    Route::post('/files/markings/upload',         [OperationFileUploadController::class, 'uploadMarkingsManualFile']);
    Route::post('/files/pci/upload',              [OperationFileUploadController::class, 'uploadPCIManualFile']);
    Route::post('/files/vor/upload',              [OperationFileUploadController::class, 'uploadVORManualFile']);
    Route::post('/files/surveillance/upload',     [OperationFileUploadController::class, 'uploadSurveillanceManualFile']);
    Route::post('/files/etod/upload',             [OperationFileUploadController::class, 'uploadEtodManualFile']);
    Route::post('/files/reports/upload',          [OperationFileUploadController::class, 'uploadReportsManualFile']);
    Route::post('/files/aerodrome-beacon/upload', [OperationFileUploadController::class, 'uploadAerodromeBeaconManualFile']);
    Route::post('/files/wdi/upload',              [OperationFileUploadController::class, 'uploadWdiManualFile']);

    // ── OperationFileConfirmController ──────────────────────────────────────
    Route::post('/files/operation/confirm-upload',                    [OperationFileConfirmController::class, 'confirmOperationFileUpload']);
    Route::post('/files/reports/confirm-upload',                      [OperationFileConfirmController::class, 'confirmReportUpload']);
    Route::post('/aerodrome-beacon/files/confirm-upload',             [OperationFileConfirmController::class, 'confirmAerodromeBeaconUpload']);
    Route::post('/aerodrome-beacon/files/confirm-images-upload',      [OperationFileConfirmController::class, 'confirmBeaconImagesUpload']);
    Route::post('/aerodrome-beacon/files/confirm-other-files-upload', [OperationFileConfirmController::class, 'confirmBeaconOtherFilesUpload']);
    Route::post('/wdi/files/confirm-images-upload',                   [OperationFileConfirmController::class, 'confirmWdiImagesUpload']);
    Route::post('/wdi/files/confirm-other-files-upload',              [OperationFileConfirmController::class, 'confirmWdiOtherFilesUpload']);
    Route::post('/wdi/files/confirm-upload',                          [OperationFileConfirmController::class, 'confirmWdiUpload']);
    Route::post('/acmaint/confirm-upload',                            [OperationFileConfirmController::class, 'confirmAcMaintUpload']);
    Route::post('/acmaint/process-zip',                               [OperationFileConfirmController::class, 'processAcMaintZip']);
    Route::post('/acmaint/confirm-other-files-upload',                [OperationFileConfirmController::class, 'confirmAcMaintOtherFilesUpload']);
    Route::post('/acmaint/image/delete',                              [OperationFileConfirmController::class, 'deleteAcMaintImage']);
    Route::post('/flight-turn/process-zip',                           [OperationFileConfirmController::class, 'processFlightTurnZip']);
    Route::post('/etod/confirm-images-upload',                        [OperationFileConfirmController::class, 'confirmEtodImagesUpload']);
    Route::post('/etod/confirm-other-files-upload',                   [OperationFileConfirmController::class, 'confirmEtodOtherFilesUpload']);
    Route::post('/ils/confirm-other-files-upload',                    [OperationFileConfirmController::class, 'confirmIlsOtherFilesUpload']);
    Route::post('/fod/confirm-other-files-upload',                    [OperationFileConfirmController::class, 'confirmFodOtherFilesUpload']);
    Route::post('/pci/confirm-other-files-upload',                    [OperationFileConfirmController::class, 'confirmPciOtherFilesUpload']);
    Route::post('/vor/confirm-other-files-upload',                    [OperationFileConfirmController::class, 'confirmVorOtherFilesUpload']);
    Route::post('/surveillance/confirm-other-files-upload',           [OperationFileConfirmController::class, 'confirmSurveillanceOtherFilesUpload']);

    // ── FlightTurnController ────────────────────────────────────────────────
    Route::get('/flight-turn/image',                                              [FlightTurnController::class, 'viewImage']);
    Route::get('/flight-turn/{operationId}/{taskId}/result',                      [FlightTurnController::class, 'viewResultTask']);
    Route::get('/flight-turn/{operationId}/{taskId}/{runId}',                     [FlightTurnController::class, 'viewFlightTurn']);
    Route::put('/flight-turn/review',                                             [FlightTurnController::class, 'updateReviewStatus']);
    Route::delete('/flight-turn/{folder}/{operationId}/{taskId}/{runId}',         [FlightTurnController::class, 'deleteRun']);

    // ── FloodlightTowerController ───────────────────────────────────────────
    Route::get('/airports/{airport}/floodlight-towers/operation', [FloodlightTowerController::class, 'getFloodlightsInAirportOperation']);
    Route::get('/airports/{airport}/floodlight-towers',           [FloodlightTowerController::class, 'getFloodlightsInAirport']);
    Route::post('/airports/{airport}/floodlight-towers',          [FloodlightTowerController::class, 'store']);
    Route::get('/floodlight-towers/{floodlight}/edit',            [FloodlightTowerController::class, 'edit']);
    Route::get('/floodlight-towers/{floodlight}',                 [FloodlightTowerController::class, 'show']);
    Route::put('/floodlight-towers/{floodlight}',                 [FloodlightTowerController::class, 'update']);
    Route::delete('/floodlight-towers/{floodlight}',              [FloodlightTowerController::class, 'destroy']);

    // ── FodController ───────────────────────────────────────────────────────
    Route::get('/fod/processed-db',                                              [FodController::class, 'getProcessedDataDB']);
    Route::get('/fod/image/{fodimgid}/bboxes',                                   [FodController::class, 'generateImageWithBboxes']);
    Route::get('/fod/image/{fodimgid}',                                          [FodController::class, 'viewSpecificFOD']);
    Route::get('/fod/{operationId}/{taskId}/{runId}/processed',                  [FodController::class, 'viewFODProcessed']);
    Route::get('/fod/{operationId}/{taskId}/{runId}/image/{imageName}/coordinates', [FodController::class, 'getImageCoordinates']);

    Route::post('/fod/image-url',                [FodController::class, 'getUrlImageFod']);
    Route::post('/fod/image-url-by-path',        [FodController::class, 'getUrlImageFodPath']);
    Route::post('/fod/status',                   [FodController::class, 'updateStatus']);
    Route::post('/fod/save-yaml',                [FodController::class, 'saveYamlToDatabase']);
    Route::post('/fod/detection',                [FodController::class, 'saveManualNewDetection']);
    Route::post('/fod/image/cutout',             [FodController::class, 'generateImageCutout']);

    Route::put('/fod/run/valid',                 [FodController::class, 'toggleValidRun']);
    Route::put('/fod/image/review',              [FodController::class, 'updateReviewStatus']);
    Route::put('/fod/detection/disable',         [FodController::class, 'disableDetection']);
    Route::put('/fod/detection/restore',         [FodController::class, 'restoreDetection']);

    Route::delete('/fod/detection/hard-delete',                          [FodController::class, 'hardDeleteDetection']);
    Route::delete('/fod/detection',                                      [FodController::class, 'deleteDetection']);
    Route::delete('/fod/{folder}/{operationId}/{taskId}/{runId}',        [FodController::class, 'deleteRun']);

    // ── HeaderController ────────────────────────────────────────────────────
    Route::get('/airports/{airport}/headers/with-papis/operation',  [HeaderController::class, 'getHeadersWithPapisInAirportOperation']);
    Route::get('/airports/{airport}/headers/with-papis',            [HeaderController::class, 'getHeadersWithPapisInAirport']);
    Route::get('/airports/{airport}/headers/with-ils/operation',    [HeaderController::class, 'getHeadersWithIlsInAirportOperation']);
    Route::get('/airports/{airport}/headers/with-ils',              [HeaderController::class, 'getHeadersWithIlsInAirport']);
    Route::get('/airports/{airport}/headers/with-als/operation',    [HeaderController::class, 'getHeadersWithAlsInAirportOperation']);
    Route::get('/airports/{airport}/headers/with-als',              [HeaderController::class, 'getHeadersWithAlsInAirport']);
    Route::get('/airports/{airport}/headers',                       [HeaderController::class, 'getHeadersInAirport']);
    Route::get('/runways/{runway}/headers',                         [HeaderController::class, 'getHeadersInRunway']);
    Route::post('/headers',                                         [HeaderController::class, 'store']);
    Route::get('/headers/{headerId}/with-runway',                   [HeaderController::class, 'getHeader']);
    Route::get('/headers/{header}/edit',                            [HeaderController::class, 'edit']);
    Route::get('/headers/{header}',                                 [HeaderController::class, 'show']);
    Route::put('/headers/{header}',                                 [HeaderController::class, 'update']);
    Route::delete('/headers/{header}',                              [HeaderController::class, 'destroy']);

    // ── AcMaintController ───────────────────────────────────────────────────
    Route::get('/ac-maint/images/{acmaintImgId}/bboxes',                         [AcMaintController::class, 'generateImageWithBboxes']);
    Route::get('/ac-maint/images/{acmaintImgId}',                                [AcMaintController::class, 'viewSpecificImage']);
    Route::get('/ac-maint/{operationId}/tasks/{taskId}/results',                 [AcMaintController::class, 'viewResultTask']);
    Route::get('/ac-maint/{operationId}/{taskId}/{runId}',                       [AcMaintController::class, 'viewAircraftMaintenance']);
    Route::post('/ac-maint/images/cutout',                                       [AcMaintController::class, 'generateImageCutout']);
    Route::post('/ac-maint/detections',                                          [AcMaintController::class, 'saveManualNewDetection']);
    Route::put('/ac-maint/images/{imageId}/review',                              [AcMaintController::class, 'updateReviewStatus']);
    Route::delete('/ac-maint/detections/{detectionId}',                          [AcMaintController::class, 'deleteDetection']);
    Route::delete('/ac-maint/{folder}/{operationId}/{taskId}/{runId}',           [AcMaintController::class, 'deleteRun']);

    // ── AerodromeBeaconController ───────────────────────────────────────────
    Route::get('/airports/{airport}/aerodrome-beacons/operation',  [AerodromeBeaconController::class, 'getBeaconsInAirportOperation']);
    Route::get('/airports/{airport}/aerodrome-beacons',            [AerodromeBeaconController::class, 'getBeaconsInAirport']);
    Route::post('/airports/{airport}/aerodrome-beacons',           [AerodromeBeaconController::class, 'store']);
    Route::get('/tasks/{taskId}/aerodrome-beacon/files/{fileName}',[AerodromeBeaconController::class, 'viewFile']);
    Route::get('/tasks/{taskId}/aerodrome-beacon/files',           [AerodromeBeaconController::class, 'getTaskFiles']);
    Route::get('/aerodrome-beacons/{beacon}/edit',                 [AerodromeBeaconController::class, 'edit']);
    Route::get('/aerodrome-beacons/{beacon}',                      [AerodromeBeaconController::class, 'show']);
    Route::put('/aerodrome-beacons/{beacon}',                      [AerodromeBeaconController::class, 'update']);
    Route::delete('/aerodrome-beacons/{beacon}',                   [AerodromeBeaconController::class, 'destroy']);

    // ── AircraftController ──────────────────────────────────────────────────
    Route::get('/aircrafts/search',                          [AircraftController::class, 'getNames']);
    Route::get('/aircrafts/{aircraft}/parts',                [AircraftController::class, 'parts']);
    Route::get('/aircrafts/{aircraftId}/parts/{partId}/edit',[AircraftController::class, 'editParts']);
    Route::get('/aircrafts/{aircraft}',                      [AircraftController::class, 'show']);
    Route::get('/aircrafts',                                 [AircraftController::class, 'index']);
    Route::post('/aircrafts',                                [AircraftController::class, 'store']);
    Route::put('/aircrafts/parts/update',                    [AircraftController::class, 'updatePartsAircraft']);
    Route::put('/aircrafts/{aircraft}',                      [AircraftController::class, 'update']);
    Route::delete('/aircrafts/{aircraft}',                   [AircraftController::class, 'destroy']);

    // ── AircraftPartsController ─────────────────────────────────────────────
    Route::get('/aircraft-parts/search',          [AircraftPartsController::class, 'getNames']);
    Route::get('/aircraft-parts/{aircraftParts}', [AircraftPartsController::class, 'show']);
    Route::get('/aircraft-parts',                 [AircraftPartsController::class, 'index']);
    Route::post('/aircraft-parts',                [AircraftPartsController::class, 'store']);
    Route::delete('/aircraft-parts/{aircraftParts}', [AircraftPartsController::class, 'destroy']);

    // ── AirportController ───────────────────────────────────────────────────
    Route::get('/countries/{country}/airports/operation',   [AirportController::class, 'getAirportsInCountryOperation']);
    Route::get('/countries/{country}/airports',             [AirportController::class, 'getAirportsInCountry']);
    Route::get('/airports/search',                          [AirportController::class, 'getNames']);
    Route::get('/airports/{airport}/data',                  [AirportController::class, 'returnAirport']);
    Route::get('/airports/{airport}/edit',                  [AirportController::class, 'edit']);
    Route::get('/airports/{airport}',                       [AirportController::class, 'show']);
    Route::get('/airports',                                 [AirportController::class, 'index']);
    Route::post('/airports',                                [AirportController::class, 'store']);
    Route::put('/airports/{airport}',                       [AirportController::class, 'update']);
    Route::delete('/airports/{airport}',                    [AirportController::class, 'destroy']);

    // ── AirportManagerController ────────────────────────────────────────────
    Route::get('/airport-managers/{airportManager}',  [AirportManagerController::class, 'edit']);
    Route::get('/airport-managers',                   [AirportManagerController::class, 'index']);
    Route::post('/airport-managers',                  [AirportManagerController::class, 'store']);
    Route::put('/airport-managers/{airportManager}',  [AirportManagerController::class, 'update']);
    Route::delete('/airport-managers/{airportManager}', [AirportManagerController::class, 'destroy']);

    // ── AirportReferenceController ──────────────────────────────────────────
    Route::get('/airports/{airport}/references',       [AirportReferenceController::class, 'getReferencesInAirport']);
    Route::post('/airports/{airport}/references',      [AirportReferenceController::class, 'store']);
    Route::get('/references/{reference}/edit',         [AirportReferenceController::class, 'edit']);
    Route::put('/references/{reference}',              [AirportReferenceController::class, 'update']);

    // ── AlsController ───────────────────────────────────────────────────────
    Route::get('/headers/{header}/als/json',          [AlsController::class, 'getJsonAls']);
    Route::get('/headers/{header}/als',               [AlsController::class, 'getAlsInHeader']);
    Route::get('/als/{als}/edit',                     [AlsController::class, 'edit']);
    Route::get('/als/{als}',                          [AlsController::class, 'show']);
    Route::get('/als',                                [AlsController::class, 'index']);
    Route::post('/als',                               [AlsController::class, 'store']);
    Route::post('/als/update-papi-contact-point',     [AlsController::class, 'updatePapiContactPoint']);
    Route::put('/als/{als}',                          [AlsController::class, 'update']);
    Route::delete('/als/{als}',                       [AlsController::class, 'destroy']);

    // ── AlsDetectionController ──────────────────────────────────────────────
    Route::get('/als/image-view/{operationId}/{fileId}',          [AlsDetectionController::class, 'showImageView']);
    Route::get('/als/generate-image/{fileId}',                    [AlsDetectionController::class, 'generateImage']);
    Route::get('/als/image-coordinates/{operationId}/{fileId}',   [AlsDetectionController::class, 'getImageCoordinates']);
    Route::post('/als/generate-cutout',                           [AlsDetectionController::class, 'generateCutout']);
    Route::post('/als/detections',                                [AlsDetectionController::class, 'saveDetection']);
    Route::post('/als/toggle-reviewed',                           [AlsDetectionController::class, 'toggleReviewed']);
    Route::post('/als/trigger-manual-projection',                 [AlsDetectionController::class, 'triggerManualProjection']);
    Route::post('/als/manual-projection-status/{jobId}',          [AlsDetectionController::class, 'checkManualProjectionStatus']);
    Route::post('/als/confirm-other-files-upload',                [AlsDetectionController::class, 'confirmOtherFilesUpload']);
    Route::post('/als/confirm-images-upload',                     [AlsDetectionController::class, 'confirmImagesUpload']);
    Route::delete('/als/detections',                              [AlsDetectionController::class, 'deleteDetection']);
    Route::delete('/als/delete-run/{folder}/{operationId}/{taskId}/{runId}', [AlsDetectionController::class, 'deleteRun']);

    // ── ApronController ─────────────────────────────────────────────────────
    Route::get('/airports/{airport}/aprons/operation', [ApronController::class, 'getApronsInAirportOperation']);
    Route::get('/airports/{airport}/aprons/list',      [ApronController::class, 'getApronsInAirport']);
    Route::get('/airports/{airport}/aprons',           [ApronController::class, 'show']);
    Route::post('/aprons',                             [ApronController::class, 'store']);
    Route::put('/aprons/{apron}',                      [ApronController::class, 'update']);
    Route::delete('/aprons/{apron}/markers',           [ApronController::class, 'destroyMarkers']);
    Route::delete('/aprons/{apron_id}',                [ApronController::class, 'destroy']);

    // ── CanardAIController ──────────────────────────────────────────────────
    Route::get('/canard-ai/active-job',      [CanardAIController::class, 'getActiveJob']);
    Route::get('/canard-ai/job-status',      [CanardAIController::class, 'getJobStatus']);
    Route::get('/canard-ai/queue-status',    [CanardAIController::class, 'getQueueStatus']);
    Route::get('/canard-ai/server/status',   [CanardAIController::class, 'getServerStatus']);
    Route::post('/canard-ai/process-job',    [CanardAIController::class, 'processJob']);
    Route::post('/canard-ai/enqueue-job',    [CanardAIController::class, 'enqueueJob']);
    Route::post('/canard-ai/server/start',   [CanardAIController::class, 'startServer']);
    Route::post('/canard-ai/server/stop',    [CanardAIController::class, 'stopServer']);

    // ── ClientController ────────────────────────────────────────────────────
    Route::get('/clients/{client}/edit',          [ClientController::class, 'edit']);
    Route::get('/clients/{client}',               [ClientController::class, 'show']);
    Route::get('/clients',                        [ClientController::class, 'index']);
    Route::post('/clients',                       [ClientController::class, 'store']);
    Route::put('/clients/{client}',               [ClientController::class, 'update']);
    Route::delete('/clients/{client}/users/{user}', [ClientController::class, 'removeUser']);
    Route::delete('/clients/{client}',            [ClientController::class, 'destroy']);

    // ── CompanyController ───────────────────────────────────────────────────
    Route::get('/companies/airports-by-country/{country_id}', [CompanyController::class, 'getAirportsByCountry']);
    Route::get('/companies/{company}/edit',   [CompanyController::class, 'edit']);
    Route::get('/companies',                  [CompanyController::class, 'index']);
    Route::post('/companies',                 [CompanyController::class, 'store']);
    Route::put('/companies/{company}',        [CompanyController::class, 'update']);
    Route::delete('/companies/{company}',     [CompanyController::class, 'destroy']);

    // ── CountryController ───────────────────────────────────────────────────
    Route::get('/countries/by-subject-type/{subject_type_id}', [CountryController::class, 'getCountriesWithSubjectsOfType']);
    Route::get('/countries/with-airport',    [CountryController::class, 'withAirport']);
    Route::get('/countries',                 [CountryController::class, 'allCountries']);

    // ── DashboardController ─────────────────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // ── DashboardFilterController ───────────────────────────────────────────
    Route::get('/dashboard/operator/{operator_id}/company/{company_id}', [DashboardFilterController::class, 'filterByBoth']);
    Route::get('/dashboard/operator/{operator_id}',                      [DashboardFilterController::class, 'filterByOperator']);
    Route::get('/dashboard/company/{company_id}',                        [DashboardFilterController::class, 'filterByCompany']);

    // ── DroneController ─────────────────────────────────────────────────────
    Route::get('/operators/{operator}/drones/by-operation-type/{operationTypeId}', [DroneController::class, 'getDronesByOperationType']);
    Route::get('/operators/{operator}/drones',                                     [DroneController::class, 'getDronesFromOperator']);
    Route::get('/drones/from-operator/{operatorId}/by-operation-type/{operationTypeId}', [DroneController::class, 'fromOperatorByOperationType']);
    Route::get('/drones/{drone}/edit',  [DroneController::class, 'edit']);
    Route::get('/drones/{drone}',       [DroneController::class, 'show']);
    Route::get('/drones',               [DroneController::class, 'index']);
    Route::post('/drones',              [DroneController::class, 'store']);
    Route::post('/drones/position/{environment}/{operationId}',  [DroneController::class, 'updatePosition']);
    Route::post('/drones/telemetry/{environment}/{operationId}', [DroneController::class, 'updateTelemetry']);
    Route::put('/drones/{drone}',       [DroneController::class, 'update']);
    Route::delete('/drones/{drone}',    [DroneController::class, 'destroy']);

    // ── DroneTypeController ─────────────────────────────────────────────────
    Route::get('/drones/types/{type}/edit', [DroneTypeController::class, 'edit']);
    Route::get('/drones/types',             [DroneTypeController::class, 'index']);
    Route::post('/drones/types',            [DroneTypeController::class, 'store']);
    Route::put('/drones/types/{type}',      [DroneTypeController::class, 'update']);
    Route::delete('/drones/types/{type}',   [DroneTypeController::class, 'destroy']);

    // ── EtodController ──────────────────────────────────────────────────────
    Route::get('/airports/{airport}/etods/operation', [EtodController::class, 'getEtodsInAirportOperation']);
    Route::get('/airports/{airport}/etods/list',      [EtodController::class, 'getEtodsInAirport']);
    Route::get('/airports/{airport}/etods',           [EtodController::class, 'show']);
    Route::post('/etods',                             [EtodController::class, 'store']);
    Route::put('/etods/{etod}',                       [EtodController::class, 'update']);
    Route::delete('/etods/{etod}/markers',            [EtodController::class, 'destroyMarkers']);
    Route::delete('/etods/{etod_id}',                 [EtodController::class, 'destroy']);

    // ── IlsController ───────────────────────────────────────────────────────
    Route::get('/headers/{header}/ils',   [IlsController::class, 'getIlsInHeader']);
    Route::get('/ils/{ils}',              [IlsController::class, 'show']);
    Route::get('/ils',                    [IlsController::class, 'index']);
    Route::post('/ils',                   [IlsController::class, 'store']);
    Route::put('/ils/{ils}',              [IlsController::class, 'update']);
    Route::delete('/ils/{ils}',           [IlsController::class, 'destroy']);

    // ── IlsVisualizationController ──────────────────────────────────────────
    Route::get('/ils/visualization/{idfile}', [IlsVisualizationController::class, 'getVisualizationData']);
    Route::get('/ils/data/{idfile}',          [IlsVisualizationController::class, 'getData']);

    // ── ItemController ──────────────────────────────────────────────────────
    Route::get('/item-types/{type}/firmware-versions', [ItemController::class, 'getFirmwareVersions']);
    Route::get('/inventory/{item}',     [ItemController::class, 'show']);
    Route::get('/inventory',            [ItemController::class, 'index']);
    Route::post('/inventory',           [ItemController::class, 'store']);
    Route::put('/inventory/{item}',     [ItemController::class, 'update']);
    Route::delete('/inventory/{item}',  [ItemController::class, 'destroy']);

    // ── ItemProviderController ──────────────────────────────────────────────
    Route::get('/item-providers',  [ItemProviderController::class, 'index']);
    Route::post('/item-providers', [ItemProviderController::class, 'store']);

    // ── ItemTypeController ──────────────────────────────────────────────────
    Route::get('/item-types',  [ItemTypeController::class, 'index']);
    Route::post('/item-types', [ItemTypeController::class, 'store']);

    // ── LightsController ────────────────────────────────────────────────────
    Route::get('/lights/inspections/{id}',                  [LightsController::class, 'getInspection']);
    Route::post('/lights/confirm-upload',                   [LightsController::class, 'confirmUpload']);
    Route::post('/lights/register-operation-file',          [LightsController::class, 'registerOperationFile']);
    Route::post('/lights/confirm-rwy-image-upload',         [LightsController::class, 'confirmRwyImageUpload']);
    Route::post('/lights/confirm-txy-upload',               [LightsController::class, 'confirmTxyUpload']);
    Route::post('/lights/complete-extraction',              [LightsController::class, 'completeExtraction']);
    Route::post('/lights/toggle-valid',                     [LightsController::class, 'toggleValid']);
    Route::put('/lights/inspections/{id}/processing-status',[LightsController::class, 'updateProcessingStatus']);
    Route::delete('/lights/runs',                           [LightsController::class, 'deleteRun']);

    // ── LightsManualProjectionController ────────────────────────────────────
    Route::get('/lights/manual-projection/status/{jobId}',                             [LightsManualProjectionController::class, 'checkManualProjectionStatus']);
    Route::post('/lights/manual-projection/{operationId}/{taskId}/{run}/{side}',       [LightsManualProjectionController::class, 'triggerManualProjection']);

    // ── LightsProcessingController ───────────────────────────────────────────
    Route::get('/lights/processing/{jobId}/progress',        [LightsProcessingController::class, 'checkProgress']);
    Route::post('/lights/processing/start-frame-extraction', [LightsProcessingController::class, 'startFrameExtraction']);

    // ── LogController ───────────────────────────────────────────────────────
    Route::get('/logs/suggestions', [LogController::class, 'suggestions']);
    Route::get('/logs',             [LogController::class, 'index']);

    // ── MaintenanceController ───────────────────────────────────────────────
    Route::get('/maintenances/form-data',               [MaintenanceController::class, 'create']);
    Route::get('/maintenances/items-by-type/{itemTypeId}', [MaintenanceController::class, 'getItemsToItemTypes']);
    Route::get('/maintenances/{maintenance}/edit-data', [MaintenanceController::class, 'edit']);
    Route::get('/maintenances',                         [MaintenanceController::class, 'index']);
    Route::post('/maintenances',                        [MaintenanceController::class, 'store']);
    Route::put('/maintenances/{maintenance}/complete-and-reschedule', [MaintenanceController::class, 'updateAndStore']);
    Route::put('/maintenances/{maintenance}',           [MaintenanceController::class, 'update']);
    Route::delete('/maintenances/{maintenance}',        [MaintenanceController::class, 'destroy']);

    // ── MarkingsController ──────────────────────────────────────────────────
    Route::post('/markings/diagram',           [MarkingsController::class, 'uploadDiagram']);
    Route::post('/markings/stretches',         [MarkingsController::class, 'saveMarkings']);
    Route::post('/markings/runs/toggle-valid', [MarkingsController::class, 'toggleValidRun']);
    Route::delete('/markings/diagram',         [MarkingsController::class, 'deleteDiagram']);

    // ── MarkingsDefectController ─────────────────────────────────────────────
    Route::get('/markings/defects/{operationId}/{taskId}/{runId}', [MarkingsDefectController::class, 'getDefects']);
    Route::post('/markings/defects',                               [MarkingsDefectController::class, 'storeDefect']);
    Route::patch('/markings/defects/{defectId}/toggle',            [MarkingsDefectController::class, 'toggleDefectById']);
    Route::patch('/markings/defects/toggle',                       [MarkingsDefectController::class, 'toggleDefect']);

    // ── MarkingsImageController ──────────────────────────────────────────────
    Route::get('/markings/images/coordinates/{operationId}/{taskId}/{runId}/{imageName}', [MarkingsImageController::class, 'getImageCoordinates']);
    Route::get('/markings/images/download/{taskId}/{run}',         [MarkingsImageController::class, 'downloadAllImages']);
    Route::get('/markings/images/{imageId}/url',                   [MarkingsImageController::class, 'getImageUrl']);
    Route::get('/markings/runs/images',                            [MarkingsImageController::class, 'getRunImages']);
    Route::get('/markings/images/{operationId}/{taskId}/{runId}/{imageId}', [MarkingsImageController::class, 'showMarkingsImageData']);
    Route::post('/markings/images/comment',                        [MarkingsImageController::class, 'saveImageComment']);
    Route::patch('/markings/images/review-status',                 [MarkingsImageController::class, 'updateReviewStatus']);
    Route::delete('/markings/srt',                                 [MarkingsImageController::class, 'deleteSrt']);

    // ── MarkingsProcessingController ─────────────────────────────────────────
    Route::get('/markings/processing/data',         [MarkingsProcessingController::class, 'getProcessedDataDB']);
    Route::post('/markings/processing/status',      [MarkingsProcessingController::class, 'updateStatus']);
    Route::post('/markings/processing/save-yaml',   [MarkingsProcessingController::class, 'saveYamlToDatabase']);

    // ── OperatorController ────────────────────────────────────────────────────
    Route::get('/operators/autocomplete',                  [\App\Http\Controllers\Api\OperatorController::class, 'autocomplete']);
    Route::get('/operators/form-data',                     [\App\Http\Controllers\Api\OperatorController::class, 'create']);
    Route::get('/operators/{operator}/edit-data',          [\App\Http\Controllers\Api\OperatorController::class, 'edit']);
    Route::get('/operators/{operator}/pilots',             [\App\Http\Controllers\Api\OperatorController::class, 'getPilots']);
    Route::get('/operators/{operator}/technicians',        [\App\Http\Controllers\Api\OperatorController::class, 'getTechnicians']);
    Route::apiResource('/operators', \App\Http\Controllers\Api\OperatorController::class);

    // ── PapiController ────────────────────────────────────────────────────────
    Route::get('/airports/{airport}/papis/form-data',      [\App\Http\Controllers\Api\PapiController::class, 'create']);
    Route::get('/headers/{header}/papis',                  [\App\Http\Controllers\Api\PapiController::class, 'getPapisInHeader']);
    Route::get('/papis/{id}/update-params',                [\App\Http\Controllers\Api\PapiController::class, 'updateNewParamsPAPI']);
    Route::get('/papis/{papi}/edit-data',                  [\App\Http\Controllers\Api\PapiController::class, 'edit']);
    Route::apiResource('/papis', \App\Http\Controllers\Api\PapiController::class);

    // ── PapiVisualInspectionController ────────────────────────────────────────
    Route::get('/papi/inspection/{operation_id}',          [\App\Http\Controllers\Api\PapiVisualInspectionController::class, 'getInspection']);
    Route::post('/papi/inspection/save',                   [\App\Http\Controllers\Api\PapiVisualInspectionController::class, 'saveInspection']);
    Route::post('/papi/observations/save',                 [\App\Http\Controllers\Api\PapiVisualInspectionController::class, 'saveObservations']);

    // ── ParameterController ───────────────────────────────────────────────────
    Route::get('/parameters/form-data',                    [\App\Http\Controllers\Api\ParameterController::class, 'manage']);
    Route::get('/parameters/{subject_type}/{subject}',     [\App\Http\Controllers\Api\ParameterController::class, 'getParametersBySubject']);
    Route::apiResource('/parameters', \App\Http\Controllers\Api\ParameterController::class)->except(['index', 'show', 'create', 'edit']);

    // ── PciController ─────────────────────────────────────────────────────────
    Route::get('/pci/processed-db',                        [\App\Http\Controllers\Api\PciController::class, 'getProcessedDataDB']);
    Route::post('/pci/status',                             [\App\Http\Controllers\Api\PciController::class, 'updateStatus']);
    Route::post('/pci/save-yaml',                          [\App\Http\Controllers\Api\PciController::class, 'saveYamlToDatabase']);
    Route::put('/pci/run/valid',                           [\App\Http\Controllers\Api\PciController::class, 'toggleValidRun']);
    Route::delete('/pci/{folder}/{operationId}/{taskId}/{runId}', [\App\Http\Controllers\Api\PciController::class, 'deleteRun']);

    // ── PciDetectionController ────────────────────────────────────────────────
    Route::get('/pci/image/{pciimgid}',                                              [\App\Http\Controllers\Api\PciDetectionController::class, 'viewSpecificPCI']);
    Route::get('/pci/image/{imageId}/polygons',                                      [\App\Http\Controllers\Api\PciDetectionController::class, 'generateImageWithPolygons']);
    Route::get('/pci/{operationId}/{taskId}/{runId}/image/{imageName}/coordinates',  [\App\Http\Controllers\Api\PciDetectionController::class, 'getImageCoordinates']);
    Route::post('/pci/image/cutout',                                                 [\App\Http\Controllers\Api\PciDetectionController::class, 'generateImageCutout']);
    Route::post('/pci/detections',                                                   [\App\Http\Controllers\Api\PciDetectionController::class, 'saveManualNewDetection']);
    Route::put('/pci/image/review',                                                  [\App\Http\Controllers\Api\PciDetectionController::class, 'updateReviewStatus']);
    Route::put('/pci/detection/disable',                                             [\App\Http\Controllers\Api\PciDetectionController::class, 'disableDetection']);
    Route::put('/pci/detection/restore',                                             [\App\Http\Controllers\Api\PciDetectionController::class, 'restoreDetection']);
    Route::delete('/pci/detection/hard-delete',                                      [\App\Http\Controllers\Api\PciDetectionController::class, 'hardDeleteDetection']);
    Route::delete('/pci/detection',                                                  [\App\Http\Controllers\Api\PciDetectionController::class, 'deleteDetection']);

    // ── ProcessVideoController ────────────────────────────────────────────────
    Route::get('/process-video',       [\App\Http\Controllers\Api\ProcessVideoController::class, 'index']);
    Route::get('/process-video/srt',   [\App\Http\Controllers\Api\ProcessVideoController::class, 'getSrtName']);

    // ── ReleaseController ─────────────────────────────────────────────────────
    Route::get('/releases',                                          [\App\Http\Controllers\Api\ReleaseController::class, 'index']);
    Route::post('/releases/calibration-tool',                        [\App\Http\Controllers\Api\ReleaseController::class, 'storeCalibrationTool']);
    Route::get('/releases/calibration-tool/{version}/download',      [\App\Http\Controllers\Api\ReleaseController::class, 'downloadCt']);
    Route::delete('/releases/calibration-tool/{version_id}',         [\App\Http\Controllers\Api\ReleaseController::class, 'deleteCt']);
    Route::post('/releases/pna-app',                                 [\App\Http\Controllers\Api\ReleaseController::class, 'storePnaAndroidApp']);
    Route::get('/releases/pna-app/{version}/download',               [\App\Http\Controllers\Api\ReleaseController::class, 'downloadPnaApp']);
    Route::delete('/releases/pna-app/{version_id}',                  [\App\Http\Controllers\Api\ReleaseController::class, 'deletePnaApp']);
    Route::post('/releases/pna-receiver',                            [\App\Http\Controllers\Api\ReleaseController::class, 'storePnaReceiverApp']);
    Route::get('/releases/pna-receiver/{version}/download',          [\App\Http\Controllers\Api\ReleaseController::class, 'downloadPnaRf']);
    Route::delete('/releases/pna-receiver/{version_id}',             [\App\Http\Controllers\Api\ReleaseController::class, 'deletePnaRf']);

    // ── ReportController ──────────────────────────────────────────────────────
    Route::post('/reports/{operation}/generate/{language}/{angle_unit}', [\App\Http\Controllers\Api\ReportController::class, 'generate']);
    Route::post('/reports/{operation}/generate/{language}',              [\App\Http\Controllers\Api\ReportController::class, 'generate']);
    Route::post('/reports/{operation}/generate',                         [\App\Http\Controllers\Api\ReportController::class, 'generate']);
    Route::get('/reports/{operationId}/status',                          [\App\Http\Controllers\Api\ReportController::class, 'status']);
    Route::get('/reports/{id}/view',                                     [\App\Http\Controllers\Api\ReportController::class, 'viewReport']);
    Route::delete('/reports/{id}',                                       [\App\Http\Controllers\Api\ReportController::class, 'destroy']);

    // ── RoleController ────────────────────────────────────────────────────────
    Route::get('/roles', [\App\Http\Controllers\Api\RoleController::class, 'index']);

    // ── RunwayController ──────────────────────────────────────────────────────
    Route::get('/airports/{airport}/runways/operation',    [\App\Http\Controllers\Api\RunwayController::class, 'getRunwaysInAirportOperation']);
    Route::get('/airports/{airport}/runways/form-data',    [\App\Http\Controllers\Api\RunwayController::class, 'create']);
    Route::get('/airports/{airport}/runways',              [\App\Http\Controllers\Api\RunwayController::class, 'getRunwaysInAirport']);
    Route::get('/runways/{runway}/edit-data',              [\App\Http\Controllers\Api\RunwayController::class, 'edit']);
    Route::apiResource('/runways', \App\Http\Controllers\Api\RunwayController::class)->except(['create', 'edit', 'index']);

    // ── RunwayRunController ───────────────────────────────────────────────────
    Route::get('/operations/{operation}/tasks/{taskId}/runs',                        [\App\Http\Controllers\Api\RunwayRunController::class, 'getTaskRuns']);
    Route::get('/operations/light-image',                                            [\App\Http\Controllers\Api\RunwayRunController::class, 'viewLightImage']);
    Route::get('/operations/{operationId}/tasks/{taskId}/runs/{runId}/images/lights',   [\App\Http\Controllers\Api\RunwayRunController::class, 'getRunImagesLights']);
    Route::get('/operations/{operationId}/tasks/{taskId}/runs/{runId}/images/markings', [\App\Http\Controllers\Api\RunwayRunController::class, 'getRunImagesMarkings']);
    Route::get('/operations/runs/validation-status',                                 [\App\Http\Controllers\Api\RunwayRunController::class, 'getRunValidationStatus']);
    Route::post('/operations/runs/toggle-validation',                                [\App\Http\Controllers\Api\RunwayRunController::class, 'toggleRunValidation']);
    Route::post('/runways/{runway}/lights-diagram',                                  [\App\Http\Controllers\Api\RunwayRunController::class, 'uploadLightsDiagram']);
    Route::delete('/operations/runs',                                                [\App\Http\Controllers\Api\RunwayRunController::class, 'deleteRun']);

    // ── StandController ───────────────────────────────────────────────────────
    Route::get('/airports/{airport}/stands/operation',     [\App\Http\Controllers\Api\StandController::class, 'getStandsInAirportOperation']);
    Route::get('/airports/{airport}/stands/form-data',     [\App\Http\Controllers\Api\StandController::class, 'create']);
    Route::get('/airports/{airportId}/stands',             [\App\Http\Controllers\Api\StandController::class, 'getStandsInAirport']);
    Route::get('/stands/{stand}/edit-data',                [\App\Http\Controllers\Api\StandController::class, 'edit']);
    Route::get('/stands/{stand}/aircrafts',                [\App\Http\Controllers\Api\StandController::class, 'getAircrafts']);
    Route::apiResource('/stands', \App\Http\Controllers\Api\StandController::class)->except(['index', 'create', 'edit']);

    // ── StreamingController ───────────────────────────────────────────────────
    Route::get('/streaming/server/status',  [\App\Http\Controllers\Api\StreamingController::class, 'getServerStatus']);
    Route::post('/streaming/server/start',  [\App\Http\Controllers\Api\StreamingController::class, 'startServer']);
    Route::post('/streaming/server/stop',   [\App\Http\Controllers\Api\StreamingController::class, 'stopServer']);

    // ── StretchController ─────────────────────────────────────────────────────
    Route::get('/runways/{runway}/stretches/{stretch_type}/edit-data', [\App\Http\Controllers\Api\StretchController::class, 'edit']);
    Route::get('/runways/{runway}/stretches/{stretch_type}',           [\App\Http\Controllers\Api\StretchController::class, 'show']);
    Route::post('/stretches',                                          [\App\Http\Controllers\Api\StretchController::class, 'store']);

    // ── SurveillanceController ────────────────────────────────────────────────
    Route::get('/airports/{airport}/surveillances/operation',  [\App\Http\Controllers\Api\SurveillanceController::class, 'getSurveillancesInAirportOperation']);
    Route::get('/airports/{airport}/surveillances/form-data',  [\App\Http\Controllers\Api\SurveillanceController::class, 'create']);
    Route::get('/airports/{airport}/surveillances',            [\App\Http\Controllers\Api\SurveillanceController::class, 'getSurveillancesInAirport']);
    Route::get('/surveillances/{surveillance}/edit-data',      [\App\Http\Controllers\Api\SurveillanceController::class, 'edit']);
    Route::apiResource('/surveillances', \App\Http\Controllers\Api\SurveillanceController::class)->except(['index', 'create', 'edit']);

    // ── TaxiwayController ─────────────────────────────────────────────────────
    Route::get('/airports/{airport}/taxiways/operation',  [\App\Http\Controllers\Api\TaxiwayController::class, 'getTaxiwaysInAirportOperation']);
    Route::get('/airports/{airport}/taxiways/form-data',  [\App\Http\Controllers\Api\TaxiwayController::class, 'create']);
    Route::get('/airports/{airport}/taxiways',            [\App\Http\Controllers\Api\TaxiwayController::class, 'getTaxiwaysInAirport']);
    Route::get('/taxiways/{taxiway}/edit-data',           [\App\Http\Controllers\Api\TaxiwayController::class, 'edit']);
    Route::apiResource('/taxiways', \App\Http\Controllers\Api\TaxiwayController::class)->except(['index', 'create', 'edit']);

    // ── UserController ────────────────────────────────────────────────────────
    Route::get('/users/form-data',        [\App\Http\Controllers\Api\UserController::class, 'create']);
    Route::get('/users/names',            [\App\Http\Controllers\Api\UserController::class, 'getNames']);
    Route::get('/users/{user}/edit-data', [\App\Http\Controllers\Api\UserController::class, 'edit']);
    Route::apiResource('/users', \App\Http\Controllers\Api\UserController::class)->except(['create', 'edit']);

    // ── VorController ─────────────────────────────────────────────────────────
    Route::get('/vors/form-data',                              [\App\Http\Controllers\Api\VorController::class, 'create']);
    Route::get('/vors/code-search',                            [\App\Http\Controllers\Api\VorController::class, 'getCode']);
    Route::get('/vors/{vor}/edit-data',                        [\App\Http\Controllers\Api\VorController::class, 'edit']);
    Route::get('/vors/{vor}/json',                             [\App\Http\Controllers\Api\VorController::class, 'getJsonVor']);
    Route::put('/vors/{vor}/magnetic-declination',             [\App\Http\Controllers\Api\VorController::class, 'updateMagneticDeclination']);
    Route::get('/countries/{country}/vors/operation',          [\App\Http\Controllers\Api\VorController::class, 'getVorsInCountryOperation']);
    Route::get('/countries/{country}/vors',                    [\App\Http\Controllers\Api\VorController::class, 'getVorsInCountry']);
    Route::get('/countries/{country}/airports',                [\App\Http\Controllers\Api\VorController::class, 'getJsonAirports']);
    Route::apiResource('/vors', \App\Http\Controllers\Api\VorController::class)->except(['create', 'edit']);

    // ── VorReferenceController ────────────────────────────────────────────────
    Route::get('/vors/{vor}/references/form-data',        [\App\Http\Controllers\Api\VorReferenceController::class, 'create']);
    Route::get('/vors/{vor}/references',                  [\App\Http\Controllers\Api\VorReferenceController::class, 'getReferencesInVor']);
    Route::post('/vors/{vor}/references',                 [\App\Http\Controllers\Api\VorReferenceController::class, 'store']);
    Route::get('/vor-references/{reference}/edit-data',   [\App\Http\Controllers\Api\VorReferenceController::class, 'edit']);
    Route::put('/vor-references/{reference}',             [\App\Http\Controllers\Api\VorReferenceController::class, 'update']);

    // ── VorVisualizationController ────────────────────────────────────────────
    Route::get('/operation-files/{idfile}/vor-data',           [\App\Http\Controllers\Api\VorVisualizationController::class, 'getData']);
    Route::get('/operation-files/{idfile}/vor-visualization',  [\App\Http\Controllers\Api\VorVisualizationController::class, 'getVisualizationData']);
    Route::get('/tasks/{taskId}/vor-axis-config',              [\App\Http\Controllers\Api\VorVisualizationController::class, 'getTaskAxisConfiguration']);

    // ── WdiController ─────────────────────────────────────────────────────────
    Route::get('/airports/{airport}/wdis/form-data',    [\App\Http\Controllers\Api\WdiController::class, 'create']);
    Route::get('/airports/{airport}/wdis',              [\App\Http\Controllers\Api\WdiController::class, 'inAirport']);
    Route::get('/wdis/{wdi}/edit-data',                 [\App\Http\Controllers\Api\WdiController::class, 'edit']);
    Route::post('/wdis/runs/toggle-validation',         [\App\Http\Controllers\Api\WdiController::class, 'toggleValidRunWdi']);
    Route::delete('/wdi-files/{fileId}',                [\App\Http\Controllers\Api\WdiController::class, 'deleteFile']);
    Route::apiResource('/wdis', \App\Http\Controllers\Api\WdiController::class)->except(['index', 'create', 'edit']);
});