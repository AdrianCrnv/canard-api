<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemType extends Model {

    use HasFactory;

    public $timestamps = false; // disabled the fields created_at and updated_at because we dont need it.

    protected $fillable = ['name','category_id','maintenance','mtn_value','mtn_unit_id']; // only this columns can be mass assignment.

    public function category(){
        return $this->belongsTo(ItemCategory::class);
    }

    public function items(){
        return $this->hasMany(Item::class);
    }

    public function firmware_versions(){
        return $this->hasMany(FirmwareVersion::class);
    }
}
