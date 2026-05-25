<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeasurementPapiUnitLocation extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'measurements_papi_unit_location'; // Set non-standard table name

    public function result(){
        return $this->belongsTo(ResultPapiUnitLocation::class);
    }
}
