<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskType extends Model {
    use HasFactory;

    public function tasks(){
        return $this->hasMany(Task::class);
    }

    // Relación con gvi_task_parameters
    public function taskParameters()
    {
        return $this->hasMany(GviTaskParameter::class, 'task_type_id');
    }
}
