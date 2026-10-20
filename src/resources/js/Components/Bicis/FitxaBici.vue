<script setup lang="ts">
/**
 * La fitxa d'una bici: el que diuen les seves sortides. Les dades es demanen en obrir-la
 * —són milers de sortides i no viatgen amb la pàgina— i les sortides, any per any.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';

ChartJS.register(...registerables);

interface Any {
    any: number;
    sortides: number;
    km: number;
    /** Dels rodets: quilòmetres virtuals, inclosos a `km`. */
    km_indoor: number;
    hores: number;
    desnivell: number;
    despeses: number;
    cost_km: number | null;
}

interface Mes {
    mes: string;
    sortides: number;
    km: number;
    km_indoor: number;
}

interface Component {
    id: number;
    nom: string;
    estat: string;
    data_inici: string | null;
    data_fi: string | null;
    sortides: number;
    km: number;
    /** Els de la bici entre les dates del component, s'hi marqués o no. */
    sortides_en_dates: number;
    km_en_dates: number;
}

interface Sortida {
    id: number;
    data: string;
    hora: string | null;
    nom: string | null;
    tipus: string | null;
    km: number | null;
    temps_s: number | null;
    desnivell: number | null;
    kmh: number | null;
    fc: number | null;
    amb_recorregut: boolean;
    desnivell_corregit: boolean;
}

interface Fitxa {
    per_any: Any[];
    per_mes: Mes[];
    tipus: { tipus: string; sortides: number }[];
    components: Component[];
}

const props = defineProps<{ vehicleId: number }>();

const pestanya = ref<'anys' | 'sortides' | 'components' | 'grafiques'>('anys');
const fitxa = ref<Fitxa | null>(null);
const error = ref<string | null>(null);

const ETIQUETA_TIPUS: Record<string, string> = {
    mountain_biking: 'BTT',
    cycling: 'Ciclisme',
    road_biking: 'Carretera',
    gravel_cycling: 'Gravel',
    indoor_cycling: 'Rodets',
    track_cycling: 'Pista',
    downhill_biking: 'Descens',
};
const etiquetaTipus = (t: string | null) => (t ? ETIQUETA_TIPUS[t] ?? t : '—');

async function demana<T>(url: string): Promise<T | null> {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        error.value = `No s'han pogut carregar les dades (error ${res.status}).`;
        return null;
    }
    return res.json();
}

onMounted(async () => {
    fitxa.value = await demana<Fitxa>(route('bicis.fitxa', props.vehicleId));
    anySortides.value = fitxa.value?.per_any.find((a) => a.sortides > 0)?.any ?? null;
});

