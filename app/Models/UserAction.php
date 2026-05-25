<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// This class is used to keep a log of actions performed by users
// over different Eloquent models. Observers are used to achieve
// this: https://laravel.com/docs/5.7/eloquent#observers
class UserAction extends Model {
    use HasFactory;

    protected $fillable = ['user_id', 'action', 'action_model', 'action_id'];

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function register($actionName, $actionModel, $actionId){
        $userId = Auth::check() ? Auth::user()->id : null;

        UserAction::create([
            'user_id'      => $userId,
            'action'       => $actionName,
            'action_model' => $actionModel,
            'action_id'    => $actionId
        ]);
    }
}
