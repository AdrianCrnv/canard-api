<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultAcMaint extends Model
{
    use HasFactory;

    protected $table = 'results_aircraft_maintenance';

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'is_valid',
        'status',
        'process_uuid',
        'read_yaml',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function images()
    {
        return $this->hasMany(AcMaintImage::class, 'ac_maint_id');
    }
}
