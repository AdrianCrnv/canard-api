<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessTask extends Model
{
    use HasFactory;

    public function framesCoords(){
        return $this->hasMany(FramesCoords::class,'task_id');
    }

    public function media(){
        return $this->belongsTo(Media::class, 'media_id');
    }
}
