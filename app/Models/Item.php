<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function type(){
        return $this->belongsTo(ItemType::class);
    }

    public function provider(){
        return $this->belongsTo(ItemProvider::class);
    }

    public function firmware_version(){
        return $this->belongsTo(FirmwareVersion::class);
    }

    public function drone(){
        return $this->belongsTo(Drone::class);
    }

    public function status(){
        return $this->belongsTo(ItemStatus::class);
    }

    public function currency(){
        return $this->belongsTo(Currency::class);
    }

    public function operator(){
        return $this->belongsTo(Operator::class, 'operator_id');
    }
}
