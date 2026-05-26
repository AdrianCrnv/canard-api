<?php

namespace App\Http\Controllers\Api;

use App\Header;
use App\Ils;
use App\OperationFiles;
use App\Operation;
use App\ResultsIlsGlidePath;
use App\ResultsIlsLocalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class IlsVisualizationController extends IlsController
{
    private static array $LOC_TASK_IDS = [8, 9, 10, 11, 12, 13, 14, 15, 42];
    private static array $GP_TASK_IDS  = [17, 18, 19, 20, 21, 22, 23, 24, 44];

    #[OA\Get(
        path: '/api/ils/visualization/{idfile}',
        summary: 'Get ILS visualization data for a file',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'idfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'run_number', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Visualization data'),
            new OA\Response(response: 404, description: 'File not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function getVisualizationData(Request $request, int $idfile): JsonResponse
    {
        try {
            $file       = OperationFiles::findOrFail($idfile);
            $runNumber  = $request->query('run_number');
            $paramsMap  = $this->defineAllParameters();
            $taskTypeId = $file->task->type_id;
            $operation  = $file->task->operation;
            $ils        = Ils::where('header_id', $operation->subject_id)->first();
            $config     = $this->getConfigurationByTaskType($taskTypeId, $ils, $operation->type_id);
            $taskId     = $file->task->id;
            $resultsIls = null;

            if (in_array($taskTypeId, self::$LOC_TASK_IDS)) {
                $resultsIls = ResultsIlsLocalizer::where('task_id', $taskId)->where('operation_file_id', $idfile)->get();
                if ($resultsIls->isEmpty()) {
                    $resultsIls = ResultsIlsLocalizer::where('task_id', $taskId)->where('run_number', $runNumber)->get();
                }
            } elseif (in_array($taskTypeId, self::$GP_TASK_IDS)) {
                $resultsIls = ResultsIlsGlidePath::where('task_id', $taskId)->where('operation_file_id', $idfile)->get();
                if ($resultsIls->isEmpty()) {
                    $resultsIls = ResultsIlsGlidePath::where('task_id', $taskId)->where('run_number', $runNumber)->get();
                }
            }

            $chartInfoParams = (is_null($resultsIls) || $resultsIls->isEmpty())
                ? $this->getChartInfoParams($taskTypeId, $idfile, null)
                : $this->getChartInfoParams($taskTypeId, $idfile, $resultsIls);

            return response()->json([
                'success' => true,
                'data'    => [
                    'idfile'          => $idfile,
                    'file'            => $file,
                    'paramsMap'       => $paramsMap,
                    'operation'       => $file->task->operation,
                    'task'            => $file->task,
                    'config'          => $config,
                    'chartInfoParams' => $chartInfoParams,
                    'taskTypeId'      => $taskTypeId,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error obteniendo datos de visualización: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/ils/data/{idfile}',
        summary: 'Get raw CSV data for an ILS operation file',
        security: [['bearerAuth' => []]],
        tags: ['ILS'],
        parameters: [
            new OA\Parameter(name: 'idfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV data as JSON array'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function getData(int $idfile): JsonResponse
    {
        $file = OperationFiles::findOrFail($idfile);

        $folderMapping = Operation::getFolderMapping();
        $folderType    = $folderMapping[$file->task->operation->type_id] ?? null;
        $filePath      = "$folderType/{$file->task->operation->id}/{$file->task->id}/{$file->file_name}";

        if (!Storage::disk('s3')->exists($filePath)) {
            return response()->json(['error' => 'File not found in S3'], 404);
        }

        $tempFilePath  = public_path('temp/extra/' . $file->file_name);
        $fileContents  = Storage::disk('s3')->get($filePath);
        file_put_contents($tempFilePath, $fileContents);

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
                        $value         = $row[$index];
                        $record[$header] = is_numeric($value) ? (float) $value : $value;
                    }
                }
                if (isset($record['course_freq_offset']) && isset($record['clearance_freq_offset'])) {
                    $record['joint_sum_freq_offset'] = ($record['course_freq_offset'] + $record['clearance_freq_offset']) / 2;
                }
                $data[] = $record;
            }
            $count++;
        }

        fclose($fileHandle);
        if (file_exists($tempFilePath)) unlink($tempFilePath);

        return response()->json($data);
    }

    // ---------------------------------------------------------------
    // Configuration / tolerance helpers
    // ---------------------------------------------------------------

    private function defineAllParameters(): array
    {
        return [
            'joint_sum_DDM'          => ['color' => '#2196F3', 'text' => 'Joint Sum'],
            'clearance_DDM'          => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_DDM'             => ['color' => '#4CAF50', 'text' => 'Course'],
            'joint_sum_DDM_ua'       => ['color' => '#2196F3', 'text' => 'Joint Sum'],
            'clearance_DDM_ua'       => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_DDM_ua'          => ['color' => '#4CAF50', 'text' => 'Course'],
            'joint_sum_SDM'          => ['color' => '#2196F3', 'text' => 'Joint Sum'],
            'clearance_SDM'          => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_SDM'             => ['color' => '#4CAF50', 'text' => 'Course'],
            'joint_sum_field_strength'  => ['color' => '#2196F3', 'text' => 'Joint Sum'],
            'course_field_strength'     => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_field_strength'  => ['color' => '#FF9800', 'text' => 'Clearance'],
            'joint_sum_freq_offset'     => ['color' => '#2196F3', 'text' => 'Joint Sum'],
            'course_freq_offset'        => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_freq_offset'     => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_f150'            => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_f150'         => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_f90'             => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_f90'          => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_m150'            => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_m150'         => ['color' => '#FF9800', 'text' => 'Clearance'],
            'course_m90'             => ['color' => '#4CAF50', 'text' => 'Course'],
            'clearance_m90'          => ['color' => '#FF9800', 'text' => 'Clearance'],
            'DDM_error'              => ['color' => '#2196F3', 'text' => 'DDM Error'],
        ];
    }

    private function getConfigurationByTaskType(int $taskTypeId, ?Ils $ils, int $operationTypeId): array
    {
        $isLOC     = in_array($taskTypeId, self::$LOC_TASK_IDS);
        $isGP      = in_array($taskTypeId, self::$GP_TASK_IDS);
        $baseConfig = $this->getBaseConfigByTaskType($taskTypeId, $ils);
        $this->addSeparatedTolerancesToConfig($baseConfig, $taskTypeId, $ils, $isLOC, $isGP);
        return $baseConfig;
    }

    private function addSeparatedTolerancesToConfig(array &$baseConfig, int $taskTypeId, ?Ils $ils, bool $isLOC, bool $isGP): void
    {
        $baseConfig['toleranceConfigs'] = [];

        if ($isLOC) {
            $this->applyToleranceConfig($baseConfig, 'DDM',   $this->getLOCDDMToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'RF',    $this->getLOCRFToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'SDM',   $this->getLOCSDMToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'DDMUA', $this->getLOCDDMUAToleranceConfig($taskTypeId, $ils));
        }

        if ($isGP) {
            $this->applyToleranceConfig($baseConfig, 'DDM',   $this->getGPDDMToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'RF',    $this->getGPRFToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'SDM',   $this->getGPSDMToleranceConfig($taskTypeId, $ils));
            $this->applyToleranceConfig($baseConfig, 'DDMUA', $this->getGPDDMUAToleranceConfig($taskTypeId, $ils));
        }

        if (isset($baseConfig['toleranceConfigs']['DDM'])) {
            $baseConfig['limitLines'] = $baseConfig['toleranceConfigs']['DDM'];
        }

        Log::info('Tolerance config generated', [
            'taskTypeId'      => $taskTypeId,
            'isLOC'           => $isLOC,
            'isGP'            => $isGP,
            'toleranceConfigs' => array_keys($baseConfig['toleranceConfigs']),
            'DDM_lines'       => isset($baseConfig['toleranceConfigs']['DDM']) ? count($baseConfig['toleranceConfigs']['DDM']) : 0,
            'RF_lines'        => isset($baseConfig['toleranceConfigs']['RF'])  ? count($baseConfig['toleranceConfigs']['RF'])  : 0,
        ]);
    }

    private function applyToleranceConfig(array &$baseConfig, string $key, array $config): void
    {
        if (!empty($config['topLine']) && !empty($config['bottomLine'])) {
            $baseConfig['toleranceConfigs'][$key] = [
                ['color' => 'red', 'points' => $config['topLine']],
                ['color' => 'red', 'points' => $config['bottomLine']],
            ];
        }
    }

    private function getLOCDDMToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        $topLine = []; $bottomLine = [];

        switch ($taskTypeId) {
            case 8: case 15:
                switch ($ils->category_id ?? 1) {
                    case 1:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 0.03125], ['x' => $ils->point_b ?? 1050, 'y' => 0.0153], ['x' => $ils->point_c ?? 100, 'y' => 0.0153]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -0.03125], ['x' => $ils->point_b ?? 1050, 'y' => -0.0153], ['x' => $ils->point_c ?? 100, 'y' => -0.0153]];
                        break;
                    case 2:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 0.03125], ['x' => $ils->point_b ?? 1050, 'y' => 0.005], ['x' => $ils->point_d ?? -900, 'y' => 0.005]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -0.03125], ['x' => $ils->point_b ?? 1050, 'y' => -0.005], ['x' => $ils->point_d ?? -900, 'y' => -0.005]];
                        break;
                    case 3:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 0.03125], ['x' => $ils->point_b ?? 1050, 'y' => 0.005], ['x' => $ils->point_c ?? 100, 'y' => 0.005], ['x' => $ils->point_e ?? -3000, 'y' => 0.010]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -0.03125], ['x' => $ils->point_b ?? 1050, 'y' => -0.005], ['x' => $ils->point_c ?? 100, 'y' => -0.005], ['x' => $ils->point_e ?? -3000, 'y' => -0.010]];
                        break;
                }
                break;
            case 9: case 12: case 13: case 14:
                $topLine    = [['x' => -15, 'y' => 0.155], ['x' => 15, 'y' => 0.155]];
                $bottomLine = [['x' => -15, 'y' => -0.155], ['x' => 15, 'y' => -0.155]];
                break;
            case 42:
                [$topVal, $botVal] = match ($ils->category_id ?? 1) { 2 => [0.01085, -0.01085], 3 => [0.010, -0.010], default => [0.01519, -0.01519] };
                $topLine    = [['x' => 0, 'y' => $topVal], ['x' => 200, 'y' => $topVal]];
                $bottomLine = [['x' => 0, 'y' => $botVal], ['x' => 200, 'y' => $botVal]];
                break;
        }

        return ['topLine' => $topLine, 'bottomLine' => $bottomLine];
    }

    private function getLOCDDMUAToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        $topLine = []; $bottomLine = [];

        switch ($taskTypeId) {
            case 8: case 15:
                switch ($ils->category_id ?? 1) {
                    case 1:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 30], ['x' => $ils->point_b ?? 1050, 'y' => 15], ['x' => $ils->point_c ?? 100, 'y' => 15]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -30], ['x' => $ils->point_b ?? 1050, 'y' => -15], ['x' => $ils->point_c ?? 100, 'y' => -15]];
                        break;
                    case 2:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 30], ['x' => $ils->point_b ?? 1050, 'y' => 5], ['x' => $ils->point_d ?? -900, 'y' => 5]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -30], ['x' => $ils->point_b ?? 1050, 'y' => -5], ['x' => $ils->point_d ?? -900, 'y' => -5]];
                        break;
                    case 3:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 30], ['x' => $ils->point_b ?? 1050, 'y' => 5], ['x' => $ils->point_d ?? -900, 'y' => 5], ['x' => $ils->point_e ?? -3000, 'y' => 10]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -30], ['x' => $ils->point_b ?? 1050, 'y' => -5], ['x' => $ils->point_c ?? 100, 'y' => -5], ['x' => $ils->point_e ?? -3000, 'y' => -10]];
                        break;
                }
                break;
            case 42:
                [$topVal, $botVal] = match ($ils->category_id ?? 1) { 2 => [10.5, -10.5], 3 => [4.2, -4.2], default => [14.7, -14.7] };
                $topLine    = [['x' => 0, 'y' => $topVal], ['x' => 200, 'y' => $topVal]];
                $bottomLine = [['x' => 0, 'y' => $botVal], ['x' => 200, 'y' => $botVal]];
                break;
        }

        return ['topLine' => $topLine, 'bottomLine' => $bottomLine];
    }

    private function getLOCRFToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        if (!in_array($taskTypeId, self::$LOC_TASK_IDS)) return ['topLine' => [], 'bottomLine' => []];
        return [
            'topLine'    => [['x' => $ils->point_e ?? -3000, 'y' => -10], ['x' => $ils->point_a ?? 7408, 'y' => -10]],
            'bottomLine' => [['x' => $ils->point_e ?? -3000, 'y' => -80], ['x' => $ils->point_a ?? 7408, 'y' => -80]],
        ];
    }

    private function getLOCSDMToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        if (!in_array($taskTypeId, self::$LOC_TASK_IDS)) return ['topLine' => [], 'bottomLine' => []];
        return [
            'topLine'    => [['x' => $ils->point_e ?? -3000, 'y' => 44], ['x' => $ils->point_a ?? 7408, 'y' => 44]],
            'bottomLine' => [['x' => $ils->point_e ?? -3000, 'y' => 36], ['x' => $ils->point_a ?? 7408, 'y' => 36]],
        ];
    }

    private function getGPDDMToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        $topLine = []; $bottomLine = [];

        switch ($taskTypeId) {
            case 17: case 19: case 20: case 24:
                switch ($ils->category_id ?? 1) {
                    case 1:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 0.035], ['x' => $ils->point_c ?? 100, 'y' => 0.035]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -0.035], ['x' => $ils->point_c ?? 100, 'y' => -0.035]];
                        break;
                    case 2: case 3:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 0.035], ['x' => $ils->point_b ?? 1050, 'y' => 0.023], ['x' => 0, 'y' => 0.023]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -0.035], ['x' => $ils->point_b ?? 1050, 'y' => -0.023], ['x' => 0, 'y' => -0.023]];
                        break;
                }
                break;
            case 18: case 21: case 22:
                $topLine    = [['x' => -2, 'y' => 0.0875], ['x' => 10, 'y' => 0.0875]];
                $bottomLine = [['x' => -2, 'y' => -0.0875], ['x' => 10, 'y' => -0.0875]];
                break;
            case 23:
                [$topVal, $botVal] = match ($ils->category_id ?? 1) { 2 => [0.01085, -0.01085], 3 => [0.010, -0.010], default => [0.01519, -0.01519] };
                $topLine    = [['x' => 0, 'y' => $topVal], ['x' => 200, 'y' => $topVal]];
                $bottomLine = [['x' => 0, 'y' => $botVal], ['x' => 200, 'y' => $botVal]];
                break;
        }

        return ['topLine' => $topLine, 'bottomLine' => $bottomLine];
    }

    private function getGPDDMUAToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        $topLine = []; $bottomLine = [];

        switch ($taskTypeId) {
            case 17: case 19: case 20: case 24:
                switch ($ils->category_id ?? 1) {
                    case 1:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 30], ['x' => $ils->point_c ?? 100, 'y' => 30]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -30], ['x' => $ils->point_c ?? 100, 'y' => -30]];
                        break;
                    case 2: case 3:
                        $topLine    = [['x' => $ils->point_a ?? 7408, 'y' => 30], ['x' => $ils->point_b ?? 1050, 'y' => 20], ['x' => 0, 'y' => 20]];
                        $bottomLine = [['x' => $ils->point_a ?? 7408, 'y' => -30], ['x' => $ils->point_b ?? 1050, 'y' => -20], ['x' => 0, 'y' => -20]];
                        break;
                }
                break;
            case 18: case 21: case 22:
                $topLine    = [['x' => -2, 'y' => 0.0875], ['x' => 10, 'y' => 0.0875]];
                $bottomLine = [['x' => -2, 'y' => -0.0875], ['x' => 10, 'y' => -0.0875]];
                break;
            case 23:
                [$topVal, $botVal] = match ($ils->category_id ?? 1) { 2 => [10.5, -10.5], 3 => [4.2, -4.2], default => [14.7, -14.7] };
                $topLine    = [['x' => 0, 'y' => $topVal], ['x' => 200, 'y' => $topVal]];
                $bottomLine = [['x' => 0, 'y' => $botVal], ['x' => 200, 'y' => $botVal]];
                break;
        }

        return ['topLine' => $topLine, 'bottomLine' => $bottomLine];
    }

    private function getGPRFToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        if (!in_array($taskTypeId, self::$GP_TASK_IDS)) return ['topLine' => [], 'bottomLine' => []];
        return [
            'topLine'    => [['x' => $ils->point_e ?? -3000, 'y' => -10], ['x' => $ils->point_a ?? 7408, 'y' => -10]],
            'bottomLine' => [['x' => $ils->point_e ?? -3000, 'y' => -80], ['x' => $ils->point_a ?? 7408, 'y' => -80]],
        ];
    }

    private function getGPSDMToleranceConfig(int $taskTypeId, ?Ils $ils): array
    {
        if (!in_array($taskTypeId, self::$GP_TASK_IDS)) return ['topLine' => [], 'bottomLine' => []];
        return [
            'topLine'    => [['x' => $ils->point_e ?? -3000, 'y' => 85], ['x' => $ils->point_a ?? 7408, 'y' => 85]],
            'bottomLine' => [['x' => $ils->point_e ?? -3000, 'y' => 75], ['x' => $ils->point_a ?? 7408, 'y' => 75]],
        ];
    }

    private function getBaseConfigByTaskType(int $taskTypeId, ?Ils $ils): array
    {
        $pE = $ils->point_e ?? -3000; $pD = $ils->point_d ?? -900;
        $pC = $ils->point_c ?? 100;   $pB = $ils->point_b ?? 1050;
        $pA = $ils->point_a ?? 7408;  $gp = $ils->gp_angle ?? 3;

        $structureConfig = [
            'xMin' => $pE, 'xMax' => $pA, 'yMin' => -0.4, 'yMax' => 0.4,
            'xText' => 'Distance (m)', 'yText' => 'DDM',
            'verticalLines' => [0, $pD, $pC, $pB],
        ];
        $gpStructureConfig = [
            'xMin' => $pD, 'xMax' => $pA, 'yMin' => -0.4, 'yMax' => 0.4,
            'xText' => 'Distance (m)', 'yText' => 'DDM',
            'verticalLines' => [0, $pD, $pC, $pB],
        ];
        $widthLocConfig = ['xMin' => -15, 'xMax' => 15, 'yMin' => -2, 'yMax' => 2, 'xText' => 'Angle (°)', 'yText' => 'DDM', 'verticalLines' => [0]];
        $widthGpConfig  = ['xMin' => 1, 'xMax' => 8, 'yMin' => -2, 'yMax' => 2, 'xText' => 'Angle (°)', 'yText' => 'DDM', 'verticalLines' => [$gp]];
        $fluctConfig    = ['xMin' => 0, 'xMax' => 200, 'yMin' => -0.4, 'yMax' => 0.4, 'xText' => 'Time (s)', 'yText' => 'DDM'];

        $configs = [
            8  => $structureConfig, 10 => $structureConfig, 11 => $structureConfig, 15 => $structureConfig,
            17 => $gpStructureConfig, 19 => $gpStructureConfig, 20 => $gpStructureConfig, 24 => $gpStructureConfig,
            9  => $widthLocConfig, 12 => $widthLocConfig, 13 => $widthLocConfig,
            18 => $widthGpConfig, 21 => $widthGpConfig, 22 => $widthGpConfig,
            42 => $fluctConfig, 23 => array_merge($fluctConfig, ['xText' => 'Time']),
            14 => ['xMin' => -40, 'xMax' => 40, 'yMin' => -0.5, 'yMax' => 0.5, 'xText' => 'Angle (°)', 'yText' => 'DDM', 'verticalLines' => [0, 10, -10, 35, -35]],
            44 => ['xMin' => -12, 'xMax' => 12, 'yMin' => -1, 'yMax' => 0.4, 'xText' => 'Angle (°)', 'yText' => 'DDM'],
        ];

        return $configs[$taskTypeId] ?? ['xMin' => -1000, 'xMax' => 7000, 'yMin' => -0.4, 'yMax' => 0.4, 'xText' => 'Distance (m)', 'yText' => 'DDM', 'verticalLines' => []];
    }

    // ---------------------------------------------------------------
    // Chart info params
    // ---------------------------------------------------------------

    private function getChartInfoParams(int $taskTypeId, ?int $idfile = null, $resultsIls = null): array
    {
        $calculatedValues = array_fill_keys([
            'ddm_avg', 'ddm_max', 'ddm_min', 'ddm_max_absolute', 'ddm_avg_ua', 'sdm_avg',
            'rf_pwr', 'flight_dist', 'flight_time', 'avg_speed', 'mod_90', 'mod_150',
            'freq_offset', 'ang_150', 'ang_neg_150', 'ang_150_ua', 'ang_neg_150_ua', 'width',
            'sens_disp', 'zero_ddm_ang', 'freq_sep', 'avg_ang', 'left_min_ang', 'right_max_ang',
            'left_min_ddm', 'right_max_ddm',
        ], 'N/A');

        if ($resultsIls !== null && !in_array($taskTypeId, [15, 24])) {
            $this->calculateValuesFromResults($taskTypeId, $calculatedValues, $resultsIls, $idfile);
        } elseif ($idfile) {
            $this->calculateValues($idfile, $calculatedValues, $taskTypeId, $resultsIls);
        }

        $paramSets = $this->defineParamSets($calculatedValues);

        $taskTypeToParamSet = [
            8 => 'basicDdm', 10 => 'basicDdm', 11 => 'basicDdm',
            9 => 'angularMeasurements', 12 => 'angularMeasurements', 13 => 'angularMeasurements',
            14 => 'leftRightAngles', 15 => 'basicDdmRawDataLoc',
            17 => 'modulation', 19 => 'modulation', 20 => 'modulation', 24 => 'modulation',
            18 => 'angularGpMeasurements', 21 => 'angularGpMeasurements', 22 => 'angularGpMeasurements',
            23 => 'basicWithMax', 42 => 'basicWithMax', 44 => 'ddmOnly',
        ];

        return $paramSets[$taskTypeToParamSet[$taskTypeId] ?? 'default'];
    }

    private function calculateValuesFromResults(int $taskTypeId, array &$cv, $resultsIls, ?int $idfile): void
    {
        if (!$resultsIls || $resultsIls->isEmpty()) return;
        $data = $this->getDataForCalculations($idfile);
        if (empty($data)) return;

        $r = $resultsIls->first();

        $cv['ddm_avg']     = round($r->average_ddm, 4)    ?? 'N/A';
        $cv['ddm_max']     = $r->max_ddm ?? 'N/A';
        $cv['ddm_min']     = $r->min_ddm ?? 'N/A';
        $cv['avg_ang']     = round($r->mean_glide_path_angle, 2) ?? 'N/A';
        $cv['ddm_avg_ua']  = round($r->average_ddm_ua, 4)  ?? 'N/A';
        $cv['sdm_avg']     = isset($r->average_sdm) ? round($r->average_sdm * 100, 2) : 'N/A';
        $cv['rf_pwr']      = $r->mean_rf_level !== null ? round($r->mean_rf_level, 2) . ' dB' : 'N/A';
        $cv['flight_dist'] = $r->flight_distance !== null ? round($r->flight_distance, 2) : 'N/A';
        $cv['mod_90']      = $r->mean_mod90  !== null ? round($r->mean_mod90  * 100, 2) . ' %' : 'N/A';
        $cv['mod_150']     = $r->mean_mod150 !== null ? round($r->mean_mod150 * 100, 2) . ' %' : 'N/A';
        $cv['freq_offset'] = $r->mean_freq_offset !== null ? round($r->mean_freq_offset, 2) . ' Hz' : 'N/A';
        $cv['freq_sep']    = $r->mean_freq_sep    !== null ? round($r->mean_freq_sep,    2) . ' Hz' : 'N/A';
        $cv['ang_150']        = $r->angle_left150  !== null ? round($r->angle_left150,  2) : 'N/A';
        $cv['ang_neg_150']    = $r->angle_right150 !== null ? round($r->angle_right150, 2) : 'N/A';
        $cv['ang_150_ua']     = $r->angle_high150  !== null ? round($r->angle_high150,  2) : 'N/A';
        $cv['ang_neg_150_ua'] = $r->angle_low150   !== null ? round($r->angle_low150,   2) : 'N/A';
        $cv['sens_disp']   = $r->sensitivity_displacement !== null ? round($r->sensitivity_displacement, 5) : 'N/A';
        $cv['zero_ddm_ang']  = $r->angle_zero_cross !== null ? round($r->angle_zero_cross, 2) . '°' : 'N/A';
        $cv['left_min_ang']  = $r->clearance_angle_left  !== null ? round($r->clearance_angle_left,  2) . '°' : 'N/A';
        $cv['right_max_ang'] = $r->clearance_angle_right !== null ? round($r->clearance_angle_right, 2) . '°' : 'N/A';
        $cv['left_min_ddm']  = $r->clearance_ddm_left  !== null ? round($r->clearance_ddm_left,  4) : 'N/A';
        $cv['right_max_ddm'] = $r->clearance_ddm_right !== null ? round($r->clearance_ddm_right, 4) : 'N/A';

        // ddm_max_absolute
        if ($r->max_ddm !== null && $r->min_ddm !== null) {
            $cv['ddm_max_absolute'] = round(abs($r->max_ddm) >= abs($r->min_ddm) ? $r->max_ddm : $r->min_ddm, 4);
        } elseif ($r->max_ddm !== null) {
            $cv['ddm_max_absolute'] = round($r->max_ddm, 4);
        } elseif ($r->min_ddm !== null) {
            $cv['ddm_max_absolute'] = round($r->min_ddm, 4);
        }

        // flight_time + avg_speed
        if ($r->flight_time !== null) {
            $totalSeconds     = round($r->flight_time / 1000);
            $cv['flight_time'] = sprintf('%02d:%02d', floor($totalSeconds / 60), $totalSeconds % 60);
            if ($cv['flight_dist'] !== 'N/A' && $totalSeconds > 0) {
                $cv['avg_speed'] = round($cv['flight_dist'] / $totalSeconds, 2) . ' m/s';
            }
        }

        // width
        $isLOC = in_array($taskTypeId, self::$LOC_TASK_IDS);
        if ($isLOC) {
            if ($cv['ang_neg_150'] !== 'N/A' && $cv['ang_150'] !== 'N/A') {
                $cv['width'] = round(abs($cv['ang_neg_150']) + abs($cv['ang_150']), 2);
            }
        } else {
            if ($cv['ang_neg_150_ua'] !== 'N/A' && $cv['ang_150_ua'] !== 'N/A') {
                $cv['width'] = round(abs(abs($cv['ang_neg_150_ua']) - abs($cv['ang_150_ua'])), 2);
            }
        }
    }

    private function calculateValues(int $idfile, array &$cv, int $taskTypeId, $resultsIls): void
    {
        $result = $resultsIls ? $resultsIls->first() : null;
        $data   = $this->getDataForCalculations($idfile);
        if (empty($data)) return;

        $cv['avg_ang'] = $result ? round($result->mean_glide_path_angle, 2) : 'N/A';

        $ddmValues = array_column($data, 'joint_sum_DDM');
        if (!empty($ddmValues)) {
            $cv['ddm_avg'] = round(array_sum($ddmValues) / count($ddmValues), 4);
            $cv['ddm_max'] = round(max($ddmValues), 4);
            $cv['ddm_min'] = round(min($ddmValues), 4);
            $maxAbs = $ddmValues[0];
            foreach ($ddmValues as $v) { if (abs($v) > abs($maxAbs)) $maxAbs = $v; }
            $cv['ddm_max_absolute'] = round($maxAbs, 4);
        }

        $this->calcMeanPercent($data, 'course_m90',  $cv, 'mod_90');
        $this->calcMeanPercent($data, 'course_m150', $cv, 'mod_150');

        $sdmValues = array_column($data, 'joint_sum_SDM');
        if (!empty($sdmValues)) $cv['sdm_avg'] = round(array_sum($sdmValues) / count($sdmValues) * 100, 2);

        $rfValues = array_column($data, 'joint_sum_field_strength');
        if (!empty($rfValues)) $cv['rf_pwr'] = round(array_sum($rfValues) / count($rfValues), 2);

        $ddmUaValues = array_column($data, 'joint_sum_DDM_ua');
        if (!empty($ddmUaValues)) $cv['ddm_avg_ua'] = round(array_sum($ddmUaValues) / count($ddmUaValues), 4);

        $courseFO    = array_column($data, 'course_freq_offset');
        $clearanceFO = array_column($data, 'clearance_freq_offset');
        if (!empty($courseFO) || !empty($clearanceFO)) {
            $cMean = !empty($courseFO)    ? array_sum($courseFO)    / count($courseFO)    : 0;
            $lMean = !empty($clearanceFO) ? array_sum($clearanceFO) / count($clearanceFO) : 0;
            $cv['freq_offset'] = round(($cMean + $lMean) / 2, 2);
        }

        $freqSep = array_column($data, 'joint_freq_separation');
        if (!empty($freqSep)) $cv['freq_sep'] = round(array_sum($freqSep) / count($freqSep), 2);

        if ($taskTypeId == 15) {
            $positions = array_column($data, 'position');
            if (!empty($positions)) $cv['flight_dist'] = round(max($positions) - min($positions), 2);
        } else {
            $cv['flight_dist'] = $this->calculateFlightDistance($data);
        }

        $cv['flight_time'] = $this->calculateFlightTime($data);

        if ($cv['flight_dist'] !== 'N/A' && $cv['flight_time'] !== 'N/A') {
            $parts     = explode(':', $cv['flight_time']);
            $totalSecs = ($parts[0] * 60) + $parts[1];
            if ($totalSecs > 0) {
                $cv['avg_speed'] = round(floatval(str_replace(',', '.', $cv['flight_dist'])) / $totalSecs, 2) . ' m/s';
            }
        }

        $positionDdmPairs = [];
        foreach ($data as $row) {
            if (isset($row['position'], $row['joint_sum_DDM'])) {
                $positionDdmPairs[] = ['position' => $row['position'], 'ddm' => $row['joint_sum_DDM']];
            }
        }

        $cv['ang_150']     = $this->interpolateAngleAtDdm($positionDdmPairs, 0.155)  ?? 'N/A';
        $cv['ang_neg_150'] = $this->interpolateAngleAtDdm($positionDdmPairs, -0.155) ?? 'N/A';

        if ($cv['ang_150'] !== 'N/A' && $cv['ang_neg_150'] !== 'N/A') {
            $pos = floatval(str_replace('°', '', $cv['ang_150']));
            $neg = floatval(str_replace('°', '', $cv['ang_neg_150']));
            $cv['width']    = round(abs($pos) + abs($neg), 2) . '°';
            $cv['sens_disp'] = $this->calcSensDisp($idfile, $pos, $neg);
        }

        $cv['zero_ddm_ang'] = $this->interpolateAngleAtDdm($positionDdmPairs, 0, true) ?? 'N/A';
    }

    // ---------------------------------------------------------------
    // Calculation helpers
    // ---------------------------------------------------------------

    private function calcMeanPercent(array $data, string $column, array &$cv, string $key): void
    {
        $values = array_column($data, $column);
        if (!empty($values)) {
            $cv[$key] = round(array_sum($values) / count($values) * 100, 2) . ' %';
        }
    }

    private function interpolateAngleAtDdm(array $pairs, float $targetDdm, bool $nearZero = false): ?string
    {
        $range = $nearZero ? [-0.05, 0.05] : ($targetDdm > 0 ? [0.140, 0.170] : [-0.170, -0.140]);
        $points = array_filter($pairs, fn($p) => $p['ddm'] >= $range[0] && $p['ddm'] <= $range[1]);

        if (count($points) < 2) return null;

        $x = array_column(array_values($points), 'ddm');
        $y = array_column(array_values($points), 'position');
        $n = count($x);

        $sumX  = array_sum($x); $sumY  = array_sum($y);
        $sumXY = 0; $sumX2 = 0;
        for ($i = 0; $i < $n; $i++) { $sumXY += $x[$i] * $y[$i]; $sumX2 += $x[$i] ** 2; }

        $denom = ($n * $sumX2) - ($sumX ** 2);
        if (abs($denom) < 1e-10) {
            return round($sumY / $n, 2) . '°';
        }

        $m = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $b = ($sumY - ($m * $sumX)) / $n;
        return round($m * $targetDdm + $b, 2) . '°';
    }

    private function calcSensDisp(int $idfile, float $leftAngle, float $rightAngle): mixed
    {
        $operationFile = OperationFiles::find($idfile);
        if (!$operationFile) return 'N/A';

        $header = Header::find($operationFile->task->operation->subject_id);
        $ils    = Ils::where('header_id', $header->id)->first();
        if (!$ils) return 'N/A';

        $distThrToLoc = ($ils->loc_antenna_latitude && $ils->loc_antenna_longitude && $ils->threshold_latitude && $ils->threshold_longitude)
            ? $this->calculateDistanceInMeters($ils->threshold_latitude, $ils->threshold_longitude, $ils->loc_antenna_latitude, $ils->loc_antenna_longitude)
            : ($ils->distance_thr_to_loc ?? 3000);

        $leftDist  = abs(tan(deg2rad($leftAngle))  * $distThrToLoc);
        $rightDist = abs(tan(deg2rad($rightAngle)) * $distThrToLoc);
        $distAtThr = $leftDist + $rightDist;

        return $distAtThr > 0 ? round((0.155 * 2) / $distAtThr, 5) : 'N/A';
    }

    private function defineParamSets(array $cv): array
    {
        $flight = [
            ['label' => 'Flight Dist', 'key' => 'flight_dist', 'value' => $cv['flight_dist'] . ' m'],
            ['label' => 'Flight time', 'key' => 'flight_time', 'value' => $cv['flight_time']],
            ['label' => 'Avg Speed',   'key' => 'avg_speed',   'value' => $cv['avg_speed']],
        ];
        $basicRf = [
            ['label' => 'SDM Avg', 'key' => 'sdm_avg', 'value' => $cv['sdm_avg']],
            ['label' => 'RF Pwr',  'key' => 'rf_pwr',  'value' => $cv['rf_pwr']],
        ];
        $extRf = array_merge($basicRf, [
            ['label' => 'DDM Avg [μA]', 'key' => 'ddm_avg_ua',  'value' => $cv['ddm_avg_ua']],
            ['label' => 'Freq Offset',  'key' => 'freq_offset',  'value' => $cv['freq_offset']],
            ['label' => 'Freq Sep',     'key' => 'freq_sep',     'value' => $cv['freq_sep']],
        ]);

        return [
            'default' => [
                'measurement' => [['label' => 'DDM Avg', 'key' => 'ddm_avg', 'value' => $cv['ddm_avg']]],
                'rf' => $basicRf, 'flight' => $flight,
            ],
            'basicDdm' => [
                'measurement' => [
                    ['label' => 'DDM Avg', 'key' => 'ddm_avg', 'value' => $cv['ddm_avg']],
                    ['label' => 'DDM Max', 'key' => 'ddm_max', 'value' => $cv['ddm_max_absolute']],
                    ['label' => 'Mod 90',  'key' => 'mod_90',  'value' => $cv['mod_90']],
                    ['label' => 'Mod 150', 'key' => 'mod_150', 'value' => $cv['mod_150']],
                ],
                'rf' => $extRf, 'flight' => $flight,
            ],
            'basicDdmRawDataLoc' => [
                'measurement' => [
                    ['label' => 'DDM Avg', 'key' => 'ddm_avg', 'value' => $cv['ddm_avg']],
                    ['label' => 'DDM Max', 'key' => 'ddm_max', 'value' => $cv['ddm_max_absolute']],
                    ['label' => 'Mod 90',  'key' => 'mod_90',  'value' => $cv['mod_90']],
                    ['label' => 'Mod 150', 'key' => 'mod_150', 'value' => $cv['mod_150']],
                ],
                'rf' => $extRf, 'flight' => $flight,
            ],
            'angularMeasurements' => [
                'measurement' => [
                    ['label' => 'Ang. 150μA',  'key' => 'ang_150',     'value' => $cv['ang_150']],
                    ['label' => 'Ang. -150μA', 'key' => 'ang_neg_150', 'value' => $cv['ang_neg_150']],
                    ['label' => 'Width',        'key' => 'width',       'value' => $cv['width']],
                    ['label' => 'Sens. Disp',   'key' => 'sens_disp',   'value' => $cv['sens_disp']],
                    ['label' => '0 DDM Ang',    'key' => 'zero_ddm_ang','value' => $cv['zero_ddm_ang']],
                ],
                'rf' => $basicRf, 'flight' => $flight,
            ],
            'leftRightAngles' => [
                'measurement' => [
                    ['label' => 'Left Min. Ang',  'key' => 'left_min_ang',  'value' => $cv['left_min_ang']],
                    ['label' => 'Left Min. DDM',  'key' => 'left_min_ddm',  'value' => $cv['left_min_ddm']],
                    ['label' => 'Width',           'key' => 'width',         'value' => $cv['width']],
                    ['label' => 'Right Max. Ang',  'key' => 'right_max_ang', 'value' => $cv['right_max_ang']],
                    ['label' => 'Right Max. DDM',  'key' => 'right_max_ddm', 'value' => $cv['right_max_ddm']],
                ],
                'rf' => $basicRf, 'flight' => $flight,
            ],
            'modulation' => [
                'measurement' => [
                    ['label' => 'DDM Avg', 'key' => 'ddm_avg',          'value' => $cv['ddm_avg']],
                    ['label' => 'DDM Max', 'key' => 'ddm_max',          'value' => $cv['ddm_max_absolute']],
                    ['label' => 'Avg Ang', 'key' => 'avg_ang',          'value' => $cv['avg_ang']],
                    ['label' => 'Mod 90',  'key' => 'mod_90',           'value' => $cv['mod_90']],
                    ['label' => 'Mod 150', 'key' => 'mod_150',          'value' => $cv['mod_150']],
                ],
                'rf' => $extRf, 'flight' => $flight,
            ],
            'angularGpMeasurements' => [
                'measurement' => [
                    ['label' => 'Ang. 150μA',  'key' => 'ang_150_ua',     'value' => $cv['ang_150_ua']],
                    ['label' => 'Ang. -150μA', 'key' => 'ang_neg_150_ua', 'value' => $cv['ang_neg_150_ua']],
                    ['label' => 'Width',        'key' => 'width',          'value' => $cv['width']],
                    ['label' => 'Sens. Disp',   'key' => 'sens_disp',      'value' => $cv['sens_disp']],
                    ['label' => '0 DDM Ang',    'key' => 'zero_ddm_ang',   'value' => $cv['zero_ddm_ang']],
                ],
                'rf' => [
                    ['label' => 'SDM Avg', 'key' => 'sdm_avg_ua', 'value' => $cv['sdm_avg']],
                    ['label' => 'RF Pwr',  'key' => 'rf_pwr',     'value' => $cv['rf_pwr']],
                ],
                'flight' => $flight,
            ],
            'basicWithMax' => [
                'measurement' => [
                    ['label' => 'DDM Avg', 'key' => 'ddm_avg', 'value' => $cv['ddm_avg']],
                    ['label' => 'DDM Max', 'key' => 'ddm_max', 'value' => $cv['ddm_max_absolute']],
                ],
                'rf' => [
                    ['label' => 'DDM Avg [μA]', 'key' => 'ddm_avg_ua', 'value' => $cv['ddm_avg_ua']],
                    ['label' => 'SDM Avg',       'key' => 'sdm_avg',    'value' => $cv['sdm_avg']],
                    ['label' => 'RF Pwr',         'key' => 'rf_pwr',     'value' => $cv['rf_pwr']],
                ],
                'flight' => $flight,
            ],
            'ddmOnly' => [
                'measurement' => [['label' => 'DDM Avg', 'key' => 'ddm_avg', 'value' => $cv['ddm_avg']]],
                'rf' => [
                    ['label' => 'DDM Avg [μA]', 'key' => 'ddm_avg_ua', 'value' => $cv['ddm_avg_ua']],
                    ['label' => 'SDM Avg',       'key' => 'sdm_avg',    'value' => $cv['sdm_avg']],
                    ['label' => 'RF Pwr',         'key' => 'rf_pwr',     'value' => $cv['rf_pwr']],
                ],
                'flight' => $flight,
            ],
        ];
    }

    private function getDataForCalculations(int $idfile): array
    {
        $file = OperationFiles::find($idfile);
        if (!$file) return [];

        $folderMapping = Operation::getFolderMapping();
        $folderType    = $folderMapping[$file->task->operation->type_id] ?? null;
        $filePath      = "$folderType/{$file->task->operation->id}/{$file->task->id}/{$file->file_name}";

        if (!Storage::disk('s3')->exists($filePath)) return [];

        $tempFilePath = public_path('temp/extra/' . $file->file_name);
        if (!file_exists(dirname($tempFilePath))) mkdir(dirname($tempFilePath), 0755, true);

        file_put_contents($tempFilePath, Storage::disk('s3')->get($filePath));
        if (!file_exists($tempFilePath)) return [];

        $fileHandle = fopen($tempFilePath, 'r');
        $headers    = fgetcsv($fileHandle);
        $data       = [];

        while (($row = fgetcsv($fileHandle)) !== false) {
            $record = [];
            foreach ($headers as $index => $header) {
                if (isset($row[$index])) {
                    $v = $row[$index];
                    $record[$header] = is_numeric($v) ? (float) $v : $v;
                }
            }
            if (isset($record['course_freq_offset'], $record['clearance_freq_offset'])) {
                $record['joint_sum_freq_offset'] = ($record['course_freq_offset'] + $record['clearance_freq_offset']) / 2;
            }
            $data[] = $record;
        }

        fclose($fileHandle);
        if (file_exists($tempFilePath)) unlink($tempFilePath);

        return $data;
    }

    private function calculateDistanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function calculateFlightDistance(array $data): mixed
    {
        if (count($data) < 2) return 'N/A';
        $lat1 = $data[0]['latitude']; $lon1 = $data[0]['longitude'];
        $max  = 0;
        for ($i = 1; $i < count($data); $i++) {
            $d = $this->calculateDistanceInMeters($lat1, $lon1, $data[$i]['latitude'], $data[$i]['longitude']);
            if ($d > $max) $max = $d;
        }
        return round($max, 2);
    }

    private function calculateFlightTime(array $data): mixed
    {
        if (empty($data)) return 'N/A';
        $timestamps = array_column($data, 'timestamp');
        if (empty($timestamps)) return 'N/A';

        $min  = $this->convertToMilliseconds(min($timestamps));
        $max  = $this->convertToMilliseconds(max($timestamps));
        $secs = round(($max - $min) / 1000);
        return sprintf('%02d:%02d', floor($secs / 60), $secs % 60);
    }

    private function convertToMilliseconds(mixed $time): float
    {
        if (is_numeric($time)) return (float) $time;
        if (strpos($time, ':') !== false) return $this->timeToMilliseconds($time);
        return 0;
    }

    private function timeToMilliseconds(string $time): float
    {
        $parts = explode(':', $time);
        return match (count($parts)) {
            2 => intval($parts[0]) * 60000 + intval($parts[1]) * 1000,
            3 => intval($parts[0]) * 3600000 + intval($parts[1]) * 60000 + intval($parts[2]) * 1000,
            4 => intval($parts[0]) * 3600000 + intval($parts[1]) * 60000 + intval($parts[2]) * 1000 + intval($parts[3]),
            default => 0,
        };
    }
}
