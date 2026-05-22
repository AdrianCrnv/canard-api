<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlsBar extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function als(){
        return $this->belongsTo(Als::class);
    }

    public function type(){
        return $this->belongsTo(AlsType::class);
    }

    public function color(){
        return $this->belongsTo(AlsColor::class);
    }
}
