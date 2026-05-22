<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GviTaskParameter extends Model
{
    use HasFactory;

    protected $table = 'gvi_task_parameters';
    protected $primaryKey = 'task_type_id';
    public $timestamps = false;
    protected $fillable = ['task_type_id', 'section_type_id', 'side'];

    public function sectionType()
    {
        return $this->belongsTo(GviSectionType::class, 'section_type_id');
    }

    public function taskType()
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }
}
