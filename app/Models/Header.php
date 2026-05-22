<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Header extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function runway(){
        return $this->belongsTo(Runway::class);
    }

    public function papis(){
        return $this->hasMany(Papi::class)->where('enabled', 1);
    }

    public function ils(){
        return $this->hasOne(Ils::class);
    }

    public function als(){
        return $this->hasOne(Als::class);
    }

    public function getOpposite(){
        // Returns the opposite header in the same runway
        return $this->runway->headers->where('id', '!=', $this->id)->first(); // Ensure to return only 1 result
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 1)->where('subject_id', $this->id)->get();
    }

    public function hasAls(){
        return $this->als()->count() ? true : false;
    }

    public function hasPapis(){
        return $this->papis()->count() ? true : false;
    }

    public function hasIls(){
        return $this->ils()->count() ? true : false;
    }
}
