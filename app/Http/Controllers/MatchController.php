<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use Illuminate\Http\Request;
use App\Models\H2hMatch;
use App\Models\Standing;
use Inertia\Inertia;

class MatchController extends Controller
{

public function index()
{
    // Step 1: Get all matches
    $matches = FootballMatch::select(
            'id',
            'match_key',
            'home_team',
            'away_team',
            'match_date',
            'match_time',
            'home_odds',
            'draw_odds',
            'away_odds',
            'match_url',
            'scraped_at'
        )
        ->orderBy('match_date', 'asc')
        ->orderBy('match_time', 'asc')
        ->get();

    // Step 2: Collect all match IDs
    $matchIds = $matches->pluck('id')->toArray();

    // Step 3: Get H2H for these matches only
    $h2hMatches = H2hMatch::whereIn('match_id', $matchIds)
        ->select(
            'id',
            'match_id',
            'date',
            'home_team',
            'away_team',
            'score',
            'home_odds',
            'draw_odds',
            'away_odds',
            'created_at'
        )
        ->orderBy('date', 'desc')
        ->get();

    // Step 4: Get standings
    $standings = Standing::select(
            'id',
            'match_id',
            'team',
            'rank',
            'mp',
            'wins',
            'draws',
            'losses',
            'goals',
            'gd',
            'pts'
        )
        ->orderByRaw('CAST(rank AS UNSIGNED) ASC')
        ->get();

    return Inertia::render('Dashboard', [
        'matches'    => $matches,
        'h2hMatches' => $h2hMatches,
        'standings'  => $standings,
    ]);
}

}
