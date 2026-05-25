<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Papi extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function lights(){
        return $this->hasMany(PapiLight::class);
    }

    public function side(){
        return $this->belongsTo(PapiSide::class);
    }

    public function type(){
        return $this->belongsTo(PapiType::class);
    }

    public function header(){
        return $this->belongsTo(Header::class);
    }

    public function results_unit_location(){
        return $this->hasMany(ResultPapiUnitLocation::class);
    }

    public function results_vertical_angle(){
        return $this->hasMany(ResultPapiVerticalAngle::class);
    }

    public function results_angular_coverage(){
        return $this->hasMany(ResultPapiAngularCoverage::class);
    }
}
