<?php

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use CpChart\Data;
use CpChart\Image;
use CpChart\Draw;
use CpChart\Chart\Scatter;
use Mpdf\Mpdf;


class IlsReport extends Report {

    use HasFactory;

    function generate(Operation $operation, $language){

        // ── LOG: comprobar todos los CSVs de esta operación en S3 ──────────
        $folderMapping = Operation::getFolderMapping();
        $folder = $folderMapping[$operation->type_id] ?? 'ILS';
        $allFiles = OperationFiles::where('file_name', 'LIKE', 'data_' . $operation->id . '%')->get();
        \Log::info("[IlsReport][generate] Operación {$operation->id} — {$allFiles->count()} fichero(s) en operation_files:");
        foreach ($allFiles as $of) {
            $s3Path = "{$folder}/{$operation->id}/{$of->task_id}/{$of->file_name}";
            $exists = Storage::disk('s3')->exists($s3Path);
            if ($exists) {
                \Log::info("[IlsReport][generate]   ✓ ENCONTRADO en S3: {$s3Path}");
            } else {
                \Log::error("[IlsReport][generate]   ✗ NO encontrado en S3: {$s3Path}");
            }
        }
        // ────────────────────────────────────────────────────────────────────

        $details = $this->get_operation_details($operation, $language);
        $details['title'] = trans('report_ils.title', [], $language);
        $details['is_localizer'] = ($operation->type_id == 5);
        $operator = Operator::where('id', $operation['operator_id'])->get();
        $path = storage_path('app/platform/temp/');
        $chart_images = [];
        $course_alignment = '-';
        $course_alignment_ua = '-';
        /*  */
        $course_alignment_right = '-';
        $course_alignment_left = '-';
        $course_alignment_right_tx2 = '-';
        $course_alignment_left_tx2 = '-';
        $freq_sep = '-';
        $freq_sep = '-';
        $sum_of_SDM = '-';
        $hz90 = '-';
        $hz150 = '-';
        $course_alignment_tx2 = '-';
        $course_alignment_ua_tx2 = '-';
        $freq_sep_tx2 = '-';
        $freq_sep_tx2 = '-';
        $sum_of_SDM_tx2 = '-';
        $hz90_tx2 = '-';
        $hz150_tx2 = '-';
        $frequency_offset_tx2 = '-';
        $course_frequency_offset_tx2 = '-';
        $clearance_frequency_offset_tx2 = '-';
        $frequency_offset = '-';
        $course_frequency_offset = '-';
        $clearance_frequency_offset = '-';
        $nominal_width = '-';
        $max_width = '-';
        $min_width = '-';
        $alarm_high_width = '-';
        $alarm_low_width = '-';
        $alarm_high_width_tx2 = '-';
        $alarm_low_width_tx2 = '-';
        $course_90 = '-';
        $course_150 = '-';
        $nominal_width_tx2 = '-';
        $max_width_tx2 = '-';
        $min_width_tx2 = '-';
        $course_90_tx2 = '-';
        $course_150_tx2 = '-';
        $angle_left150 = '-';
        $angle_right150 = '-';
        $angle_left075 = '-';
        $angle_right075 = '-';
        $angle_high150 = '-';
        $angle_low150 = '-';
        $angle_high150_tx2 = '-';
        $angle_low150_tx2 = '-';
        $gp_angle_tx1 = '-';
        $gp_angle_tx2 = '-';
        /* Min & Max width alarm GP */
        $minwa_max_width = '-';
        $minwa_min_width = '-';
        $minwa_nominal_width = '-';
        $minwa_max_width_tx2 = '-';
        $minwa_min_width_tx2 = '-';
        $minwa_nominal_width_tx2 = '-';
        $maxwa_max_width = '-';
        $maxwa_min_width = '-';
        $maxwa_nominal_width = '-';
        $maxwa_max_width_tx2 = '-';
        $maxwa_min_width_tx2 = '-';
        $maxwa_nominal_width_tx2 = '-';
        /* Min & Max width alarm Localizer */
        $min_course_alignment = '-';
        $min_course_alignment_ua = '-';
        $min_nominal_width = '-';
        $min_course_alignment_tx2 = '-';
        $min_course_alignment_ua_tx2 = '-';
        $min_nominal_width_tx2 = '-';
        $max_course_alignment = '-';
        $max_course_alignment_ua = '-';
        $max_nominal_width = '-';
        $max_course_alignment_tx2 = '-';
        $max_course_alignment_ua_tx2 = '-';
        $max_nominal_width_tx2 = '-';
        /* Course 90Hz / 150Hz */
        $course_alignment_alarm_left = '-';
        $course_alignment_alarm_left_ua = '-';
        $course_alignment_alarm_left_tx2 = '-';
        $course_alignment_alarm_left_ua_tx2 = '-';
        $course_alignment_alarm_right = '-';
        $course_alignment_alarm_right_ua = '-';
        $course_alignment_alarm_right_tx2 = '-';
        $course_alignment_alarm_right_ua_tx2 = '-';
        /* Fluctuation */
        /* LOC */
        $max_fluc_loc = '-';
        $max_fluc_loc_ua = '-';
        $min_fluc_loc = '-';
        $min_fluc_loc_ua = '-';
        $max_fluc_loc_tx2 = '-';
        $max_fluc_loc_tx2_ua = '-';
        $min_fluc_loc_tx2 = '-';
        $min_fluc_loc_tx2_ua = '-';
        /* Fluctuation */
        /* GP */
        $max_fluc_gp = '-';
        $max_fluc_gp_ua = '-';
        $min_fluc_gp = '-';
        $min_fluc_gp_ua = '-';
        $max_fluc_gp_tx2 = '-';
        $max_fluc_gp_tx2_ua = '-';
        $min_fluc_gp_tx2 = '-';
        $min_fluc_gp_tx2_ua = '-';

        /* Transverse Check */
        /* GP */
        $max_trans_gp = '-';
        $max_trans_gp_ua = '-';
        $min_trans_gp = '-';
        $min_trans_gp_ua = '-';
        $max_trans_gp_tx2 = '-';
        $max_trans_gp_tx2_ua = '-';
        $min_trans_gp_tx2 = '-';
        $min_trans_gp_tx2_ua = '-';

        /* Low and high angle alarm */
        $low_course_alignment = '-';
        $low_course_alignment_ua = '-';
        $low_nominal_width = '-';
        $low_course_alignment_tx2 = '-';
        $low_course_alignment_ua_tx2 = '-';
        $low_nominal_width_tx2 = '-';
        $hig_course_alignment = '-';
        $hig_course_alignment_ua = '-';
        $hig_nominal_width = '-';
        $hig_course_alignment_tx2 = '-';
        $hig_course_alignment_ua_tx2 = '-';
        $hig_nominal_width_tx2 = '-';

        $frequency_offset_tolerance = '-';
        $frequency_offset_tolerance_tx2 = '-';

        //Structure
        $structure_zone_v_ddm = '-';
        $structure_zone_v_ua = '-';
        $structure_zone_v_ddm_tx2 = '-';
        $structure_zone_v_ua_tx2 = '-';
        $structure_zone_iv_ddm = '-';
        $structure_zone_iv_ua = '-';
        $structure_zone_iv_ddm_tx2 = '-';
        $structure_zone_iv_ua_tx2 = '-';
        $structure_zone_iii_ddm = '-';
        $structure_zone_iii_ua = '-';
        $structure_zone_iii_ddm_tx2 = '-';
        $structure_zone_iii_ua_tx2 = '-';
        $structure_zone_ii_ddm = '-';
        $structure_zone_ii_ua = '-';
        $structure_zone_ii_ddm_tx2 = '-';
        $structure_zone_ii_ua_tx2 = '-';

        $all_structure_data = [];
        $all_structure_data_tx2 = [];

        $tasks = Task::where('operation_id', $operation->id)->get();

        $operation_type = $operation->type_id;

        //Basic info ILS system
        $ils = Ils::where('header_id', $operation->subject_id)->first();
        $header = Header::find($ils->header_id);
        $freq = IlsChannel::where('id', $ils->channel_id)->first();

        $category = $ils->category;
        $latitude = $ils->loc_antenna_latitude;
        $longitude = $ils->loc_antenna_longitude;
        $altitude = $ils->loc_antenna_elevation;

        /* Calculate points */
        $signal_angle = $ils->gp_angle;

        $ils_point_a = $ils->point_a;
        $ils_point_b = $ils->point_b;
        $ils_point_c = $ils->point_c;
        $ils_point_d = $ils->point_d;
        $ils_point_e = $ils->point_e;

        // Structure
        $structure_values = [];

        $counter = 0;
        $counter_tx2 = 0;
        foreach ($tasks as $key => $task) {
            $results = [];
            if($operation_type == 5){
                if($task->status_id == 2 || $task->status_id == 3) {
                    $result = ResultsIlsLocalizer::where('task_id', $task->id)->first();
                    $ils = Ils::find($result->ils_id);
                    $header = Header::find($ils->header_id);
                    $freq = IlsChannel::where('id', $ils->channel_id)->first();
                    $category = $ils->category;
                    $latitude = $ils->loc_antenna_latitude;
                    $longitude = $ils->loc_antenna_longitude;
                    $altitude = $ils->loc_antenna_elevation;
                }
            }else{
                if($task->status_id == 2 || $task->status_id == 3) {
                    $result = ResultsIlsGlidePath::where('task_id', $task->id)->first();
                    $ils = Ils::find($result->ils_id);
                    $header = Header::find($ils->header_id);
                    $freq = IlsChannel::where('id', $ils->channel_id)->first();
                    $category = $ils->category;
                    $latitude = $ils->loc_antenna_latitude;
                    $longitude = $ils->loc_antenna_longitude;
                    $altitude = $ils->loc_antenna_elevation;
                }
            }

            if($operation_type == 5){
                if($task->status_id == 2 || $task->status_id == 3) {
                    $results = ResultsIlsLocalizer::where('task_id', $task->id)->get();
                }
            }else{

                if($task->status_id == 2 || $task->status_id == 3){
                    $results = ResultsIlsGlidePath::where('task_id', $task->id)->get();
                }
            }

            if(isset($results)){
                foreach ($results as $key => $result) {

                    if($result->is_valid_run == 1){

                        if($task->type_id != 17 && $task->type_id != 8){
                            $file_name = 'data_' . $operation->id . '_' . $result->task_id . '_' . $result->run_number . '_' . $result->transmitter;
                            $media_file = OperationFiles::where('file_name', 'LIKE', '%' . $file_name . '%')->first();
                        }else{
                            $file_name = 'data_' . $operation->id . '_structure_' . $result->transmitter;
                            $media_file = OperationFiles::where('file_name', 'LIKE', '%' . $file_name . '%')->first();
                        }

                        if(isset($media_file)){
                            switch ($task->type_id) {
                                case 8:   // Localizer Structure
                                    if($result->transmitter == 1) {
                                        if($counter == 0){
                                            $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Course alignment');
                                            array_push($chart_images, $chart_image);

                                            $course_alignment = round($result->average_ddm_str, 2);
                                            $course_alignment_ua = round($result->average_ddm_ua_str, 1);
                                            $freq_sep = round(($result->mean_freq_sep_str)/1000, 1);
                                            $sum_of_SDM = round($result->average_sdm_str * 100, 1); //COURSE ALIGNMENT HAS TO BE ABOVE GET_AVERAGE BECAUSE THE FILE WE NEED TO ACCESS IT IS PREVIOUSLY STORE IN OUR STORAGE
                                            $hz90 = round($result->mean_mod90_str * 100, 1);
                                            $hz150 = round($result->mean_mod150_str * 100, 1);

                                            $frequency_offset = floatval($result->mean_freq_offset_str);
                                            $localizer_freq = floatval($freq->localizer_frequency);
                                            $frequency_offset_tolerance = round(($frequency_offset/($localizer_freq*1000000))*100, 4);

                                            // Counter to not draw all chart task_type 8 LOC structure
                                            $counter++;
                                        }
                                        //Calcular el mayor absoluto sin perder el signo y ese será el valor que aparecerá en la tabla del reporte
                                        // Get max_ddm and min_ddm to results and return the absolute max whit
                                        // $task_description = $task->description;
                                        // $second_space = strpos($task_description, " ", 5);
                                        // $zone_name_length = $second_space - 5;
                                        // $zone_name = substr($task_description, 5, $zone_name_length);

                                        // $ddm_structure_data =   [$result->max_ddm, $result->max_ddm_ua,
                                        //                         $result->min_ddm, $result->min_ddm_ua];
                                        // $all_structure_data = $this->array_push_assoc($all_structure_data, $zone_name, $ddm_structure_data);

                                    }else if($result->transmitter == 2){
                                        if($counter_tx2 == 0){
                                            $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Course alignment');
                                            array_push($chart_images, $chart_image);

                                            $course_alignment_tx2 = round($result->average_ddm_str, 4);
                                            $course_alignment_ua_tx2 = round($result->average_ddm_ua_str, 1);
                                            $freq_sep_tx2 = round(($result->mean_freq_sep_str)/1000, 1);
                                            $sum_of_SDM_tx2 = round($result->average_sdm_str * 100, 1); //COURSE ALIGNMENT HAS TO BE ABOVE GET_AVERAGE BECAUSE THE FILE WE NEED TO ACCESS IT IS PREVIOUSLY STORE IN OUR STORAGE
                                            $hz90_tx2 = round($result->mean_mod90_str * 100, 1);
                                            $hz150_tx2 = round($result->mean_mod150_str * 100, 1);

                                            $frequency_offset_tx2 = floatval($result->mean_freq_offset_str);
                                            $frequency_freq_tx2 = floatval($freq->localizer_frequency);
                                            $frequency_offset_tolerance_tx2 = round(($frequency_offset_tx2/($frequency_freq_tx2*1000000))*100, 4);

                                            // Counter to not draw all chart task_type 8 LOC structure
                                            $counter_tx2++;
                                        }

                                        // Get max_ddm and min_ddm to results and return the absolute max whit
                                        // $task_description_tx2 = $task->description;
                                        // $second_space_tx2 = strpos($task_description_tx2, " ", 5);  // Usa _tx2 aquí
                                        // $zone_name_length_tx2 = $second_space_tx2 - 5;  // Usa _tx2 aquí
                                        // $zone_name_tx2 = substr($task_description_tx2, 5, $zone_name_length_tx2);  // Usa _tx2 aquí

                                        // $ddm_structure_data_tx2 = [$result->max_ddm, $result->max_ddm_ua,
                                        //                         $result->min_ddm, $result->min_ddm_ua];
                                        // $all_structure_data_tx2 = $this->array_push_assoc($all_structure_data_tx2, $zone_name_tx2, $ddm_structure_data_tx2);
                                    }

                                    break;
                                case 9:     // Localizer Width
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $course_alignment_right = $result->angle_right150;
                                        $course_alignment_left = $result->angle_left150;
                                        $nominal_width = round(($result->angle_right150 - $result->angle_left150), 2);
                                    } else {
                                        $course_alignment_right_tx2 = $result->angle_right150;
                                        $course_alignment_left_tx2 = $result->angle_left150;
                                        $nominal_width_tx2 = round(($result->angle_right150 - $result->angle_left150), 2);
                                    }
                                    break;
                                case 10:   // Localizer Course Alarm (left)
                                    $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Course alarm Left');
                                    array_push($chart_images, $chart_image);
                                    //Log::alert($result);
                                    if($result->transmitter == 1){
                                        $course_alignment_alarm_left = round($result->average_ddm, 4);
                                        $course_alignment_alarm_left_ua = round($result->average_ddm_ua,2);
                                    } else {
                                        $course_alignment_alarm_left_tx2 = round($result->average_ddm, 4);
                                        $course_alignment_alarm_left_ua_tx2 = round($result->average_ddm_ua,2);
                                    }
                                    break;
                                case 11:   // Localizer Course Alarm (right)
                                    $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Course alarm Right');
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $course_alignment_alarm_right = round($result->average_ddm, 4);
                                        $course_alignment_alarm_right_ua = round($result->average_ddm_ua,2);
                                    } else {
                                        $course_alignment_alarm_right_tx2 = round($result->average_ddm, 4);
                                        $course_alignment_alarm_right_ua_tx2 = round($result->average_ddm_ua,2);
                                    }
                                    break;
                                case 12:   // Localizer Width Alarm Min
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $min_course_alignment = $result->angle_right150;
                                        $min_course_alignment_ua = $result->angle_left150;
                                        $min_nominal_width = round(($result->angle_right150 - $result->angle_left150), 2);
                                    } else {
                                        $min_course_alignment_tx2 = $result->angle_right150;
                                        $min_course_alignment_ua_tx2 = $result->angle_left150;
                                        $min_nominal_width_tx2 = round(($result->angle_right150 - $result->angle_left150), 2);
                                    }
                                    break;
                                case 13:    // Localizer Width Alarm Max
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $max_course_alignment = $result->angle_right150;
                                        $max_course_alignment_ua = $result->angle_left150;
                                        $max_nominal_width = round(($result->angle_right150 - $result->angle_left150), 2);
                                    } else {
                                        $max_course_alignment_tx2 = $result->angle_right150;
                                        $max_course_alignment_ua_tx2 = $result->angle_left150;
                                        $max_nominal_width_tx2 = round(($result->angle_right150 - $result->angle_left150), 2);
                                    }
                                    break;
                                case 14:    // Localizer Clearance
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    break;
                                case 17:    // Glide Path Angle
                                    if($result->transmitter == 1) {
                                        if($counter == 0){
                                            $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Glide Path Angle');
                                            array_push($chart_images, $chart_image);

                                            $sum_of_SDM = round($result->average_sdm_str * 100, 1); //COURSE ALIGNMENT HAS TO BE ABOVE GET_AVERAGE BECAUSE THE FILE WE NEED TO ACCESS IT IS PREVIOUSLY STORE IN OUR STORAGE
                                            $hz90 = round($result->mean_mod90_str * 100, 1);
                                            $hz150 = round($result->mean_mod150_str * 100, 1);
                                            $gp_angle_tx1 = $result->mean_glide_path_angle_str;

                                            // Calcular DDM para el ángulo GP
                                            $gp_angle_nominal = $ils->gp_angle; // Ángulo nominal del ILS
                                            $gp_angle_deviation_tx1 = $gp_angle_tx1 - $gp_angle_nominal;
                                            // Para GP: 0.0875 DDM = 0.24 grados de desviación
                                            $gp_angle_ddm_tx1 = ($gp_angle_deviation_tx1 / 0.24) * 0.0875;

                                            $freq_sep = round(($result->mean_freq_sep_str)/1000, 1);
                                            $frequency_offset = $result->mean_freq_offset_str;
                                            $localizer_freq = floatval($freq->localizer_frequency);
                                            $frequency_offset_tolerance = round(($frequency_offset/($localizer_freq*1000000))*100, 4);

                                            $course_frequency_offset = $this->get_average(15, $media_file);
                                            $clearance_frequency_offset = $this->get_average(24, $media_file);

                                            $counter++;
                                        }

                                        // $task_description = $task->description;
                                        // $second_space = strpos($task_description, " ", 5);
                                        // $zone_name_length = $second_space - 5;
                                        // $zone_name = substr($task_description, 5, $zone_name_length);

                                        // $ddm_structure_data =   [$result->max_ddm, $result->max_ddm_ua,
                                        //                         $result->min_ddm, $result->min_ddm_ua];
                                        // $all_structure_data = $this->array_push_assoc($all_structure_data, $zone_name, $ddm_structure_data);

                                    } else if($result->transmitter == 2){
                                        if($counter_tx2 == 0){
                                            $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Glide Path Angle');
                                            array_push($chart_images, $chart_image);

                                            $sum_of_SDM_tx2 = round($result->average_sdm_str * 100, 1); //COURSE ALIGNMENT HAS TO BE ABOVE GET_AVERAGE BECAUSE THE FILE WE NEED TO ACCESS IT IS PREVIOUSLY STORE IN OUR STORAGE
                                            $hz90_tx2 = round($result->mean_mod90_str * 100, 1);
                                            $hz150_tx2 = round($result->mean_mod150_str * 100, 1);
                                            $gp_angle_tx2 = $result->mean_glide_path_angle_str;

                                            // Calcular DDM para el ángulo GP TX2
                                            $gp_angle_nominal = $ils->gp_angle; // Ángulo nominal del ILS
                                            $gp_angle_deviation_tx2 = $gp_angle_tx2 - $gp_angle_nominal;
                                            // Para GP: 0.0875 DDM = 0.24 grados de desviación
                                            $gp_angle_ddm_tx2 = ($gp_angle_deviation_tx2 / 0.24) * 0.0875;

                                            $freq_sep_tx2 = round(($result->mean_freq_sep_str)/1000, 1);
                                            $frequency_offset_tx2 = $result->mean_freq_offset_str;
                                            $localizer_freq = floatval($freq->localizer_frequency);
                                            $frequency_offset_tolerance_tx2 = round(($frequency_offset_tx2/($localizer_freq*1000000))*100, 4);

                                            $course_frequency_offset_tx2 = $this->get_average(15, $media_file);
                                            $clearance_frequency_offset_tx2 = $this->get_average(24, $media_file);

                                            $counter_tx2++;
                                        }

                                        // $task_description_tx2 = $task->description;
                                        // $second_space_tx2 = strpos($task_description_tx2, " ", 5);
                                        // $zone_name_length_tx2 = $second_space_tx2 - 5;
                                        // $zone_name_tx2 = substr($task_description_tx2, 5, $zone_name_length_tx2);

                                        // $ddm_structure_data_tx2 =   [$result->max_ddm, $result->max_ddm_ua,
                                        //                         $result->min_ddm, $result->min_ddm_ua];
                                        // $all_structure_data_tx2 = $this->array_push_assoc($all_structure_data_tx2, $zone_name_tx2, $ddm_structure_data_tx2);
                                    }
                                    break;
                                case 18:  // Glide Path Width
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $course_alignment = round($result->angle_high150, 2);
                                        $course_alignment_ua = round($result->angle_low150, 2);
                                        $nominal_width = round(($result->angle_high150 - $result->angle_low150), 2);
                                    } else {
                                        $course_alignment_tx2 = round($result->angle_high150, 2);
                                        $course_alignment_ua_tx2 = round($result->angle_low150, 2);
                                        $nominal_width_tx2 = round(($result->angle_high150 - $result->angle_low150), 2);
                                    }
                                    break;
                                //GP Angle Alarm
                                case 19:  // Glide Path Angle Alarm (Low)
                                    $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Glide Path Angle Alarm (Low)');
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $low_course_alignment = round($result->mean_glide_path_angle, 2);
                                    } else {
                                        $low_course_alignment_tx2 = round($result->mean_glide_path_angle, 2);
                                    }
                                    break;
                                case 20:  // Glide Path Angle Alarm (High)
                                    $chart_image = $this->course_alignment($media_file, $ils, $header, $result, $task, $operation, 'Glide Path Angle Alarm (High)');
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $hig_course_alignment = round($result->mean_glide_path_angle, 2);
                                    } else {
                                        $hig_course_alignment_tx2 = round($result->mean_glide_path_angle, 2);
                                    }
                                    break;
                                case 21:  // Glide Path Width Alarm (Min)
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $minwa_max_width = round($result->angle_high150, 2);
                                        $minwa_min_width = round($result->angle_low150, 2);
                                        $minwa_nominal_width = round(($result->angle_high150 - $result->angle_low150), 2);
                                    } else {
                                        $minwa_max_width_tx2 = round($result->angle_high150, 2);
                                        $minwa_min_width_tx2 = round($result->angle_low150, 2);
                                        $minwa_nominal_width_tx2 = round(($result->angle_high150 - $result->angle_low150), 2);
                                    }
                                    break;
                                case 22:  // Glide Path Width Alarm (Max)
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $maxwa_max_width = round($result->angle_high150, 2);
                                        $maxwa_min_width = round($result->angle_low150, 2);
                                        $maxwa_nominal_width = round(($result->angle_high150 - $result->angle_low150), 2);
                                    } else {
                                        $maxwa_max_width_tx2 = round($result->angle_high150, 2);
                                        $maxwa_min_width_tx2 = round($result->angle_low150, 2);
                                        $maxwa_nominal_width_tx2 = round(($result->angle_high150 - $result->angle_low150), 2);
                                    }
                                    break;
                                case 23:  // Glide Path fluctuation

                                    // Generar la gráfica
                                    $chart_image = $this->fluctuation_chart($media_file, $ils, $header, $result, $task, $operation, 'Glide Path Fluctuation');
                                    array_push($chart_images, $chart_image);

                                    if($result->transmitter == 1){
                                        $max_fluc_gp = $result->max_ddm;
                                        $max_fluc_gp_ua = $result->max_ddm_ua;
                                        $min_fluc_gp = $result->min_ddm;
                                        $min_fluc_gp_ua = $result->min_ddm_ua;
                                    } else {
                                        $max_fluc_gp_tx2 = $result->max_ddm;
                                        $max_fluc_gp_tx2_ua = $result->max_ddm_ua;
                                        $min_fluc_gp_tx2 = $result->min_ddm;
                                        $min_fluc_gp_tx2_ua = $result->min_ddm_ua;
                                    }
                                    break;
                                case 42:  // Localizer fluctuation

                                    // Generar la gráfica
                                    $chart_image = $this->fluctuation_chart($media_file, $ils, $header, $result, $task, $operation, 'Localizer Fluctuation');
                                    array_push($chart_images, $chart_image);

                                    if($result->transmitter == 1){
                                        $max_fluc_loc = $result->max_ddm;
                                        $max_fluc_loc_ua = $result->max_ddm_ua;
                                        $min_fluc_loc = $result->min_ddm;
                                        $min_fluc_loc_ua = $result->min_ddm_ua;
                                    } else {
                                        $max_fluc_loc_tx2 = $result->max_ddm;
                                        $max_fluc_loc_tx2_ua = $result->max_ddm_ua;
                                        $min_fluc_loc_tx2 = $result->min_ddm;
                                        $min_fluc_loc_tx2_ua = $result->min_ddm_ua;
                                    }
                                    break;
                                case 44:  // Glide Path Transverse Check
                                    $chart_image = $this->multi_charts($media_file, $ils, $header, $result, $task, $operation);
                                    array_push($chart_images, $chart_image);
                                    if($result->transmitter == 1){
                                        $max_trans_gp = $result->max_ddm;
                                        $max_trans_gp_ua = $result->max_ddm_ua;
                                        $min_trans_gp = $result->min_ddm;
                                        $min_trans_gp_ua = $result->min_ddm_ua;
                                    } else {
                                        $max_trans_gp_tx2 = $result->max_ddm;
                                        $max_trans_gp_tx2_ua = $result->max_ddm_ua;
                                        $min_trans_gp_tx2 = $result->min_ddm;
                                        $min_trans_gp_tx2_ua = $result->min_ddm_ua;
                                    }
                                    break;
                                default:
                                    break;
                            }

                            $fileTemp = public_path('temp/extra/'.$media_file->file_name);
                            File::delete($fileTemp);
                        }
                    }
                }
            }

        }

        if($details['is_localizer']){
            $results_cat = $this->get_data_cat($category->name);
        } else {
            $results_cat = $this->get_data_cat_glide($category->name);
        }

        // foreach($all_structure_data as $key=>$zone_structure_data){
        //     $structure_zone = 'structure_zone_'.strtolower($key);
        //     $$structure_zone = $this->find_structure_value($zone_structure_data, $key);
        //     $structure_zone_ddm = 'structure_zone_'.strtolower($key).'_ddm';
        //     $structure_zone_ua = 'structure_zone_'.strtolower($key).'_ua';
        //     $$structure_zone_ddm = $$structure_zone[0];
        //     $$structure_zone_ua = $$structure_zone[1];
        // }

        // foreach ($all_structure_data_tx2 as $key => $zone_structure_data) {
        //     $structure_zone = 'structure_zone_'.strtolower($key).'_tx2';
        //     $$structure_zone = $this->find_structure_value($zone_structure_data, $key);
        //     $structure_zone_ddm = 'structure_zone_'.strtolower($key).'_ddm_tx2';
        //     $structure_zone_ua = 'structure_zone_'.strtolower($key).'_ua_tx2';
        //     $$structure_zone_ddm = $$structure_zone[0];
        //     $$structure_zone_ua = $$structure_zone[1];
        // }


        // Select loc or gp frequency to report
        if($operation_type == 5){
            $frequency = $freq->localizer_frequency;
        }else{
            $frequency = $freq->glide_path_frequency;
        }


        $results = [
            'location_lat' => round($latitude, 7),
            'location_lon' => round($longitude, 7),
            'location_elevation' => round($altitude, 2),
            'category' => 'CAT '.$category->name,
            'frequency' => $frequency,
            'mod_tx1_90' => $hz90,
            'mod_tx1_150' => $hz150,
            'mod_tx2_90' => $hz90_tx2,
            'mod_tx2_150' => $hz150_tx2,
            'course_alignment_tx1' => $course_alignment,
            'course_alignment_tx1_ua' => $course_alignment_ua,
            'course_alignment_tx2' => $course_alignment_tx2,
            'course_alignment_tx2_ua' => $course_alignment_ua_tx2,
            'sdm_tx1' => $sum_of_SDM,
            'sdm_tx2' => $sum_of_SDM_tx2,
            'frequency_offset_tx1' => $frequency_offset,
            'frequency_offset_tolerance_tx1' => $frequency_offset_tolerance,
            'frequency_offset_tx2' => $frequency_offset_tx2,
            'frequency_offset_tolerance_tx2' => $frequency_offset_tolerance_tx2,
            'frequency_separation_tx1' => $freq_sep,
            'frequency_separation_tx2' => $freq_sep_tx2,
            'nominal_width_tx1' => $nominal_width,
            'nominal_width_tx2' => $nominal_width_tx2,
            'alarm_min_tx1' => $alarm_low_width,
            'alarm_min_tx2' => $alarm_low_width_tx2,
            'alarm_max_tx1' => $alarm_high_width,
            'alarm_max_tx2' => $alarm_high_width_tx2,
            'maximum_width_left_tx1' => $max_width,
            'maximum_width_right_tx1' => $max_width_tx2,
            'minimum_width_left_tx1' => $min_width,
            'minimum_width_right_tx1' => $min_width_tx2,
            'angle_high150' => $angle_high150,
            'angle_low150' => $angle_low150,
            'angle_high150_tx2' => $angle_high150_tx2,
            'angle_low150_tx2' => $angle_low150_tx2,
            'gp_angle_tx1' => $gp_angle_tx1,
            'gp_angle_tx2' => $gp_angle_tx2,
            'gp_angle_ddm_tx1' => isset($gp_angle_ddm_tx1) ? round($gp_angle_ddm_tx1, 4) : '-',
            'gp_angle_ddm_tx2' => isset($gp_angle_ddm_tx2) ? round($gp_angle_ddm_tx2, 4) : '-',
            'minwa_max_width' => $minwa_max_width,
            'minwa_min_width' => $minwa_min_width,
            'minwa_nominal_width' => $minwa_nominal_width,
            'minwa_max_width_tx2' => $minwa_max_width_tx2,
            'minwa_min_width_tx2' => $minwa_min_width_tx2,
            'minwa_nominal_width_tx2' => $minwa_nominal_width_tx2,
            'maxwa_max_width' => $maxwa_max_width,
            'maxwa_min_width' => $maxwa_min_width,
            'maxwa_nominal_width' => $maxwa_nominal_width,
            'maxwa_max_width_tx2' => $maxwa_max_width_tx2,
            'maxwa_min_width_tx2' => $maxwa_min_width_tx2,
            'maxwa_nominal_width_tx2' => $maxwa_nominal_width_tx2,
            /* */
            'course_alignment_right_tx1' => round((float)$course_alignment_right, 2),
            'course_alignment_left_tx1' => round((float)$course_alignment_left, 2),
            'course_alignment_right_tx2' => round((float)$course_alignment_right_tx2, 2),
            'course_alignment_left_tx2' => round((float)$course_alignment_left_tx2, 2),
            /*min and max width localizer*/
            'min_course_alignment_tx1' => $min_course_alignment,
            'min_course_alignment_tx1_ua' => $min_course_alignment_ua,
            'min_nominal_width_tx1' => $min_nominal_width,
            'min_course_alignment_tx2' => $min_course_alignment_tx2,
            'min_course_alignment_tx2_ua' => $min_course_alignment_ua_tx2,
            'min_nominal_width_tx2' => $min_nominal_width_tx2,
            'max_course_alignment_tx1' => $max_course_alignment,
            'max_course_alignment_tx1_ua' => $max_course_alignment_ua,
            'max_nominal_width_tx1' => $max_nominal_width,
            'max_course_alignment_tx2' => $max_course_alignment_tx2,
            'max_course_alignment_tx2_ua' => $max_course_alignment_ua_tx2,
            'max_nominal_width_tx2' => $max_nominal_width_tx2,
            'course_alignment_alarm_left_tx1_ua' => $course_alignment_alarm_left_ua,
            'course_alignment_alarm_left_tx1' => $course_alignment_alarm_left,
            'course_alignment_alarm_left_tx2_ua' => $course_alignment_alarm_left_ua_tx2,
            'course_alignment_alarm_left_tx2' => $course_alignment_alarm_left_tx2,
            'course_alignment_alarm_right_tx1_ua' => $course_alignment_alarm_right_ua,
            'course_alignment_alarm_right_tx1' => $course_alignment_alarm_right,
            'course_alignment_alarm_right_tx2_ua' => $course_alignment_alarm_right_ua_tx2,
            'course_alignment_alarm_right_tx2' => $course_alignment_alarm_right_tx2,
            /* LOC Fluctuation */
            'max_fluc_loc_tx1' => $max_fluc_loc,
            'max_fluc_loc_tx1_ua' => ($max_fluc_loc_ua != '-') ? round($max_fluc_loc_ua, 1) : '-',
            'min_fluc_loc_tx1' => $min_fluc_loc,
            'min_fluc_loc_tx1_ua' => ($min_fluc_loc_ua != '-') ? round($min_fluc_loc_ua, 1) : '-',
            'max_fluc_loc_tx2' => $max_fluc_loc_tx2,
            'max_fluc_loc_tx2_ua' => ($max_fluc_loc_tx2_ua != '-') ? round($max_fluc_loc_tx2_ua, 1) : '-',
            'min_fluc_loc_tx2' => $min_fluc_loc_tx2,
            'min_fluc_loc_tx2_ua' => ($min_fluc_loc_tx2_ua != '-') ? round($min_fluc_loc_tx2_ua, 1) : '-',
            /* GP Fluctuation */
            'max_fluc_gp_tx1' => $max_fluc_gp,
            'max_fluc_gp_tx1_ua' => ($max_fluc_gp_ua != '-') ? round($max_fluc_gp_ua, 1) : '-',
            'min_fluc_gp_tx1' => $min_fluc_gp,
            'min_fluc_gp_tx1_ua' => ($min_fluc_gp_ua != '-') ? round($min_fluc_gp_ua, 1) : '-',
            'max_fluc_gp_tx2' => $max_fluc_gp_tx2,
            'max_fluc_gp_tx2_ua' => ($max_fluc_gp_tx2_ua != '-') ? round($max_fluc_gp_tx2_ua, 1) : '-',
            'min_fluc_gp_tx2' => $min_fluc_gp_tx2,
            'min_fluc_gp_tx2_ua' => ($min_fluc_gp_tx2_ua != '-') ? round($min_fluc_gp_tx2_ua, 1) : '-',

            /* GP Transverse Check */
            'max_trans_gp_tx1' => $max_trans_gp,
            'max_trans_gp_tx1_ua' => ($max_trans_gp_ua != '-') ? round($max_trans_gp_ua, 1) : '-',
            'min_trans_gp_tx1' => $min_trans_gp,
            'min_trans_gp_tx1_ua' => ($min_trans_gp_ua != '-') ? round($min_trans_gp_ua, 1) : '-',
            'max_trans_gp_tx2' => $max_trans_gp_tx2,
            'max_trans_gp_tx2_ua' => ($max_trans_gp_tx2_ua != '-') ? round($max_trans_gp_tx2_ua, 1) : '-',
            'min_trans_gp_tx2' => $min_trans_gp_tx2,
            'min_trans_gp_tx2_ua' => ($min_trans_gp_tx2_ua != '-') ? round($min_trans_gp_tx2_ua, 1) : '-',

            /* Low and high angle alarm */
            'low_course_alignment_tx1' => $low_course_alignment,
            'low_course_alignment_tx1_ua' => $low_course_alignment_ua,
            'low_nominal_width_tx1' => $low_nominal_width,
            'low_course_alignment_tx2' => $low_course_alignment_tx2,
            'low_course_alignment_tx2_ua' => $low_course_alignment_ua_tx2,
            'low_nominal_width_tx2' => $low_nominal_width_tx2,
            'hig_course_alignment_tx1' => $hig_course_alignment,
            'hig_course_alignment_tx1_ua' => $hig_course_alignment_ua,
            'hig_nominal_width_tx1' => $hig_nominal_width,
            'hig_course_alignment_tx2' => $hig_course_alignment_tx2,
            'hig_course_alignment_tx2_ua' => $hig_course_alignment_ua_tx2,
            'hig_nominal_width_tx2' => $hig_nominal_width_tx2,
            /* Structure */
            'structure_zone_v_ddm' => $structure_zone_v_ddm,
            'structure_zone_v_ua' => $structure_zone_v_ua,
            'structure_zone_v_ddm_tx2' => $structure_zone_v_ddm_tx2,
            'structure_zone_v_ua_tx2' => $structure_zone_v_ua_tx2,
            'structure_zone_iv_ddm' => $structure_zone_iv_ddm,
            'structure_zone_iv_ua' => $structure_zone_iv_ua,
            'structure_zone_iv_ddm_tx2' => $structure_zone_iv_ddm_tx2,
            'structure_zone_iv_ua_tx2' => $structure_zone_iv_ua_tx2,
            'structure_zone_iii_ddm' => $structure_zone_iii_ddm,
            'structure_zone_iii_ua' => $structure_zone_iii_ua,
            'structure_zone_iii_ddm_tx2' => $structure_zone_iii_ddm_tx2,
            'structure_zone_iii_ua_tx2' => $structure_zone_iii_ua_tx2,
            'structure_zone_ii_ddm' => $structure_zone_ii_ddm,
            'structure_zone_ii_ua' => $structure_zone_ii_ua,
            'structure_zone_ii_ddm_tx2' => $structure_zone_ii_ddm_tx2,
            'structure_zone_ii_ua_tx2' => $structure_zone_ii_ua_tx2,
            'ils_angle' => $ils->gp_angle, // Para Glide Path
        ];

        $details_config = $details['config'];


        // GENERADOR DE REPORTE PDF
        try {
            $folderMapping = Operation::getFolderMapping();
            $fileName = 'ils_report_op_' . $operation->id . '.pdf';
            $tempFolderPath = public_path('temp/reports/imgs');

            // Especificar carpeta temporal para MPDF
            $mpdfConfig = [
                'tempDir' => public_path("temp/reports/")
            ];

            // Generar PDF con MPDF
            $pdf = new Mpdf($mpdfConfig);
            $viewData = compact('language', 'details', 'results', 'chart_images', 'results_cat', 'operator', 'operation', 'details_config');
            $htmlContent = view('reports.ils', $viewData)->render();
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

            foreach ($chart_images as $image) {
                File::delete($image['img_dev']);
                File::delete($image['img_mod']);
                File::delete($image['img_field']);
            }

            // Show the report to the user
            return Storage::disk('s3')->response($s3PdfPath);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error to generate report'], 500);
        }
    }

    function ddmToMicroamps($ddm, $glide_slope = false){
		/*
		* For localizers, a full scale deflection equates to a DDM value of 0.155 and requires
		* 150ua of current to move the needle to the full scale deflection.
		* Therefore to convert the DDM value to microamps (ua), multiply the DDM by the
		* conversion factor of (150/0.155)
		* For Glide Slope, a full scale deflection equates to a DDM value of 0.175
		*/
		if($glide_slope){
			$constant = 857.142857142; // == (150/0.175)
		} else {
			$constant = 967.741935484; // == (150/0.155)
		}

		return round($ddm * $constant, 3);
	}

    function get_average($pos, $media_file)
    {
        $tempFolderPath = public_path('temp/extra');
        $localFilePath = $tempFolderPath . '/' . $media_file->file_name;
        // Leer el archivo CSV existente
        $reader = Reader::createFromPath($localFilePath, 'r');

        $arr = $reader->fetchColumn((float) $pos);
        $arr = iterator_to_array($arr, false);
        $arr = array_map("floatval", $arr);
        array_shift($arr);
        $arr = array_filter($arr);

        if(count($arr)) {
            $average = array_sum($arr)/count($arr);
        }else{
            $average = 0; // Si no hay elementos en el array, el promedio es 0 y no 'vacio/undefined'
        }

        return $average;
    }

    // Custom function to push array assoc
    function array_push_assoc($array, $key, $value){
        if(isset($array[$key])){
            $array[$key][count($array[$key])] = $value;
            return $array;
        }else{
            $array[$key][0] = $value;
            return $array;
        }
    }

    // Custom function to sort ddm
    function array_sort($array, $order){
        $array_order = [];
        $find_index = "";

        foreach($array as $values){
            if($order=='max'){
                array_push($array_order, $values[0]);
            }else{
                array_push($array_order, $values[2]);
            }
        }
        if($order=='max'){
            $find_index = array_search(max($array_order), $array_order);
        }else{
            $find_index = array_search(min($array_order), $array_order);
        }

        return $find_index;
    }

    function find_structure_value($zone_structure_data, $key){
        //select value structure
        $index_max_structure_data = $this->array_sort($zone_structure_data, 'max');
        $index_min_structure_data = $this->array_sort($zone_structure_data, 'min');

        $max_structure = [$zone_structure_data[$index_max_structure_data][0]
                        ,$zone_structure_data[$index_max_structure_data][1]];
        $min_structure = [$zone_structure_data[$index_min_structure_data][2]
                        ,$zone_structure_data[$index_min_structure_data][3]];

        if(abs($max_structure[0]>abs($min_structure[0]))){
            //El valor de structure V es el $max_structure [0]ddm y [1]ua
            return $max_structure;
        }elseif(abs($max_structure[0]<abs($min_structure[0]))){
            return $min_structure;
        }else{
            return $max_structure;
        }
    }

    function course_alignment($media_file, $ils, $header, $result, $task, $operation, $chart_type){
        $folderMapping = Operation::getFolderMapping();
        $s3FilePath = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$media_file->task_id}/{$media_file->file_name}";

        // Descargar el archivo desde S3 y guardarlo temporalmente en public/temp/extra
        if (Storage::disk('s3')->exists($s3FilePath)) {
            \Log::info("[IlsReport][course_alignment] Archivo encontrado en S3: {$s3FilePath}");
        } else {
            \Log::error("[IlsReport][course_alignment] Archivo NO encontrado en S3: {$s3FilePath}");
        }
        $csv = Storage::disk('s3')->get($s3FilePath);
        $tempFolderPath = public_path('temp/extra');
        $localFilePath = $tempFolderPath . '/' . $media_file->file_name;
        File::put($localFilePath, $csv);

        // Leer el archivo CSV desde la nueva ubicación temporal
        $reader = Reader::createFromPath($localFilePath, 'r');

        $result_latitude = $reader->fetchColumn((float) 0);
        $result_longitude = $reader->fetchColumn((float) 1);
        $result_position = $reader->fetchColumn((float) 4);
        $result_joint_sum_ddm = $reader->fetchColumn((float) 7);
        $result_joint_sum_sdm = $reader->fetchColumn((float) 10);
        $result_course_field_strength = $reader->fetchColumn((float) 14);
        $result_clearance_field_strength = $reader->fetchColumn((float) 23);

        //Define points A, B, C, D, E
        $pointA=$ils->point_a;
        $pointB=$ils->point_b;
        $pointC=$ils->point_c;
        $pointD=$ils->point_d;
        $pointE=$ils->point_e;

        $latitude = iterator_to_array($result_latitude, false);
        array_shift($latitude);
        $latitude = array_map("floatval", $latitude);
        $longitude = iterator_to_array($result_longitude, false);
        array_shift($longitude);
        $longitude = array_map("floatval", $longitude);
        $joint_sum_ddm = iterator_to_array($result_joint_sum_ddm, false);
        array_shift($joint_sum_ddm);
        $joint_sum_ddm = array_map("floatval", $joint_sum_ddm);
        $joint_sum_sdm = iterator_to_array($result_joint_sum_sdm, false);
        array_shift($joint_sum_sdm);
        $joint_sum_sdm = array_map("floatval", $joint_sum_sdm);
        $position = iterator_to_array($result_position, false);
        array_shift($position);
        $position = array_map("floatval", $position);
        $course_field_strength = iterator_to_array($result_course_field_strength, false);
        array_shift($course_field_strength);
        $course_field_strength = array_map("floatval", $course_field_strength);
        $clearance_field_strength = iterator_to_array($result_clearance_field_strength, false);
        array_shift($clearance_field_strength);
        $clearance_field_strength = array_map("floatval", $clearance_field_strength);

        $dataDeviation = new Data();
        $dataModulation = new Data();
        $dataField = new Data();

        $imgWidth = 1600;
        $imgHeight = 350;
        $maxXLabels = 40;
        $lowerDistanceValue = 0;
        $higherDistanceValue = 0;
        $maxScatterModulation = 44;
        $minScatterModulation = 36;
        $fieldScaleMax = 10;
        $fieldScaleMin = -110;
        $upperFieldLimit = -10;
        $lowerFieldLimit = -90;
        // $lowerDDM = -400; // The -400 to 400 range is taken from the AENA ILS Report
        // $higherDDM = 400;
        $lowerDDM = -0.09;
        $higherDDM = 0.09;
        $dataLength = 0;
        $previousDistanceToLLZ = null; // This is set high in order to make it  got through  second if statement

        $test = 0;

        //$sdmInPercentage = $joint_sum_sdm[$key]*100; // TODO: CHANGE THIS TO 100 IN ORDER TO MAKE THE NUMBER TO THE TENS
        $sdmInPercentage = [];
        foreach($joint_sum_sdm as $joint_sum_sdm_key){
            $test = $joint_sum_sdm_key*100;
            array_push($sdmInPercentage, $test);
        }

        //Revisar los valores para las tolerances de structure
        if($operation->type_id == 5){ // ILS LOC
            switch ($ils->category_id) {
                case 1:
                    $tolerance_cat_max = array(0.0153, 0.0153, 0.3125);
                    $tolerance_cat_min = array(-0.0153, -0.0153, -0.3125);
                    $tolerance_points =array($pointC, $pointB, $pointA);
                    break;
                case 2:
                    $tolerance_cat_max = array(0.005, 0.005, 0.005, 0.3125);
                    $tolerance_cat_min = array(-0.005, -0.005, -0.005, -0.3125);
                    $tolerance_points = array($pointD, $pointC, $pointB, $pointA);
                    break;
                case 3:
                    $tolerance_cat_max = array(0.010, 0.005, 0.005, 0.005, 0.3125);
                    $tolerance_cat_min = array(-0.010, -0.005, -0.005, -0.005, -0.3125);
                    $tolerance_points = array($pointE, $pointD, $pointC, $pointB, $pointA);
                    break;
                default:
                    $tolerance_cat_max = array(-0.010, 0.005, 0.005, 0.005, 0.3125);
                    $tolerance_cat_min = array(-0.010, -0.005, -0.005, -0.005, -0.3125);
                    $tolerance_points = array($pointE, $pointD, $pointC, $pointB, $pointA);
                    break;
            }

        }else{ // ILS GP
            switch ($ils->category_id) {
                case 1:
                    $tolerance_cat_max = array(0.035, 0.035);
                    $tolerance_cat_min = array(-0.035, -0.035);
                    $tolerance_points = array($pointC, $pointA);
                    break;
                case 2:
                    $tolerance_cat_max = array(0.023, 0.025, 0.035);
                    $tolerance_cat_min = array(-0.023, -0.025, -0.035);
                    $tolerance_points = array(0, $pointB, $pointA);
                    break;
                case 3:
                    $tolerance_cat_max = array(0.023, 0.025, 0.035);
                    $tolerance_cat_min = array(-0.023, -0.025, -0.035);
                    $tolerance_points = array(0, $pointB, $pointA);
                    break;
                default:
                    $tolerance_cat_max = array(0.023, 0.025, 0.035);
                    $tolerance_cat_min = array(-0.023, -0.025, -0.035);
                    $tolerance_points = array(0, $pointB, $pointA);
                    break;
            }
        }

        $dataDeviation->addPoints($joint_sum_ddm, "DDM");
        $dataDeviation->addPoints($tolerance_cat_max, "DDM_max");
        $dataDeviation->addPoints($tolerance_cat_min, "DDM_min");
        $dataDeviation->addPoints($tolerance_points, "Distance_tolerance");
        $dataDeviation->addPoints($position, "Distance");

        // Add point to Modulation Graph
        $dataModulation->addPoints($sdmInPercentage, "SDM");
        $dataModulation->addPoints($position, "Distance");

        // Add points to Field Graph
        $dataField->addPoints($clearance_field_strength, "Field Strength Clearance");
        $dataField->addPoints($course_field_strength, "Field Strength Course");
        $dataField->addPoints($position, "Distance");

        // Configure axis for Deviation Graph
        $dataDeviation->setSerieOnAxis("Distance", 0);
        $dataDeviation->setSerieOnAxis("Distance_tolerance", 0);
        $dataDeviation->setAxisName(0, "Distance");
        $dataDeviation->setAxisUnit(0, " m");
        $dataDeviation->setAxisXY(0, AXIS_X);
        $dataDeviation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataDeviation->setSerieOnAxis("DDM", 1);
        $dataDeviation->setSerieOnAxis("DDM_max", 1);
        $dataDeviation->setSerieOnAxis("DDM_min", 1);
        $dataDeviation->setAxisName(1, "DDM");
        $dataDeviation->setAxisUnit(1, "");
        $dataDeviation->setAxisXY(1, AXIS_Y);
        $dataDeviation->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Configure axis for Modulation Graph
        $dataModulation->setAxisName(0, "Distance");
        $dataModulation->setAxisUnit(0, " m");
        $dataModulation->setAxisXY(0, AXIS_X);
        $dataModulation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataModulation->setSerieOnAxis("SDM", 1);
        $dataModulation->setAxisName(1, "SDM %");
        $dataModulation->setAxisUnit(1, "");
        $dataModulation->setAxisXY(1, AXIS_Y);
        $dataModulation->setAxisPosition(1, AXIS_POSITION_LEFT);

        //Configure axis for Field Strength
        $dataField->setAxisName(0, "Distance");
        $dataField->setAxisUnit(0, " m");
        $dataField->setAxisXY(0, AXIS_X);
        $dataField->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataField->setSerieOnAxis("Field Strength Clearance", 1);
        $dataField->setSerieOnAxis("Field Strength Course", 1);
        $dataField->setAxisName(1, "Field Strength dBm");
        $dataField->setAxisUnit(1, "");
        $dataField->setAxisXY(1, AXIS_Y);
        $dataField->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Create data binding (DDM/Distance) for Deviation Graph
        $dataDeviation->setScatterSerie("Distance", "DDM", 0);
        $dataDeviation->setScatterSerieDescription(0, "DDM [DDM]");
        $dataDeviation->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));


        $dataDeviation->setScatterSerie("Distance_tolerance", "DDM_max", 1);
        $dataDeviation->setScatterSerie("Distance_tolerance", "DDM_min", 2);
        $dataDeviation->setScatterSerieDescription(1, "DDM_max");
        $dataDeviation->setScatterSerieDescription(2, "DDM_min");
        $dataDeviation->setScatterSerieColor(1, array("R" => 255, "G" => 0, "B" => 0));
        $dataDeviation->setScatterSerieTicks(1,4);
        $dataDeviation->setScatterSerieColor(2, array("R" => 255, "G" => 0, "B" => 0));
        $dataDeviation->setScatterSerieTicks(2,4);

        // Create data binding (SDM/Distance) for Modulation Graph
        $dataModulation->setScatterSerie("Distance", "SDM", 0);
        $dataModulation->setScatterSerieDescription(0, "SDM [%]");
        $dataModulation->setScatterSerieColor(0, array("R" => 242, "G" => 122, "B" => 16));

        $dataField->setScatterSerie("Distance", "Field Strength Course", 0);
        $dataField->setScatterSerieDescription(0, "Course Field Strength [dBm]");
        $dataField->setScatterSerieColor(0, array("R" => 242, "G" => 122, "B" => 16));

        // Create data binding (dBm/Distance) for Field Strength Graph
        $dataField->setScatterSerie("Distance", "Field Strength Clearance", 1);
        $dataField->setScatterSerieDescription(1, "Clearance Field Strength [dBm]");
        $dataField->setScatterSerieColor(1, array("R" => 30, "G" => 36, "B" => 96));

        $pictureDeviation = new Image($imgWidth, $imgHeight, $dataDeviation);
        $pictureModulation = new Image($imgWidth, $imgHeight, $dataModulation);
        $pictureField = new Image($imgWidth, $imgHeight, $dataField);

        // Turn off Antialiasing
        $pictureDeviation->Antialias = FALSE;
        $pictureModulation->Antialias = FALSE;
        $pictureField->Antialias = FALSE;

        // Set the default fonts
        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));

        // Define the chart areas
        $pictureDeviation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureModulation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureField->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);

        // Create the Scatter chart objects
        $scatterDeviation = new Scatter($pictureDeviation, $dataDeviation);
        $scatterModulation = new Scatter($pictureModulation, $dataModulation);
        $scatterField = new Scatter($pictureField, $dataField);


        // These set the higher and lower X axis boundaries
        // Use min/max to avoid index-out-of-bounds when the CSV has very few data rows
        if (empty($position)) {
            throw new \Exception("No position data found in CSV for ILS course alignment chart.");
        }
        $lowerDistanceValue  = min($position) - 100;
        $higherDistanceValue = max($position) + 100;


        // Draw the scale for Deviation Graph
        $axisBoundariesDeviation = array(
            0 => array("Min" => $lowerDistanceValue, "Max" => $higherDistanceValue), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $lowerDDM, "Max" => $higherDDM));
        $scaleSettingsDeviation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesDeviation,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        if($operation->type_id == 5){ // if localizer ground inspection (operation type)
            $sdmYScaleMin = 20;
            $sdmYScaleMax = 60;
        } else { // if glide path ground inspection (operation type)
            $sdmYScaleMin = 60;
            $sdmYScaleMax = 100;
        }

        // Draw the scale for Modulation Graph
        $axisBoundariesModulation = array(
            0 => array("Min" => $lowerDistanceValue, "Max" => $higherDistanceValue), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $sdmYScaleMin, "Max" => $sdmYScaleMax));
        $scaleSettingsModulation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesModulation,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Draw the scale for Field Strength Graph
        $axisBoundariesField = array(
            0 => array("Min" => $lowerDistanceValue, "Max" => $higherDistanceValue), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $fieldScaleMin, "Max" => $fieldScaleMax));
        $scaleSettingsField = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesField,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Draw the scales
        $scatterDeviation->drawScatterScale($scaleSettingsDeviation);
        $scatterModulation->drawScatterScale($scaleSettingsModulation);
        $scatterField->drawScatterScale($scaleSettingsField);

        // Turn on Antialiasing
        $pictureDeviation->Antialias = TRUE;
        $pictureModulation->Antialias = TRUE;
        $pictureField->Antialias = TRUE;

        // Draw a scatter plot chart for Deviation Graph
        $scatterDeviation->drawScatterLineChart();

        // Draw a scatter plot chart for Modulation Graph
        $scatterModulation->drawScatterLineChart();

        // Draw a scatter plot chart for Field Graph
        $scatterField->drawScatterLineChart();

        switch ($ils->category_id) { // Category of ILS
            case 1:
                $upperLimit = 0.015;
                $lowerLimit = -0.015;
                break;
            case 2:
                $upperLimit = 0.010;
                $lowerLimit = -0.010;
                break;
            case 3:
                $upperLimit = 0.004;
                $lowerLimit = -0.004;
                break;
            default:
                $upperLimit = 0.015;
                $lowerLimit = -0.015;
                break;
        }

        //Draw tolerances
        if($operation->type_id == 5) {
            //$scatterDeviation->drawScatterThreshold($upperLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $upperLimit . "DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
            //$scatterDeviation->drawScatterThreshold($lowerLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $lowerLimit . "DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
            $scatterDeviation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

            $scatterModulation->drawScatterThreshold($maxScatterModulation, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $maxScatterModulation . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
            $scatterModulation->drawScatterThreshold($minScatterModulation, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $minScatterModulation . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
            $scatterModulation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

            $scatterField->drawScatterThreshold($upperFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $upperFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
            $scatterField->drawScatterThreshold($lowerFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $lowerFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
            $scatterField->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

        } else {
            $scatterDeviation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterDeviation->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterDeviation->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

            $scatterModulation->drawScatterThreshold(85, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => 85 . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
            $scatterModulation->drawScatterThreshold(75, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => 75 . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
            $scatterModulation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterModulation->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterModulation->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

            $scatterField->drawScatterThreshold($upperFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $upperFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
            $scatterField->drawScatterThreshold($lowerFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $lowerFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
            $scatterField->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "THR", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointA, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "A", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointB, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "B", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            $scatterField->drawScatterThreshold($pointC, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "C", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterField->drawScatterThreshold($pointD, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "D", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line
            //$scatterField->drawScatterThreshold($pointE, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 132, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => "E", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line

        }

        // Write the chart legend for Deviation Graph
        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterDeviation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        // Write the chart legend for Modulation Graph
        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterModulation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        // Write the chart legend for Field Strength Graph
        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterField->drawScatterLegend(1250, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        $aux_name = '';
        if($chart_type == 'Glide Path Angle Alarm (Low)'){
            $aux_name = 'low';
        }elseif($chart_type == 'Glide Path Angle Alarm (High)'){
            $aux_name = 'high';
        }elseif($chart_type == 'Course alarm Left'){
            $aux_name = 'left';
        }elseif($chart_type == 'Course alarm Right'){
            $aux_name = 'right';
        }

        if($result->transmitter == 1){
            $chart_type = $chart_type . ' TX1';
        } else {
            $chart_type = $chart_type . ' TX2';
        }

        // Render charts to image files
        $imgDir = public_path('temp/extra/');
        $imgDev   = $imgDir . 'course_alignment_dev'.$aux_name.$result->transmitter.'.png';
        $imgMod   = $imgDir . 'course_alignment_mod'.$aux_name.$result->transmitter.'.png';
        $imgField = $imgDir . 'course_alignment_field'.$aux_name.$result->transmitter.'.png';

        $pictureModulation->render($imgMod);
        $pictureDeviation->render($imgDev);
        $pictureField->render($imgField);

        return ['img_dev' => $imgDev, 'img_mod' => $imgMod, 'img_field' => $imgField, 'chart_type' => $chart_type];
    }

    function multi_charts($media_file, $ils, $header, $result, $task, $operation){

        $maxScatterModulation = 44;
        $minScatterModulation = 36;
        $imgWidth = 1600;
        $imgHeight = 350;
        $maxXLabels = 40;
        $maxAngle = 0;
        $lowerDDM = -0.26; // The -400 to 400 range is taken from the AENA ILS Report
        $higherDDM = 0.30;
        $dataLength = 0;
        $runwayBearing = $this->getRhumbLineBearing($header->threshold_latitude, $header->threshold_longitude, $ils->loc_antenna_latitude, $ils->loc_antenna_longitude); //thr latitude, thr longitude, ils_antenna lat, ils_antenna long
        $previousAngle = -INF;
        $positiveLimit = 0; // 150 for NW and Al or 175 uA for Clearance
        $negativeLimit = 0; // -150 for NW and Al or -175 uA for Clearance
        $positiveLimit75 = 0;
        $negativeLimit75 = 0;
        $fieldScaleMax = 10;
        $fieldScaleMin = -110;
        $upperFieldLimit = -10;
        $lowerFieldLimit = -80;
        $asc = false;

        $angle = 0;
        $isGlideSlope = ($operation->type_id == 6) ? true : false;

        $dataDeviation = new Data();
        $dataModulation = new Data();
        $dataField = new Data();

        $chart_type = $task->type_id;

        if($chart_type == 9){
            $chart_type = 'nominal_width';
            $name = 'Width';
        } elseif ($chart_type == 10) {
            $chart_type = 'course_alarm_left';
            $name = 'Course Alarm Left';
        } elseif ($chart_type == 11) {
            $chart_type = 'course_alarm_right';
            $name = 'Course Alarm Right';
        } else if($chart_type ==12){
            $chart_type = 'alarm_min';
            $name = 'Min. Width Alarm';
        } else if($chart_type == 13){
            $chart_type = 'alarm_max';
            $name = 'Max. Width Alarm';
        } else if($chart_type == 14){
            $chart_type = 'clearance';
            $name = 'Clearance';
        } else if($chart_type == 18){
            $chart_type = 'glide_path_width';
            $name = 'Glide Path Width';
        } else if($chart_type == 19){
            $chart_type = 'glide_path_alarm_low';
            $name = 'Glide Path Angle Alarm (Low)';
        } else if($chart_type == 20){
            $chart_type = 'glide_path_alarm_high';
            $name = 'Glide Path Angle Alarm (High)';
        } else if($chart_type == 21){
            $chart_type = 'glide_path_width_alarm_min';
            $name = 'Glide Path Width Alarm (Min)';
        }else if($chart_type == 22){
            $chart_type = 'glide_path_width_alarm_max';
            $name = 'Glide Path Width Alarm (Max)';
        }else if($chart_type == 44){
            $chart_type = 'glide_path_trans';
            $name = 'Glide Path Transverse Check';
        } else {
            die('ERROR: Invalid chart type ' . $chart_type);
        }

        $folderMapping = Operation::getFolderMapping();
        $s3FilePath = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$media_file->task_id}/{$media_file->file_name}";

        // Descargar el archivo desde S3 y guardarlo temporalmente en public/temp/extra
        if (Storage::disk('s3')->exists($s3FilePath)) {
            \Log::info("[IlsReport][multi_charts] Archivo encontrado en S3: {$s3FilePath}");
        } else {
            \Log::error("[IlsReport][multi_charts] Archivo NO encontrado en S3: {$s3FilePath}");
        }
        $csv = Storage::disk('s3')->get($s3FilePath);
        $tempFolderPath = public_path('temp/extra');
        $localFilePath = $tempFolderPath . '/' . $media_file->file_name;
        File::put($localFilePath, $csv);

        // Leer el archivo CSV desde la nueva ubicación temporal
        $reader = Reader::createFromPath($localFilePath, 'r');

        $result_latitude = $reader->fetchColumn((float) 0);
        $result_longitude = $reader->fetchColumn((float) 1);
        $result_hsml = $reader->fetchColumn((float) 2);
        $result_position = $reader->fetchColumn((float) 4);
        $result_joint_sum_ddm = $reader->fetchColumn((float) 7);
        $result_joint_sum_sdm = $reader->fetchColumn((float) 11);
        $result_course_ddm = $reader->fetchColumn((float) 13);
        $result_course_field_strength = $reader->fetchColumn((float) 16);
        $result_clearance_ddm = $reader->fetchColumn((float) 22);
        $result_clearance_sdm = $reader->fetchColumn((float) 24);
        $result_clearance_field_strength = $reader->fetchColumn((float) 25);

        $latitude = iterator_to_array($result_latitude, false);
        array_shift($latitude);
        $latitude = array_map("floatval", $latitude);
        $longitude = iterator_to_array($result_longitude, false);
        array_shift($longitude);
        $longitude = array_map("floatval", $longitude);
        $hsml = iterator_to_array($result_hsml, false);
        array_shift($hsml);
        $hsml = array_map("floatval", $hsml);
        $joint_sum_ddm = iterator_to_array($result_joint_sum_ddm, false);
        array_shift($joint_sum_ddm);
        $joint_sum_ddm = array_map("floatval", $joint_sum_ddm);
        $course_ddm = iterator_to_array($result_course_ddm, false);
        array_shift($course_ddm);
        $course_ddm = array_map("floatval", $course_ddm);
        $joint_sum_sdm = iterator_to_array($result_joint_sum_sdm, false);
        array_shift($joint_sum_sdm);
        $joint_sum_sdm = array_map("floatval", $joint_sum_sdm);
        $position = iterator_to_array($result_position, false);
        array_shift($position);
        $position = array_map("floatval", $position);
        $clearance_ddm = iterator_to_array($result_clearance_ddm, false);
        array_shift($clearance_ddm);
        $clearance_ddm = array_map("floatval", $clearance_ddm);
        $clearance_sdm = iterator_to_array($result_clearance_sdm, false);
        array_shift($clearance_sdm);
        $clearance_sdm = array_map("floatval", $clearance_sdm);
        $course_field_strength = iterator_to_array($result_course_field_strength, false);
        array_shift($course_field_strength);
        $course_field_strength = array_map("floatval", $course_field_strength);
        $clearance_field_strength = iterator_to_array($result_clearance_field_strength, false);
        array_shift($clearance_field_strength);
        $clearance_field_strength = array_map("floatval", $clearance_field_strength);

        //dd($position[0], $position[count($position)-1]);

        $sdmInPercentage = [];
        foreach($joint_sum_sdm as $joint_sum_sdm_key){
            $test = $joint_sum_sdm_key*100;
            array_push($sdmInPercentage, $test);
        }

        if($task->type_id == 14){ // LOC Clearance Limits
            // Nominal angle /2 for tolerance
            $half_nominal_angle = $ils->nominal_width;
            // Valores de tolerancia LOC Clearance
            $distance_clearance_1   = array(-35, -10);
            $value_clearance_1      = array(0.155, 0.155);
            $distance_clearance_2   = array(-10, -$half_nominal_angle);
            $value_clearance_2      = array(0.180, 0.180);
            $distance_clearance_3   = array(floatval($half_nominal_angle), 10);
            $value_clearance_3      = array(-0.180, -0.180);
            $distance_clearance_4   = array(10, 35);
            $value_clearance_4      = array(-0.155, -0.155);
        }

        // These set the higher and lower Y axis boundaries
        $lowerDDM = min($joint_sum_ddm);
        $higherDDM = max($joint_sum_ddm);

        // Add point to Deviation Graph

        $joint_sum_ddm = array_map(function($val) {
            return (abs($val) < 1e-10) ? 0 : $val;
        }, $joint_sum_ddm);

        $dataDeviation->addPoints($joint_sum_ddm, "DDM");

        if($task->type_id == 14){
            $dataDeviation->addPoints($value_clearance_1, "Tolerance_value_1");
            $dataDeviation->addPoints($distance_clearance_1, "Tolerance_angle_1");
            $dataDeviation->addPoints($value_clearance_2, "Tolerance_value_2");
            $dataDeviation->addPoints($distance_clearance_2, "Tolerance_angle_2");
            $dataDeviation->addPoints($value_clearance_3, "Tolerance_value_3");
            $dataDeviation->addPoints($distance_clearance_3, "Tolerance_angle_3");
            $dataDeviation->addPoints($value_clearance_4, "Tolerance_value_4");
            $dataDeviation->addPoints($distance_clearance_4, "Tolerance_angle_4");
        }

        $position_clean = array_map(function($val) {
            return (abs($val) < 1e-10) ? 0 : $val;
        }, $position);

        $dataDeviation->addPoints($position_clean, "Angle");

        // Add point to Modulation Graph
        $dataModulation->addPoints($sdmInPercentage, "SDM");
        $dataModulation->addPoints($position, "Angle");

        // Add points to Field Graph
        $dataField->addPoints($clearance_field_strength, "Field Strength Clearance");
        $dataField->addPoints($course_field_strength, "Field Strength Course");
        $dataField->addPoints($position, "Angle");

        foreach ($position as $key => $value) {
            if($operation->type_id == 5){
                if($chart_type == 'clearance'){
                    // For clearance, draw the red lines when crossing 175 uA
                    if($joint_sum_ddm[$key] == 0.180)
                        $positiveLimit = $angle;
                    if($joint_sum_ddm[$key] <= -0.180 && $angle >= 0 && $angle < 10)
                        $negativeLimit = $angle;
                } else {
                    // For the rest of the charts, draw the dashed line when crossing 150 uA
                    if($joint_sum_ddm[$key] >= 0.155 && $angle <= 0 && $angle > -10)
                        $positiveLimit = $angle;
                    if($joint_sum_ddm[$key] <= -0.155 && $angle >= 0 && $angle < 10 && $negativeLimit == 0)
                        $negativeLimit = $angle;

                    // Also, calculate the crossings at 75 uA
                    if($joint_sum_ddm[$key] >= 0.0775 && $angle <= 0 && $angle > -10)
                        $positiveLimit75 = $angle;
                    if($joint_sum_ddm[$key] <= -0.0775 && $angle >= 0 && $angle < 10 && $negativeLimit75 == 0)
                        $negativeLimit75 = $angle;
                }
            } else {
                // Only calculate the crossings at 75 uA (no 150 uA in GS)
                if($joint_sum_ddm[$key] <= -0.0775 && $angle <= $ils->gp_angle ) // 3.00 glidepath angle
                    $positiveLimit75 = $angle;
                if($joint_sum_ddm[$key] >= 0.0775 && $angle >= $ils->gp_angle  && $negativeLimit75 == 0) // 3.00 glidepath angle
                    $negativeLimit75 = $angle;
            }

            // Set the higher and lower X axis boundaries
            if(abs($angle) > $maxAngle) // This way the 0º mark is always in the middle
                $maxAngle = ceil(abs($angle)); // The max angle is rounded up to avoid an error in the graph lib where the 01 value in the X axis shows a weird number

            // Record the previous disangletance value
            $previousAngle = $angle;
        }

        // Configure axis for Deviation Graph
        $dataDeviation->setAxisName(0, "Angle");
        $dataDeviation->setAxisUnit(0, " &#176;");
        $dataDeviation->setAxisXY(0, AXIS_X);
        $dataDeviation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $dataDeviation->setSerieOnAxis("DDM", 1);
            if($task->type_id == 14){
                $dataDeviation->setSerieOnAxis("Tolerance_value_1", 1);
                $dataDeviation->setSerieOnAxis("Tolerance_angle_1", 0);
                $dataDeviation->setSerieOnAxis("Tolerance_value_2", 1);
                $dataDeviation->setSerieOnAxis("Tolerance_angle_2", 0);
                $dataDeviation->setSerieOnAxis("Tolerance_value_3", 1);
                $dataDeviation->setSerieOnAxis("Tolerance_angle_3", 0);
                $dataDeviation->setSerieOnAxis("Tolerance_value_4", 1);
                $dataDeviation->setSerieOnAxis("Tolerance_angle_4", 0);
            }
            $dataDeviation->setAxisName(1, "DDM");
        //}
        $dataDeviation->setAxisUnit(1, "");
        $dataDeviation->setAxisXY(1, AXIS_Y);
        $dataDeviation->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Configure axis for Modulation Graph
        $dataModulation->setAxisName(0, "Angle");
        $dataModulation->setAxisUnit(0, " &#176;");
        $dataModulation->setAxisXY(0, AXIS_X);
        $dataModulation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $dataModulation->setSerieOnAxis("SDM", 1);
            $dataModulation->setAxisName(1, "SDM %");
        //}
        $dataModulation->setAxisUnit(1, "");
        $dataModulation->setAxisXY(1, AXIS_Y);
        $dataModulation->setAxisPosition(1, AXIS_POSITION_LEFT);

        //Configure axis for Field Strength
        $dataField->setAxisName(0, "Angle");
        $dataField->setAxisUnit(0, " &#176;");
        $dataField->setAxisXY(0, AXIS_X);
        $dataField->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        //if($latitude[$key] != 0 && $longitude[$key] != 0){
            $dataField->setSerieOnAxis("Field Strength Clearance", 1);
            $dataField->setSerieOnAxis("Field Strength Course", 1);
        //}
        $dataField->setAxisName(1, "Field Strength dBm");
        $dataField->setAxisUnit(1, "");
        $dataField->setAxisXY(1, AXIS_Y);
        $dataField->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Create data binding (DDM/Angle) for Deviation Graph
        $dataDeviation->setScatterSerie("Angle", "DDM", 0);
        $dataDeviation->setScatterSerieDescription(0, "DDM");
        $dataDeviation->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));
        if($task->type_id == 14){
            $dataDeviation->setScatterSerie("Tolerance_angle_1", "Tolerance_value_1", 1);
            $dataDeviation->setScatterSerie("Tolerance_angle_2", "Tolerance_value_2", 2);
            $dataDeviation->setScatterSerie("Tolerance_angle_3", "Tolerance_value_3", 3);
            $dataDeviation->setScatterSerie("Tolerance_angle_4", "Tolerance_value_4", 4);
            $dataDeviation->setScatterSerieDescription(1, "0.155");
            $dataDeviation->setScatterSerieDescription(2, "0.180");
            $dataDeviation->setScatterSerieDescription(3, "-0.180");
            $dataDeviation->setScatterSerieDescription(4, "-0.155");
            $dataDeviation->setScatterSerieColor(1, array("R" => 255, "G" => 0, "B" => 0));
            $dataDeviation->setScatterSerieTicks(1,4);
            $dataDeviation->setScatterSerieColor(2, array("R" => 255, "G" => 0, "B" => 0));
            $dataDeviation->setScatterSerieTicks(2,4);
            $dataDeviation->setScatterSerieColor(3, array("R" => 255, "G" => 0, "B" => 0));
            $dataDeviation->setScatterSerieTicks(3,4);
            $dataDeviation->setScatterSerieColor(4, array("R" => 255, "G" => 0, "B" => 0));
            $dataDeviation->setScatterSerieTicks(4,4);
        }

        // Create data binding (SDM/Angle) for Modulation Graph
        $dataModulation->setScatterSerie("Angle", "SDM", 0);
        $dataModulation->setScatterSerieDescription(0, "SDM [%]");
        $dataModulation->setScatterSerieColor(0, array("R" => 242, "G" => 122, "B" => 16));

        // Create data binding (dBm/Distance) for Field Strength Graph
        $dataField->setScatterSerie("Angle", "Field Strength Course", 0);
        $dataField->setScatterSerieDescription(0, "Course Field Strength [dBm]");
        $dataField->setScatterSerieColor(0, array("R" => 242, "G" => 122, "B" => 16));

        // Create data binding (dBm/Distance) for Field Strength Graph
        $dataField->setScatterSerie("Angle", "Field Strength Clearance", 1);
        $dataField->setScatterSerieDescription(1, "Clearance Field Strength [dBm]");
        $dataField->setScatterSerieColor(1, array("R" => 30, "G" => 36, "B" => 96));

        // Create the image objects
        $pictureDeviation = new Image($imgWidth, $imgHeight, $dataDeviation);
        $pictureModulation = new Image($imgWidth, $imgHeight, $dataModulation);
        $pictureField = new Image($imgWidth, $imgHeight, $dataField);

        // Turn of Antialiasing
        $pictureDeviation->Antialias = FALSE;
        $pictureModulation->Antialias = FALSE;
        $pictureField->Antialias = FALSE;

        // Set the default fonts
        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));

        // Define the chart areas
        $pictureDeviation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureModulation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureField->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);

        // Create the Scatter chart objects
        $scatterDeviation = new Scatter($pictureDeviation, $dataDeviation);
        $scatterModulation = new Scatter($pictureModulation, $dataModulation);
        $scatterField = new Scatter($pictureField, $dataField);

        // Forcing boundaries from -40 to 40 degrees (For clearance only)
        if($chart_type == 'clearance'){
            $maxAngle = 40;
        }

        if($operation->type_id == 5){ //loc
            $minAngle = $maxAngle;
            $minAngle = $maxAngle * -1;
            $sdmYScaleMin = 20;
            $sdmYScaleMax = 60;
            if($chart_type == 'nominal_width' || $chart_type == 'alarm_min' || $chart_type == 'alarm_max'){
                $minAngle = $position[0]-0.5;
                $maxAngle = $position[count($position)-1]+0.5;
            }
        } else {
            $maxAngle = $ils->gp_angle * 2; // 3.00 = glidepath_angle
            $minAngle = 0;
            $sdmYScaleMin = 60;
            $sdmYScaleMax = 100;

            if($chart_type == 'glide_path_trans') //GP Transverse Check
            {
                $maxAngle = 10;
                $minAngle = -10;
            }
        }

        // Draw the scale for Deviation Graph
        $axisBoundariesDeviation = array(
            0 => array("Min" => $minAngle, "Max" => $maxAngle), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $lowerDDM, "Max" => $higherDDM));
        $scaleSettingsDeviation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesDeviation,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $axisBoundariesModulation = array(
            0 => array("Min" => $minAngle, "Max" => $maxAngle), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $sdmYScaleMin, "Max" => $sdmYScaleMax));
        $scaleSettingsModulation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesModulation,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Draw the scale for Field Strength Graph
        $axisBoundariesField = array(
            0 => array("Min" => $minAngle, "Max" => $maxAngle), // , "Rows" => 12, "RowHeight" => 300
            1 => array("Min" => $fieldScaleMin, "Max" => $fieldScaleMax));
        $scaleSettingsField = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesField,
            "LabelSkip" => floor($dataLength / $maxXLabels),
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Draw the scales
        $scatterDeviation->drawScatterScale($scaleSettingsDeviation);
        $scatterModulation->drawScatterScale($scaleSettingsModulation);
        $scatterField->drawScatterScale($scaleSettingsField);

        // Turn on Antialiasing
        $pictureDeviation->Antialias = TRUE;
        $pictureModulation->Antialias = TRUE;
        $pictureField->Antialias = TRUE;

        // Draw a scatter plot chart for Deviation Graph
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterDeviation->drawScatterLineChart();
        }
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterModulation->drawScatterLineChart();
        }
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterField->drawScatterLineChart();
        }

        if($operation->type->id == 5){
            $signal_angle = 0;
            $width_caption = "THR";
        }else{
            $signal_angle = $ils->gp_angle;
            $width_caption = "Nominal Angle";
        }

        // Draw the thresholds for Deviation Graph
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterDeviation->drawScatterThreshold(0, array("AxisID" => 1, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 0)); // Zero
            if($operation->type_id == 5){
                $scatterDeviation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 0)); // Vertical line
                $scatterModulation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 0)); // Vertical line
                $scatterField->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 0)); // Vertical line
            } else {
                $scatterDeviation->drawScatterThreshold($signal_angle, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 0, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => $width_caption, "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line, 3.00 is glide_path_angle

            }
        }


        if($chart_type == 'clearance'){
            /* $scatterDeviation->drawScatterThreshold(-10, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold(10, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line

            $scatterDeviation->drawScatterThreshold(-35, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line
            $scatterDeviation->drawScatterThreshold(35, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line

            // Draw the thresholds for Modulation Graph
            $scatterModulation->drawScatterThreshold(-10, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold(10, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line

            $scatterModulation->drawScatterThreshold(-35, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line
            $scatterModulation->drawScatterThreshold(35, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 8, "Ticks" => 0)); // Vertical line */
        } else {
            // Only the Localizer graphs show 150/-150 lines
            if($operation->type_id == 5){ //LOC
                // The rest of the graphs show threshold lines
                $scatterDeviation->drawScatterThreshold(0.775, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "-75 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // 75 uA
                $scatterDeviation->drawScatterThreshold(-0.775, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "-75 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // -75 uA

                if($chart_type != 'course_alarm_left' || $chart_type != 'course_alarm_right'){
                    $scatterDeviation->drawScatterThreshold(0.155, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "0.155 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // 150 uA
                    $scatterDeviation->drawScatterThreshold(-0.155, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "-0.155 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // -150 uA
                }
            } else {
                if($chart_type == 'glide_path_width' || $chart_type == 'glide_path_width_alarm_min' || $chart_type == 'glide_path_width_alarm_max' || $chart_type == 'glide_path_trans'){
                    $scatterDeviation->drawScatterThreshold(0.0875, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "0.0875 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // 150 uA
                    $scatterDeviation->drawScatterThreshold(-0.0875, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => "-0.0875 DDM", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // -150 uA

                    $scatterModulation->drawScatterThreshold(85, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => 85 . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // 150 uA
                    $scatterModulation->drawScatterThreshold(75, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 2, "WriteCaption" => TRUE, "Caption" => 75 . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // -150 uA
                }
            }
        }

        // Write the chart legend for Deviation Graph
        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterDeviation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        }

        // Write the chart legend for Modulation Graph
        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterModulation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        }

        $scatterModulation->drawScatterThreshold($maxScatterModulation, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $maxScatterModulation . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
        $scatterModulation->drawScatterThreshold($minScatterModulation, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $minScatterModulation . "%", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
        if($latitude[$key] != 0 && $longitude[$key] != 0 /*&& $chart_type != 'glide_path_width'*/ && $chart_type != 'nominal_width' && $chart_type != 'clearance'){
            $scatterModulation->drawScatterThreshold($signal_angle, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 0, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => $width_caption, "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line, 3.00 is glide_path_angle
        }elseif($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterModulation->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 8, "Ticks" => 0)); // Vertical line
        }

        $scatterField->drawScatterThreshold($upperFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $upperFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Upper limit
        $scatterField->drawScatterThreshold($lowerFieldLimit, array("AxisID" => 1, "R" => 255, "G" => 0, "B" => 0, "Ticks" => 4, "WriteCaption" => TRUE, "Caption" => $lowerFieldLimit . "dBm", "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Lower limit
        if($latitude[$key] != 0 && $longitude[$key] != 0 /*&& $chart_type != 'glide_path_width'*/ && $chart_type != 'nominal_width' && $chart_type != 'clearance'){
            $scatterField->drawScatterThreshold($signal_angle, array("AxisID" => 0, "R" => 0, "G" => 132, "B" => 0, "Ticks" => 0, "WriteCaption" => TRUE, "Caption" => $width_caption, "CaptionR" => 0, "CaptionG" => 0, "CaptionB" => 0)); // Vertical line, 3.00 is glide_path_angle
        }elseif($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterField->drawScatterThreshold(0, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 8, "Ticks" => 0)); // Vertical line
        }

        if($chart_type == 'clearance'){
            $scatterDeviation->drawScatterThreshold(10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterDeviation->drawScatterThreshold(-10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterDeviation->drawScatterThreshold(35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterDeviation->drawScatterThreshold(-35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line

            $scatterModulation->drawScatterThreshold(10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterModulation->drawScatterThreshold(-10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterModulation->drawScatterThreshold(35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterModulation->drawScatterThreshold(-35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line

            $scatterField->drawScatterThreshold(10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterField->drawScatterThreshold(-10, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterField->drawScatterThreshold(35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line
            $scatterField->drawScatterThreshold(-35, array("AxisID" => 0, "R" => 0, "G" => 0, "B" => 0, "Ticks" => 4)); // Vertical line

        }


        // Write the chart legend for Field Strength Graph
        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        if($latitude[$key] != 0 && $longitude[$key] != 0){
            $scatterField->drawScatterLegend(1250, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));
        }

        if($result->transmitter == 1){
            $name = $name . ' TX1';
        } else {
            $name = $name . ' TX2';
        }

        // Render charts to image files
        $imgDir   = public_path('temp/extra/');
        $imgDev   = $imgDir . $chart_type . '_dev'.$result->transmitter.'.png';
        $imgMod   = $imgDir . $chart_type . '_mod'.$result->transmitter.'.png';
        $imgField = $imgDir . $chart_type . '_field'.$result->transmitter.'.png';

        $pictureDeviation->render($imgDev);
        $pictureModulation->render($imgMod);
        $pictureField->render($imgField);

        return ['img_dev' => $imgDev, 'img_mod' => $imgMod, 'img_field' => $imgField, 'chart_type' => $name];
    }

    /**
	 * https://stackoverflow.com/questions/10053358/measuring-the-distance-between-two-coordinates-in-php
	 * Calculates the great-circle distance between two points, with
	 * the Vincenty formula.
	 * @param float $latitudeFrom Latitude of start point in [deg decimal]
	 * @param float $longitudeFrom Longitude of start point in [deg decimal]
	 * @param float $latitudeTo Latitude of target point in [deg decimal]
	 * @param float $longitudeTo Longitude of target point in [deg decimal]
	 * @param float $earthRadius Mean earth radius in [m]
	 * @return float Distance between points in [m] (same as earthRadius)
	 */
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
		return rad2deg(atan2($dLon, $dPhi)) + 360;
    }

    function getAltitudeAngle($droneLat, $droneLon, $droneAlt, $pointLat, $pointLon, $pointAlt){

		$distance = $this->vincentyGreatCircleDistance($droneLat, $droneLon, $pointLat, $pointLon);
		$altDelta = $droneAlt - $pointAlt;
		$angle = rad2deg(atan($altDelta / $distance));
		return round($angle, 2);
    }

    function get_data_cat($cat)
    {
        switch ($cat) {
            case 'I':
                $ddm = (14.7*0.155)/150;
                $ddm = round($ddm, 4);
                $results = [
                    'mod_90_cat' => '18-22%',
                    'mod_150_cat' => '18-22%',
                    'course_alignment_cat' => '±14,7 μA',
                    'course_alignment_cat_ddm' => $ddm .' DDM',
                    'sdm_cat' => '36-44%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 5 kHz < 14 kHz',
                    'nominal_width_cat' => 'Ø ±17%',
                    'alarm_min_cat' => '-17%',
                    'alarm_max_cat' => '+17%',
                    'course_min_width_cat' => '-14.7 μA',
                    'course_max_width_cat' => '+14.7 μA',
                    'structure_tolerance_cat_z_ii' => '30-15 μA',
                    'structure_tolerance_cat_z_iii' => '15 μA',
                    'structure_tolerance_cat_z_iv' => '-',
                    'structure_tolerance_cat_z_v' => '-',
                ];
                break;
            case 'II':
                $ddm = (10.5*0.155)/150;
                $ddm = round($ddm, 4);
                $results = [
                    'mod_90_cat' => '18-22%',
                    'mod_150_cat' => '18-22%',
                    'course_alignment_cat' => '±10.5 μA',
                    'course_alignment_cat_ddm' => $ddm .' DDM',
                    'sdm_cat' => '36-44%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 5 kHz < 14 kHz',
                    'nominal_width_cat' => 'Ø ±17%',
                    'alarm_min_cat' => '-17%',
                    'alarm_max_cat' => '+17%',
                    'course_min_width_cat' => '-10.5 μA',
                    'course_max_width_cat' => '+10.5 μA',
                    'structure_tolerance_cat_z_ii' => '30-5 μA',
                    'structure_tolerance_cat_z_iii' => '5 μA',
                    'structure_tolerance_cat_z_iv' => '5 μA',
                    'structure_tolerance_cat_z_v' => '-',
                ];
                break;
            case 'III':
                $ddm = (4.2*0.155)/150;
                $ddm = round($ddm, 4);
                $results = [
                    'mod_90_cat' => '18-22%',
                    'mod_150_cat' => '18-22%',
                    'course_alignment_cat' => '±4.2 μA',
                    'course_alignment_cat_ddm' => $ddm .' DDM',
                    'sdm_cat' => '36-44%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 5 kHz < 14 kHz',
                    'nominal_width_cat' => 'Ø ±17%',
                    'alarm_min_cat' => '-17%',
                    'alarm_max_cat' => '+17%',
                    'course_min_width_cat' => '-4.2 μA',
                    'course_max_width_cat' => '+4.2 μA',
                    'structure_tolerance_cat_z_ii' => '30-5 μA',
                    'structure_tolerance_cat_z_iii' => '5 μA',
                    'structure_tolerance_cat_z_iv' => '5 μA',
                    'structure_tolerance_cat_z_v' => '5-10 μA',
                ];
                break;

            default:
                $results = [
                    'mod_90_cat' => '-%',
                    'mod_150_cat' => '-%',
                    'course_alignment_cat' => '-%',
                    'sdm_cat' => '-%',
                    'frequency_offset_cat_sing' => '-%',
                    'frequency_offset_cat_dual' => '-%',
                    'frequency_separation_cat' => '-',
                    'nominal_width_cat' => '-%',
                    'alarm_min_cat' => '-',
                    'alarm_max_cat' => '-',
                    'course_min_width_cat' => '-',
                    'course_max_width_cat' => '-',
                    'structure_tolerance_cat_z_ii' => '- μA',
                    'structure_tolerance_cat_z_iii' => '- μA',
                    'structure_tolerance_cat_z_iv' => '- μA',
                    'structure_tolerance_cat_z_v' => '- μA',
                ];
                break;
        }

        return $results;

    }

    function get_data_cat_glide($cat){

        switch ($cat) {
            case 'I':
                $results = [
                    'mod_90_cat' => '40±2.5%',
                    'mod_150_cat' => '40±2.5%',
                    'course_alignment_cat' => '±14,7 μA',
                    'course_alignment_cat_ddm' => '±7.5%',
                    'sdm_cat' => '80±5%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 4 kHz < 32 kHz',
                    'nominal_width_cat' => '0.24Ø±25%',
                    'alarm_min_cat' => 'Ø±7.5%',
                    'alarm_max_cat' => 'Ø±7.5%',
                    'course_min_width_cat' => '0.24Ø±25%',
                    'course_max_width_cat' => '0.24Ø±25%',
                    'structure_tolerance_cat_z_ii' => '30 μA',
                    'structure_tolerance_cat_z_iii' => '30 μA',
                    'structure_tolerance_cat_z_iv' => '-',
                    'structure_tolerance_cat_z_v' => '-',
                ];
                break;
            case 'II':
                $results = [
                    'mod_90_cat' => '40±2.5%',
                    'mod_150_cat' => '40±2.5%',
                    'course_alignment_cat' => '±10.5 μA',
                    'course_alignment_cat_ddm' => '±7.5%',
                    'sdm_cat' => '80±5%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 4 kHz < 32 kHz',
                    'nominal_width_cat' => '0.24Ø±25%',
                    'alarm_min_cat' => 'Ø±7.5%',
                    'alarm_max_cat' => 'Ø±7.5%',
                    'course_min_width_cat' => '0.24Ø±25%',
                    'course_max_width_cat' => '0.24Ø±25%',
                    'structure_tolerance_cat_z_ii' => '0.035-0.023 DDM',
                    'structure_tolerance_cat_z_iii' => '0.023 DDM',
                    'structure_tolerance_cat_z_iv' => '0.023 DDM',
                    'structure_tolerance_cat_z_v' => '0.023 DDM',
                ];
                break;
            case 'III':
                $results = [
                    'mod_90_cat' => '40±2.5%',
                    'mod_150_cat' => '40±2.5%',
                    'course_alignment_cat' => '±4.2 μA',
                    'course_alignment_cat_ddm' => '±4%',
                    'sdm_cat' => '80±5%',
                    'frequency_offset_cat_sing' => 'Single: 0.005%',
                    'frequency_offset_cat_dual' => 'Dual: 0.002%',
                    'frequency_separation_cat' => '> 4 kHz < 32 kHz',
                    'nominal_width_cat' => '0.24Ø±25%',
                    'alarm_min_cat' => 'Ø±7.5%',
                    'alarm_max_cat' => 'Ø±7.5%',
                    'course_min_width_cat' => '0.24Ø±25%',
                    'course_max_width_cat' => '0.24Ø±25%',
                    'structure_tolerance_cat_z_ii' => '0.035-0.023 DDM',
                    'structure_tolerance_cat_z_iii' => '0.023 DDM',
                    'structure_tolerance_cat_z_iv' => '0.023 DDM',
                    'structure_tolerance_cat_z_v' => '0.023 DDM',
                ];
                break;

            default:
                $results = [
                    'mod_90_cat' => '-%',
                    'mod_150_cat' => '-%',
                    'course_alignment_cat' => '-%',
                    'sdm_cat' => '-%',
                    'frequency_offset_cat_sing' => '-%',
                    'frequency_offset_cat_dual' => '-%',
                    'frequency_separation_cat' => '-',
                    'nominal_width_cat' => '-%',
                    'alarm_min_cat' => '-',
                    'alarm_max_cat' => '-',
                    'course_min_width_cat' => '-',
                    'course_max_width_cat' => '-',
                    'structure_tolerance_cat_z_ii' => '- DDM',
                    'structure_tolerance_cat_z_iii' => '- DDM',
                    'structure_tolerance_cat_z_iv' => '- DDM',
                    'structure_tolerance_cat_z_v' => '- DDM',
                ];
                break;
        }

        return $results;

    }

    function fluctuation_chart($media_file, $ils, $header, $result, $task, $operation, $chart_type){

        $folderMapping = Operation::getFolderMapping();
        $s3FilePath = "{$folderMapping[$operation->type_id]}/{$operation->id}/{$media_file->task_id}/{$media_file->file_name}";

        // Descargar archivo desde S3
        $csv = Storage::disk('s3')->get($s3FilePath);
        $tempFolderPath = public_path('temp/extra');
        $localFilePath = $tempFolderPath . '/' . $media_file->file_name;
        File::put($localFilePath, $csv);

        // Leer el archivo CSV
        $reader = Reader::createFromPath($localFilePath, 'r');

        $records = $reader->setHeaderOffset(0)->fetchOne(1);

        // Verificar las columnas disponibles
        $headers = $reader->fetchOne(0);

        // Obtener las columnas necesarias
        $result_time = $reader->fetchColumn((float) 3);
        $result_joint_sum_ddm = $reader->fetchColumn((float) 7);
        $result_joint_sum_sdm = $reader->fetchColumn((float) 10);
        $result_course_field_strength = $reader->fetchColumn((float) 14);

        // Convertir a arrays
        $time = iterator_to_array($result_time, false);
        array_shift($time);
        $time = array_map("floatval", $time);

        $joint_sum_ddm = iterator_to_array($result_joint_sum_ddm, false);
        array_shift($joint_sum_ddm);
        $joint_sum_ddm = array_map("floatval", $joint_sum_ddm);

        $joint_sum_sdm = iterator_to_array($result_joint_sum_sdm, false);
        array_shift($joint_sum_sdm);
        $joint_sum_sdm = array_map("floatval", $joint_sum_sdm);

        $course_field_strength = iterator_to_array($result_course_field_strength, false);
        array_shift($course_field_strength);
        $course_field_strength = array_map("floatval", $course_field_strength);

        // Convertir SDM a porcentaje
        $sdmInPercentage = array_map(function($val) {
            return $val * 100;
        }, $joint_sum_sdm);

        // Configuración de las gráficas
        $imgWidth = 1600;
        $imgHeight = 350;

        // Crear objetos de datos
        $dataDeviation = new Data();
        $dataModulation = new Data();
        $dataField = new Data();

        // Agregar puntos para la gráfica de DDM (fluctuación)
        $dataDeviation->addPoints($joint_sum_ddm, "DDM Fluctuation");
        $dataDeviation->addPoints($time, "Time");

        // Agregar puntos para la gráfica de SDM
        $dataModulation->addPoints($sdmInPercentage, "SDM");
        $dataModulation->addPoints($time, "Time");

        // Agregar puntos para Field Strength
        $dataField->addPoints($course_field_strength, "Field Strength");
        $dataField->addPoints($time, "Time");

        // Configurar ejes para DDM
        $dataDeviation->setAxisName(0, "Time");
        $dataDeviation->setAxisUnit(0, " s");
        $dataDeviation->setAxisXY(0, AXIS_X);
        $dataDeviation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataDeviation->setSerieOnAxis("DDM Fluctuation", 1);
        $dataDeviation->setAxisName(1, "DDM");
        $dataDeviation->setAxisUnit(1, "");
        $dataDeviation->setAxisXY(1, AXIS_Y);
        $dataDeviation->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Configurar ejes para SDM
        $dataModulation->setAxisName(0, "Time");
        $dataModulation->setAxisUnit(0, " s");
        $dataModulation->setAxisXY(0, AXIS_X);
        $dataModulation->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataModulation->setSerieOnAxis("SDM", 1);
        $dataModulation->setAxisName(1, "SDM %");
        $dataModulation->setAxisUnit(1, "");
        $dataModulation->setAxisXY(1, AXIS_Y);
        $dataModulation->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Configurar ejes para Field Strength
        $dataField->setAxisName(0, "Time");
        $dataField->setAxisUnit(0, " s");
        $dataField->setAxisXY(0, AXIS_X);
        $dataField->setAxisPosition(0, AXIS_POSITION_BOTTOM);
        $dataField->setSerieOnAxis("Field Strength", 1);
        $dataField->setAxisName(1, "Field Strength dBm");
        $dataField->setAxisUnit(1, "");
        $dataField->setAxisXY(1, AXIS_Y);
        $dataField->setAxisPosition(1, AXIS_POSITION_LEFT);

        // Crear bindings para scatter plots
        $dataDeviation->setScatterSerie("Time", "DDM Fluctuation", 0);
        $dataDeviation->setScatterSerieDescription(0, "DDM Fluctuation");
        $dataDeviation->setScatterSerieColor(0, array("R" => 0, "G" => 0, "B" => 255));

        $dataModulation->setScatterSerie("Time", "SDM", 0);
        $dataModulation->setScatterSerieDescription(0, "SDM [%]");
        $dataModulation->setScatterSerieColor(0, array("R" => 242, "G" => 122, "B" => 16));

        $dataField->setScatterSerie("Time", "Field Strength", 0);
        $dataField->setScatterSerieDescription(0, "Field Strength [dBm]");
        $dataField->setScatterSerieColor(0, array("R" => 30, "G" => 36, "B" => 96));

        // Crear objetos de imagen
        $pictureDeviation = new Image($imgWidth, $imgHeight, $dataDeviation);
        $pictureModulation = new Image($imgWidth, $imgHeight, $dataModulation);
        $pictureField = new Image($imgWidth, $imgHeight, $dataField);

        // Configurar propiedades
        $pictureDeviation->Antialias = FALSE;
        $pictureModulation->Antialias = FALSE;
        $pictureField->Antialias = FALSE;

        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));

        // Definir áreas de gráficas
        $pictureDeviation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureModulation->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);
        $pictureField->setGraphArea(95, 20, $imgWidth - 30, $imgHeight - 80);

        // Crear objetos scatter
        $scatterDeviation = new Scatter($pictureDeviation, $dataDeviation);
        $scatterModulation = new Scatter($pictureModulation, $dataModulation);
        $scatterField = new Scatter($pictureField, $dataField);

        // Calcular límites para DDM basados en los valores máximos y mínimos
        $maxDDM = max($joint_sum_ddm);
        $minDDM = min($joint_sum_ddm);
        $ddmMargin = ($maxDDM - $minDDM) * 0.1; // 10% de margen

        // Definir límites de tolerancia para fluctuación
        $fluctuationTolerance = 0.005; // ±0.005 DDM para fluctuación típica

        // Configurar escalas
        $axisBoundariesDeviation = array(
            0 => array("Min" => min($time), "Max" => max($time)),
            1 => array("Min" => $minDDM - $ddmMargin, "Max" => $maxDDM + $ddmMargin)
        );

        $scaleSettingsDeviation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesDeviation,
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Escalas para SDM y Field
        $axisBoundariesModulation = array(
            0 => array("Min" => min($time), "Max" => max($time)),
            1 => array("Min" => 0, "Max" => 100)
        );

        $axisBoundariesField = array(
            0 => array("Min" => min($time), "Max" => max($time)),
            1 => array("Min" => -110, "Max" => 10)
        );

        $scaleSettingsModulation = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesModulation,
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        $scaleSettingsField = array(
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => $axisBoundariesField,
            "GridR" => 200,
            "GridG" => 200,
            "GridB" => 200
        );

        // Dibujar escalas
        $scatterDeviation->drawScatterScale($scaleSettingsDeviation);
        $scatterModulation->drawScatterScale($scaleSettingsModulation);
        $scatterField->drawScatterScale($scaleSettingsField);

        // Activar antialiasing
        $pictureDeviation->Antialias = TRUE;
        $pictureModulation->Antialias = TRUE;
        $pictureField->Antialias = TRUE;

        // Dibujar las gráficas
        $scatterDeviation->drawScatterLineChart();
        $scatterModulation->drawScatterLineChart();
        $scatterField->drawScatterLineChart();

        // Dibujar líneas de tolerancia para fluctuación
        $scatterDeviation->drawScatterThreshold($fluctuationTolerance, array(
            "AxisID" => 1,
            "R" => 255,
            "G" => 0,
            "B" => 0,
            "Ticks" => 4,
            "WriteCaption" => TRUE,
            "Caption" => "+0.005 DDM",
            "CaptionR" => 0,
            "CaptionG" => 0,
            "CaptionB" => 0
        ));

        $scatterDeviation->drawScatterThreshold(-$fluctuationTolerance, array(
            "AxisID" => 1,
            "R" => 255,
            "G" => 0,
            "B" => 0,
            "Ticks" => 4,
            "WriteCaption" => TRUE,
            "Caption" => "-0.005 DDM",
            "CaptionR" => 0,
            "CaptionG" => 0,
            "CaptionB" => 0
        ));

        // Línea central (0 DDM)
        $scatterDeviation->drawScatterThreshold(0, array(
            "AxisID" => 1,
            "R" => 0,
            "G" => 0,
            "B" => 0,
            "Ticks" => 0
        ));

        // Dibujar leyendas
        $pictureDeviation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterDeviation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        $pictureModulation->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterModulation->drawScatterLegend(1350, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        $pictureField->setFontProperties(array("FontName" => "fonts/pf_arma_five.ttf", "FontSize" => 10));
        $scatterField->drawScatterLegend(1250, 20, array("Style" => LEGEND_NOBORDER, "Mode" => LEGEND_VERTICAL));

        // Agregar TX1 o TX2 al nombre
        if($result->transmitter == 1){
            $chart_type = $chart_type . ' TX1';
        } else {
            $chart_type = $chart_type . ' TX2';
        }

        // Generar nombres de archivos
        $imgDir   = public_path('temp/extra/');
        $imgDev   = $imgDir . 'fluctuation_dev_' . $result->transmitter . '.png';
        $imgMod   = $imgDir . 'fluctuation_mod_' . $result->transmitter . '.png';
        $imgField = $imgDir . 'fluctuation_field_' . $result->transmitter . '.png';

        // Renderizar imágenes
        $pictureDeviation->render($imgDev);
        $pictureModulation->render($imgMod);
        $pictureField->render($imgField);

        return ['img_dev' => $imgDev, 'img_mod' => $imgMod, 'img_field' => $imgField, 'chart_type' => $chart_type];
    }
}