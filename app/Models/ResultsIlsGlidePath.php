<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsIlsGlidePath extends Model {
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_ils_glide_path'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function ils(){
        return $this->belongsTo(Ils::class);
    }
}
