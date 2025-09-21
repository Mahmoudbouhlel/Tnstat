<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FootballMatch extends Model
{
      protected $table = 'matches';

    protected $fillable = [
        'match_key',
        'home_team',
        'away_team',
        'home_odds',
        'draw_odds',
        'away_odds',
        'match_url',
        'scraped_at',
        'match_date', // ✅ ajout ici
    ];

    public $timestamps = false;
}
