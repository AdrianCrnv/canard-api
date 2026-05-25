<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class PapiReport extends Report {

    use HasFactory;

    public function generate(Operation $operation, $language, $angle_unit, $stream = true){ // If "stream", show the PDF directly. Else, return URL
        $details = $this->get_operation_details($operation, $language);
        $details['title'] = trans('report_papi.title', [], $language);
        $operator = Operator::where('id', $operation['operator_id'])->get();

        // Get the latest results for every task of the operation
        $results = [];

        foreach ($operation->tasks as $task) {
            switch ($task->type_id) {
                case 2:
                    // Check if the results are for a PAPI in the left or the right side
                    if($task->getPapi()->side_id == 1){ // Left PAPI
                        $results['unit_location']['left'] = $task->resultsPapiUnitLocation();
                    } else { // Right PAPI
                        $results['unit_location']['right'] = $task->resultsPapiUnitLocation();
                    }
                    break;

                case 5:
                    // Check if the results are for a PAPI in the left or the right side
                    if($task->getPapi()->side_id == 1){ // Left PAPI
                        $results['vertical_angle']['left'] = $task->resultsPapiVerticalAngle()->where('is_valid_set', 1);
                    } else { // Right PAPI
                        $results['vertical_angle']['right'] = $task->resultsPapiVerticalAngle()->where('is_valid_set', 1);
                    }
                    break;

                case 6:
                    $results['angular_coverage'] = $task->resultsPapiAngularCoverage()->where('is_valid_set', 1);
                    break;
            }
        }


        // Initialize the report data
        $reportData = [
            'equipment' => $operation->subject()->papis()->first()->type->name,
            'thr_lat' => $operation->subject()->threshold_latitude,
            'thr_lon' => $operation->subject()->threshold_longitude,
            'thr_elevation' => round($operation->subject()->threshold_elevation, 1),
            'published_angle' => $operation->subject()->papis()->first()->glide_path_angle,
            'angle_left_a' => null,
            'angle_left_b' => null,
            'angle_left_c' => null,
            'angle_left_d' => null,
            'angle_right_a' => null,
            'angle_right_b' => null,
            'angle_right_c' => null,
            'angle_right_d' => null,
            'angle_left_a_sa' => null,
            'angle_left_b_sa' => null,
            'angle_left_c_sa' => null,
            'angle_left_d_sa' => null,
            'angle_right_a_sa' => null,
            'angle_right_b_sa' => null,
            'angle_right_c_sa' => null,
            'angle_right_d_sa' => null,
            'system_angle_left' => null,
            'system_angle_right' => null,
            'system_angle_left_sa' => null,
            'system_angle_right_sa' => null,
            'horizontality_left_a' => null,
            'horizontality_left_b' => null,
            'horizontality_left_c' => null,
            'horizontality_left_d' => null,
            'horizontality_left' => null,
            'horizontality_right' => null,
            'horizontality_right_a' => null,
            'horizontality_right_b' => null,
            'horizontality_right_c' => null,
            'horizontality_right_d' => null,
            'meht' => null,
            'angular_coverage_left_min' => null,
            'angular_coverage_left_max' => null,
            'angular_coverage_right_min' => null,
            'angular_coverage_right_max' => null,
            'angular_coverage_left_min_sa' => null,
            'angular_coverage_left_max_sa' => null,
            'angular_coverage_right_min_sa' => null,
            'angular_coverage_right_max_sa' => null,
        ];


        // Calculate the mean angles for each light on the LEFT side

        if($results['vertical_angle'] && isset($results['vertical_angle']['left']) && $results['vertical_angle']['left']->count()){
            foreach ($results['vertical_angle']['left'] as $result) {
                switch ($result->light->position_id) {
                    case 1: // A
                        $reportData['angle_left_a'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_left_a_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 2: // B
                        $reportData['angle_left_b'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_left_b_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 3: // C
                        $reportData['angle_left_c'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_left_c_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 4: // D
                        $reportData['angle_left_d'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_left_d_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                }
            }
        }

        // Calculate the mean angles for each light on the RIGHT side
        if($results['vertical_angle'] && isset($results['vertical_angle']['right']) && $results['vertical_angle']['right']->count()){
            foreach ($results['vertical_angle']['right'] as $result) {
                switch ($result->light->position_id) {
                    case 1: // A
                        $reportData['angle_right_a'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_right_a_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 2: // B
                        $reportData['angle_right_b'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_right_b_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 3: // C
                        $reportData['angle_right_c'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_right_c_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                    case 4: // D
                        $reportData['angle_right_d'] = round(($result['mean_angle_high'] + $result['mean_angle_low']) / 2, 2);
                        $reportData['angle_right_d_sa'] = $this->convertToSexagesimal(($result['mean_angle_high'] + $result['mean_angle_low']) / 2);
                        break;
                }
            }
        }

        // Calculate the system angles
        if($reportData['equipment'] == 'PAPI'){
            // The system is a PAPI: use 4 lights (A, B, C, D) to calculate the system angle
            $reportData['system_angle_left'] = round(
                ($reportData['angle_left_b'] +
                $reportData['angle_left_c']) / 2, 2);

            $reportData['system_angle_right'] = round(
                ($reportData['angle_right_b'] +
                $reportData['angle_right_c']) / 2, 2);

            $reportData['system_angle_left_sa'] = $this->convertToSexagesimal(
                ($reportData['angle_left_b'] +
                $reportData['angle_left_c']) / 2);

            $reportData['system_angle_right_sa'] = $this->convertToSexagesimal(
                ($reportData['angle_right_b'] +
                $reportData['angle_right_c']) / 2);
        } else {
            // The system is an APAPI: use 2 lights (A, B) to calculate the system angle
            $reportData['system_angle_left'] = round(
                ($reportData['angle_left_a'] +
                $reportData['angle_left_b']) / 2, 2);

            $reportData['system_angle_right'] = round(
                ($reportData['angle_right_a'] +
                $reportData['angle_right_b']) / 2, 2);

            $reportData['system_angle_left_sa'] = $this->convertToSexagesimal(
                ($reportData['angle_left_a'] +
                $reportData['angle_left_b']) / 2);

            $reportData['system_angle_right_sa'] = $this->convertToSexagesimal(
                ($reportData['angle_right_a'] +
                $reportData['angle_right_b']) / 2);
        }

        // Get horizontality values for the LEFT side
        if($results['unit_location'] && isset($results['unit_location']['left']) && $results['unit_location']['left']->count()){
            foreach ($results['unit_location']['left'] as $result) {
                $reportData['horizontality_left_a'] = $result['distance_a'];
                $reportData['horizontality_left_b'] = $result['distance_b'];
                $reportData['horizontality_left_c'] = $result['distance_c'];
                $reportData['horizontality_left_d'] = $result['distance_d'];
                $reportData['horizontality_left'] = round($result['horizontality'], 2);
            }
        }

        // Get horizontality values for the RIGHT side
        if($results['unit_location'] && isset($results['unit_location']['right']) && $results['unit_location']['right']->count()){
            foreach ($results['unit_location']['right'] as $result) {
                $reportData['horizontality_right_a'] = $result['distance_a'];
                $reportData['horizontality_right_b'] = $result['distance_b'];
                $reportData['horizontality_right_c'] = $result['distance_c'];
                $reportData['horizontality_right_d'] = $result['distance_d'];
                $reportData['horizontality_right'] = round($result['horizontality'], 2);
            }
        }

        // 698-horizontality-results-papi: medias de horizontalidad por lado
        $valsLeft = array_filter([
            $reportData['horizontality_left_a'], $reportData['horizontality_left_b'],
            $reportData['horizontality_left_c'], $reportData['horizontality_left_d'],
        ], fn($v) => $v !== null);
        $reportData['horizontality_left_mean'] = count($valsLeft) ? round(array_sum(array_map('abs', $valsLeft)) / count($valsLeft), 4) : null;

        $valsRight = array_filter([
            $reportData['horizontality_right_a'], $reportData['horizontality_right_b'],
            $reportData['horizontality_right_c'], $reportData['horizontality_right_d'],
        ], fn($v) => $v !== null);
        $reportData['horizontality_right_mean'] = count($valsRight) ? round(array_sum(array_map('abs', $valsRight)) / count($valsRight), 4) : null;


        // Calculate MEHT
        $mehtValue = 0;
        $measurements = [];
        $mehtResult = null;

        if($results['unit_location'] && isset($results['unit_location']['left']) && $results['unit_location']['left']->count() && $results['unit_location']['left']->first()->is_location_valid){
            $mehtResult = $results['unit_location']['left']->first() ?? null;
            $measurements = $mehtResult->measurements;
        } else if($results['unit_location'] && isset($results['unit_location']['right']) && $results['unit_location']['right']->count() && $results['unit_location']['right']->first()->is_location_valid){
            $mehtResult = $results['unit_location']['right']->first() ?? null;
            $measurements = $mehtResult->measurements;
        }


        // Calculate average location
        $papiAvgLatitude = 0;
        $papiAvgLongitude = 0;
        $papiAvgElevation = 0;

        if($measurements){
            foreach ($measurements as $measurement) {
                $papiAvgLatitude += $measurement['latitude'];
                $papiAvgLongitude += $measurement['longitude'];
                $papiAvgElevation += $measurement['elevation'];
            }

            $papiAvgLatitude /= $measurements->count();
            $papiAvgLongitude /= $measurements->count();
            $papiAvgElevation /= $measurements->count();
        }

        $papiBearing = $this->getRhumbLineBearing(
            floatval($operation->subject()->threshold_latitude),
            floatval($operation->subject()->threshold_longitude),
            floatval($papiAvgLatitude),
            floatval($papiAvgLongitude));

        $angleToPapi = $operation->subject()->bearing - $papiBearing;


        $distanceToPapi = $this->vincentyGreatCircleDistance(
            floatval($operation->subject()->threshold_latitude),
            floatval($operation->subject()->threshold_longitude),
            floatval($papiAvgLatitude),
            floatval($papiAvgLongitude));

        $realDistance = cos(deg2rad($angleToPapi)) * $distanceToPapi;

        $angleMeht = 0;

        if($mehtResult){
            if ($reportData['equipment'] == 'PAPI') {
                if($mehtResult->papi->side_id == 1){ // Left
                    $angleMeht = $reportData['angle_left_b'];
                } else { // Right
                    $angleMeht = $reportData['angle_right_b'];
                }
            } else {
                if($mehtResult->papi->side_id == 1){ // Left
                    $angleMeht = $reportData['angle_left_a'];
                } else { // Right
                    $angleMeht = $reportData['angle_right_a'];
                }
            }
        }

        if ($angleMeht != 0) {
            $mehtValue = ($realDistance * tan(deg2rad(floatval($angleMeht - 0.03333))) + ($papiAvgElevation - $operation->subject()->threshold_elevation));
            $mehtValue = round($mehtValue, 2);
        }else{
            $mehtValue = 'NULL';
        }

        $reportData['meht'] = $mehtValue;


        // Get the angular coverage
        if($results['angular_coverage'] && isset($results['angular_coverage']) && $results['angular_coverage']->count()) {
            foreach ($results['angular_coverage'] as $result) {
                switch ($result['transition_type_id']) {
                    case 1: // LL
                        $reportData['angular_coverage_left_min'] = round($result['mean_angle'], 1);
                        $reportData['angular_coverage_left_min_sa'] = $this->convertToSexagesimal($result['mean_angle'], 1);
                        break;
                    case 2: // LR
                        $reportData['angular_coverage_left_max'] = round($result['mean_angle'], 1);
                        $reportData['angular_coverage_left_max_sa'] = $this->convertToSexagesimal($result['mean_angle'], 1);
                        break;
                    case 3: // RL
                        $reportData['angular_coverage_right_min'] = round($result['mean_angle'], 1);
                        $reportData['angular_coverage_right_min_sa'] = $this->convertToSexagesimal($result['mean_angle'], 1);
                        break;
                    case 4: // RR
                        $reportData['angular_coverage_right_max'] = round($result['mean_angle'], 1);
                        $reportData['angular_coverage_right_max_sa'] = $this->convertToSexagesimal($result['mean_angle'], 1);
                        break;
                }
            }
        }

        // Get visual inspection data
        $visualInspection = ResultPapiVisualInspection::where('operation_id', $operation->id)->first();

        $folderMapping = Operation::getFolderMapping();

        // Especificar carpeta temporal para MPDF
        File::ensureDirectoryExists(public_path("temp/reports/mpdf"));

        $mpdfConfig = [
            'tempDir' => public_path("temp/reports/mpdf")
        ];

        $fileName = 'papi_report_op_' . $operation->id . '.pdf';

        // Generar PDF con MPDF
        $pdf = new Mpdf($mpdfConfig);
        $viewData = compact('language', 'details', 'reportData', 'operator', 'operation', 'angle_unit', 'visualInspection');
        $htmlContent = view('reports.papi', $viewData)->render();
        $pdf->WriteHTML($htmlContent);

        // Definir ruta temporal en public/temp/reports
        $tempPath = public_path("temp/reports/" . uniqid('report_', true) . ".pdf");
        File::ensureDirectoryExists(dirname($tempPath));
        $pdf->Output($tempPath, 'F');

        // Verificar existencia y generar nombre único en S3
        $s3FileName = $fileName;
        $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
        $counter = 1;

        while (Storage::disk('s3')->exists($s3PdfPath)) {
            $s3FileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$counter}." . pathinfo($fileName, PATHINFO_EXTENSION);
            $s3PdfPath = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
            $counter++;
        }

        // Subir PDF a S3
        Storage::disk('s3')->put($s3PdfPath, File::get($tempPath));

        // Guardar en la base de datos
        OperationReports::create([
            'name' => $s3FileName,
            'description' => '',
            'type' => 'pdf',
            'size' => File::size($tempPath),
            'operation_id' => $operation->id,
        ]);

        // Eliminar archivo temporal
        File::delete($tempPath);

        return Storage::disk('s3')->response($s3PdfPath);
    }

    function convertToSexagesimal($decimal_angle){
        // Converts decimal format to degrees and minutes with 1 decimal place
        if($decimal_angle != 0){
            $sign = $decimal_angle < 0 ? -1 : 1;
            $abs = abs((float)$decimal_angle);
            $deg = floor($abs);
            $minutes = round(($abs - $deg) * 60, 1);
            if ($minutes >= 60) {
                $deg += 1;
                $minutes = 0.0;
            }
            return ($sign < 0 ? '-' : '') . $deg . '°' . number_format($minutes, 1) . "'";
        }
    }

    function getRhumbLineBearing($lat1, $lon1, $lat2, $lon2) {
        // difference in longitudinal coordinates
        $dLon = deg2rad($lon2) - deg2rad($lon1);

        // difference in the phi of latitudinal coordinates
        $dPhi = log(tan(deg2rad($lat2) / 2 + pi() / 4) / tan(deg2rad($lat1) / 2 + pi() / 4));

        // we need to recalculate $dLon if it is greater than pi
        if(abs($dLon) > pi()) {
            if($dLon > 0) {
                $dLon = (2 * pi() - $dLon) * -1;
            } else {
                $dLon = 2 * pi() + $dLon;
            }
        }

        return rad2deg(atan2($dLon, $dPhi)) + 360;
    }

    function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000){
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $lonDelta = $lonTo - $lonFrom;
        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);
        return $angle * $earthRadius;
    }
}
