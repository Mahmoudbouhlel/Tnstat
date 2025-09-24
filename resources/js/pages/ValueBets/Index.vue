<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

type H2HMatch = {
  date: string;
  home_team: string;
  away_team: string;
  score: string;
  home_odds?: string;
  draw_odds?: string;
  away_odds?: string;
};

type ValueBet = {
  id: number;
  type?: string;
  home_team: string;
  away_team: string;
  match_date: string; // YYYY-MM-DD
  match_time: string; // HH:mm:ss
  match_url?: string;
  home_odds: string;
  away_odds: string;
  home_rank: string | number;
  away_rank: string | number;
  home_mp?: string | number;
  home_wins?: string | number;
  home_draws?: string | number;
  home_losses?: string | number;
  home_pts?: string | number;
  home_gd?: string | number;
  away_mp?: string | number;
  away_wins?: string | number;
  away_draws?: string | number;
  away_losses?: string | number;
  away_pts?: string | number;
  away_gd?: string | number;
  home_wins_vs_away: number;
  away_wins_vs_home: number;
  h2h_history?: H2HMatch[];
};

const props = defineProps<{ valueBets: ValueBet[] }>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Dashboard', href: dashboard().url },
  { title: 'Value Bets', href: '/value-bets' },
];

/* ------------------------- HELPERS ------------------------- */
const calcH2hPct = (home: number, away: number) => {
  const total = home + away;
  if (total === 0) return { pHome: 50, pAway: 50 };
  return {
    pHome: Math.round((home / total) * 100),
    pAway: Math.round((away / total) * 100),
  };
};
const predictionFilter = ref<"all" | "home" | "away" | "draw">("all");

// Prediction Algorithm
const predictWinner = (bet: ValueBet) => {
  const { pHome, pAway } = calcH2hPct(bet.home_wins_vs_away, bet.away_wins_vs_home);

  const homeFormScore =
    (Number(bet.home_wins) || 0) * 3 +
    (Number(bet.home_draws) || 0) * 1 -
    (Number(bet.home_losses) || 0) * 1 +
    (Number(bet.home_gd) || 0);

  const awayFormScore =
    (Number(bet.away_wins) || 0) * 3 +
    (Number(bet.away_draws) || 0) * 1 -
    (Number(bet.away_losses) || 0) * 1 +
    (Number(bet.away_gd) || 0);

  const homeStrength = Math.max(homeFormScore, 0) * 0.9 + pHome * 0.1;
  const awayStrength = Math.max(awayFormScore, 0) * 0.9 + pAway * 0.1;

  const total = homeStrength + awayStrength || 1;
  const confidence = Math.round(Math.max(homeStrength, awayStrength) / total * 100);

  let winner: string | "draw" = "draw";
  if (Math.abs(homeStrength - awayStrength) <= 5) {
    winner = "draw";
  } else {
    winner = homeStrength > awayStrength ? bet.home_team : bet.away_team;
  }

  return { winner, confidence, isStrong: confidence >= 70 };
};

const formatDate = (date: string, time: string) =>
  new Date(`${date}T${time}`).toLocaleString("fr-FR", {
    weekday: "short",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });

/* ------------------------- FILTERS ------------------------- */
const currentPage = ref(1);
const perPage = ref(50);
const searchTeam = ref("");
const showOnlyStrong = ref(false);
const minMP = ref(0);

// Date range defaults (today)
const today = new Date().toISOString().split("T")[0];
const dateFrom = ref(today);
const dateTo = ref(today);

// Time range defaults
const timeFrom = ref("00:00");
const timeTo = ref("23:59");

const filteredBets = computed(() =>
  props.valueBets.filter((b) => {
    const s = searchTeam.value.toLowerCase();
    const prediction = predictWinner(b);
    const homeMP = Number(b.home_mp) || 0;
    const awayMP = Number(b.away_mp) || 0;

    const matchDateTime = new Date(`${b.match_date}T${b.match_time}`);
    const start = new Date(`${dateFrom.value}T${timeFrom.value}`);
    const end = new Date(`${dateTo.value}T${timeTo.value}`);

    // Date+Time range filter
    if (matchDateTime < start || matchDateTime > end) return false;

    // Search filter
    if (s && !b.home_team.toLowerCase().includes(s) && !b.away_team.toLowerCase().includes(s)) return false;

    // Min MP filter
    if (homeMP < minMP.value || awayMP < minMP.value) return false;

    // Strong prediction filter
    if (showOnlyStrong.value && !prediction.isStrong) return false;

    // Prediction outcome filter
    if (predictionFilter.value !== "all") {
      if (predictionFilter.value === "home" && prediction.winner !== b.home_team) return false;
      if (predictionFilter.value === "away" && prediction.winner !== b.away_team) return false;
      if (predictionFilter.value === "draw" && prediction.winner !== "draw") return false;
    }

    return true;
  })
);

