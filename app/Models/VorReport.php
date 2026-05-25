<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use Mpdf\Mpdf;
use CpChart\Data;
use CpChart\Image;
use CpChart\Draw;
use CpChart\Chart\Scatter;

class VorReport extends Report
{
    function generate(Operation $operation, $language){
        $details = $this->get_operation_details($operation, $language);

        $details['title'] = trans('report_vor.title', [], $language);
        $operator = Operator::where('id', $operation['operator_id'])->get();

        $chart_images = [];
        /* Global results */
        $all_results = [];
        /* Results to the VOR Orbit Task */
        $results_orbit = [];
        /* Results to the VOR Radial Task */
        $results_radial = [];
        //Default values

        array_push($all_results, $results_orbit);
        array_push($all_results, $results_radial);

        /* Parameters of the first page */
        $average_bearing = '-';
        $average_bearing_error = '-';
        $max_bearing_error = '-';
        $mean_f30_hz_mod_depth = '-';
        $mean_f30_hz_mod_freq = '-';
        $mean_f9960_hz30_hz_mod_freq = '-';
        $mean_f9960_hz_dev = '-';
        $mean_f9960_hz_mod_depth = '-';
        $mean_f9960_hz_sub_freq = '-';
        $mean_field_strength = '-';
        //$min_bearing_error = '-';
        $posFrom = '-';
        $posTo = '-';
        $over_vor = '-';
        $orbit_radio = '=';


        $tasks = Task::where('operation_id', $operation->id)->get();

        // Basic info of the VOR
        $vor = Vor::find($operation->subject_id);
        $frequency  = VorChannel::where('id', $vor->channel_id)->first('frequency');
        if($vor->vor_type == 0){
            $vor_type = 'Dual Frequency';
        } else if($vor->vor_type == 1){
            $vor_type = 'Single Frequency';
        } else{
            $vor_type = 'No defined';
        }
        $country    = Country::where('id', $vor->country_id)->first('name');
        $vor_basic_info = array(
            // Lat and Long get to the object_id (VOR)
            'latitude'  => round($vor->latitude, 7),
            'longitude' => round($vor->longitude, 7),
            'elevation' => round($vor->elevation, 2),
            'channel_id'=> $frequency['frequency'],
            'ref_radial'=> $vor->ref_radial,
            'country_id'=> $country['name'],
            'vor_type'  => $vor_type,
            'code'      => $vor->code
        );
        $operation_type = $operation->type_id;

        foreach ($tasks as $key => $task) {
            $i = 0; //Counter results for task

            if($task->status_id == 2) {
                $results = ResultsVor::where('task_id', $task->id)->get();
            }else{
                $results = '';
            }
            // Basic info of the VOR
            if(!empty($results[0]) && $i == 0){
                $vor        = Vor::find($results[0]->vor_id);
                $frequency  = VorChannel::where('id', $vor->channel_id)->first('frequency');
                if($vor->vor_type == 0){
                    $vor_type = 'Dual Frequency';
                } else if($vor->vor_type == 1){
                    $vor_type = 'Single Frequency';
                } else{
                    $vor_type = 'No defined';
                }
                $country    = Country::where('id', $vor->country_id)->first('name');

                $vor_basic_info = array(
                    // Lat and Long get to the object_id (VOR)
                    'latitude'  => round($vor->latitude, 7),
                    'longitude' => round($vor->longitude, 7),
                    'elevation' => round($vor->elevation, 2),
                    'channel_id'=> $frequency['frequency'],
                    'country_id'=> $country['name'],
                    'vor_type'  => $vor_type,
                    'code'      => $vor->code
                );
            }
            if(!empty($results[0])){
                foreach ($results as $key => $result) {
                    if($result->is_valid_run == 1){

                        $file_name = 'data_' . $operation->id . '_' . $result->task_id . '_' . $result->run_number . '_' . $result->transmitter;
                        $media_file = OperationFiles::where('file_name', 'LIKE', '%' . $file_name . '%')->first();

                        //Calculte elevation with .csv file
                        //ALL: REFACTOR THIS CODE AS SOON AS VOR REPORT WORKS

                        if(isset($media_file)){
                            switch ($task->type_id) {
                                case 26:   // VOR Orbit
                                    $chart_image = $this->multi_charts($media_file, $vor, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);

                                    if($result->transmitter == 1) {                     //Data base fields
                                        $results_orbit[$i] = array(
                                            'tx_o' => $result->transmitter,               //transmitter
                                            'orbit_o' => round($result->orbit_radio, 0),  //radio
                                            'altitude_o' => round($result->over_vor, 0),
                                            'modulation_9960hz_o' => round(($result->mean_f9960_hz_mod_depth*100), 1),
                                            'modulation_30hz_o' => round(($result->mean_f30_hz_mod_depth*100), 1),
                                            'modulation_fm_ratio_o' => round($result->mean_f9960_hz_dev, 1),
                                            'structure_average_error_o' => round($result->average_bearing_error, 2),
                                            // Structure
                                            'structure_scalloping_o' => round($result->scalloping, 2),
                                            'structure_scalloping_pos_o' => round($result->scalloping_position, 2),
                                            'structure_bends_o' => round($result->bends, 2),
                                            'structure_bends_pos_o' => round($result->bends_position, 2),
                                        );

                                        array_push($all_results[0], $results_orbit[$i]);

                                    } else if($result->transmitter == 2){
                                        $results_orbit[$i] = array(
                                            'tx_o' => $result->transmitter,                 //transmitter
                                            'orbit_o' => round($result->orbit_radio, 0),    //radio
                                            'altitude_o' => round($result->over_vor, 0),
                                            'modulation_9960hz_o' => round(($result->mean_f9960_hz_mod_depth*100), 1),
                                            'modulation_30hz_o' => round(($result->mean_f30_hz_mod_depth*100), 1),
                                            'modulation_fm_ratio_o' => round($result->mean_f9960_hz_dev, 1),
                                            'structure_average_error_o' => round($result->average_bearing_error, 2),
                                            // Structure
                                            'structure_scalloping_o' => round($result->scalloping, 2),
                                            'structure_scalloping_pos_o' => round($result->scalloping_position, 2),
                                            'structure_bends_o' => round($result->bends, 2),
                                            'structure_bends_pos_o' => round($result->bends_position, 2),
                                        );

                                        array_push($all_results[0], $results_orbit[$i]);

                                    }
                                    break;

                                case 27:     // VOR Radial
                                    $chart_image = $this->multi_charts($media_file, $vor, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);

                                    if($result->transmitter == 1){
                                        $results_radial[$i] = array(
                                            'tx_r' => $result->transmitter,                     //transmitter
                                            'azimuth_r' => round($result->radial_number, 0),  //average_beating
                                            'from_r' => round($result->pos_from, 0),
                                            'to_r'  => round($result->pos_to, 0),
                                            'altitude_r' => round($result->over_vor, 0),
                                            'modulation_9960hz_r' => round(($result->mean_f9960_hz_mod_depth*100), 1),
                                            'modulation_30hz_r' => round(($result->mean_f30_hz_mod_depth*100), 1),
                                            'modulation_fm_ratio_r' => round($result->mean_f9960_hz_dev, 1),
                                            'structure_average_error_r' => round($result->average_bearing_error, 2),
                                            // Structure
                                            'structure_scalloping_r' => round($result->scalloping, 2),
                                            'structure_scalloping_pos_r' => round($result->scalloping_position, 2),
                                            'structure_bends_r' => round($result->bends, 2),
                                            'structure_bends_pos_r' => round($result->bends_position, 2),
                                        );

                                        array_push($all_results[1], $results_radial[$i]);

                                    } else {
                                        $results_radial[$i] = array(
                                            'tx_r' => $result->transmitter,                     //transmitter
                                            'azimuth_r' => round($result->radial_number, 0),  //average_beating
                                            'from_r' => round($result->pos_from, 0),
                                            'to_r'  => round($result->pos_to, 0),
                                            'altitude_r' => round($result->over_vor, 0),
                                            'modulation_9960hz_r' => round(($result->mean_f9960_hz_mod_depth*100), 1),
                                            'modulation_30hz_r' => round(($result->mean_f30_hz_mod_depth*100), 1),
                                            'modulation_fm_ratio_r' => round($result->mean_f9960_hz_dev, 1),
                                            'structure_average_error_r' => round($result->average_bearing_error, 2),
                                            // Structure
                                            'structure_scalloping_r' => round($result->scalloping, 2),
                                            'structure_scalloping_pos_r' => round($result->scalloping_position, 2),
                                            'structure_bends_r' => round($result->bends, 2),
                                            'structure_bends_pos_r' => round($result->bends_position, 2),
                                        );

                                        array_push($all_results[1], $results_radial[$i]);

                                    }

                                    break;

                                default:
                                    break;
                            }
                            $i++;
                        }
                    }
                }
            }

            if($task->type_id == 26 && empty($all_results[0][0])){
                $results_orbit[0] = array(
                    'tx_o' => '-',
                    'orbit_o' => '-',
                    'altitude_o' => '-',
                    'modulation_9960hz_o' => '-',
                    'modulation_30hz_o' => '-',
                    'modulation_fm_ratio_o' => '-',
                    'structure_average_error_o' => '-',
                    // Structure
                    'structure_scalloping_o' => '-',
                    'structure_scalloping_pos_o' => '-',
                    'structure_bends_o' => '-',
                    'structure_bends_pos_o' => '-',
                );
                array_push($all_results[0], $results_orbit[0]);
            }
            if($task->type_id == 27 && empty($all_results[1][0])){
                $results_radial[0] = array(
                    'tx_r' => '-',
                    'azimuth_r' => '-',
                    'from_r' => '-',
                    'to_r'  => '-',
                    'altitude_r' => '-',
                    'modulation_9960hz_r' => '-',
                    'modulation_30hz_r' => '-',
                    'modulation_fm_ratio_r' => '-',
                    'structure_average_error_r' => '-',
                    // Structure
                    'structure_scalloping_r' => '-',
                    'structure_scalloping_pos_r' => '-',
                    'structure_bends_r' => '-',
                    'structure_bends_pos_r' => '-',
                );
                array_push($all_results[1], $results_radial[0]);
            }
            $i++;
        }


        // GENERADOR DE REPORTE PDF
        try {
            $folderMapping = Operation::getFolderMapping();
            $fileName = 'vor_report_op_' . $operation->id . '.pdf';
            $tempFolderPath = public_path('temp/reports/imgs');

            // Especificar carpeta temporal para MPDF
            $mpdfConfig = [
                'tempDir' => public_path("temp/reports/")
            ];

            // Generar PDF con MPDF
            $pdf = new Mpdf($mpdfConfig);
            $viewData = compact('language', 'details', 'results', 'chart_images', 'operator', 'operation', 'vor', 'vor_basic_info','all_results');
            $htmlContent = view('reports.vor', $viewData)->render();
            $pdf->WriteHTML($htmlContent);

            // Definir ruta temporal en public/temp/reports
            $tempPath = public_path("temp/reports/" . uniqid('report_', true) . ".pdf");
            File::ensureDirectoryExists(dirname($tempPath)); // Asegurar que la carpeta exista
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

            // Eliminar archivo temporal y imagenes temporales
            File::delete($tempPath);
            $tempFolderPathFile = public_path('temp/extra');
            File::cleanDirectory($tempFolderPathFile);

            if (File::exists($tempFolderPath)) {
                File::cleanDirectory($tempFolderPath);
            }

            // Show the report to the user
            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error to generate report'], 500);
        }

    }

