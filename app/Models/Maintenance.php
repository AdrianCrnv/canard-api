<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Maintenance extends Model implements HasMedia {

    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function type(){
        return $this->belongsTo(MaintenanceType::class);
    }

    public function status(){
        return $this->belongsTo(MaintenanceStatus::class);
    }

    public function subjectType(){
        return $this->belongsTo(ItemType::class);
    }

    public function subject(){
        return $this->belongsTo(Item::class);
    }

    public function drone()
    {
        return $this->belongsTo(Drone::class, 'subject_id');
    }

    public function technician(){
        return $this->belongsTo(User::class);
    }
}
