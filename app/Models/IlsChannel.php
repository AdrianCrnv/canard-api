<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IlsChannel extends Model {

    use HasFactory;

    public $timestamps = false;

    protected $hidden = ['id', 'channel']; //Hiding Attributes From JSON

    public function ils(){
        return $this->hasMany(Ils::class);
    }
}
