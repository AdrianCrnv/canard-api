<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsAls extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_als'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function als(){
        return $this->belongsTo(Als::class);
    }

    public function measurements()
    {
        return $this->hasMany(MeasurementAls::class, 'result_id');
    }
}
