<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parameter extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function parameter_type(){
        return $this->belongsTo(ParameterType::class);
    }

    public function subject_type(){
        return $this->belongsTo(SubjectType::class);
    }

    public function task_type(){
        return $this->belongsTo(TaskType::class);
    }

    public function subject(){
        $subject = null;

        switch ($this->subject_type_id) {
            case 1: // Header
                $subject = Header::find($this->subject_id);
                break;
            case 3: // Runway
                $subject = Runway::find($this->subject_id);
                break;
            case 4: // Airport
                $subject = Airport::find($this->subject_id);
                break;
            case 5: // Vor
                $subject = Vor::find($this->subject_id);
                break;
            case 6: // Apron
                $subject = Apron::find($this->subject_id);
                break;
            case 7: // Drone
                $subject = Drone::find($this->subject_id);
                break;
            case 8: // TXY
                $subject = Taxiway::find($this->subject_id);
                break;
            case 9: // Surveillance
                $subject = Surveillance::find($this->subject_id);
                break;
            case 10: // ETOD
                $subject = Etod::find($this->subject_id);
                break;

            default:
                break;
        }

        return $subject;
    }
}
