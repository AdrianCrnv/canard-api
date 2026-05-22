<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DistressSeverity extends Model {

    use HasFactory;

    public function DistressSeverity(){
        return $this->hasMany(DistressSeverity::class);
    }
}
