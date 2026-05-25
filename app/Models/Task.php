<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    // public $timestamps = false; // Don't try to update timestamps in database

    public function operation(){
        return $this->belongsTo(Operation::class);
    }

    public function type(){
        return $this->belongsTo(TaskType::class, 'type_id');
    }

    public function status(){
        return $this->belongsTo(TaskStatus::class);
    }

    public function parameters(){
        // var subject type id if PCI TWY
        /* This is a workaround, all operations so far had a runway
        header as subject_id and subject_type_id. For the PCI Taxiway
        operation it is necessary that the subject_type_id be 8 and
        the subject_id the taxiway of the task */
        if($this->operation->type->id == "13"){
            $taxiway_name = $this->description;
            $subject_type_id = 8;
            $subject_id = $this->operation->subject_id;
            $subject_element_id = Taxiway::where('name', $taxiway_name)->where('airport_id', $subject_id)->get();
            $element_id = $subject_element_id[0]->id;
        }if($this->operation->type->id == "18"){
            $floodlight_name = $this->description;
            $subject_type_id = 11;
            $subject_id = $this->operation->subject_id;
            $subject_element_id = FloodlightTower::where('name', $floodlight_name)->where('airport_id', $subject_id)->get();
            $element_id = $subject_element_id[0]->id;
        }else{
            $subject_type_id = $this->operation->type->subject_type->id;
            $element_id = $this->operation->subject_id;
        }

        return Parameter::where('subject_type_id', $subject_type_id) //
                            ->where('subject_id', $element_id)
                            ->where('task_type_id', $this->type->id)
                            ->with('parameter_type', 'task_type')
                            ->get();
    }

    // If the task is related to a PAPI, find it and return it
    public function getPapi(){
        // Check if the task belongs to a PAPI operation
        if($this->type_id >= 1 && $this->type_id <= 6){
            // Get the PAPI depending on the side of the task
            if($this->description == 'Left side'){
                $side = 1;
            } else if($this->description == 'Right side') {
                $side = 2;
            } else {
                return null; // No side detected
            }

            // Get the subject (header) of the operation
            $header = $this->operation->subject();
            $papi = $header->papis->where('side_id', $side)->first();

            return $papi;
        } else {
            return null;
        }
    }

    public function resultsPapiUnitLocation(){
        return $this->hasMany(ResultPapiUnitLocation::class)->with('measurements')->get();
    }

    public function resultsPapiVerticalAngle(){
        return $this->hasMany(ResultPapiVerticalAngle::class)->with('measurements')->get();
    }

    public function resultsPapiAngularCoverage(){
        return $this->hasMany(ResultPapiAngularCoverage::class)->with('measurements')->get();
    }

    public function results_aircraft_maintenance()
    {
        return $this->hasMany(ResultAcMaint::class, 'task_id');
    }

    public function results_flight_turn()
    {
        return $this->hasMany(ResultFlightTurn::class, 'task_id');
    }

    public function resultsAls()
    {
        return $this->hasMany(ResultsAls::class, 'task_id');
    }

    public function operationFiles()
    {
        return $this->hasMany(OperationFiles::class);
    }

    public function resultsIlsGP()
    {
        return $this->hasMany(ResultsIlsGlidePath::class, 'task_id');
    }

    public function resultsIlsLoc()
    {
        return $this->hasMany(ResultsIlsLocalizer::class, 'task_id');
    }

    public function resultsLights()
    {
        $operationTypeId = $this->operation->type_id;

        if ($operationTypeId == 11) {
            return $this->hasMany(ResultsTxyLights::class, 'task_id', 'id');
        } else {
            return $this->hasMany(ResultsRwyLights::class, 'task_id', 'id');
        }
    }

    public function resultsMarkings()
    {
        $operationTypeId = $this->operation->type_id;

        if ($operationTypeId == 22) {
            return $this->hasMany(ResultsRwyMarkings::class, 'task_id', 'id');
        }
    }

    public function images()
    {
        $operationTypeId = $this->operation->type_id;

        if ($operationTypeId == 10) {
            // Runway Lights
            return $this->hasManyThrough(LightsImage::class, ResultsRwyLights::class, 'task_id', 'rwy_id');
        } else if ($operationTypeId == 11) {
            // Taxiway Lights
            return $this->hasManyThrough(LightsImage::class, ResultsTxyLights::class, 'task_id', 'txy_id');
        } else if ($operationTypeId == 22) {
            // Runway Markings
            return $this->hasManyThrough(MarkingsImage::class, ResultsRwyMarkings::class, 'task_id', 'rwy_id');
        }
    }

    public function getRunsAttribute()
    {
        $operationType = $this->operation->type_id;

        // Runway Lights
        if ($operationType == 10) {
            return $this->hasMany(ResultsRwyLights::class, 'task_id')->get();
        }
        // Taxiway Lights
        else if ($operationType == 11) {
            return $this->hasMany(ResultsTxyLights::class, 'task_id')->get();
        }
        // Runway Markings
        else if ($operationType == 22) {
            return $this->hasMany(ResultsRwyMarkings::class, 'task_id')->get();
        }
    }
}
