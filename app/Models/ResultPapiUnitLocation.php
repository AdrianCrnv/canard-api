<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultPapiUnitLocation extends Model {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_papi_unit_location'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function papi(){
        return $this->belongsTo(Papi::class);
    }

    public function measurements(){
        return $this->hasMany(MeasurementPapiUnitLocation::class, 'result_id');
    }
}
