<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VorChannel extends Model {
    use HasFactory;

    protected $hidden = ['id', 'channel']; //Hiding Attributes From JSON

    public function vors(){
        return $this->hasMany(Vor::class);
    }
}
