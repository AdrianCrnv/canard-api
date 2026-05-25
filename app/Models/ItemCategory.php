<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model {

    use HasFactory;

    public $timestamps = false; // disabled the fields created_at and updated_at because we dont need it.
    protected $fillable = ['name']; // only this column can be mass assignment.

    public function types(){
        return $this->hasMany(ItemType::class, 'category_id');
    }

    // Get the item count for this category
    public function itemCount($idOperatorSelected){
        $types = $this->types()->get(['id']); // Get only the type IDs
        if($idOperatorSelected == ""){
            $count = Item::whereIn('type_id', $types)->count();
        }
        else {
            $count = Item::whereIn('type_id', $types)->where('operator_id', $idOperatorSelected)->count();
        }
        return $count;
    }

    public function itemCountOperator($user_operator_id){
        $types = $this->types()->get(['id']); // Get only the type IDs
        $count = Item::whereIn('type_id', $types)->where('operator_id', $user_operator_id)->count();
        return $count;
    }

    public function itemCountAdmin($idOperatorSelected){
        if($idOperatorSelected  == ""){
            $this->itemCount();
        } else {
            $types = $this->types()->get(['id']); // Get only the type IDs
            $count = Item::whereIn('type_id', $types)->where('operator_id', $idOperatorSelected)->count();
            return $count;
        }
    }
}
