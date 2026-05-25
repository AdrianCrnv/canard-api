<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationType extends Model {

    use HasFactory;

    public function operations(){
        return $this->hasMany(Operation::class);
    }

    public function subject_type(){
        return $this->belongsTo(SubjectType::class);
    }

    public function task_types(){
        // Include the extra field "description" from the pivot table in the results
        return $this->belongsToMany(TaskType::class)->withPivot('description', 'order');
    }
}
