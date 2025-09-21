<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

type Match = {
  id: number;
  match_key?: string;
  home_team: string;
  away_team: string;
  match_date?: string;
  match_time?: string;
  home_odds?: string;
  draw_odds?: string;
  away_odds?: string;
  match_url?: string;
  scraped_at?: string;
};

type H2H = {
  id: number;
  match_id: number;
  date?: string;
  home_team: string;
  away_team: string;
  score?: string;
  home_odds?: string;
  draw_odds?: string;
  away_odds?: string;
  created_at?: string;
};

type Standing = {
  id: number;
  match_id?: number;
  team: string;
  rank: string | number;
  mp?: string | number;
  wins?: string | number;
  draws?: string | number;
  losses?: string | number;
  goals?: string;
  gd?: string | number;
  pts?: string | number;
};

const props = defineProps<{
  matches: Match[];
  h2hMatches: H2H[];
  standings: Standing[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Dashboard', href: dashboard().url },
];

/* ------------------------- UTILS ------------------------- */
const toNum = (v: unknown, def = 0) => {
  const n = Number(String(v ?? '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : def;
};
const getTimeSafe = (t?: string) => {
  const s = String(t ?? '').trim();
  // Accept HH:MM or HH:MM:SS
  const m = s.match(/^(\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return '00:00';
  return `${m[1]}:${m[2]}`;
};
const toDate = (d?: string) => {
  if (!d) return undefined;
  const dt = new Date(`${d}T00:00:00`);
  return isNaN(dt.getTime()) ? undefined : dt;
};
const fmtDateTime = (d?: string, t?: string) => {
  if (!d) return '';
  const dateTimeStr = `${d}T${t ?? '00:00'}`;
  const dt = new Date(dateTimeStr);
  if (isNaN(dt.getTime())) return d;
  return dt.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
/* ------------------------- NEW HELPERS ------------------------- */
const hasH2hDominance = (match: Match, minDiff = 1) => {
  const stats = getH2hStats(match);
  return Math.abs(stats.homeWins - stats.awayWins) >= minDiff;
};
const isStrongFavoriteMatch = (match: Match) => {
  const home = getTeamStanding(match.home_team);
  const away = getTeamStanding(match.away_team);
  if (!home || !away) return false;

  const homeRank = toNum(home.rank, 9999);
  const awayRank = toNum(away.rank, 9999);
  const homeOdd = toNum(match.home_odds, 0);
  const awayOdd = toNum(match.away_odds, 0);
  if (homeOdd <= 2 || awayOdd <= 2) return false;

  const h2h = getH2hStats(match);
  const homeBetterH2h = h2h.homeWins > h2h.awayWins;
  const awayBetterH2h = h2h.awayWins > h2h.homeWins;

  // We consider "strong favorite" only if one team is clearly ahead in rank & H2H & odds
  if (homeBetterH2h && homeRank < awayRank && homeOdd < awayOdd) return true;
  if (awayBetterH2h && awayRank < homeRank && awayOdd < homeOdd) return true;

  return false;
};

const getOver25Probability = (match: Match) => {
  const rows = getH2hForMatch(match.id);
  if (!rows.length) return 0;
  const overCount = rows.filter(r => {
    const { h, a, ok } = parseScore(r.score);
    return ok && (h + a) >= 3;
  }).length;
  return Math.round((overCount / rows.length) * 100);
};
const isUnder25Match = (match: Match) => {
  const rows = getH2hForMatch(match.id);
  if (!rows.length) return false;
  // Return true if *all* matches are 0-0, 1-0, 0-1, 1-1, 2-0, or 0-2 (<=2 goals total)
  return rows.every(r => {
    const { h, a, ok } = parseScore(r.score);
    return ok && (h + a) <= 2;
  });
};

const fmtDate = (d?: string) => toDate(d)?.toLocaleDateString() ?? (d ?? '');
const sortByDateTime = (a: Match, b: Match) => {
  const ad = toDate(a.match_date)?.getTime() ?? 0;
  const bd = toDate(b.match_date)?.getTime() ?? 0;
  if (ad !== bd) return ad - bd;
  return getTimeSafe(a.match_time).localeCompare(getTimeSafe(b.match_time));
};

/* ------------------------- STATE ------------------------- */
const currentPage = ref(1);
const perPage = ref(100);
const filterTop5 = ref(false);
const filterRankDiff = ref(0);
const searchTeam = ref("");
const filterH2hDominance = ref(false);
const minOver25Pct = ref(0);
const filterUnder25 = ref(false);
const filterStrongFavorite = ref(false);

const chronological = computed(() => [...props.matches].sort(sortByDateTime));
const allDatesSorted = computed(() =>
  chronological.value.map(m => m.match_date).filter(Boolean) as string[]
);

const minDate = ref<string>(allDatesSorted.value[0] ?? '');
const maxDate = ref<string>(allDatesSorted.value[allDatesSorted.value.length - 1] ?? '');
const minTime = ref('00:00');
const maxTime = ref('23:59');

watch([filterTop5, filterRankDiff, searchTeam, minDate, maxDate, minTime, maxTime, perPage, filterH2hDominance, minOver25Pct], () => {
  currentPage.value = 1;
});

/* ------------------------- HELPERS ------------------------- */
const getTeamStanding = (team: string) =>
  props.standings.find(s => s.team.toLowerCase() === team.toLowerCase());

const getH2hForMatch = (matchId: number) =>
  props.h2hMatches.filter(h2h => h2h.match_id === matchId);

const parseScore = (score?: string): { h: number; a: number; ok: boolean } => {
  const s = String(score ?? '').trim();
  // match first two integers separated by :, -, or en dash
  const m = s.match(/^\s*(\d+)\s*[:\-–]\s*(\d+)\s*/);
  if (!m) return { h: 0, a: 0, ok: false };
  const h = Number(m[1]);
  const a = Number(m[2]);
  return { h, a, ok: Number.isFinite(h) && Number.isFinite(a) };
};

const getH2hStats = (match: Match) => {
  const rows = getH2hForMatch(match.id);
  let homeWins = 0, awayWins = 0, draws = 0, total = 0;

  rows.forEach(r => {
    const { h, a, ok } = parseScore(r.score);
    if (!ok) return;
    total++;

    // Map goals relative to current home/away
    let currentHomeGoals: number, currentAwayGoals: number;

    if (r.home_team === match.home_team && r.away_team === match.away_team) {
      // Same orientation
      currentHomeGoals = h;
      currentAwayGoals = a;
    } else if (r.home_team === match.away_team && r.away_team === match.home_team) {
      // Flipped orientation -> swap goals
      currentHomeGoals = a;
      currentAwayGoals = h;
    } else {
      // Safety fallback: assume first team is home
      currentHomeGoals = h;
      currentAwayGoals = a;
    }

    if (currentHomeGoals > currentAwayGoals) homeWins++;
    else if (currentAwayGoals > currentHomeGoals) awayWins++;
    else draws++;
  });

  return { homeWins, awayWins, draws, total };
};


const getH2hPercentages = (s: { homeWins: number; awayWins: number; draws: number; total: number }) => {
  if (!s.total) return { pHome: 0, pDraw: 0, pAway: 0 };
  let pHome = Math.round((s.homeWins / s.total) * 100);
  let pAway = Math.round((s.awayWins / s.total) * 100);
  let pDraw = 100 - pHome - pAway;
  if (pDraw < 0) pDraw = 0;
  return { pHome, pDraw, pAway };
};

/* ------------------------- PREDICTION ------------------------- */
const getPrediction = (match: Match) => {
  const home = getTeamStanding(match.home_team);
  const away = getTeamStanding(match.away_team);
  if (!home || !away) return { winner: "Unknown", confidence: 0 };

  let homeScore = 0, awayScore = 0;
  const homeRank = toNum(home.rank, 9999);
  const awayRank = toNum(away.rank, 9999);
  const homePts  = toNum(home.pts, 0);
  const awayPts  = toNum(away.pts, 0);

  // rank + points
  const rankDiff = awayRank - homeRank;
  if (rankDiff > 0) homeScore += Math.min(rankDiff, 10) * 2;
  if (rankDiff < 0) awayScore += Math.min(Math.abs(rankDiff), 10) * 2;
  const pointDiff = awayPts - homePts;
  if (pointDiff > 0) awayScore += Math.min(pointDiff, 20) * 1.2;
  if (pointDiff < 0) homeScore += Math.min(Math.abs(pointDiff), 20) * 1.2;

  // odds
  const homeOdd = toNum(match.home_odds, 0);
  const awayOdd = toNum(match.away_odds, 0);
  if (homeOdd > 0 && awayOdd > 0) {
    const invHome = 1 / homeOdd, invAway = 1 / awayOdd;
    homeScore += (invHome / (invHome + invAway)) * 10;
    awayScore += (invAway / (invHome + invAway)) * 10;
  }

  // h2h
  getH2hForMatch(match.id).forEach(g => {
    const { h, a, ok } = parseScore(g.score);
    if (!ok) return;
    if (g.home_team === match.home_team) {
      if (h > a) homeScore++; else if (a > h) awayScore++;
    } else if (g.away_team === match.home_team) {
      if (a > h) homeScore++; else if (h > a) awayScore++;
    }
  });

  const total = homeScore + awayScore;
  if (total <= 0) return { winner: "Balanced", confidence: 50 };
  const homePct = Math.round((homeScore / total) * 100);
  if (Math.abs(homePct - (100 - homePct)) < 10) return { winner: "Draw Likely", confidence: 50 };
  return homePct > 50
    ? { winner: match.home_team, confidence: homePct }
    : { winner: match.away_team, confidence: 100 - homePct };
};

/* ------------------------- FILTERING & PAGINATION ------------------------- */
const filteredMatches = computed(() =>
  chronological.value.filter(m => {
    const home = getTeamStanding(m.home_team);
    const away = getTeamStanding(m.away_team);
    if (!home || !away) return false;

    const dm = toDate(m.match_date)?.getTime() ?? 0;
    if (minDate.value && dm < (toDate(minDate.value)?.getTime() ?? -Infinity)) return false;
    if (maxDate.value && dm > (toDate(maxDate.value)?.getTime() ?? Infinity)) return false;

    const t = getTimeSafe(m.match_time);
    if (t < getTimeSafe(minTime.value) || t > getTimeSafe(maxTime.value)) return false;

    const diff = Math.abs(toNum(home.rank, 9999) - toNum(away.rank, 9999));
    if (filterTop5.value && toNum(home.rank, 9999) > 5 && toNum(away.rank, 9999) > 5) return false;
    if (filterRankDiff.value > 0 && diff < filterRankDiff.value) return false;

    if (searchTeam.value.trim()) {
      const s = searchTeam.value.toLowerCase();
      if (!m.home_team.toLowerCase().includes(s) && !m.away_team.toLowerCase().includes(s)) return false;
    }
    if (filterH2hDominance.value && !hasH2hDominance(m, 1)) return false;
if (minOver25Pct.value > 0 && getOver25Probability(m) < minOver25Pct.value) return false;
if (filterUnder25.value && !isUnder25Match(m)) return false;
if (filterStrongFavorite.value && !isStrongFavoriteMatch(m)) return false;

    return true;
  })
);

const totalMatches = computed(() => filteredMatches.value.length);
const totalPages = computed(() => Math.max(1, Math.ceil(totalMatches.value / perPage.value)));
const paginatedMatches = computed(() => {
  const start = (currentPage.value - 1) * perPage.value;
  return filteredMatches.value.slice(start, start + perPage.value);
});

const visiblePages = computed(() => {
  const maxPages = 5;
  let start = Math.max(1, currentPage.value - 2);
  let end = Math.min(totalPages.value, start + maxPages - 1);
  if (end - start < maxPages - 1) start = Math.max(1, end - maxPages + 1);
  return Array.from({ length: end - start + 1 }, (_, i) => start + i);
});

const goToPage = (p: number) => { if (p >= 1 && p <= totalPages.value) currentPage.value = p; };
const resetFilters = () => {
  filterTop5.value = false;
  filterRankDiff.value = 0;
  searchTeam.value = "";
  filterH2hDominance.value = false;
  filterUnder25.value = false;
  minOver25Pct.value = 0;
filterStrongFavorite.value = false;

  minTime.value = "00:00";
  maxTime.value = "23:59";
  minDate.value = allDatesSorted.value[0] ?? "";
  maxDate.value = allDatesSorted.value[allDatesSorted.value.length - 1] ?? "";

  perPage.value = 100;
  currentPage.value = 1;
};

</script>

<template>
  <Head title="Dashboard" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex flex-col gap-10 p-6">
      <!-- FILTER BAR -->
    <div
  class="rounded-xl bg-white/70 dark:bg-gray-800/70 border p-4 flex flex-wrap items-center gap-4 shadow backdrop-blur">

  <!-- Date Filter -->
  <div class="flex items-center gap-2">
    <span class="text-sm text-gray-600 dark:text-gray-300">Date:</span>
    <input type="date" v-model="minDate"
      class="border rounded p-1 text-sm dark:bg-gray-700 dark:text-white">
    <span>-</span>
    <input type="date" v-model="maxDate"
      class="border rounded p-1 text-sm dark:bg-gray-700 dark:text-white">
  </div>

  <!-- Time Filter -->
  <div class="flex items-center gap-2">
    <span class="text-sm text-gray-600 dark:text-gray-300">Time:</span>
    <input type="time" v-model="minTime"
      class="border rounded p-1 text-sm dark:bg-gray-700 dark:text-white">
    <span>-</span>
    <input type="time" v-model="maxTime"
      class="border rounded p-1 text-sm dark:bg-gray-700 dark:text-white">
  </div>

  <!-- Show Top 5 Teams Filter -->
  <button @click="filterTop5 = !filterTop5"
    class="px-3 py-1 rounded-full text-sm border transition"
    :class="filterTop5
      ? 'bg-blue-500 text-white border-blue-500'
      : 'bg-gray-100 dark:bg-gray-700 border-gray-300 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'">
    {{ filterTop5 ? '✓ ' : '' }} Show Top 5
  </button>

  <!-- Rank Difference Slider -->
  <div class="flex items-center gap-2">
    <span class="text-sm">Min Rank Diff:</span>
    <input type="range" min="0" max="20" v-model.number="filterRankDiff"
      class="accent-blue-500 w-40">
    <span class="text-sm font-bold">{{ filterRankDiff }}</span>
  </div>

  <!-- H2H Dominance Filter -->
  <button @click="filterH2hDominance = !filterH2hDominance"
    class="px-3 py-1 rounded-full text-sm border transition"
    :class="filterH2hDominance
      ? 'bg-purple-500 text-white border-purple-500'
      : 'bg-gray-100 dark:bg-gray-700 border-gray-300 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'">
    {{ filterH2hDominance ? '✓ ' : '' }} H2H Dominance
  </button>

  <!-- Min Over 2.5 Goals Slider -->
  <div class="flex items-center gap-2">
    <span class="text-sm">Min Over 2.5%:</span>
    <input type="range" min="0" max="100" step="5" v-model.number="minOver25Pct"
      class="accent-purple-500 w-40">
    <span class="text-sm font-bold">{{ minOver25Pct }}%</span>
  </div>
<!-- Under 2.5 Filter -->
<button @click="filterUnder25 = !filterUnder25"
  class="px-3 py-1 rounded-full text-sm border transition"
  :class="filterUnder25
    ? 'bg-amber-500 text-white border-amber-500'
    : 'bg-gray-100 dark:bg-gray-700 border-gray-300 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'">
  {{ filterUnder25 ? '✓ ' : '' }} Under 2.5 H2H
</button>
<!-- Strong Favorite Filter -->
<button @click="filterStrongFavorite = !filterStrongFavorite"
  class="px-3 py-1 rounded-full text-sm border transition"
  :class="filterStrongFavorite
    ? 'bg-emerald-500 text-white border-emerald-500'
    : 'bg-gray-100 dark:bg-gray-700 border-gray-300 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'">
  {{ filterStrongFavorite ? '✓ ' : '' }} Strong Favorite
</button>

  <!-- Search Team -->
  <div class="ml-auto relative">
    <input type="text" placeholder="Search team..." v-model="searchTeam"
      class="rounded-full border pl-8 pr-3 py-1 text-sm dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-400">
    <svg class="w-4 h-4 absolute left-2 top-2.5 text-gray-400" fill="none" stroke="currentColor"
      viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M21 21l-4.35-4.35M17 10.5A6.5 6.5 0 104 10.5a6.5 6.5 0 0013 0z" />
    </svg>
  </div>

  <!-- Reset Button -->
  <button @click="resetFilters"
    class="px-3 py-1 rounded-full text-sm border bg-gray-50 hover:bg-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600">
    ♻ Reset
  </button>
</div>

      <!-- HEADER -->
      <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-300">
        <h1 class="text-2xl font-bold">Latest Matches</h1>
        <div class="flex items-center gap-3">
          <span>Showing <b>{{ paginatedMatches.length }}</b> of <b>{{ totalMatches }}</b></span>
          <select v-model.number="perPage" class="border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
            <option :value="12">12</option>
            <option :value="24">24</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </div>
      </div>

      <!-- MATCH CARDS -->
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="match in paginatedMatches" :key="match.id"
          class="rounded-2xl border bg-white dark:bg-gray-800 p-5 shadow-lg hover:shadow-xl transition flex flex-col gap-4">
          <div class="flex justify-between text-xs text-gray-400">
<span>{{ fmtDateTime(match.match_date, match.match_time) }}</span>
            <span v-if="match.scraped_at">Updated: {{ new Date(match.scraped_at).toLocaleString() }}</span>
          </div>
          <div class="flex justify-between text-lg font-semibold">
            <span>{{ match.home_team }}</span><span class="text-gray-500 text-sm">vs</span><span>{{ match.away_team }}</span>
          </div>
          <div class="flex justify-between text-xs font-bold">
            <span class="bg-green-100 text-green-700 px-2 py-1 rounded-md">Home: {{ match.home_odds ?? '--' }}</span>
            <span class="bg-gray-200 text-gray-800 px-2 py-1 rounded-md">Draw: {{ match.draw_odds ?? '--' }}</span>
            <span class="bg-red-100 text-red-700 px-2 py-1 rounded-md">Away: {{ match.away_odds ?? '--' }}</span>
          </div>

          <!-- Prediction -->
          <div class="flex flex-col gap-1">
            <div class="text-center text-sm font-bold py-1 rounded-lg"
              :class="{
                'bg-green-100 text-green-700': getPrediction(match).winner === match.home_team,
                'bg-red-100 text-red-700': getPrediction(match).winner === match.away_team,
                'bg-gray-200 text-gray-700': ['Draw Likely','Balanced','Unknown'].includes(getPrediction(match).winner)
              }">
              🧠 Predicted: {{ getPrediction(match).winner }}
            </div>
            <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
              <div class="h-full bg-blue-500" :style="{ width: getPrediction(match).confidence + '%' }"></div>
            </div>
  <p class="text-xs text-gray-500 text-center">Confidence: {{ getPrediction(match).confidence }}%</p>
          </div>
<!-- NEW: H2H Badge + Over 2.5 Goals -->
<div class="flex flex-col items-center gap-1">
  <div v-if="hasH2hDominance(match)" class="text-xs text-purple-600 font-bold">
    🔥 H2H Dominance
  </div>
  <p class="text-xs text-blue-500">
    Over 2.5 Goals: {{ getOver25Probability(match) }}%
  </p>
</div>
          <!-- Standings -->
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div v-if="getTeamStanding(match.home_team)" class="rounded-lg bg-gray-50 dark:bg-gray-700 p-2">
              <p class="text-[10px] text-gray-500 uppercase">Home</p>
              <p>Rank: <b>{{ getTeamStanding(match.home_team)?.rank }}</b></p>
              <p>W: {{ getTeamStanding(match.home_team)?.wins ?? 0 }} / D: {{ getTeamStanding(match.home_team)?.draws ?? 0 }} / L: {{ getTeamStanding(match.home_team)?.losses ?? 0 }}</p>
              <p class="font-semibold text-green-600">Pts: {{ getTeamStanding(match.home_team)?.pts ?? 0 }}</p>
            </div>
            <div v-if="getTeamStanding(match.away_team)" class="rounded-lg bg-gray-50 dark:bg-gray-700 p-2">
              <p class="text-[10px] text-gray-500 uppercase">Away</p>
              <p>Rank: <b>{{ getTeamStanding(match.away_team)?.rank }}</b></p>
              <p>W: {{ getTeamStanding(match.away_team)?.wins ?? 0 }} / D: {{ getTeamStanding(match.away_team)?.draws ?? 0 }} / L: {{ getTeamStanding(match.away_team)?.losses ?? 0 }}</p>
              <p class="font-semibold text-green-600">Pts: {{ getTeamStanding(match.away_team)?.pts ?? 0 }}</p>
            </div>
          </div>

          <!-- H2H -->
          <div v-if="getH2hForMatch(match.id).length" class="text-xs">
            <h3 class="font-semibold mb-2">H2H ({{ match.home_team }} vs {{ match.away_team }})</h3>
            <div class="flex flex-wrap gap-4 mb-2">
              <span><span class="inline-block w-3 h-3 bg-green-500 rounded"></span> {{ match.home_team }} Wins: <b>{{ getH2hStats(match).homeWins }}</b></span>
              <span><span class="inline-block w-3 h-3 bg-gray-400 rounded"></span> Draws: <b>{{ getH2hStats(match).draws }}</b></span>
              <span><span class="inline-block w-3 h-3 bg-red-500 rounded"></span> {{ match.away_team }} Wins: <b>{{ getH2hStats(match).awayWins }}</b></span>
              <span>Total: <b>{{ getH2hStats(match).total }}</b></span>
            </div>
            <div class="flex h-3 rounded overflow-hidden">
              <div class="bg-green-500" :style="{ width: getH2hPercentages(getH2hStats(match)).pHome + '%' }"></div>
              <div class="bg-gray-400" :style="{ width: getH2hPercentages(getH2hStats(match)).pDraw + '%' }"></div>
              <div class="bg-red-500" :style="{ width: getH2hPercentages(getH2hStats(match)).pAway + '%' }"></div>
            </div>
            <ul class="mt-3 space-y-1">
              <li v-for="h2h in getH2hForMatch(match.id).slice(0, 5)" :key="h2h.id" class="flex justify-between text-gray-500">
                <span>{{ h2h.date }} {{ h2h.home_team }} vs {{ h2h.away_team }}</span><b>{{ h2h.score }}</b>
              </li>
            </ul>
          </div>

          <!-- Footer -->
          <div class="flex justify-between text-[11px] text-gray-400 border-t pt-2">
            <a :href="match.match_url" target="_blank" class="text-blue-500 hover:underline">View</a>
            <span v-if="match.match_key" class="truncate max-w-[60%]">{{ match.match_key }}</span>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div class="flex justify-center mt-6 gap-2">
        <button :disabled="currentPage === 1" @click="goToPage(currentPage - 1)" class="px-3 py-1 rounded-full border text-sm disabled:opacity-50">‹</button>
        <span v-if="visiblePages[0] > 1" class="px-2">...</span>
        <button v-for="page in visiblePages" :key="page"
          @click="goToPage(page)"
          class="px-3 py-1 rounded-full border text-sm"
          :class="page === currentPage ? 'bg-blue-500 text-white border-blue-500' : 'border-gray-300'">
          {{ page }}
        </button>
        <span v-if="visiblePages[visiblePages.length - 1] < totalPages" class="px-2">...</span>
        <button :disabled="currentPage === totalPages" @click="goToPage(currentPage + 1)" class="px-3 py-1 rounded-full border text-sm disabled:opacity-50">›</button>
      </div>
    </div>
  </AppLayout>
</template>
