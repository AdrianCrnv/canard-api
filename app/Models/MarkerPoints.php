<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkerPoints extends Model {

    use HasFactory;

    protected $fillable = [
        'id',
        'order',
        'lat',
        'lng',
        'height',
        'subject_id',
        'subject_type_id'
    ];

    public function taxiway(){
        return Parameter::where('subject_type_id', 8)
                        ->where('subject_id', $this->id)
                        ->get();
    }

    public function surveillance(){
        return Parameter::where('subject_type_id', 9)
                        ->where('subject_id', $this->id)
                        ->get();
    }

    public function etod(){
        return Parameter::where('subject_type_id', 10)
                        ->where('subject_id', $this->id)
                        ->get();
    }

    public function apron(){
        return Parameter::where('subject_type_id', 6)
                        ->where('subject_id', $this->id)
                        ->get();
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 8)->where('subject_id', $this->id)->get();
    }
}
