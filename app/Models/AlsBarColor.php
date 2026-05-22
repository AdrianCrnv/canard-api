<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlsBarColor extends Model {

    use HasFactory;

    public function bars(){
        return $this->hasMany(AlsBars::class);
    }
}