// ---- Formats ----
const nombre = (v: number, decimals = 0) =>
    v.toLocaleString('ca-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const euros = (v: number) => v.toLocaleString('ca-ES', { style: 'currency', currency: 'EUR' });
function durada(s: number | null): string {
    if (s === null) return '—';
    const h = Math.floor(s / 3600);
    const m = Math.round((s % 3600) / 60);
    return `${h}:${String(m).padStart(2, '0')}`;
}

// ---- Per any ----
const total = computed(() => {
    const a = fitxa.value?.per_any ?? [];
    const suma = (k: keyof Any) => a.reduce((t, f) => t + ((f[k] as number) ?? 0), 0);
    return { sortides: suma('sortides'), km: suma('km'), km_indoor: suma('km_indoor'), hores: suma('hores'), desnivell: suma('desnivell'), despeses: suma('despeses') };
});

// ---- Sortides ----
const anySortides = ref<number | null>(null);
const tipusSortides = ref<string>('');
const sortides = ref<Sortida[]>([]);
const carregantSortides = ref(false);

async function carregaSortides() {
    if (anySortides.value === null) return;
    carregantSortides.value = true;
    const params = new URLSearchParams({ any: String(anySortides.value) });
    if (tipusSortides.value) params.set('tipus', tipusSortides.value);
    sortides.value = (await demana<Sortida[]>(route('bicis.sortides', props.vehicleId) + '?' + params)) ?? [];
    carregantSortides.value = false;
}

watch([pestanya, anySortides, tipusSortides], () => {
    if (pestanya.value === 'sortides') carregaSortides();
});

const anysAmbSortides = computed(() => (fitxa.value?.per_any ?? []).filter((a) => a.sortides > 0).map((a) => a.any));

// ---- Components ----
/** Sortides de la bici dins de les dates del component on no es va marcar. */
const noMarcades = (c: Component) => Math.max(0, c.sortides_en_dates - c.sortides);

// ---- Gràfiques ----
/** Els mateixos dos colors de sèrie que les gràfiques de vehicles: validats en clar i en fosc. */
const SERIE_BLAU = '#0284c7';
const SERIE_AMBRE = '#d97706';

const fosc = ref(false);
let consultaFosc: MediaQueryList | null = null;
const anotaFosc = (e: MediaQueryListEvent | MediaQueryList) => (fosc.value = e.matches);
onMounted(() => {
    consultaFosc = window.matchMedia('(prefers-color-scheme: dark)');
    anotaFosc(consultaFosc);
    consultaFosc.addEventListener('change', anotaFosc);
});
onUnmounted(() => consultaFosc?.removeEventListener('change', anotaFosc));

const tinta = computed(() => (fosc.value ? '#9ca3af' : '#6b7280'));
const quadricula = computed(() => (fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'));
const superficie = computed(() => (fosc.value ? '#1f2937' : '#ffffff'));

const teIndoor = computed(() => total.value.km_indoor > 0);

/** Barres primes, arrodonides a la punta i separades del segment del costat per la superfície. */
const barra = (color: string) => ({
    backgroundColor: color,
    borderColor: superficie.value,
    borderWidth: { top: 2 },
    borderRadius: 4,
    borderSkipped: 'bottom' as const,
    maxBarThickness: 36,
});

function series(etiquetes: string[], exterior: number[], indoor: number[]): ChartData<'bar'> {
    const sets: ChartData<'bar'>['datasets'] = [{ label: 'Exterior', data: exterior, ...barra(SERIE_BLAU) }];
    if (teIndoor.value) sets.push({ label: 'Rodets (virtuals)', data: indoor, ...barra(SERIE_AMBRE) });
    return { labels: etiquetes, datasets: sets };
}

const cronologic = computed(() => [...(fitxa.value?.per_any ?? [])].filter((a) => a.sortides > 0).reverse());

const dadesPerAny = computed(() =>
    series(
        cronologic.value.map((a) => String(a.any)),
        cronologic.value.map((a) => Math.round(a.km - a.km_indoor)),
        cronologic.value.map((a) => Math.round(a.km_indoor)),
    ),
);

const anyGrafica = ref<number | null>(null);
watch(anysAmbSortides, (anys) => { if (anyGrafica.value === null) anyGrafica.value = anys[0] ?? null; }, { immediate: true });

const NOMS_MES = ['gen', 'feb', 'març', 'abr', 'maig', 'juny', 'jul', 'ag', 'set', 'oct', 'nov', 'des'];
const dadesPerMes = computed(() => {
    const mesos = new Map((fitxa.value?.per_mes ?? []).filter((m) => m.mes.startsWith(String(anyGrafica.value))).map((m) => [Number(m.mes.slice(5)), m]));
    return series(
        NOMS_MES,
        NOMS_MES.map((_, i) => mesos.get(i + 1)?.km ?? 0),
        NOMS_MES.map((_, i) => mesos.get(i + 1)?.km_indoor ?? 0),
    );
});

const opcions = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        // Amb una sola sèrie el títol ja la diu: la llegenda només quan n'hi ha dues
        legend: { display: teIndoor.value, labels: { color: tinta.value, boxWidth: 12 } },
        tooltip: {
            callbacks: {
                label: (c) => `${c.dataset.label}: ${nombre(c.parsed.y as number)} km`,
            },
        },
    },
    scales: {
        x: { stacked: true, grid: { display: false }, ticks: { color: tinta.value } },
        y: { stacked: true, beginAtZero: true, grid: { color: quadricula.value }, border: { display: false }, ticks: { color: tinta.value } },
    },
}));
</script>