    function multi_charts($media_file, $vor, $result, $task, $operation){

        /*******************   Define chart limits   ***************/
        $maxScatterModulation = 44;
        $minScatterModulation = 36;
        $imgWidth = 1600;
        $imgHeight = 350;

        $maxXLabels = 20;
        $dataLength = 10;

        $maxAngle = 0;
        $lowerDDM = -0.26; // The -400 to 400 range is taken from the AENA ILS Report
        $higherDDM = 0.30;
        $previousAngle = -INF;
        $positiveLimit = 0; // 150 for NW and Al or 175 uA for Clearance
        $negativeLimit = 0; // -150 for NW and Al or -175 uA for Clearance
        $positiveLimit75 = 0;
        $negativeLimit75 = 0;
        $fieldScaleMax = 10;
        $fieldScaleMin = -110;
        $upperFieldLimit = -10;
        $lowerFieldLimit = -90;
        $asc = false;

        $angle = 0;

        $dataBearingErr = new Data();
        $dataBearing = new Data();
        $dataRF = new Data();
        $dataMod30 = new Data();
        $dataMod9960 = new Data();
        //$dataFM = new Data();

        $chart_type = $task->type_id;
        //$result->orbit_radio añadir al nombre del título de las gráficas

        if($chart_type == 26){
            $orbit_radio = round($result->orbit_radio, 0);
            $chart_type = 'vor_orbit'.$orbit_radio;
            $name = 'VOR Orbit ' . $orbit_radio . "m";
        } else if ($chart_type == 27) {
            $radial_number = $result->radial_number;
            $chart_type = 'vor_radial'.$radial_number;
            $name = 'VOR Radial ' . $radial_number . "°";
        } else {
            die('ERROR: Invalid chart type ' . $chart_type);
        }

        // Obtener la ruta del archivo en S3 basada en la nueva estructura
        $folderMapping = Operation::getFolderMapping();
        $s3FilePath = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$task->id}/{$media_file->file_name}";

