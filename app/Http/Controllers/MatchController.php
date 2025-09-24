<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use Illuminate\Http\Request;
use App\Models\H2hMatch;
use App\Models\Standing;
use Illuminate\Support\Facades\DB;
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
public function valueBets()
{
    // Fetch value bets with standings + aggregated H2H stats
    $valueBets = DB::table('matches as m')
        ->leftJoin('standings as sh', function ($join) {
            $join->on('sh.match_id', '=', 'm.id')->whereColumn('sh.team', 'm.home_team');
        })
        ->leftJoin('standings as sa', function ($join) {
            $join->on('sa.match_id', '=', 'm.id')->whereColumn('sa.team', 'm.away_team');
        })
        ->leftJoin(DB::raw("
            (
                SELECT match_id,
                    SUM(CASE WHEN CAST(SUBSTRING_INDEX(score, ':', 1) AS UNSIGNED) >
                                   CAST(SUBSTRING_INDEX(score, ':', -1) AS UNSIGNED)
                             THEN 1 ELSE 0 END) AS home_wins,
                    SUM(CASE WHEN CAST(SUBSTRING_INDEX(score, ':', 1) AS UNSIGNED) <
                                   CAST(SUBSTRING_INDEX(score, ':', -1) AS UNSIGNED)
                             THEN 1 ELSE 0 END) AS away_wins
                FROM h2h_matches
                WHERE score REGEXP '^[0-9]+:[0-9]+$'
                GROUP BY match_id
            ) h2h_stats
        "), 'h2h_stats.match_id', '=', 'm.id')
        ->select(
            'm.id','m.match_key','m.match_url','m.home_team','m.away_team','m.match_date','m.match_time',
            'm.home_odds', DB::raw("ROUND((1/m.home_odds)*100,1) as home_win_prob"),
            'm.away_odds', DB::raw("ROUND((1/m.away_odds)*100,1) as away_win_prob"),

            // Standings Home
            'sh.rank as home_rank','sh.mp as home_mp','sh.wins as home_wins','sh.draws as home_draws',
            'sh.losses as home_losses','sh.pts as home_pts','sh.gd as home_gd',

            // Standings Away
            'sa.rank as away_rank','sa.mp as away_mp','sa.wins as away_wins','sa.draws as away_draws',
            'sa.losses as away_losses','sa.pts as away_pts','sa.gd as away_gd',

            // H2H aggregated
            DB::raw("COALESCE(h2h_stats.home_wins,0) as home_wins_vs_away"),
            DB::raw("COALESCE(h2h_stats.away_wins,0) as away_wins_vs_home")
        )
        ->whereRaw("(m.home_odds > 1.40 OR m.away_odds > 1.40)")
        ->whereRaw("sh.rank REGEXP '^[0-9]+' AND sa.rank REGEXP '^[0-9]+'")
        ->orderByRaw("LEAST(CAST(sh.rank AS UNSIGNED), CAST(sa.rank AS UNSIGNED)) ASC")
        ->orderByRaw("ABS(m.home_odds - m.away_odds) ASC")
        ->get();

    // Fetch full H2H history for matches shown
    $h2hByMatch = H2hMatch::whereIn('match_id', $valueBets->pluck('id'))
        ->select('match_id','date','home_team','away_team','score','home_odds','draw_odds','away_odds')
        ->orderBy('date', 'desc')
        ->get()
        ->groupBy('match_id');

    // Attach H2H history to each valueBet
    $valueBets->transform(function($bet) use ($h2hByMatch) {
        $bet->h2h_history = $h2hByMatch[$bet->id] ?? [];
        return $bet;
    });

    return Inertia::render('ValueBets/Index', [
        'valueBets' => $valueBets
    ]);
}



}
