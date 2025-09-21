<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Standing extends Model
{
   protected $table = 'standings';
    protected $fillable = [
        'match_id', 'team', 'rank', 'mp', 'wins', 'draws', 'losses',
        'goals', 'gd', 'pts', 'created_at'
    ];
    public $timestamps = false;
}
