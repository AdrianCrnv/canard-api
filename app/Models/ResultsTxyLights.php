<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsTxyLights extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'results_txy_lights'; // Set non-standard table name

    public function task(){
        return $this->belongsTo(Task::class);
    }

    public function images()
    {
        return $this->hasMany(LightsImage::class, 'txy_id', 'id');
    }
}