        // Descargar el archivo desde S3 y guardarlo temporalmente en public/temp/extra
        $csv = Storage::disk('s3')->get($s3FilePath);
        $tempFolderPath = public_path('temp/extra');
        $localFilePath = $tempFolderPath . '/' . $media_file->file_name;
        File::put($localFilePath, $csv);

        // Leer el archivo CSV desde la nueva ubicación temporal
        $reader = Reader::createFromPath($localFilePath, 'r');

        $result_hsml = $reader->fetchColumn((float) 0);
        $result_latitude = $reader->fetchColumn((float) 1);
        $result_longitude = $reader->fetchColumn((float) 2);
        $result_position = $reader->fetchColumn((float) 3);
        $result_distance_to_vor = $reader->fetchColumn((float) 6);
        $result_bearing = $reader->fetchColumn((float) 7);
        $result_bearing_error = $reader->fetchColumn((float) 8);
        $result_f30Hz_mod_depth = $reader->fetchColumn((float) 13);
        $result_f9960Hz_30Hz_mod_frequency = $reader->fetchColumn((float) 15);
        $result_f9960Hz_mod_depth = $reader->fetchColumn((float) 18);
        $result_field_strength = $reader->fetchColumn((float) 20);

        //$low_pass_filter_test = $reader->fetchColumn((double) 9);

