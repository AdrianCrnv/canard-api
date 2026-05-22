<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FirmwareType extends Model {

    use HasFactory;

    public function versions(){
        return $this->hasMany(FirmwareVersion::class);
    }
}
