<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\OperationFiles;
use App\Models\Parameter;
use App\Models\ResultsVor;
use App\Models\Task;
use App\Models\Vor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class VorVisualizationController extends Controller
{
    // ── Get CSV data ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operation-files/{idfile}/vor-data',
        summary: 'Get sampled CSV data for a VOR operation file',
        security: [['bearerAuth' => []]],
        tags: ['VOR Visualization'],
        parameters: [
            new OA\Parameter(name: 'idfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sampled CSV rows as JSON array'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getData(int $idfile): JsonResponse
    {
        $file = OperationFiles::findOrFail($idfile);

        $filePath = $this->resolveS3Path($file);
        if (!$filePath || !Storage::disk('s3')->exists($filePath)) {
            return response()->json(['error' => 'File not found in S3'], 404);
        }

        $tempFilePath = public_path('temp/extra/' . $file->file_name);
        file_put_contents($tempFilePath, Storage::disk('s3')->get($filePath));

        if (!file_exists($tempFilePath)) {
            return response()->json(['error' => 'El archivo CSV no existe en el sistema'], 404);
        }

        $fileHandle = fopen($tempFilePath, 'r');
        $headers    = fgetcsv($fileHandle);
        $data       = [];
        $count      = 0;
        $sampleRate = 15;

        while (($row = fgetcsv($fileHandle)) !== false) {
            if ($count % $sampleRate === 0) {
                $record = [];
                foreach ($headers as $index => $header) {
                    if (isset($row[$index])) {
                        $value          = $row[$index];
                        $record[$header] = is_numeric($value) ? (float) $value : $value;
                    }
                }
                $data[] = $record;
            }
            $count++;
        }

        fclose($fileHandle);

        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }

        return response()->json($data);
    }

    // ── Visualization data ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/operation-files/{idfile}/vor-visualization',
        summary: 'Get chart configuration and results for a VOR operation file',
        security: [['bearerAuth' => []]],
        tags: ['VOR Visualization'],
        parameters: [
            new OA\Parameter(name: 'idfile',    in: 'path',  required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'runNumber', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Visualization config and chart data'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function getVisualizationData(Request $request, int $idfile): JsonResponse
    {
        try {
            $file       = OperationFiles::findOrFail($idfile);
            $taskTypeId = $file->task->type_id;
            $operation  = $file->task->operation;
            $vor        = Vor::where('id', $operation->subject_id)->first();
            $runNumber  = $request->get('runNumber', 1);
            $taskId     = $file->task->id;

            $results = ResultsVor::where('task_id', $taskId)
                ->where('operation_file_id', $idfile)
                ->first();

            if (is_null($results)) {
                $results = ResultsVor::where('task_id', $taskId)
                    ->where('run_number', $runNumber)
                    ->get();
            }

            $config         = $this->getVorConfigurationByTaskType($taskTypeId, $vor, $operation->type_id, $results);
            $chartInfoParams = $this->getVorChartInfoParams($taskId, $idfile, $results, $taskTypeId);

            return response()->json([
                'success' => true,
                'data'    => [
                    'idfile'          => $idfile,
                    'file'            => $file,
                    'paramsMap'       => $this->defineAllVorParameters(),
                    'operation'       => $operation,
                    'task'            => $file->task,
                    'config'          => $config,
                    'chartInfoParams' => $chartInfoParams,
                    'taskTypeId'      => $taskTypeId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('=== ERROR en getVisualizationData ===', [
                'idfile'  => $idfile,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Error obteniendo datos de visualización VOR: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Task axis configuration ───────────────────────────────────────────────

    #[OA\Get(
        path: '/api/tasks/{taskId}/vor-axis-config',
        summary: 'Get axis configuration for a VOR task',
        security: [['bearerAuth' => []]],
        tags: ['VOR Visualization'],
        parameters: [
            new OA\Parameter(name: 'taskId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Axis config'),
            new OA\Response(response: 404, description: 'Task not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function getTaskAxisConfiguration(int $taskId): JsonResponse
    {
        try {
            $task = Task::find($taskId);

            if (!$task) {
                return response()->json(['error' => 'Task not found'], 404);
            }

            $vorResults = ResultsVor::where('task_id', $taskId)->get();

            $config = [
                'taskType' => $task->type->name,
                'isRadial' => str_contains(strtolower($task->type->name), 'radial'),
            ];

            if ($config['isRadial'] && $vorResults->count() > 0) {
                $parameter = Parameter::where('subject_id', $vorResults[0]->vor_id)
                    ->where('subject_type_id', 5)
                    ->where('parameter_type_id', 13)
                    ->where('task_type_id', 27)
                    ->first();

                $config['axisConfig'] = [
                    'xMin'     => -100,
                    'xMax'     => ($parameter->value ?? 1000) + 100,
                    'xText'    => 'Distance (m)',
                    'dynamicX' => true,
                ];
            } else {
                $config['axisConfig'] = [
                    'xMin'     => 0,
                    'xMax'     => 360,
                    'xText'    => 'Angle (°)',
                    'dynamicX' => true,
                ];
            }

            return response()->json(['success' => true, 'config' => $config]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveS3Path(OperationFiles $file): ?string
    {
        $folderMapping = Operation::getFolderMapping();
        $folderType    = $folderMapping[$file->task->operation->type_id] ?? null;
        if (!$folderType) return null;
        return "{$folderType}/{$file->task->operation->id}/{$file->task->id}/{$file->file_name}";
    }

    private function defineAllVorParameters(): array
    {
        $color = '#4CAF50';
        return [
            'bearing_error'                => ['text' => 'Error',                       'color' => $color],
            'bearing_low_pass_filter'      => ['text' => 'Bearing Low Pass Filter',     'color' => $color],
            'bearing_high_pass_filter'     => ['text' => 'Bearing High Pass Filter',    'color' => $color],
            'calculated_bearing'           => ['text' => 'Calculated Bearing',          'color' => $color],
            'field_strength'               => ['text' => 'Field Strength',              'color' => $color],
            'carrier_frequency'            => ['text' => 'Carrier Frequency',           'color' => $color],
            'f30Hz_mod_depth'              => ['text' => 'F30Hz Modulation Depth',      'color' => $color],
            'f30Hz_mod_frequency'          => ['text' => 'F30Hz Modulation Frequency',  'color' => $color],
            'f9960Hz_mod_depth'            => ['text' => 'F9960Hz Modulation Depth',    'color' => $color],
            'f9960Hz_subcarrier_frequency' => ['text' => 'F9960Hz Subcarrier Frequency','color' => $color],
            'f9960Hz_deviation'            => ['text' => 'F9960Hz Deviation',           'color' => $color],
            'f9960Hz_frequency_deviation'  => ['text' => 'F9960Hz Frequency Deviation', 'color' => $color],
            'f9960Hz_30Hz_mod_frequency'   => ['text' => 'F9960Hz 30Hz Mod Frequency',  'color' => $color],
            'bearing'                      => ['text' => 'Bearing',                     'color' => $color],
            'distance_to_vor'              => ['text' => 'Distance to VOR',             'color' => $color],
            'hmsl'                         => ['text' => 'Height MSL',                  'color' => $color],
            'latitude'                     => ['text' => 'Latitude',                    'color' => $color],
            'longitude'                    => ['text' => 'Longitude',                   'color' => $color],
            'position'                     => ['text' => 'Position',                    'color' => $color],
            'posFlag'                      => ['text' => 'Position Flag',               'color' => $color],
            'battery'                      => ['text' => 'Battery',                     'color' => $color],
            'timestamp'                    => ['text' => 'Timestamp',                   'color' => $color],
        ];
    }

    private function getVorConfigurationByTaskType(int $taskTypeId, ?Vor $vor, int $operationTypeId, $results): array
    {
        if ($taskTypeId == 27) {
            $parameter    = Parameter::where('subject_id', $vor?->id)
                ->where('subject_type_id', 5)
                ->where('parameter_type_id', 13)
                ->where('task_type_id', 27)
                ->first();
            $xMaxDistance = $parameter->value ?? 1000;

            $baseConfig = [
                'yMin'          => -12,
                'yMax'          => 12,
                'xMin'          => -100,
                'xMax'          => $xMaxDistance + 100,
                'xText'         => 'Distance (m)',
                'yText'         => 'Error (°)',
                'verticalLines' => [],
                'limitLines'    => [],
            ];
        } else {
            $baseConfig = [
                'yMin'          => -12,
                'yMax'          => 12,
                'xMin'          => 0,
                'xMax'          => 360,
                'xText'         => 'Angle (°)',
                'yText'         => 'Error (°)',
                'verticalLines' => [],
                'limitLines'    => [],
            ];
        }

        if ($vor) {
            $baseConfig['limitLines'] = [
                ['points' => [['x' => $baseConfig['xMin'], 'y' => 2],  ['x' => $baseConfig['xMax'], 'y' => 2]]],
                ['points' => [['x' => $baseConfig['xMin'], 'y' => -2], ['x' => $baseConfig['xMax'], 'y' => -2]]],
            ];

            $first_result    = $results->first();
            $over_vor        = $first_result?->over_vor ?? 0;
            $punto_silencio  = $over_vor > 0 ? $over_vor / tan(deg2rad(40)) : 0;
            $initial_bearing = $first_result?->initial_bearing ?? 0;
            $initial_record  = $first_result?->initial_record  ?? 0;

            switch ($taskTypeId) {
                case 27:
                    $baseConfig['verticalLines'] = [
                        ['x' => 0,              'label' => 'Inicio',  'color' => 'rgba(0, 0, 255, 0.5)'],
                        ['x' => $punto_silencio, 'label' => 'Silencio','color' => 'rgba(0, 0, 255, 0.5)'],
                    ];
                    break;
                case 26:
                    $baseConfig['verticalLines'] = [
                        ['x' => $initial_bearing, 'label' => '°', 'color' => 'rgba(0, 0, 255, 0.5)'],
                        ['x' => $initial_record,  'label' => '°', 'color' => '#d500f9'],
                    ];
                    break;
            }
        }

        return $baseConfig;
    }

    private function getVorChartInfoParams(int $taskId, int $idfile, $results, int $taskTypeId): array
    {
        $flight = [];

        if ($taskTypeId == 27) {
            $data      = $this->getDataForCalculations($idfile);
            $latValues = array_column($data, 'latitude');
            $lonValues = array_column($data, 'longitude');
            $distance  = 'N/A';

            if (count($latValues) > 1 && count($lonValues) > 1) {
                $distance = $this->calculateDistanceInMeters(
                    $latValues[0], $lonValues[0],
                    $latValues[count($latValues) - 1], $lonValues[count($lonValues) - 1]
                );
            }

            $flight = [['key' => 'flight_dist', 'label' => 'Flight Dist', 'value' => number_format($distance, 2) . ' m']];
        }

        $first_result = $results->first();

        return [
            'measurement' => [
                [
                    'key'   => 'bearing_error',
                    'label' => 'Avg. Err',
                    'value' => $first_result?->average_bearing_error !== null
                                ? number_format($first_result->average_bearing_error, 1)
                                : 'N/A',
                ],
            ],
            'rf' => [
                [
                    'key'   => 'f9960',
                    'label' => 'F9960',
                    'value' => $first_result?->mean_f9960_hz_sub_freq !== null
                                ? number_format($first_result->mean_f9960_hz_sub_freq, 2) . ' Hz'
                                : 'N/A',
                ],
                [
                    'key'   => 'f30',
                    'label' => 'F30',
                    'value' => $first_result?->mean_f30_hz_mod_freq !== null
                                ? number_format($first_result->mean_f30_hz_mod_freq, 2) . ' Hz'
                                : 'N/A',
                ],
                [
                    'key'   => 'rf_pwr',
                    'label' => 'RF Pwr',
                    'value' => $first_result?->mean_field_strength !== null
                                ? number_format($first_result->mean_field_strength, 2) . ' dBm'
                                : 'N/A',
                ],
            ],
            'flight' => $flight,
        ];
    }

    private function getDataForCalculations(int $idfile): array
    {
        $file = OperationFiles::findOrFail($idfile);

        $filePath = $this->resolveS3Path($file);
        if (!$filePath || !Storage::disk('s3')->exists($filePath)) {
            return [];
        }

        $tempFilePath = public_path('temp/extra/' . $file->file_name);
        $dir          = dirname($tempFilePath);

        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($tempFilePath, Storage::disk('s3')->get($filePath));

        if (!file_exists($tempFilePath)) {
            return [];
        }

        $fileHandle = fopen($tempFilePath, 'r');
        $headers    = fgetcsv($fileHandle);
        $data       = [];

        while (($row = fgetcsv($fileHandle)) !== false) {
            $record = [];
            foreach ($headers as $index => $header) {
                if (isset($row[$index])) {
                    $value          = $row[$index];
                    $record[$header] = is_numeric($value) ? (float) $value : $value;
                }
            }
            $data[] = $record;
        }

        fclose($fileHandle);

        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }

        return $data;
    }

    private function calculateDistanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $lat1Rad     = deg2rad($lat1);
        $lon1Rad     = deg2rad($lon1);
        $lat2Rad     = deg2rad($lat2);
        $lon2Rad     = deg2rad($lon2);
        $dLat        = $lat2Rad - $lat1Rad;
        $dLon        = $lon2Rad - $lon1Rad;

        $a = sin($dLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
