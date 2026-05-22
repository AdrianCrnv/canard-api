<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DistressType extends Model {

    use HasFactory;

    public function distress(){
        return $this->hasMany(Distress::class);
    }
}