        $hsml = iterator_to_array($result_hsml, false);
        array_shift($hsml);
        $hsml = array_map("floatval", $hsml);
        $latitude = iterator_to_array($result_latitude, false);
        array_shift($latitude);
        $latitude = array_map("floatval", $latitude);
        $position = iterator_to_array($result_position, false);
        array_shift($position);
        asort($position);
        $position = array_map("floatval", $position);
        $longitude = iterator_to_array($result_longitude, false);
        array_shift($longitude);
        $longitude = array_map("floatval", $longitude);
        $distance_to_vor = iterator_to_array($result_distance_to_vor, false);
        array_shift($distance_to_vor);
        $distance_to_vor = array_map("floatval", $distance_to_vor);
        $bearing = iterator_to_array($result_bearing, false);
        array_shift($bearing);
        $bearing = array_map("floatval", $bearing);
        $bearing_error = iterator_to_array($result_bearing_error, false);
        array_shift($bearing_error);
        $bearing_error = array_map("floatval", $bearing_error);
        $f30Hz_mod_depth = iterator_to_array($result_f30Hz_mod_depth, false);
        array_shift($f30Hz_mod_depth);
        $f30Hz_mod_depth = array_map("floatval", $f30Hz_mod_depth);
        $f9960Hz_mod_depth = iterator_to_array($result_f9960Hz_mod_depth, false);
        array_shift($f9960Hz_mod_depth);
        $f9960Hz_mod_depth = array_map("floatval", $f9960Hz_mod_depth);
        $field_strength = iterator_to_array($result_field_strength, false);
        array_shift($field_strength);
        $field_strength = array_map("floatval", $field_strength);

        $f30Hz_mod_depth_percentage = [];
        foreach($f30Hz_mod_depth as $f30Hz_mod_depth_key){
            $test = $f30Hz_mod_depth_key*100;
            array_push($f30Hz_mod_depth_percentage, $test);
        }
        $f9960Hz_mod_depth_percentage = [];
        foreach($f9960Hz_mod_depth as $f9960Hz_mod_depth_key){
            $test = $f9960Hz_mod_depth_key*100;
            array_push($f9960Hz_mod_depth_percentage, $test);
        }

        if($task->type_id == 26){ //VOR Orbit
            // Value to position in Orbit Verification
            $position_angle = $position;
            $position_first_angle = $position[0];

            $dataBearingErr->addPoints($position_angle, "Angle");
            $dataBearing->addPoints($position_angle, "Angle");
            $dataRF->addPoints($position_angle, "Angle");
            $dataMod30->addPoints($position_angle, "Angle");
            $dataMod9960->addPoints($position_angle, "Angle");
            // (1) Add point to Bearing Error vs Distance/Position
            // Add point to bearing_error vs Position
            $dataBearingErr->addPoints($bearing_error, "Bearing Error");
            // (2) Add point to Bearing vs Distance/Position
            // Add point to bearing vs Position
            $dataBearing->addPoints($bearing, "Bearing");
            // (3) Add point to RF vs Distance/Position
            // Add point to field_strength vs Position
            $dataRF->addPoints($field_strength, "RF");
            // (4) Add point to Mod 30 vs Distance/Position
            // Add point to f30Hz_mod_depth vs Position
            $dataMod30->addPoints($f30Hz_mod_depth_percentage, "Mod 30Hz");
            // (5) Add point to Mod 9960 vs Distance/Position
            // Add point to f9960Hz_mod_depth vs Position
            $dataMod9960->addPoints($f9960Hz_mod_depth_percentage, "Mod 9960Hz");
            // (6) Add point to FM (mean_9960_hz_dev) vs Distance/Position
            // Add point to mean_9960_hz_dev vs Position
            // $dataFM->addPoints($f9960Hz_mod_depth[$key], "Mean 9960Hz");
        } else {
            $position_distance = $distance_to_vor;

            $dataBearingErr->addPoints($position_distance, "Distance");
            $dataBearing->addPoints($position_distance, "Distance");
            $dataRF->addPoints($position_distance, "Distance");
            $dataMod30->addPoints($position_distance, "Distance");
            $dataMod9960->addPoints($position_distance, "Distance");
            // (1) Add point to Bearing Error vs Distance/Position
            // Add point to bearing_error vs Position
            $dataBearingErr->addPoints($bearing_error, "Bearing Error");
            // (2) Add point to Bearing vs Distance/Position
            // Add point to bearing vs Position
            $dataBearing->addPoints($bearing, "Bearing");
            // (3) Add point to RF vs Distance/Position
            // Add point to field_strength vs Position
            $dataRF->addPoints($field_strength, "RF");
            // (4) Add point to Mod 30 vs Distance/Position
            // Add point to f30Hz_mod_depth vs Position
            $dataMod30->addPoints($f30Hz_mod_depth_percentage, "Mod 30Hz");
            // (5) Add point to Mod 9960 vs Distance/Position
            // Add point to f9960Hz_mod_depth vs Position
            $dataMod9960->addPoints($f9960Hz_mod_depth_percentage, "Mod 9960Hz");
            // (6) Add point to FM (mean_9960_hz_dev) vs Distance/Position
            // Add point to mean_9960_hz_dev vs Position
            //$dataFM->addPoints($f9960Hz_mod_depth[$key], "Mean 9960Hz");
        }

