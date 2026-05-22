<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EtodAreas extends Model {

    use HasFactory;

    public function etod(){
        return $this->hasMany(Etod::class);
    }
}
