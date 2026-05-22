<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ils extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    protected $hidden = ['channel_id']; //Hiding Attributes From JSON

    public function category(){
        return $this->belongsTo(IlsCategory::class);
    }

    public function channel(){
        return $this->belongsTo(IlsChannel::class);
    }

    public function header(){
        return $this->belongsTo(Header::class);
    }

    public function stretches(){
        return $this->hasMany(Stretch::class, 'subject_id');
    }
}
