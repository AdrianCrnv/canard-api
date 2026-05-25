<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stretch extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    //protected $table = 'stretches'; // Allow all fields to be saved as Mass Assignment

    public function runway(){
        return $this->belongsTo(Runway::class);
    }

    public function subject(){
        $subject = null;

        switch ($this->subject_id) {
            case 1: // Runway
                $subject = Runway::find($this->subject_id);
                break;

            default:
                # code...
                break;
        }

        return $subject;
    }
}
