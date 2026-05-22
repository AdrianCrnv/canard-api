<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Drone extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function type(){
        return $this->belongsTo(DroneType::class);
    }

    public function status(){
        return $this->belongsTo(DroneStatus::class);
    }

    public function operator(){
        return $this->belongsTo(Operator::class);
    }

    public function operations(){
        return $this->hasMany(Operation::class);
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 7)->where('subject_id', $this->id)->get();
    }

    public function operationTypes()
    {
        return $this->belongsToMany(
            OperationType::class,       // Modelo relacionado
            'drone_operation_type',     // Tabla pivote
            'drone_id',                 // FK de este modelo en la pivote
            'operation_type_id'         // FK del relacionado en la pivote
        );
    }

    public function serial_number(){
        $FCItems = ItemType::where('category_id', 3)->get()->all();
        $allItems = [];

        $count_fc = count($FCItems);
        for($i=0; $i < $count_fc; $i++){
            $allItem = Item::where('drone_id', $this->id)->where('type_id', $FCItems[$i]->id)->get();
            if(!empty($allItem[0])){
                array_push($allItems, $allItem);
            }
        }
        $allItems = $allItems[0][0]['serial_number'];
        return $allItems;
    }

    public function items(){
        return $this->hasMany(Item::class);
    }

    public function maintenances(){
        return $this->hasMany(Maintenance::class);
    }
}
