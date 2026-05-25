<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reference extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function airport(){
        return $this->belongsTo(Airport::class);
    }

    public function vor(){
        return $this->belongsTo(Vor::class);
    }
}
