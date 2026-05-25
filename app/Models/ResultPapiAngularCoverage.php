<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultPapiAngularCoverage extends Model {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_papi_angular_coverage'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function papi(){
        return $this->belongsTo(Papi::class);
    }

    public function transition_type(){
        return $this->belongsTo(AngularCoverageTransitionType::class);
    }

    public function measurements(){
        return $this->hasMany(MeasurementPapiAngularCoverage::class, 'result_id');
    }
}
