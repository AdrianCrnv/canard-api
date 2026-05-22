<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class DroneType extends Model implements HasMedia {

    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function drones(){
        return $this->hasMany(Drone::class);
    }
}
