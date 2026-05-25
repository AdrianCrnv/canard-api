<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemProvider extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    public $timestamps = false;

    public function items(){
        return $this->hasMany(Item::class);
    }
}