const totalBets = computed(() => filteredBets.value.length);
const totalPages = computed(() => Math.max(1, Math.ceil(totalBets.value / perPage.value)));
const paginatedBets = computed(() => {
  const start = (currentPage.value - 1) * perPage.value;
  return filteredBets.value.slice(start, start + perPage.value);
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
  searchTeam.value = "";
  showOnlyStrong.value = false;
  minMP.value = 0;
  dateFrom.value = today;
  dateTo.value = today;
  timeFrom.value = "00:00";
  timeTo.value = "23:59";
  currentPage.value = 1;
};
</script>

<template>
  <Head title="Value Bets" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex flex-col gap-8 p-6">

      <!-- FILTER BAR -->
      <div class="rounded-xl bg-gradient-to-r from-indigo-50 via-white to-indigo-50 dark:from-gray-800 dark:to-gray-900 border p-4 flex flex-wrap items-center gap-4 shadow">

        <!-- Date Range -->
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Date From:</label>
          <input type="date" v-model="dateFrom"
            class="rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
        </div>
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Date To:</label>
          <input type="date" v-model="dateTo"
            class="rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
        </div>

        <!-- Time Range -->
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Time From:</label>
          <input type="time" v-model="timeFrom"
            class="rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
        </div>
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Time To:</label>
          <input type="time" v-model="timeTo"
            class="rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
        </div>

        <!-- Search -->
        <input type="text" placeholder="🔍 Search team..." v-model="searchTeam"
          class="rounded-full border pl-8 pr-3 py-1 text-sm dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-indigo-400">

        <!-- Min MP Filter -->
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Min MP:</label>
          <input type="number" v-model.number="minMP" min="0"
            class="w-16 rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
        </div>

        <!-- Strong Prediction Toggle -->
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" v-model="showOnlyStrong" class="accent-rose-500">
          🔥 Strong Predictions (≥ 70%)
        </label>

        <!-- Prediction Filter -->
        <div class="flex items-center gap-2 text-sm">
          <label class="font-semibold">Prediction:</label>
          <select v-model="predictionFilter"
            class="rounded-md border px-2 py-1 text-sm dark:bg-gray-700 dark:text-white">
            <option value="all">All</option>
            <option value="home">Home Win</option>
            <option value="away">Away Win</option>
            <option value="draw">Draw</option>
          </select>
        </div>

        <!-- Reset -->
        <button @click="resetFilters"
          class="px-3 py-1 rounded-full text-sm border bg-gray-50 hover:bg-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600">
          ♻ Reset
        </button>
      </div>

      <!-- VALUE BET CARDS -->
      <div v-if="paginatedBets.length" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="bet in paginatedBets" :key="bet.id"
          class="rounded-2xl border bg-white dark:bg-gray-800 p-5 shadow-lg hover:shadow-2xl transition transform hover:-translate-y-1 flex flex-col gap-3">

          <!-- Prediction Badge -->
          <div v-if="predictWinner(bet)" class="flex justify-end">
            <span :class="[
                'text-xs font-bold px-3 py-1 rounded-full shadow',
                predictWinner(bet).confidence >= 70
                  ? 'bg-orange-100 text-orange-700'
                  : predictWinner(bet).confidence >= 55
                    ? 'bg-yellow-100 text-yellow-700'
                    : 'bg-gray-100 text-gray-700'
              ]">
              🔮 {{ predictWinner(bet).winner }} ({{ predictWinner(bet).confidence }}%)
            </span>
          </div>

          <!-- Date & Teams -->
          <div class="text-xs text-gray-400">{{ formatDate(bet.match_date, bet.match_time) }}</div>
          <div class="flex justify-between text-lg font-bold">
            <span>{{ bet.home_team }}</span>
            <span class="text-gray-500 text-sm">vs</span>
            <span>{{ bet.away_team }}</span>
          </div>

          <!-- Odds -->
          <div class="flex justify-between text-xs font-bold">
            <span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded-md">🏠 {{ bet.home_odds }}</span>
            <span class="bg-rose-100 text-rose-700 px-2 py-1 rounded-md">🛫 {{ bet.away_odds }}</span>
          </div>

          <!-- Standings Highlight -->
          <div class="grid grid-cols-2 gap-2 text-xs mt-2">
            <div class="rounded-lg p-2"
              :class="predictWinner(bet).winner === bet.home_team
                ? 'bg-emerald-50 border border-emerald-300'
                : 'bg-gray-50 dark:bg-gray-700'">
              <p class="text-[10px] uppercase text-gray-500">Home</p>
              <p>Rank: <b>{{ bet.home_rank }}</b></p>
              <p>MP: {{ bet.home_mp ?? '-' }} | W: {{ bet.home_wins ?? 0 }} / D: {{ bet.home_draws ?? 0 }} / L: {{ bet.home_losses ?? 0 }}</p>
              <p>Goals: {{ bet.home_gd ?? 0 }} | Pts: <b>{{ bet.home_pts ?? 0 }}</b></p>
            </div>
            <div class="rounded-lg p-2"
              :class="predictWinner(bet).winner === bet.away_team
                ? 'bg-emerald-50 border border-emerald-300'
                : 'bg-gray-50 dark:bg-gray-700'">
              <p class="text-[10px] uppercase text-gray-500">Away</p>
              <p>Rank: <b>{{ bet.away_rank }}</b></p>
              <p>MP: {{ bet.away_mp ?? '-' }} | W: {{ bet.away_wins ?? 0 }} / D: {{ bet.away_draws ?? 0 }} / L: {{ bet.away_losses ?? 0 }}</p>
              <p>Goals: {{ bet.away_gd ?? 0 }} | Pts: <b>{{ bet.away_pts ?? 0 }}</b></p>
            </div>
          </div>

          <!-- H2H -->
          <details class="mt-2 group">
            <summary class="cursor-pointer text-xs font-semibold text-gray-700 dark:text-gray-300">
              📊 Head-to-Head ({{ bet.h2h_history?.length ?? 0 }})
            </summary>
            <div class="mt-2 rounded-lg border bg-gray-50 dark:bg-gray-800 p-2 space-y-2">
              <div v-for="h in bet.h2h_history" :key="h.date"
                class="flex justify-between items-center text-xs p-2 rounded hover:bg-white/50 dark:hover:bg-gray-700 transition">
                <div class="flex flex-col">
                  <span class="font-semibold">{{ h.date }}</span>
                  <span class="text-gray-500">{{ h.home_team }} vs {{ h.away_team }}</span>
                </div>
                <span class="font-bold text-sm">{{ h.score }}</span>
                <div class="flex flex-col text-[10px] text-right">
                  <span class="text-emerald-600">H: {{ h.home_odds }}</span>
                  <span class="text-gray-500">D: {{ h.draw_odds }}</span>
                  <span class="text-rose-600">A: {{ h.away_odds }}</span>
                </div>
              </div>
            </div>
          </details>

          <!-- Footer -->
          <div class="flex justify-between items-center text-xs text-gray-500 border-t pt-2">
            <a v-if="bet.match_url" :href="bet.match_url" target="_blank"
              class="text-blue-500 hover:underline flex items-center gap-1">
              🔗 View Match
            </a>
            <span class="truncate max-w-[60%]">#{{ bet.id }}</span>
          </div>
        </div>
      </div>

      <div v-else class="text-center text-gray-400 text-sm mt-4">
        No matches found for {{ selectedDate }}.
      </div>

      <!-- PAGINATION -->
      <div class="flex justify-center mt-6 gap-2" v-if="totalPages > 1">
        <button :disabled="currentPage === 1" @click="goToPage(currentPage - 1)"
          class="px-3 py-1 rounded-full border text-sm disabled:opacity-50">‹</button>
        <span v-if="visiblePages[0] > 1" class="px-2">...</span>
        <button v-for="page in visiblePages" :key="page"
          @click="goToPage(page)"
          class="px-3 py-1 rounded-full border text-sm"
          :class="page === currentPage ? 'bg-indigo-500 text-white border-indigo-500' : 'border-gray-300'">
          {{ page }}
        </button>
        <span v-if="visiblePages[visiblePages.length - 1] < totalPages" class="px-2">...</span>
        <button :disabled="currentPage === totalPages" @click="goToPage(currentPage + 1)"
          class="px-3 py-1 rounded-full border text-sm disabled:opacity-50">›</button>
      </div>
    </div>
  </AppLayout>
</template>
