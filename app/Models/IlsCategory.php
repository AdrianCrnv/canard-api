<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IlsCategory extends Model {

    use HasFactory;

    public function ils(){
        return $this->hasMany(Ils::class);
    }
}
