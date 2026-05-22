<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DroneStatus extends Model {

    use HasFactory;

    public function drones(){
        return $this->hasMany(Drone::class);
    }
}
