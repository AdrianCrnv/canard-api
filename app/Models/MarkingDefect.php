<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkingDefect extends Model
{
    use HasFactory;

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'image_id',
        'image_path',
        'defect_id',
        'unique_defect_id',
        'pixel_x',
        'pixel_y',
        'type_defect',
        'latitude',
        'longitude',
        'altitude',
        'severity',
        'patch_path',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function typeDefect()
    {
        return $this->belongsTo(MarkingTypeDefect::class, 'type_defect');
    }

    public function image()
    {
        return $this->belongsTo(MarkingsImage::class, 'image_id');
    }
}
