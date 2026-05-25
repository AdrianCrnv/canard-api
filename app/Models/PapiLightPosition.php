<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PapiLightPosition extends Model
{
    use HasFactory;

    public function lights(){
        return $this->hasMany(PapiLight::class);
    }
}