          /******************************************************************************************/
         /************************         CONFIGURE A SCATTER LINE         ************************/
        /******************************************************************************************/
        // Configure axis for Radial Graph
        // Axis to Bearing Error vs Distance/Position

        if($task->type_id == 26){ //VOR Orbit
            $dataBearingErr->setAxisName(0, "Angle (&#176;)");
            $dataBearingErr->setAxisUnit(0, "&#176;");
            $dataBearingErr->setAxisXY(0, AXIS_X);
            $dataBearingErr->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataBearingErr->setSerieOnAxis("Angle", 0);
            $dataBearingErr->setSerieOnAxis("Bearing Error", 1);
            $dataBearingErr->setAxisName(1, "Bearing Error (&#176;)");
            $dataBearingErr->setAxisUnit(1, ""); // &#176; degrees character
            $dataBearingErr->setAxisXY(1, AXIS_Y);
            $dataBearingErr->setAxisPosition(1, AXIS_POSITION_LEFT);
            $dataBearingErr->setScatterSerie("Angle", "Bearing Error", 0);
        } else {
            $dataBearingErr->setAxisName(0, "Distance to VOR (m)");
            $dataBearingErr->setAxisUnit(0, "m");
            $dataBearingErr->setAxisXY(0, AXIS_X);
            $dataBearingErr->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataBearingErr->setSerieOnAxis("Distance", 0);
            $dataBearingErr->setSerieOnAxis("Bearing Error", 1);
            $dataBearingErr->setAxisName(1, "Bearing Error (&#176;)");
            $dataBearingErr->setAxisUnit(1, ""); // &#176; degrees character
            $dataBearingErr->setAxisXY(1, AXIS_Y);
            $dataBearingErr->setAxisPosition(1, AXIS_POSITION_LEFT);
            $dataBearingErr->setScatterSerie("Distance", "Bearing Error", 0);
        }
        // Style to scatter
        $dataBearingErr->setScatterSerieDescription(0, "Bearing Error (&#176;)");
        $dataBearingErr->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

        // Axis to Bearing vs Distance/Position
        if($task->type_id == 26){ //VOR Orbit
            $dataBearing->setAxisName(0, "Angle (&#176;)");
            $dataBearing->setAxisUnit(0, "&#176;");
            $dataBearing->setAxisXY(0, AXIS_X);
            $dataBearing->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataBearing->setSerieOnAxis("Angle", 0);
            $dataBearing->setSerieOnAxis("Bearing", 1);
            $dataBearing->setAxisName(1, "Bearing (&#176;)");
            $dataBearing->setAxisUnit(1, ""); // &#176; degrees character
            $dataBearing->setAxisXY(1, AXIS_Y);
            $dataBearing->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataBearing->setScatterSerie("Angle", "Bearing", 0);
        } else {
            $dataBearing->setAxisName(0, "Distance to VOR (m)");
            $dataBearing->setAxisUnit(0, "m");
            $dataBearing->setAxisXY(0, AXIS_X);
            $dataBearing->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataBearing->setSerieOnAxis("Distance", 0);
            $dataBearing->setSerieOnAxis("Bearing", 1);
            $dataBearing->setAxisName(1, "Bearing (&#176;)");
            $dataBearing->setAxisUnit(1, ""); // &#176; degrees character
            $dataBearing->setAxisXY(1, AXIS_Y);
            $dataBearing->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataBearing->setScatterSerie("Distance", "Bearing", 0);
        }
        $dataBearing->setScatterSerieDescription(0, "Bearing (&#176;)");
        $dataBearing->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

