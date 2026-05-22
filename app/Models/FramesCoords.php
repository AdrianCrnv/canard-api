<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FramesCoords extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'frames_coords'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function distress(){
        return $this->hasMany(Distress::class,'frame_id','id');
    }

    public function processTask(){
        return $this->belongsTo(ProcessTask::class, 'task_id');
    }
}