<template>
    <div>
        <div class="mb-3 flex gap-1 border-b border-gray-200 dark:border-gray-600">
            <button v-for="[clau, etiqueta] in [['anys', 'Per any'], ['sortides', 'Sortides'], ['components', 'Components'], ['grafiques', 'Gràfiques']]"
                :key="clau" @click="pestanya = clau as any"
                class="px-3 py-1.5 text-xs font-medium transition-colors"
                :class="pestanya === clau
                    ? 'border-b-2 border-sky-500 text-sky-600 dark:text-sky-400'
                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'">
                {{ etiqueta }}
            </button>
        </div>

        <p v-if="error" class="py-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <p v-else-if="!fitxa" class="py-6 text-center text-xs text-gray-400 dark:text-gray-500">Carregant…</p>
        <p v-else-if="!fitxa.per_any.length" class="py-6 text-center text-xs text-gray-400 dark:text-gray-500">
            Aquesta bici encara no té sortides ni despeses.
        </p>

        <template v-else>
            <!-- Per any -->
            <div v-if="pestanya === 'anys'" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                            <th class="pb-1 pr-3 font-medium">Any</th>
                            <th class="pb-1 pr-3 text-right font-medium">Sortides</th>
                            <th class="pb-1 pr-3 text-right font-medium">Km</th>
                            <th class="pb-1 pr-3 text-right font-medium">Hores</th>
                            <th class="pb-1 pr-3 text-right font-medium">Desnivell (m)</th>
                            <th class="pb-1 pr-3 text-right font-medium">Despeses</th>
                            <th class="pb-1 text-right font-medium">€/km</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr class="font-semibold text-gray-900 dark:text-gray-100">
                            <td class="py-1 pr-3">Total</td>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ nombre(total.sortides) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ nombre(total.km) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ nombre(total.hores) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ nombre(total.desnivell) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ total.despeses ? euros(total.despeses) : '—' }}</td>
                            <td class="py-1 text-right tabular-nums">{{ total.despeses && total.km ? (total.despeses / total.km).toFixed(3) : '—' }}</td>
                        </tr>
                        <tr v-for="a in fitxa.per_any" :key="a.any">
                            <td class="py-1 pr-3 font-medium text-gray-900 dark:text-gray-100">{{ a.any }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(a.sortides) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">
                                {{ nombre(a.km) }}
                                <span v-if="a.km_indoor" class="block text-xs font-normal text-gray-400 dark:text-gray-500">
                                    {{ nombre(a.km_indoor) }} virtuals
                                </span>
                            </td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(a.hores, 1) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(a.desnivell) }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ a.despeses ? euros(a.despeses) : '—' }}</td>
                            <td class="py-1 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ a.cost_km?.toFixed(3) ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    Els quilòmetres, les hores (en moviment) i el desnivell són els del resum de Garmin Connect. Els
                    dels rodets són virtuals i s'hi compten, però es diuen a part.
                </p>
            </div>

            <!-- Sortides -->
            <div v-if="pestanya === 'sortides'">
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <label class="text-xs text-gray-500 dark:text-gray-400">
                        Any
                        <select v-model="anySortides"
                            class="mt-1 block rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="a in anysAmbSortides" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </label>
                    <label v-if="fitxa.tipus.length > 1" class="text-xs text-gray-500 dark:text-gray-400">
                        Tipus
                        <select v-model="tipusSortides"
                            class="mt-1 block rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="">Tots</option>
                            <option v-for="t in fitxa.tipus" :key="t.tipus" :value="t.tipus">{{ etiquetaTipus(t.tipus) }} ({{ t.sortides }})</option>
                        </select>
                    </label>
                    <span class="pb-1 text-xs text-gray-400 dark:text-gray-500">
                        {{ carregantSortides ? 'Carregant…' : `${sortides.length} sortides` }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                <th class="pb-1 pr-3 font-medium">Data</th>
                                <th class="pb-1 pr-3 font-medium">Sortida</th>
                                <th class="pb-1 pr-3 text-right font-medium">Km</th>
                                <th class="pb-1 pr-3 text-right font-medium">Temps</th>
                                <th class="pb-1 pr-3 text-right font-medium">Desnivell</th>
                                <th class="pb-1 pr-3 text-right font-medium">km/h</th>
                                <th class="pb-1 text-right font-medium">FC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="s in sortides" :key="s.id">
                                <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-700 dark:text-gray-300">
                                    {{ s.data }} <span class="text-xs text-gray-400 dark:text-gray-500">{{ s.hora }}</span>
                                </td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-gray-100">
                                    {{ s.nom ?? '—' }}
                                    <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ etiquetaTipus(s.tipus) }}</span>
                                    <span v-if="!s.amb_recorregut" class="ml-1 text-xs text-gray-400 dark:text-gray-500" title="Garmin no en va donar el fitxer original: hi ha el resum però no els punts">sense recorregut</span>
                                </td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ s.km !== null ? nombre(s.km, 1) : '—' }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ durada(s.temps_s) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                    {{ s.desnivell !== null ? `${nombre(s.desnivell)} m` : '—' }}
                                    <span v-if="s.desnivell_corregit" class="text-xs text-gray-400" title="Corregit per Garmin Connect: el dispositiu va mesurar una altra cosa">*</span>
                                </td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ s.kmh !== null ? nombre(s.kmh, 1) : '—' }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ s.fc ?? '—' }}</td>
                            </tr>
                            <tr v-if="!carregantSortides && !sortides.length">
                                <td colspan="7" class="py-4 text-center text-xs text-gray-400 dark:text-gray-500">Cap sortida.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Components -->
            <div v-if="pestanya === 'components'" class="overflow-x-auto">
                <p v-if="!fitxa.components.length" class="py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                    Cap component no s'ha marcat en cap sortida d'aquesta bici.
                </p>
                <template v-else>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                <th class="pb-1 pr-3 font-medium">Component</th>
                                <th class="pb-1 pr-3 font-medium">Des de</th>
                                <th class="pb-1 pr-3 font-medium">Fins a</th>
                                <th class="pb-1 pr-3 text-right font-medium">Sortides</th>
                                <th class="pb-1 pr-3 text-right font-medium">Km</th>
                                <th class="pb-1 text-right font-medium" title="Sortides de la bici dins de les dates del component on no es va marcar a Garmin">Sense marcar <span class="text-gray-400">ⓘ</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="c in fitxa.components" :key="c.id" :class="{ 'opacity-60': c.estat === 'retirat' }">
                                <td class="py-1 pr-3 text-gray-900 dark:text-gray-100">
                                    {{ c.nom }}
                                    <span v-if="c.estat === 'retirat'" class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-400">retirat</span>
                                </td>
                                <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-600 dark:text-gray-400">{{ c.data_inici ?? '—' }}</td>
                                <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-600 dark:text-gray-400">{{ c.data_fi ?? '—' }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(c.sortides) }}</td>
                                <td class="py-1 pr-3 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(c.km) }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    <template v-if="noMarcades(c)">
                                        {{ noMarcades(c) }} · {{ nombre(Math.max(0, c.km_en_dates - c.km)) }} km
                                    </template>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                        Els km són els de les sortides on el component es va marcar a Garmin. <strong>Sense marcar</strong> són
                        les sortides de la bici (sense comptar els rodets) entre les dates del component on no s'hi va
                        marcar: si n'hi ha, els km reals poden ser més.
                    </p>
                </template>
            </div>

            <!-- Gràfiques -->
            <div v-if="pestanya === 'grafiques'" class="space-y-8">
                <div>
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Quilòmetres per any <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">km</span>
                    </h4>
                    <div class="h-64"><Bar :data="dadesPerAny" :options="opcions" /></div>
                </div>

                <div>
                    <div class="flex items-end justify-between gap-3">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Quilòmetres per mes <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">km</span>
                        </h4>
                        <select v-model="anyGrafica"
                            class="rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="a in anysAmbSortides" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>
                    <div class="h-64"><Bar :data="dadesPerMes" :options="opcions" /></div>
                </div>
            </div>
        </template>
    </div>
</template>