        // Axis to RF vs Distance/Position
        if($task->type_id == 26){ //VOR Orbit
            $dataRF->setAxisName(0, "Angle (&#176;)");
            $dataRF->setAxisUnit(0, "&#176;");
            $dataRF->setAxisXY(0, AXIS_X);
            $dataRF->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataRF->setSerieOnAxis("Angle", 0);
            $dataRF->setSerieOnAxis("RF", 1);
            $dataRF->setAxisName(1, "RF (dBm)");
            $dataRF->setAxisUnit(1, ""); // &#176; degrees character
            $dataRF->setAxisXY(1, AXIS_Y);
            $dataRF->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataRF->setScatterSerie("Angle", "RF", 0);
        } else {
            $dataRF->setAxisName(0, "Distance to VOR (m)");
            $dataRF->setAxisUnit(0, "m");
            //$dataRF->setSerieOnAxis("Distance", 0);
            $dataRF->setAxisXY(0, AXIS_X);
            $dataRF->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            $dataRF->setSerieOnAxis("RF", 1);
            $dataRF->setAxisName(1, "RF (dBm)");
            $dataRF->setAxisUnit(1, ""); // &#176; degrees character
            $dataRF->setAxisXY(1, AXIS_Y);
            $dataRF->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataRF->setScatterSerie("Distance", "RF", 0);
        }
        $dataRF->setScatterSerieDescription(0, "RF (dBm)");
        $dataRF->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

        // Axis to Mod 30 vs Distance/Position
        if($task->type_id == 26){ //VOR Orbit
            $dataMod30->setAxisName(0, "Angle (&#176;)");
            $dataMod30->setAxisUnit(0, "&#176;");
            $dataMod30->setAxisXY(0, AXIS_X);
            $dataMod30->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataMod30->setSerieOnAxis("Angle", 0);
            $dataMod30->setSerieOnAxis("Mod 30Hz", 1);
            $dataMod30->setAxisName(1, "Mod 30Hz (%)");
            $dataMod30->setAxisUnit(1, ""); // &#176; degrees character
            $dataMod30->setAxisXY(1, AXIS_Y);
            $dataMod30->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataMod30->setScatterSerie("Angle", "Mod 30Hz", 0);
        } else {
            $dataMod30->setAxisName(0, "Distance to VOR (m)");
            $dataMod30->setAxisUnit(0, "m");
            $dataMod30->setAxisXY(0, AXIS_X);
            $dataMod30->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataMod30->setSerieOnAxis("Distance", 0);
            $dataMod30->setSerieOnAxis("Mod 30Hz", 1);
            $dataMod30->setAxisName(1, "Mod 30Hz (%)");
            $dataMod30->setAxisUnit(1, ""); // &#176; degrees character
            $dataMod30->setAxisXY(1, AXIS_Y);
            $dataMod30->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataMod30->setScatterSerie("Distance", "Mod 30Hz", 0);
        }
        $dataMod30->setScatterSerieDescription(0, "Mod 30Hz (%)");
        $dataMod30->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

        // Axis to Mod 9960 vs Distance/Position
        if($task->type_id == 26){ //VOR Orbit
            $dataMod9960->setAxisName(0, "Angle (&#176;)");
            $dataMod9960->setAxisUnit(0, "&#176;");
            $dataMod9960->setAxisXY(0, AXIS_X);
            $dataMod9960->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataMod9960->setSerieOnAxis("Angle", 0);
            $dataMod9960->setSerieOnAxis("Mod 9960Hz", 1);
            $dataMod9960->setAxisName(1, "Mod 9960Hz (%)");
            $dataMod9960->setAxisUnit(1, ""); // &#176; degrees character
            $dataMod9960->setAxisXY(1, AXIS_Y);
            $dataMod9960->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataMod9960->setScatterSerie("Angle", "Mod 9960Hz", 0);
        } else {
            $dataMod9960->setAxisName(0, "Distance to VOR (m)");
            $dataMod9960->setAxisUnit(0, "m");
            $dataMod9960->setAxisXY(0, AXIS_X);
            $dataMod9960->setAxisPosition(0, AXIS_POSITION_BOTTOM);
            //$dataMod9960->setSerieOnAxis("Distance", 0);
            $dataMod9960->setSerieOnAxis("Mod 9960Hz", 1);
            $dataMod9960->setAxisName(1, "Mod 9960Hz (%)");
            $dataMod9960->setAxisUnit(1, ""); // &#176; degrees character
            $dataMod9960->setAxisXY(1, AXIS_Y);
            $dataMod9960->setAxisPosition(1, AXIS_POSITION_LEFT);
            /* Style to scatter */
            $dataMod9960->setScatterSerie("Distance", "Mod 9960Hz", 0);
        }
        $dataMod9960->setScatterSerieDescription(0, "Mod 9960Hz (%)");
        $dataMod9960->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

          /**********************************************************************************************/
         /*****************************      GENRATE CHARTS IMAGE      *********************************/
        /**********************************************************************************************/

