<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FirmwareVersion extends Model {

    use HasFactory;

    public function firmware_type(){
        return $this->belongsTo(FirmwareType::class);
    }

    public function item_types(){
        return $this->belongsTo(ItemType::class);
    }
}
