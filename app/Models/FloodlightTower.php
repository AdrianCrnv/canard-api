<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FloodlightTower extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'floodlight_towers'; // Set non-standard table name

    public function airport(){
        return $this->belongsTo(Airport::class);
    }
}