        // Create the image objects
        $pictureBearingErr  = new Image($imgWidth, $imgHeight, $dataBearingErr);
        $pictureBearing     = new Image($imgWidth, $imgHeight, $dataBearing);
        $pictureRF          = new Image($imgWidth, $imgHeight, $dataRF);
        $pictureMod30       = new Image($imgWidth, $imgHeight, $dataMod30);
        $pictureMod9960     = new Image($imgWidth, $imgHeight, $dataMod9960);
        //$pictureFM          = new Image($imgWidth, $imgHeight, $dataFM);

        // Turn of Antialiasing
        $pictureBearingErr->Antialias = TRUE;
        $pictureBearing->Antialias = TRUE;
        $pictureRF->Antialias = TRUE;
        $pictureMod30->Antialias = TRUE;
        $pictureMod9960->Antialias = TRUE;
        //$pictureFM->Antialias = TRUE;

        // Set the default fonts
        $pictureBearingErr->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureBearing->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureRF->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureMod30->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureMod9960->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //$pictureFM->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));

        // Define the chart areas
        $pictureBearingErr->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureBearing->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureRF->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureMod30->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureMod9960->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        //$pictureFM->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);


        // Create the Scatter chart objects
        $scatterBearingErr = new Scatter($pictureBearingErr, $dataBearingErr);
        $scatterBearing = new Scatter($pictureBearing, $dataBearing);
        $scatterRF = new Scatter($pictureRF, $dataRF);
        $scatterMod30 = new Scatter($pictureMod30, $dataMod30);
        $scatterMod9960 = new Scatter($pictureMod9960, $dataMod9960);
        //$scatterFM = new Scatter($pictureFM, $dataFM);


          /************************************************************************************************/
         /***************************************     CHART LIMITS    ************************************/
        /************************************************************************************************/
        // Forcing boundaries from -40 to 40 degrees (For clearance only)

        //Define scale toshow grid
        //Define axis Y limits
        if($task->type_id == 26){ //VOR Orbit
            $maxAxisX = 360; //scale to axis angle
            $minAxisX = 0;
            $maxBearingError = 5; //5
            $minBearingError = -5;
            $maxBearing = 360; //5 // scale 50 max limit view 400
            $minBearing = 0; //-5
            $maxRF = 0;
            $minRF = -90;
            $maxMod30 = 35; //35
            $minMod30 = 24; //24
            $maxMod9960 = 35;  //35
            $minMod9960 = 24;  //24
        } elseif($task->type_id == 27) {
            $maxAxisX = $result->pos_from; //scale to axi distance
            $minAxisX = $result->pos_to;
            $maxBearingError = 5;
            $minBearingError = -5;
            $maxBearing = 360;  //5
            $minBearing = 0;
            $maxRF = 0;
            $minRF = -90;
            $maxMod30 = 35;  //35
            $minMod30 = 24;  //24
            $maxMod9960 = 35;  //35
            $minMod9960 = 24;  //24
        }

