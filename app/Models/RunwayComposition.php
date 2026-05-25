<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RunwayComposition extends Model {
    use HasFactory;

    public function runway(){
        return $this->hasMany(Runway::class);
    }
}
