<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PapiSide extends Model
{
    use HasFactory;

    public function papis(){
        return $this->hasMany(Papi::class);
    }
}
