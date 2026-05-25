<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class System extends Model
{
    use HasFactory;

    public function tasks(){
        return $this->hasMany(TaskType::class)->with('type');
    }
}
