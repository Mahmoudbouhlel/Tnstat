<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class H2hMatch extends Model
{
     protected $table = 'h2h_matches';
    protected $fillable = [
        'match_id', 'date', 'home_team', 'away_team', 'score',
        'home_odds', 'draw_odds', 'away_odds', 'created_at'
    ];
    public $timestamps = false;
}