        $axisBearingErr = array(
            0 => array("Min" => $minAxisX, "Max" => $maxAxisX), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $minBearingError, "Max" => $maxBearingError));
        $scaleBearingErr = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBearingErr,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $axisBearing = array(
            0 => array("Min" => $minAxisX, "Max" => $maxAxisX), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $minBearing, "Max" => $maxBearing, "Rows" => 9, "RowHeight" => 40));
        $scaleBearing = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBearing,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $axisRF = array(
            0 => array("Min" => $minAxisX, "Max" => $maxAxisX), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $minRF, "Max" => $maxRF));
        $scaleRF = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisRF,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $axisMod30 = array(
            0 => array("Min" => $minAxisX, "Max" => $maxAxisX), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $minMod30, "Max" => $maxMod30));
        $scaleMod30 = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisMod30,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $axisMod9960 = array(
            0 => array("Min" => $minAxisX, "Max" => $maxAxisX), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $minMod9960, "Max" => $maxMod9960));
        $scaleMod9960 = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisMod9960,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Draw the scales
        $scatterBearingErr->drawScatterScale($scaleBearingErr);
        $scatterBearing->drawScatterScale($scaleBearing);
        $scatterRF->drawScatterScale($scaleRF);
        $scatterMod30->drawScatterScale($scaleMod30);
        $scatterMod9960->drawScatterScale($scaleMod9960);
        //$scatterFM->drawScatterScale($scaleFM);

        // Define horizontal line thresholds
        $scatterBearingErr->drawScatterThreshold(2, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));
        $scatterBearingErr->drawScatterThreshold(-2, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));
        $scatterMod30->drawScatterThreshold(28, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));
        $scatterMod30->drawScatterThreshold(32, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));
        $scatterMod9960->drawScatterThreshold(28, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));
        $scatterMod9960->drawScatterThreshold(32, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4));

        // Define vertical line thresholds (Define orbit start position)
        if($task->type_id == 26){
            $scatterBearingErr->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 0, "G" => 255, "B" => 0, "Ticks" => 4));
            $scatterBearing->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 0, "G" => 255, "B" => 0, "Ticks" => 4));
            $scatterRF->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 0, "G" => 255, "B" => 0, "Ticks" => 4));
            $scatterMod30->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 0, "G" => 255, "B" => 0, "Ticks" => 4));
            $scatterMod9960->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 0, "G" => 255, "B" => 0, "Ticks" => 4));
            //$scatterFM->drawScatterThreshold($position_first_angle, array("AxisID" => 0, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 0));
        }

        // Turn on Antialiasing
        $scatterBearingErr->Antialias = TRUE;
        $scatterBearing->Antialias = TRUE;
        $scatterRF->Antialias = TRUE;
        $scatterMod30->Antialias = TRUE;
        $scatterMod9960->Antialias = TRUE;

        $scatterBearingErr->drawScatterLineChart(); // Line
        $scatterBearing->drawScatterLineChart(); // Line
        $scatterRF->drawScatterLineChart(); // Line
        $scatterMod30->drawScatterLineChart(); // Line
        // BUG Descomentar cuando Ernesto pueda hacer una prueba con el generador
        $scatterMod9960->drawScatterLineChart(); // Line

          /************************************************************************************************/
         /******************************** IN THIS BLOCK GENERATE LINE SCATTER  **************************/
        /************************************************************************************************/
        // Draw a scatter plot chart for Bearing Error
        // with limits
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterBearingErr->drawScatterLineChart(); // Line
        //}
        // Draw a scatter plot chart for Bearing
        // 2lines
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterBearing->drawScatterLineChart(); // Line
        //}
         // Draw a scatter plot chart for RF
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterRF->drawScatterLineChart(); // Line
        //}
        // Draw a scatter plot chart for Mod30
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterMod30->drawScatterLineChart(); // Line
        //}
        // Draw a scatter plot chart for Mod9960
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterMod9960->drawScatterLineChart(); // Line
        //}


        // Write the chart legend for Deviation Graph
        $pictureBearingErr->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterBearingErr->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        //}
        $pictureBearing->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterBearing->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        //}
        //$pictureRF->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterRF->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        //}
        $pictureMod30->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterMod30->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        //}
        $pictureMod9960->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterMod9960->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        //}

        /* CADA TX TENDRÁ SUS GRÁFICAS INDEENDIENTES */
        if($result->transmitter == 1){
            $name = $name . ' TX1';
        } else {
            $name = $name . ' TX2';
        }

        if($result->transmitter == 1){
            $imgBearingErr = $chart_type . '_beaerr_TX1.png';
            $imgBearing = $chart_type . '_bea_TX1.png';
            $imgRF = $chart_type . '_rf_TX1.png';
            $imgMod30 = $chart_type . '_mod30_TX1.png';
            $imgMod9960 = $chart_type . '_mod9960_TX1.png';
        } else {
            $imgBearingErr = $chart_type . '_beaerr_TX2.png';
            $imgBearing = $chart_type . '_bea_TX2.png';
            $imgRF = $chart_type . '_rf_TX2.png';
            $imgMod30 = $chart_type . '_mod30_TX2.png';
            $imgMod9960 = $chart_type . '_mod9960_TX2.png';
        }
        //$imgFM = $chart_type . '_fm.png';

        $tempFolderPath = public_path('temp/extra');

        // Modificar las líneas de renderizado para usar la ruta completa
        $pictureBearingErr->render($tempFolderPath . '/' . $imgBearingErr);
        $pictureBearing->render($tempFolderPath . '/' . $imgBearing);
        $pictureRF->render($tempFolderPath . '/' . $imgRF);
        $pictureMod30->render($tempFolderPath . '/' . $imgMod30);
        $pictureMod9960->render($tempFolderPath . '/' . $imgMod9960);
        //$pictureFM->render($imgFM);

        return ['img_BearingErr' => $imgBearingErr, 'img_Bearing' => $imgBearing, 'img_RF' => $imgRF, 'img_Mod30' => $imgMod30, 'img_Mod9960' => $imgMod9960, 'chart_type' => $name];
    }


      /**********************************************************************************************/
     /*****************************        EXTERNAL FUNCTIONS      *********************************/
    /**********************************************************************************************/

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
    //dougv.com/2009/07/calculating-the-bearing-and-compass-rose-direction-between-two-latitude-longitude-coordinates-in-php
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

        // return the angle
        // return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360; // normalized
        return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360;
    }

    function getAltitudeAngle($droneLat, $droneLon, $droneAlt, $pointLat, $pointLon, $pointAlt){

        $distance = $this->vincentyGreatCircleDistance($droneLat, $droneLon, $pointLat, $pointLon);
        $altDelta = $droneAlt - $pointAlt;
        $angle = rad2deg(atan($altDelta / $distance));
        return round($angle, 2);
    }
}
