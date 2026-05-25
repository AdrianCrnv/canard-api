<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsVor extends Model  {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_vor'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function vor(){
        return $this->belongsTo(Vor::class);
    }
}
