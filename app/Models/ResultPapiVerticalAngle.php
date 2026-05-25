<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultPapiVerticalAngle extends Model {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_papi_vertical_angle'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function papi(){
        return $this->belongsTo(Papi::class);
    }

    public function transition_type(){
        return $this->belongsTo(TransitionType::class);
    }

    public function measurements(){
        return $this->hasMany(MeasurementPapiVerticalAngle::class, 'result_id');
    }

    public function light(){
        return $this->belongsTo(PapiLight::class);
    }
}
