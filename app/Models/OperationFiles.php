<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationFiles extends Model
{
    use HasFactory;

    protected $table = 'operation_files';

    protected $fillable = [
        'file_name',
        'description',
        'file_type',
        'type',
        'size',
        'task_id',
        'reviewed',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function alsDetections()
    {
        return $this->hasMany(AlsDetection::class, 'operation_file_id');
    }
}
