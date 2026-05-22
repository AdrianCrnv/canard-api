<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AircraftDimension extends Model
{
    use HasFactory;

    protected $table = 'aircrafts_dimensions';

    protected $primaryKey = 'aircraft_id';

    public $incrementing = false;

    protected $fillable = [
        'aircraft_id',
        'nose_to_noselandinggear',
        'aircraft_length',
        'vertical_stabilizer_top_height_overground',
        'wingtip_to_nose',
        'fuselage_width',
        'wingtip_to_fuselage_center',
        'wingapex_to_nose',
        'fuselage_top_height_overground',
        'wingapex_chord',
        'fuselage_height',
        'horizontal_stabilizer_tip_height_overground',
        'tail_height_over_ground',
        'hs_leading_edge_sweep_angle',
        'hs_trailing_edge_sweep_angle',
        'hs_leading_edge_root_to_fuselage_maximum_width',
        'nose_landing_gear_to_tail',
        'hs_tip_width',
        'hs_root_height_over_ground',
        'hs_leading_edge_length',
        'hs_trailing_edge_length',
        'nose_landing_gear_to_hs_leading_edge_root',
    ];

    public function aircraft()
    {
        return $this->belongsTo(Aircraft::class, 'aircraft_id');
    }
}
