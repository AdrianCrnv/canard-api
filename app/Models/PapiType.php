<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PapiType extends Model {

    use HasFactory;

    public function papis(){
        return $this->hasMany(Papi::class, 'type_id');
    }
}
