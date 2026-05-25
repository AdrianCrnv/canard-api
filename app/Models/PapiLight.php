<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PapiLight extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function papi(){
        return $this->belongsTo(Papi::class);
    }

    public function position(){
        return $this->belongsTo(PapiLightPosition::class);
    }
}
