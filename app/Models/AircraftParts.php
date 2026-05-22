<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AircraftParts extends Model
{
    use HasFactory;

    protected $table = 'aircraft_parts';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'part_name',
        'task_id'
    ];

    public $timestamps = true;

    public function taskType()
    {
        return $this->belongsTo(TaskType::class, 'task_id');
    }
}
