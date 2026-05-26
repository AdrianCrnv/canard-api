<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Als;
use App\Models\AerodromeBeacon;
use App\Models\Drone;
use App\Models\LightsImage;
use App\Models\MeasurementAls;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\OperationReports;
use App\Models\ResultAcMaint;
use App\Models\AcMaintImage;
use App\Models\ResultFlightTurn;
use App\Models\FlightTurnImage;
use App\Models\ResultFod;
use App\Models\ResultsFodParams;
use App\Models\ResultPci;
use App\Models\ResultsPciParams;
use App\Models\ResultsAls;
use App\Models\ResultsBeacon;
use App\Models\ResultsRwyLights;
use App\Models\ResultsRwyMarkings;
use App\Models\ResultsTxyLights;
use App\Models\ResultsWdi;
use App\Models\MarkingsImage;
use App\Models\Runway;
use App\Models\Task;
use App\Models\Taxiway;
use App\Models\Wdi;
use App\Models\WdiFile;
use App\Services\ExifMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Carbon\Carbon;
use ZipArchive;
use OpenApi\Attributes as OA;

class OperationFileUploadController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // =========================================================================
    // LIGHTS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/lights/upload',
        summary: 'Upload images or other files to a Lights operation (RWY or TXY)',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadLightsManualFile(Request $request): JsonResponse
    {
        try {
            $operationID = $request->input('operationID');
            $taskID      = $request->input('taskID');
            $operation   = Operation::find($operationID);

            $subject   = $operation->subject()->name;
            $runwayID  = Runway::where('name', $subject)->value('id');

            $parts   = explode('/', $subject);
            $header1 = $parts[0];
            $header2 = isset($parts[1]) ? str_replace(' ', '', $parts[1]) : '';
            $letter  = '';
            if (preg_match('/[A-Za-z]/', $header2, $matches)) {
                $letter  = $matches[0];
                $header2 = str_replace($letter, '', $header2);
            }
            $header1 .= $letter;
            $header2 .= $letter;

            if ($operation->type_id === 10) {
                $lastRun = ResultsRwyLights::where('task_id', $taskID)->max('run');
            } else {
                $lastRun = ResultsTxyLights::where('task_id', $taskID)->max('run');
            }
            $run = $lastRun ? $lastRun + 1 : 1;

            // ── Others ───────────────────────────────────────────────────────
            if ($request->input('upload_type') === 'others') {
                if (!$request->hasFile('files')) {
                    return response()->json(['error' => 'No files uploaded'], 400);
                }

                $description = $request->input('description', '');
                $folderPath  = "Lights/{$operationID}/{$taskID}";
                $files       = $request->file('files');

                foreach ($files as $file) {
                    $s3FilePath = "{$folderPath}/" . $file->getClientOriginalName();
                    Storage::disk('s3')->put($s3FilePath, file_get_contents($file->getRealPath()));

                    OperationFiles::create([
                        'file_name'   => $file->getClientOriginalName(),
                        'description' => $description,
                        'type'        => $file->getClientOriginalExtension(),
                        'size'        => $file->getSize(),
                        'task_id'     => $taskID,
                    ]);
                }

                ActivityLog::log('upload', 'Operation', (int) $operationID,
                    "Uploaded " . count($files) . " file(s) to Lights operation #{$operationID}, task #{$taskID}");

                return response()->json(['success' => true, 'message' => 'Files uploaded successfully', 'total_files' => count($files)]);
            }

            // ── RWY (type_id === 10) ──────────────────────────────────────────
            if ($operation->type_id === 10) {
                $side           = $request->input('direction');
                $flightAltitude = $request->input('flight_altitude');

                if (!$request->hasFile('images') || !$side) {
                    return response()->json(['error' => 'Images and direction are required'], 400);
                }

                $result = ResultsRwyLights::create([
                    'task_id'            => $taskID,
                    'rwy_id'             => $runwayID,
                    'operation_id'       => $operationID,
                    'run'                => $run,
                    'side'               => $side,
                    'content_type'       => 'images',
                    'fly_speed'          => null,
                    'objective_mpf'      => null,
                    'processing_status'  => 'Processed',
                    'is_valid'           => 0,
                    'is_video'           => 0,
                ]);

                $folderPath = "Lights/{$operationID}/{$taskID}/{$run}/{$side}";
                $files      = $request->file('images');

                foreach ($files as $file) {
                    $s3FilePath  = "{$folderPath}/" . $file->getClientOriginalName();
                    Storage::disk('s3')->put($s3FilePath, file_get_contents($file->getRealPath()));

                    $lightsImage = LightsImage::create([
                        'type_id'              => $operation->type_id,
                        'results_rwy_lights_id' => $result->id,
                        'txy_id'               => null,
                        'direction'            => $side,
                        'image_path'           => $s3FilePath,
                        'thumbnail_path'       => null,
                        'reviewed'             => 0,
                        'type_upload'          => 'images',
                        'flight_altitude'      => $flightAltitude,
                    ]);

                    $pathInfo      = pathinfo($s3FilePath);
                    $thumbnailPath = $pathInfo['dirname'] . '/thumbnail/' . $pathInfo['filename'] . '.jpg';
                    (new \App\Services\ThumbnailService())->generateThumbnail($s3FilePath, $thumbnailPath);
                    $lightsImage->thumbnail_path = $thumbnailPath;
                    $lightsImage->save();
                }

                ActivityLog::log('upload', 'Operation', (int) $operationID,
                    "Uploaded " . count($files) . " image(s) to RWY Lights operation #{$operationID}, task #{$taskID}, run #{$run} ({$side})");

                return response()->json([
                    'success'      => true,
                    'message'      => 'Images uploaded successfully',
                    'result_id'    => $result->id,
                    'total_images' => count($files),
                ]);
            }

            // ── TXY (type_id === 11) ──────────────────────────────────────────
            if ($operation->type_id === 11) {
                if (!$request->hasFile('files_side_a')) {
                    return response()->json(['error' => 'No files uploaded'], 400);
                }

                $tempFolderPath = storage_path('app/platform/temp/' . $taskID);
                if (!file_exists($tempFolderPath)) {
                    mkdir($tempFolderPath, 0777, true);
                }

                $task      = Task::where('id', $taskID)->first();
                $taxi      = Taxiway::where('name', $task->description)->first();
                $taxiwayID = $taxi->id;

                $result   = ResultsTxyLights::create([
                    'task_id'      => $taskID,
                    'txy_id'       => $taxiwayID,
                    'operation_id' => $operationID,
                    'run'          => $run,
                    'is_valid'     => 0,
                ]);
                $resultID = $result->id;

                $filesA     = $request->file('files_side_a');
                $folderPath = "Lights/{$operationID}/{$taskID}/{$run}";

                foreach ($filesA as $index => $file) {
                    $tempPath   = $tempFolderPath . '/' . $file->getClientOriginalName();
                    $file->move($tempFolderPath, $file->getClientOriginalName());

                    $s3FilePath = "$folderPath/" . $file->getClientOriginalName();
                    Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));

                    OperationFiles::create([
                        'file_name'   => $request->input("file_name_side_a_$index"),
                        'description' => '',
                        'type'        => explode('/', $request->input("file_type_side_a_$index"))[1],
                        'size'        => $request->input("file_size_side_a_$index"),
                        'task_id'     => $taskID,
                    ]);

                    LightsImage::create([
                        'type_id'              => $operation->type_id,
                        'results_rwy_lights_id' => null,
                        'txy_id'               => $resultID,
                        'direction'            => null,
                        'image_path'           => "/$s3FilePath",
                        'reviewed'             => 0,
                    ]);
                }

                Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

                ActivityLog::log('upload', 'Operation', (int) $operationID,
                    "Uploaded " . count($filesA) . " file(s) to TXY Lights operation #{$operationID}, task #{$taskID}, run #{$run}");

                return response()->json(['message' => 'Files uploaded successfully to S3']);
            }

            return response()->json(['error' => 'Unsupported operation type'], 400);

        } catch (\Exception $e) {
            Log::error('uploadLightsManualFile error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // FOD
    // =========================================================================

    #[OA\Post(
        path: '/api/files/fod/upload',
        summary: 'Upload a ZIP of images to a FOD operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No file uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadFODManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $operationID  = $request->input('operationID');
        $taskID       = $request->input('taskID');
        $altitude     = $request->input('altitude');
        $patchOverlap = $request->input('patchOverlap');
        $captureSpeed = $request->input('captureSpeed');
        $cameraID     = $request->input('cameraID');
        $file         = $request->file('file');

        try {
            $tempZipPath = storage_path('app/platform/temp/' . $file->getClientOriginalName());
            $file->move(storage_path('app/platform/temp'), $file->getClientOriginalName());

            $zip = new \ZipArchive;
            $zip->open($tempZipPath);
            $zip->extractTo(storage_path('app/platform/temp/' . $taskID));
            $zip->close();
            unlink($tempZipPath);

            $folderPath = "FOD/$operationID/$taskID";

            if (!Storage::disk('s3')->exists($folderPath)) {
                $runNumber = 1;
            } else {
                $subFolders    = Storage::disk('s3')->directories($folderPath);
                $lastFolder    = array_pop($subFolders);
                $runNumber     = (int) basename($lastFolder) + 1;
            }

            $newFolderPath = "$folderPath/$runNumber";

            $resultFod = ResultFod::create([
                'operation_id' => $operationID,
                'task_id'      => $taskID,
                'run'          => $runNumber,
                'status'       => 'Unprocessed',
                'process_uuid' => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $extractedFiles = Storage::disk('local')->allFiles("platform/temp/$taskID");

            foreach ($extractedFiles as $extractedFile) {
                $fileName = basename($extractedFile);

                if (strpos($fileName, '.') === 0) {
                    continue;
                }

                $s3FilePath  = "$newFolderPath/$fileName";
                $fileContent = Storage::disk('local')->get($extractedFile);
                Storage::disk('s3')->put($s3FilePath, $fileContent);

                if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])) {
                    try {
                        $image         = Image::make($fileContent)->resize(null, 500, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        })->encode('jpg', 75);
                        $thumbnailPath = "$newFolderPath/thumbnail/$fileName";
                        Storage::disk('s3')->put($thumbnailPath, (string) $image);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Error generate thumbnail'], 400);
                    }
                } else {
                    return response()->json(['error' => 'Image not valid'], 400);
                }
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

            $images           = Storage::disk('s3')->files($newFolderPath);
            $resultFod->images = count($images);
            $resultFod->save();

            if (empty($images)) {
                return response()->json(['error' => 'No flight meta generated'], 400);
            }

            $firstImage  = $images[0];
            $exifService = new ExifMetadataService();
            $metadata    = $exifService->extractFromS3($firstImage);

            $operation   = Operation::where('id', $operationID)->first();
            $drone       = Drone::where('id', $operation->drone_id)->first();

            $focalLength = $metadata['focal_length'] ?? 0;
            $location    = $metadata['location'];

            $flightMeta = [
                "total_images_number" => count($images),
                "date"                => $metadata['date_time'],
                "mission_name"        => $operationID . '_' . $taskID . '_' . $runNumber,
                "mission_folder"      => $runNumber,
                "location"            => $location,
                "aircraft"            => $drone->name,
                "camera"              => $metadata['camera_model'],
                "focal_length"        => $focalLength,
                "altitude"            => $altitude,
                "patch_overlap"       => $patchOverlap,
                "capture_speed"       => $captureSpeed,
            ];

            $resultsFodParams = ResultsFodParams::create([
                'camera_id'     => $cameraID,
                'focal_length'  => $focalLength,
                'altitude'      => $altitude,
                'patch_overlap' => $patchOverlap,
                'capture_speed' => $captureSpeed,
            ]);

            $resultFod->params_id = $resultsFodParams->id;
            $resultFod->save();

            $filePath    = "$newFolderPath/flight_meta.json";
            $jsonContent = json_encode($flightMeta, JSON_PRETTY_PRINT);
            Storage::disk('s3')->put($filePath, $jsonContent);

            ActivityLog::log('upload', 'Operation', (int) $operationID,
                "Uploaded FOD run #{$runNumber} to operation #{$operationID}, task #{$taskID}");

            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // AC MAINT
    // =========================================================================

    #[OA\Post(
        path: '/api/files/acmaint/upload',
        summary: 'Upload images or a ZIP to an AcMaint operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No file uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadAcMaintManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $taskID      = $request->input('taskID');

        $files = [];
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        } elseif ($request->hasFile('files')) {
            $files = $request->file('files');
        } else {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        try {
            $folderPath = "AcMaint/$operationID/$taskID";

            if (!Storage::disk('s3')->exists($folderPath)) {
                $runNumber = 1;
            } else {
                $subFolders = Storage::disk('s3')->directories($folderPath);
                $lastFolder = array_pop($subFolders);
                $runNumber  = (int) basename($lastFolder) + 1;
            }

            $newFolderPath = "$folderPath/$runNumber";

            $resultAc = ResultAcMaint::create([
                'operation_id' => $operationID,
                'task_id'      => $taskID,
                'run'          => $runNumber,
                'is_valid'     => 1,
                'status'       => 'Unprocessed',
                'process_uuid' => null,
                'read_yaml'    => 0,
            ]);

            $resultId       = $resultAc->id;
            $imagesToProcess = [];

            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());

                if ($extension === 'zip') {
                    $tempZipPath = storage_path('app/platform/temp/' . $file->getClientOriginalName());
                    $file->move(storage_path('app/platform/temp'), $file->getClientOriginalName());

                    $zip = new \ZipArchive;
                    if ($zip->open($tempZipPath) === true) {
                        $zip->extractTo(storage_path('app/platform/temp/' . $taskID));
                        $zip->close();
                    } else {
                        throw new \Exception('No se pudo abrir el archivo zip');
                    }
                    unlink($tempZipPath);

                    foreach (Storage::disk('local')->allFiles("platform/temp/$taskID") as $extractedFile) {
                        $fileName = basename($extractedFile);
                        if (strpos($fileName, '.') === 0) continue;
                        if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])) {
                            $imagesToProcess[] = ['path' => $extractedFile, 'name' => $fileName, 'isLocal' => true];
                        }
                    }
                } elseif (in_array($extension, ['jpg', 'jpeg', 'png'])) {
                    $imagesToProcess[] = ['file' => $file, 'name' => $file->getClientOriginalName(), 'isLocal' => false];
                }
            }

            foreach ($imagesToProcess as $imageData) {
                $fileName   = $imageData['name'];
                $s3FilePath = "$newFolderPath/$fileName";

                try {
                    $fileContent = $imageData['isLocal']
                        ? Storage::disk('local')->get($imageData['path'])
                        : file_get_contents($imageData['file']->getRealPath());

                    Storage::disk('s3')->put($s3FilePath, $fileContent);

                    $image         = Image::make($fileContent)->resize(null, 500, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    })->encode('jpg', 75);
                    $thumbnailPath = "$newFolderPath/thumbnail/$fileName";
                    Storage::disk('s3')->put($thumbnailPath, (string) $image);

                    AcMaintImage::create([
                        'ac_maint_id'    => $resultId,
                        'image_path'     => "/$s3FilePath",
                        'thumbnail_path' => "/$thumbnailPath",
                        'reviewed'       => 0,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Error processing image: ' . $fileName . ' - ' . $e->getMessage());
                    continue;
                }
            }

            if (Storage::disk('local')->exists("platform/temp/$taskID")) {
                Storage::disk('local')->deleteDirectory("platform/temp/$taskID");
            }

            ActivityLog::log('upload', 'Operation', $operationID,
                "AcMaint run #{$runNumber} uploaded to operation #{$operationID} (task #{$taskID}) by '" . Auth::user()->name . "'");

            return response()->json(['message' => 'Files uploaded successfully to S3']);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Operation', $operationID, 'Error in uploadAcMaintManualFile: ' . $e->getMessage());

            if (isset($resultAc)) {
                $resultAc->delete();
            }
            if (Storage::disk('local')->exists("platform/temp/$taskID")) {
                Storage::disk('local')->deleteDirectory("platform/temp/$taskID");
            }

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // FLIGHT TURN
    // =========================================================================

    #[OA\Post(
        path: '/api/files/flightturn/upload',
        summary: 'Upload a ZIP to a FlightTurn operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No file uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadFlightTurnManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $taskID      = $request->input('taskID');

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        $file = $request->file('file');

        try {
            $tempZipPath = storage_path('app/platform/temp/' . $file->getClientOriginalName());
            $file->move(storage_path('app/platform/temp'), $file->getClientOriginalName());

            $zip = new \ZipArchive;
            if ($zip->open($tempZipPath) !== true) {
                throw new \Exception('No se pudo abrir el archivo zip');
            }
            $zip->extractTo(storage_path('app/platform/temp/' . $taskID));
            $zip->close();
            unlink($tempZipPath);

            $folderPath = "FlightTurn/$operationID/$taskID";

            if (!Storage::disk('s3')->exists($folderPath)) {
                $runNumber = 1;
            } else {
                $subFolders = Storage::disk('s3')->directories($folderPath);
                $lastFolder = array_pop($subFolders);
                $runNumber  = (int) basename($lastFolder) + 1;
            }

            $newFolderPath  = "$folderPath/$runNumber";
            $extractedFiles = Storage::disk('local')->allFiles("platform/temp/$taskID");

            $resultFt = ResultFlightTurn::create([
                'operation_id' => $operationID,
                'task_id'      => $taskID,
                'run'          => $runNumber,
                'process_uuid' => null,
                'read_yaml'    => 0,
            ]);
            $resultId = $resultFt->id;

            foreach ($extractedFiles as $extractedFile) {
                $fileName = basename($extractedFile);
                if (strpos($fileName, '.') === 0) continue;

                $s3FilePath = "$newFolderPath/$fileName";
                Storage::disk('s3')->put($s3FilePath, Storage::disk('local')->get($extractedFile));

                FlightTurnImage::create([
                    'ft_id'      => $resultId,
                    'image_path' => "/$s3FilePath",
                    'reviewed'   => 0,
                ]);
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // ALS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/als/upload',
        summary: 'Upload images to an ALS operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadAlsManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $taskID      = $request->input('taskID');
        $operation   = Operation::find($operationID);
        $als         = Als::where('header_id', $operation['subject_id'])->first();

        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

            foreach ($request->file('files') as $index => $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, $imageExts)) continue;

                try {
                    $fileContent  = file_get_contents($file->getRealPath());
                    $thumbnail    = Image::make($fileContent)
                        ->resize(null, 500, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        })
                        ->encode('jpg', 75);
                    $originalName = $request->input("file_name_$index");
                    $s3ThumbPath  = "ALS/{$operationID}/{$taskID}/thumbnail/{$originalName}";
                    Storage::disk('s3')->put($s3ThumbPath, (string) $thumbnail);
                } catch (\Exception $thumbEx) {
                    Log::warning("ALS thumbnail failed for index {$index}: " . $thumbEx->getMessage());
                }
            }

            $processedFiles = $this->uploadOperationFiles($request, 'ALS');

            foreach ($processedFiles as $fileData) {
                $index      = $fileData['index'];
                $resultsAls = ResultsAls::where('task_id', $taskID)->get();

                if ($resultsAls->isEmpty()) {
                    ResultsAls::create(['task_id' => $taskID, 'als_id' => $als->id]);
                }

                $resultsAls    = ResultsAls::where('task_id', $taskID)->first();
                $imageType     = $request->input("file_image_type_$index");
                $measurementAls = MeasurementAls::where('result_id', $resultsAls->id)
                    ->where('image_type', $imageType)
                    ->orderBy('measurement_number', 'asc')
                    ->get();

                if ($measurementAls->isEmpty()) {
                    $ultimoMeasurement = 1;
                } else {
                    $ultimoMeasurement = $measurementAls->last()->measurement_number + 1;
                    MeasurementAls::where('result_id', $resultsAls->id)
                        ->where('image_type', $imageType)
                        ->update(['is_measurement_valid' => 0]);
                }

                MeasurementAls::create([
                    'result_id'            => $resultsAls->id,
                    'image_name'           => $request->input("file_name_$index"),
                    'image_type'           => $imageType,
                    'measurement_number'   => $ultimoMeasurement,
                    'is_measurement_valid' => 1,
                ]);
            }

            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PAPI
    // =========================================================================

    #[OA\Post(
        path: '/api/files/papi/upload',
        summary: 'Upload files to a PAPI operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadPAPIManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $this->uploadOperationFiles($request, 'PAPI');
            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // ILS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/ils/upload',
        summary: 'Upload files to an ILS operation (handles duplicate names)',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadIlsManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $ilsFileNameResolver = function (string $originalFileName): string {
                $existingFile = OperationFiles::where('file_name', $originalFileName)->first();
                if (!$existingFile) return $originalFileName;

                $pathInfo  = pathinfo($originalFileName);
                $baseName  = $pathInfo['filename'];
                $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
                $counter   = 2;

                do {
                    $newFileName  = "{$baseName}_{$counter}{$extension}";
                    $existingFile = OperationFiles::where('file_name', $newFileName)->first();
                    $counter++;
                } while ($existingFile);

                return $newFileName;
            };

            $this->uploadOperationFiles($request, 'ILS', $ilsFileNameResolver);
            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // MARKINGS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/markings/upload',
        summary: 'Upload images or other files to a Markings operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadMarkingsManualFile(Request $request): JsonResponse
    {
        try {
            $operationID = $request->input('operationID');
            $taskID      = $request->input('taskID');
            $uploadType  = $request->input('uploadType');
            $description = $request->input('description', '');
            $operation   = Operation::find($operationID);

            $subject  = $operation->subject()->name;
            $runwayID = Runway::where('name', $subject)->value('id');

            if (!$request->hasFile('files')) {
                return response()->json(['error' => 'No files uploaded'], 400);
            }

            $files          = $request->file('files');
            $tempFolderPath = storage_path('app/platform/temp/' . $taskID);

            if (!file_exists($tempFolderPath)) {
                mkdir($tempFolderPath, 0777, true);
            }

            $resultID = null;
            $run      = null;

            if ($uploadType === 'image' && $operation->type_id == 22) {
                $lastRun = ResultsRwyMarkings::where('task_id', $taskID)->max('run');
                $run     = $lastRun ? $lastRun + 1 : 1;

                $result   = ResultsRwyMarkings::create([
                    'task_id'            => $taskID,
                    'rwy_id'             => $runwayID,
                    'operation_id'       => $operationID,
                    'run'                => $run,
                    'is_valid'           => 0,
                    'status'             => 'Processed',
                    'read_yaml'          => false,
                    'num_imgs_processed' => 0,
                    'is_video'           => 0,
                ]);
                $resultID = $result->id;
            }

            $folderPath = $uploadType === 'image'
                ? "Markings/{$operationID}/{$taskID}/{$run}"
                : "Markings/{$operationID}/{$taskID}";

            if ($uploadType === 'image') {
                foreach ($files as $file) {
                    try {
                        $fileName = $file->getClientOriginalName();
                        $tempPath = $tempFolderPath . '/' . $fileName;
                        $file->move($tempFolderPath, $fileName);

                        $s3FilePath  = "$folderPath/$fileName";
                        Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));

                        $markingImage = MarkingsImage::create([
                            'type_id'         => $operation->type_id,
                            'rwy_id'          => $resultID,
                            'task_id'         => $taskID,
                            'image_path'      => $s3FilePath,
                            'type_upload'     => 'images',
                            'flight_altitude' => $request->input('flight_altitude'),
                        ]);

                        $pathInfo      = pathinfo($s3FilePath);
                        $thumbnailPath = $pathInfo['dirname'] . '/thumbnail/' . $pathInfo['filename'] . '.jpg';
                        (new \App\Services\ThumbnailService())->generateThumbnail($s3FilePath, $thumbnailPath);
                        $markingImage->thumbnail_path = $thumbnailPath;
                        $markingImage->save();

                        if (file_exists($tempPath)) unlink($tempPath);
                    } catch (\Exception $e) {
                        Storage::disk('local')->deleteDirectory("platform/temp/$taskID");
                        throw $e;
                    }
                }
            } else {
                foreach ($files as $index => $file) {
                    try {
                        $fileName = $file->getClientOriginalName();
                        $fileType = $request->input("file_type_$index");
                        $fileSize = $request->input("file_size_$index") ?? $file->getSize();
                        $tempPath = $tempFolderPath . '/' . $fileName;
                        $file->move($tempFolderPath, $fileName);

                        $s3FilePath = "$folderPath/$fileName";
                        Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));

                        OperationFiles::create([
                            'file_name'   => $fileName,
                            'description' => $description,
                            'type'        => $fileType ? explode('/', $fileType)[1] : pathinfo($fileName, PATHINFO_EXTENSION),
                            'size'        => $fileSize,
                            'task_id'     => $taskID,
                        ]);

                        if (file_exists($tempPath)) unlink($tempPath);
                    } catch (\Exception $e) {
                        Storage::disk('local')->deleteDirectory("platform/temp/$taskID");
                        throw $e;
                    }
                }
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

            $fileCount = count($files);
            if ($uploadType === 'image') {
                ActivityLog::log('upload', 'Operation', (int) $operationID,
                    "Uploaded {$fileCount} image(s) to Markings operation #{$operationID}, task #{$taskID}, run #{$run}");
            } else {
                ActivityLog::log('upload', 'Operation', (int) $operationID,
                    "Uploaded {$fileCount} file(s) to Markings operation #{$operationID}, task #{$taskID}");
            }

            return response()->json([
                'success'        => true,
                'message'        => $uploadType === 'image' ? 'Images uploaded successfully' : 'Files uploaded successfully',
                'run'            => $run,
                'resultId'       => $resultID,
                'filesProcessed' => count($files),
                'uploadType'     => $uploadType,
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading files: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PCI
    // =========================================================================

    #[OA\Post(
        path: '/api/files/pci/upload',
        summary: 'Upload a ZIP of images to a PCI operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No file uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadPCIManualFile(Request $request): JsonResponse
    {
        try {
            if (!$request->hasFile('file')) {
                return response()->json(['error' => 'No file uploaded'], 400);
            }

            $params        = $this->extractRequestParams($request);
            $extractedPath = $this->processZipFile($request->file('file'), $params['taskID']);
            $runNumber     = $this->determineRunNumber($params['operationID'], $params['taskID']);
            $resultPci     = $this->createPciRecord($params['operationID'], $params['taskID'], $runNumber);

            $s3FolderPath          = "PCI/{$params['operationID']}/{$params['taskID']}/$runNumber";
            $uploadedFiles         = $this->uploadFilesToS3($extractedPath, $s3FolderPath, $params['taskID']);
            $resultPci->images     = count($uploadedFiles);
            $resultPci->save();

            $paramsId              = $this->generateFlightMeta($uploadedFiles, $s3FolderPath, $params, $runNumber);
            $resultPci->params_id  = $paramsId;
            $resultPci->save();

            ActivityLog::log('upload', 'Operation', (int) $params['operationID'],
                "Uploaded files to PCI operation #{$params['operationID']}, task #{$params['taskID']}, run #{$runNumber}");

            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // VOR / SURVEILLANCE / ETOD
    // =========================================================================

    #[OA\Post(
        path: '/api/files/vor/upload',
        summary: 'Upload files to a VOR operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadVORManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $this->uploadOperationFiles($request, 'VOR');
            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/files/surveillance/upload',
        summary: 'Upload files to a Surveillance operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadSurveillanceManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $this->uploadOperationFiles($request, 'Surveillance');
            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/api/files/etod/upload',
        summary: 'Upload files to an ETOD operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadEtodManualFile(Request $request): JsonResponse
    {
        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        try {
            $this->uploadOperationFiles($request, 'ETOD');
            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // REPORTS
    // =========================================================================

    #[OA\Post(
        path: '/api/files/reports/upload',
        summary: 'Upload report files to an operation (handles duplicate names)',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files or operation not found'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadReportsManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $operation   = Operation::find($operationID);

        if (!$operation) {
            return response()->json(['error' => 'Operation not found'], 404);
        }

        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $files          = $request->file('files');
        $folderMapping  = Operation::getFolderMapping();
        $folderType     = $folderMapping[$operation->type_id] ?? 'Unknown';
        $folderPath     = "$folderType/$operationID/reports";
        $tempFolderPath = storage_path('app/platform/temp/' . $operationID);

        try {
            foreach ($files as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $extension    = strtolower($file->getClientOriginalExtension());
                $baseName     = pathinfo($originalName, PATHINFO_FILENAME);

                $finalName = $originalName;
                $counter   = 1;

                while (OperationReports::where('operation_id', $operation->id)->where('name', $finalName)->exists()) {
                    $finalName = "{$baseName}_{$counter}.{$extension}";
                    $counter++;
                }

                $tempFilePath = $tempFolderPath . '/' . $finalName;
                $file->move($tempFolderPath, $finalName);

                $s3FilePath   = "$folderPath/$finalName";
                $reportStream = fopen($tempFilePath, 'r');
                Storage::disk('s3')->put($s3FilePath, $reportStream);
                fclose($reportStream);

                OperationReports::create([
                    'name'         => $finalName,
                    'description'  => $request->input("file_description_$index") ?? '',
                    'type'         => $extension,
                    'size'         => $request->input("file_size_$index"),
                    'operation_id' => $operation->id,
                ]);
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$operationID");

            $fileCount = count($files);
            ActivityLog::log('upload', 'Operation', (int) $operationID,
                "Uploaded {$fileCount} report(s) to operation #{$operationID} ({$operation->type->name})");

            return response()->json(['message' => 'Files uploaded successfully to S3']);
        } catch (\Exception $e) {
            if (file_exists($tempFolderPath)) {
                Storage::disk('local')->deleteDirectory("platform/temp/$operationID");
            }
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // AERODROME BEACON
    // =========================================================================

    #[OA\Post(
        path: '/api/files/aerodrome-beacon/upload',
        summary: 'Upload files to an Aerodrome Beacon operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded or not found'),
            new OA\Response(response: 404, description: 'Operation, airport or beacon not found'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadAerodromeBeaconManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $taskID      = $request->input('taskID');
        $operation   = Operation::find($operationID);

        if (!$operation) {
            return response()->json(['error' => 'Operation not found'], 404);
        }

        $airport = $operation->getAirport();
        if (!$airport) {
            return response()->json(['error' => 'Airport not found for this operation'], 404);
        }

        $beacon = AerodromeBeacon::where('airport_id', $airport->id)->first();
        if (!$beacon) {
            return response()->json(['error' => 'Beacon not found'], 404);
        }

        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $files = $request->file('files');

        try {
            $folderPath     = "AerodromeBeacon/$operationID/$taskID/1";
            $tempFolderPath = storage_path('app/platform/temp/' . $taskID);

            if (!file_exists($tempFolderPath)) {
                mkdir($tempFolderPath, 0777, true);
            }

            foreach ($files as $index => $file) {
                $tempPath   = $tempFolderPath . '/' . $file->getClientOriginalName();
                $file->move($tempFolderPath, $file->getClientOriginalName());

                $s3FilePath = "$folderPath/" . $file->getClientOriginalName();
                Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));

                OperationFiles::create([
                    'file_name'   => $request->input("file_name_$index"),
                    'description' => '',
                    'type'        => explode('/', $request->input("file_type_$index"))[1],
                    'size'        => $request->input("file_size_$index"),
                    'task_id'     => $taskID,
                ]);

                ResultsBeacon::create([
                    'operation_id' => $operationID,
                    'task_id'      => $taskID,
                    'beacon_id'    => $beacon->id,
                ]);
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

            $fileCount = count($files);
            $fileTypes = array_unique(array_map(
                fn($i) => explode('/', $request->input("file_type_$i"))[1] ?? 'unknown',
                array_keys($files)
            ));

            ActivityLog::log('upload', 'Operation', (int) $operationID,
                "Uploaded {$fileCount} file(s) [" . implode(', ', $fileTypes) . "] to Aerodrome Beacon operation #{$operationID}, task #{$taskID}");

            return response()->json(['message' => 'Files uploaded successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // WDI
    // =========================================================================

    #[OA\Post(
        path: '/api/files/wdi/upload',
        summary: 'Upload files to a WDI operation',
        security: [['bearerAuth' => []]],
        tags: ['OperationFiles'],
        responses: [
            new OA\Response(response: 200, description: 'Files uploaded'),
            new OA\Response(response: 400, description: 'No files uploaded'),
            new OA\Response(response: 404, description: 'Operation, airport, task or WDI not found'),
            new OA\Response(response: 500, description: 'Upload error'),
        ]
    )]
    public function uploadWdiManualFile(Request $request): JsonResponse
    {
        $operationID = $request->input('operationID');
        $taskID      = $request->input('taskID');
        $operation   = Operation::find($operationID);

        if (!$operation) return response()->json(['error' => 'Operation not found'], 404);

        $airport = $operation->getAirport();
        if (!$airport) return response()->json(['error' => 'Airport not found for this operation'], 404);

        $task = Task::find($taskID);
        if (!$task) return response()->json(['error' => 'Task not found'], 404);

        $wdi = Wdi::where('airport_id', $airport->id)->where('name', $task->description)->first();
        if (!$wdi) return response()->json(['error' => 'WDI not found for this task'], 404);

        $lastResult = ResultsWdi::where('operation_id', $operationID)->where('task_id', $taskID)->orderBy('run', 'desc')->first();
        $nextRun    = $lastResult ? $lastResult->run + 1 : 1;

        if (!$request->hasFile('files')) {
            return response()->json(['error' => 'No files uploaded'], 400);
        }

        $files           = $request->file('files');
        $uploadedS3Paths = [];

        try {
            DB::beginTransaction();

            $folderPath     = "WDI/$operationID/$taskID/$nextRun";
            $tempFolderPath = storage_path('app/platform/temp/' . $taskID);

            if (!file_exists($tempFolderPath)) {
                mkdir($tempFolderPath, 0777, true);
            }

            $result = ResultsWdi::create([
                'operation_id' => $operationID,
                'task_id'      => $taskID,
                'run'          => $nextRun,
                'wdi_id'       => $wdi->id,
            ]);

            foreach ($files as $index => $file) {
                $tempPath   = $tempFolderPath . '/' . $file->getClientOriginalName();
                $file->move($tempFolderPath, $file->getClientOriginalName());

                $s3FilePath        = "$folderPath/" . $file->getClientOriginalName();
                Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));
                $uploadedS3Paths[] = $s3FilePath;

                WdiFile::create([
                    's3_path'   => $s3FilePath,
                    'description' => '',
                    'type'      => explode('/', $request->input("file_type_$index"))[1],
                    'size'      => $request->input("file_size_$index"),
                    'result_id' => $result->id,
                ]);
            }

            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");
            DB::commit();

            return response()->json(['message' => 'Files uploaded successfully']);
        } catch (\Exception $e) {
            DB::rollBack();

            foreach ($uploadedS3Paths as $path) {
                Storage::disk('s3')->delete($path);
            }
            Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function uploadOperationFiles(
        Request $request,
        string $folderPrefix,
        callable $fileNameResolver = null
    ): array {
        $operationID    = $request->input('operationID');
        $taskID         = $request->input('taskID');
        $folderPath     = "$folderPrefix/$operationID/$taskID";
        $tempFolderPath = storage_path('app/platform/temp/' . $taskID);

        if (!file_exists($tempFolderPath)) {
            mkdir($tempFolderPath, 0777, true);
        }

        $processedFiles = [];

        foreach ($request->file('files') as $index => $file) {
            $originalFileName = $request->input("file_name_$index");
            $finalFileName    = $fileNameResolver
                ? ($fileNameResolver)($originalFileName)
                : $originalFileName;

            $tempPath   = $tempFolderPath . '/' . $file->getClientOriginalName();
            $file->move($tempFolderPath, $file->getClientOriginalName());

            $s3FilePath = "$folderPath/$finalFileName";
            Storage::disk('s3')->put($s3FilePath, file_get_contents($tempPath));

            OperationFiles::create([
                'file_name'   => $finalFileName,
                'description' => '',
                'type'        => explode('/', $request->input("file_type_$index"))[1],
                'size'        => $request->input("file_size_$index"),
                'task_id'     => $taskID,
            ]);

            $processedFiles[] = [
                'index'         => $index,
                'originalName'  => $originalFileName,
                'finalFileName' => $finalFileName,
            ];
        }

        Storage::disk('local')->deleteDirectory("platform/temp/$taskID");

        return $processedFiles;
    }

    private function extractRequestParams(Request $request): array
    {
        return [
            'operationID'  => $request->input('operationID'),
            'taskID'       => $request->input('taskID'),
            'altitude'     => $request->input('altitude'),
            'patchOverlap' => $request->input('patchOverlap'),
            'captureSpeed' => $request->input('captureSpeed'),
            'cameraID'     => $request->input('cameraID'),
        ];
    }

    private function processZipFile($file, $taskID): string
    {
        $tempZipPath = storage_path('app/platform/temp/' . $file->getClientOriginalName());
        $extractPath = storage_path('app/platform/temp/' . $taskID);

        $file->move(storage_path('app/platform/temp'), $file->getClientOriginalName());

        $zip = new \ZipArchive;
        if ($zip->open($tempZipPath) !== true) {
            throw new \Exception('Failed to open zip file');
        }
        $zip->extractTo($extractPath);
        $zip->close();
        unlink($tempZipPath);

        return "platform/temp/$taskID";
    }

    private function determineRunNumber($operationID, $taskID): int
    {
        $folderPath = "PCI/$operationID/$taskID";

        if (!Storage::disk('s3')->exists($folderPath)) {
            return 1;
        }

        $subFolders = Storage::disk('s3')->directories($folderPath);
        if (empty($subFolders)) return 1;

        return (int) basename(array_pop($subFolders)) + 1;
    }

    private function createPciRecord($operationID, $taskID, $runNumber): ResultPci
    {
        return ResultPci::create([
            'operation_id' => $operationID,
            'task_id'      => $taskID,
            'run'          => $runNumber,
            'status'       => 'Unprocessed',
            'process_uuid' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function uploadFilesToS3($localPath, $s3FolderPath, $taskID): array
    {
        $extractedFiles = Storage::disk('local')->allFiles($localPath);
        $uploadedFiles  = [];

        foreach ($extractedFiles as $extractedFile) {
            $fileName = basename($extractedFile);
            if (strpos($fileName, '.') === 0) continue;

            $this->uploadSingleFile($extractedFile, "$s3FolderPath/$fileName");

            if ($this->isValidImage($fileName)) {
                $this->generateAndUploadThumbnail($extractedFile, "$s3FolderPath/thumbnail/$fileName");
            }

            $uploadedFiles[] = "$s3FolderPath/$fileName";
        }

        Storage::disk('local')->deleteDirectory($localPath);

        return $uploadedFiles;
    }

    private function uploadSingleFile($localFile, $s3Path): void
    {
        try {
            $fileContent = Storage::disk('local')->get($localFile);
            Storage::disk('s3')->put($s3Path, $fileContent);
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload file: ' . basename($localFile));
        }
    }

    private function isValidImage($fileName): bool
    {
        return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png']);
    }

    private function generateAndUploadThumbnail($localFile, $s3ThumbnailPath): void
    {
        try {
            $fileContent = Storage::disk('local')->get($localFile);
            $image       = Image::make($fileContent)->resize(null, 500, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->encode('jpg', 75);

            Storage::disk('s3')->put($s3ThumbnailPath, (string) $image);
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate thumbnail for: ' . basename($localFile));
        }
    }

    private function generateFlightMeta($uploadedFiles, $s3FolderPath, $params, $runNumber): int
    {
        if (empty($uploadedFiles)) {
            throw new \Exception('No images to process for flight meta');
        }

        $imageMetadata = $this->extractImageMetadata($uploadedFiles[0]);
        $operation     = Operation::findOrFail($params['operationID']);
        $drone         = Drone::findOrFail($operation->drone_id);

        $resultsPciParams = $this->createPciParams($params, $imageMetadata['focalLength']);

        $flightMeta = $this->buildFlightMetaArray($uploadedFiles, $imageMetadata, $params, $runNumber, $drone->name);
        $this->saveFlightMetaToS3($flightMeta, $s3FolderPath);

        return $resultsPciParams->id;
    }

    private function extractImageMetadata($s3ImagePath): array
    {
        $exifService = new ExifMetadataService();
        $metadata    = $exifService->extractFromS3($s3ImagePath);

        return [
            'location'    => $metadata['location'],
            'focalLength' => $metadata['focal_length'] ?? 0,
            'dateTime'    => $metadata['date_time'],
            'cameraModel' => $metadata['camera_model'],
        ];
    }

    private function createPciParams($params, $focalLength): ResultsPciParams
    {
        return ResultsPciParams::create([
            'camera_id'    => $params['cameraID'],
            'focal_length' => $focalLength,
            'altitude'     => $params['altitude'],
            'patch_overlap' => $params['patchOverlap'],
            'capture_speed' => $params['captureSpeed'],
        ]);
    }

    private function buildFlightMetaArray($uploadedFiles, $metadata, $params, $runNumber, $aircraftName): array
    {
        return [
            "total_images_number" => count($uploadedFiles),
            "date"                => $metadata['dateTime'],
            "mission_name"        => "{$params['operationID']}_{$params['taskID']}_{$runNumber}",
            "mission_folder"      => $runNumber,
            "location"            => $metadata['location'],
            "aircraft"            => $aircraftName,
            "camera"              => $metadata['cameraModel'],
            "focal_length"        => $metadata['focalLength'],
            "altitude"            => $params['altitude'],
            "patch_overlap"       => $params['patchOverlap'],
            "capture_speed"       => $params['captureSpeed'],
        ];
    }

    private function saveFlightMetaToS3($flightMeta, $s3FolderPath): void
    {
        Storage::disk('s3')->put("$s3FolderPath/flight_meta.json", json_encode($flightMeta, JSON_PRETTY_PRINT));
    }
}
