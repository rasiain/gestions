<script setup lang="ts">
/**
 * La fitxa d'una bici: el que diuen les seves sortides. Les dades es demanen en obrir-la
 * —són milers de sortides i no viatgen amb la pàgina— i les sortides, any per any.
 *
 * Sense `vehicleId`, és la de **totes les bicis juntes**: el mateix resum sumat, les sortides amb el nom
 * de la bici, i a les gràfiques els km de cada any apilats per bici. Sense components, que són de cada bici.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import SelectorAltitud from '@/Components/Bicis/SelectorAltitud.vue';
import GraficaBarres from '@/Components/Bicis/GraficaBarres.vue';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';

ChartJS.register(...registerables);

interface Any {
    any: number;
    sortides: number;
    km: number;
    /** Dels rodets: quilòmetres virtuals, inclosos a `km`. */
    km_indoor: number;
    /** En moviment. */
    hores: number;
    /** Dels rodets, incloses a `hores`. */
    hores_indoor: number;
    /** Amb l'altitud triada (el selector de dalt). */
    desnivell: number;
    /** Dels rodets, inclòs a `desnivell`. */
    desnivell_indoor: number;
    /** kcal, les que diu Garmin. */
    calories: number;
    /** Les dels rodets, incloses a `calories`: l'esforç sí que és real. */
    calories_indoor: number;
    despeses: number;
    cost_km: number | null;
}

/** El resum d'un període (mes, setmana): cada mesura de l'exterior i, a part, la dels rodets. */
interface Periode {
    sortides: number;
    km: number;
    km_indoor: number;
    calories: number;
    calories_indoor: number;
    /** En moviment. */
    hores: number;
    hores_indoor: number;
    /** Amb l'altitud triada. */
    desnivell: number;
    desnivell_indoor: number;
}

interface Mes extends Periode {
    mes: string;
}

interface Setmana extends Periode {
    /** El dilluns que la comença. */
    setmana: string;
}

interface Component {
    id: number;
    nom: string;
    estat: string;
    /** Muntat ara a aquesta bici («muntat a» de la peça). */
    muntat: boolean;
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
    /** Amb quina altitud s'ha comptat: el de Garmin Connect si és `dispositiu`. */
    desnivell_metode: string;
    kmh: number | null;
    fc: number | null;
    calories: number | null;
    amb_recorregut: boolean;
    desnivell_corregit: boolean;
    /** Només a la fitxa de totes les bicis. */
    bici?: string | null;
}

interface Fitxa {
    per_any: Any[];
    per_mes: Mes[];
    per_setmana: Setmana[];
    tipus: { tipus: string; sortides: number }[];
    components: Component[];
    /** Només a la fitxa de totes les bicis: les bicis i els km de cadascuna per any. */
    bicis?: { id: number; nom: string }[];
    per_bici?: { any: number; vehicle_id: number; km: number; calories: number; hores: number; desnivell: number }[];
}

type Pestanya = 'anys' | 'sortides' | 'components' | 'grafiques';

const props = defineProps<{
    /** null: totes les bicis juntes. */
    vehicleId: number | null;
    /** Amb què s'obre: en tornar d'una sortida, la llista de sortides del seu any. */
    pestanyaInicial?: Pestanya;
    anyInicial?: number;
}>();

const pestanya = ref<Pestanya>(props.pestanyaInicial ?? 'anys');
const totes = computed(() => props.vehicleId === null);
const urlFitxa = () => (props.vehicleId === null ? route('bicis.totes.fitxa') : route('bicis.fitxa', props.vehicleId));
const urlSortides = () => (props.vehicleId === null ? route('bicis.totes.sortides') : route('bicis.sortides', props.vehicleId));
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

/** Amb quina altitud es compta el desnivell (null = la de cada sortida); el calcula el servidor. */
const altitud = ref<string | null>(null);
const ambAltitud = (params: URLSearchParams = new URLSearchParams()) => {
    if (altitud.value) params.set('altitud', altitud.value);
    const q = params.toString();
    return q ? `?${q}` : '';
};

watch(altitud, async () => {
    fitxa.value = await demana<Fitxa>(urlFitxa() + ambAltitud());
    if (pestanya.value === 'sortides') carregaSortides();
});

onMounted(async () => {
    fitxa.value = await demana<Fitxa>(urlFitxa() + ambAltitud());
    const anys = fitxa.value?.per_any.filter((a) => a.sortides > 0).map((a) => a.any) ?? [];
    anySortides.value = props.anyInicial && anys.includes(props.anyInicial) ? props.anyInicial : anys[0] ?? null;
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
    return { sortides: suma('sortides'), km: suma('km'), km_indoor: suma('km_indoor'), hores: suma('hores'), desnivell: suma('desnivell'), calories: suma('calories'), despeses: suma('despeses') };
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
    sortides.value = (await demana<Sortida[]>(urlSortides() + ambAltitud(params))) ?? [];
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

/**
 * Què dibuixen les gràfiques: els quilòmetres, les calories (les de Garmin Connect, en kcal), el temps en moviment
 * o el desnivell positiu, amb l'altitud del selector de dalt.
 */
const mesura = ref<'km' | 'calories' | 'hores' | 'desnivell'>('km');
const MESURES = {
    km: { titol: 'Quilòmetres', unitat: 'km' },
    calories: { titol: 'Calories', unitat: 'kcal' },
    hores: { titol: 'Temps', unitat: 'h' },
    desnivell: { titol: 'Desnivell', unitat: 'm' },
} as const;
const INDOOR = { km: 'km_indoor', calories: 'calories_indoor', hores: 'hores_indoor', desnivell: 'desnivell_indoor' } as const;
/** Arrodonit per a la gràfica: les hores amb un decimal, que un mes en té poques. */
const arrodoneix = (v: number) => (mesura.value === 'hores' ? Math.round(v * 10) / 10 : Math.round(v));
/** El valor com es llegeix: les hores com a durada (12:30 h). */
const formatMesura = (v: number) => (mesura.value === 'hores' ? `${durada(Math.round(v * 3600))} h` : `${nombre(v)} ${MESURES[mesura.value].unitat}`);

function series(etiquetes: string[], exterior: number[], indoor: number[]): ChartData<'bar'> {
    const sets: ChartData<'bar'>['datasets'] = [{ label: 'Exterior', data: exterior, ...barra(SERIE_BLAU) }];
    // Els km dels rodets són virtuals; les calories, no
    if (teIndoor.value) sets.push({ label: mesura.value === 'km' ? 'Rodets (virtuals)' : 'Rodets', data: indoor, ...barra(SERIE_AMBRE) });
    return { labels: etiquetes, datasets: sets };
}

/**
 * Totes les bicis: un color per bici, de la paleta categòrica validada (clar i fosc, sobre el blanc i el
 * gris-800 del projecte), en ordre fix per id de bici: el color segueix la bici, no la seva posició.
 * Tres colors clars no arriben a 3:1 sobre el blanc: per això la taula de sota repeteix les xifres.
 */
const PALETA_CLARA = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const PALETA_FOSCA = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
/** També dona el color de cada any a la gràfica per mes. */
const colorBici = (index: number) => (fosc.value ? PALETA_FOSCA : PALETA_CLARA)[index % 8];

/** Km (calories, hores, desnivell) de cada bici per any, de l'any més antic al més recent; només les bicis que en tenen. */
const perBici = computed(() => {
    const files = fitxa.value?.per_bici ?? [];
    const anys = [...new Set(files.map((f) => f.any))].sort((a, b) => a - b);
    const bicis = (fitxa.value?.bicis ?? []).map((b, i) => ({ ...b, index: i })).filter((b) => files.some((f) => f.vehicle_id === b.id && f.km > 0));
    const valor = (any: number, bici: number) => files.find((f) => f.any === any && f.vehicle_id === bici)?.[mesura.value] ?? 0;
    return { anys, bicis, valor };
});

const dadesPerBici = computed<ChartData<'bar'>>(() => ({
    labels: perBici.value.anys.map(String),
    datasets: perBici.value.bicis.map((b) => ({
        label: b.nom,
        data: perBici.value.anys.map((a) => arrodoneix(perBici.value.valor(a, b.id))),
        ...barra(colorBici(b.index)),
    })),
}));

const opcionsPerBici = computed<ChartOptions<'bar'>>(() => ({
    ...opcions.value,
    plugins: { ...opcions.value.plugins, legend: { display: true, labels: { color: tinta.value, boxWidth: 12 } } },
}));

const cronologic = computed(() => [...(fitxa.value?.per_any ?? [])].filter((a) => a.sortides > 0).reverse());

const dadesPerAny = computed(() =>
    series(
        cronologic.value.map((a) => String(a.any)),
        // A l'any, el total ja porta els rodets: l'exterior és la resta
        cronologic.value.map((a) => arrodoneix(a[mesura.value] - a[INDOOR[mesura.value]])),
        cronologic.value.map((a) => arrodoneix(a[INDOOR[mesura.value]])),
    ),
);

/** La mesura triada d'un període que porta els rodets a part (mes, setmana); zero si no hi ha cap sortida. */
const parts = (p: Periode | undefined) => ({ exterior: p?.[mesura.value] ?? 0, indoor: p?.[INDOOR[mesura.value]] ?? 0 });
const separa = (periodes: (Periode | undefined)[]) => {
    const p = periodes.map(parts);
    return { exterior: p.map((x) => arrodoneix(x.exterior)), indoor: p.map((x) => arrodoneix(x.indoor)) };
};

const NOMS_MES = ['gen', 'feb', 'març', 'abr', 'maig', 'juny', 'jul', 'ag', 'set', 'oct', 'nov', 'des'];

// ---- Per setmana: de cinc en cinc, la darrera la d'avui ----
const SETMANES = 5;
/** Quants blocs de cinc setmanes enrere: 0, les cinc darreres. */
const blocSetmanes = ref(0);
/** La data local com la diu el servidor (sense passar per UTC, que la canviaria de dia). */
const isoDia = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const diaMes = (d: Date) => `${d.getDate()} ${NOMS_MES[d.getMonth()]}`;
const mesDies = (d: Date, dies: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + dies);

/** Els dilluns de les setmanes que es veuen, de la més antiga a la més recent. */
const setmanes = computed(() => {
    const avui = new Date();
    const dilluns = mesDies(avui, -((avui.getDay() + 6) % 7));
    return Array.from({ length: SETMANES }, (_, i) => mesDies(dilluns, -7 * (blocSetmanes.value * SETMANES + SETMANES - 1 - i)));
});
const perSetmana = computed(() => new Map((fitxa.value?.per_setmana ?? []).map((s) => [s.setmana, s])));
const hiHaSetmanesAbans = computed(() => {
    const primera = fitxa.value?.per_setmana[0]?.setmana;
    return !!primera && primera < isoDia(setmanes.value[0]);
});
const rangSetmanes = computed(() => {
    const fi = mesDies(setmanes.value[SETMANES - 1], 6);
    return `${diaMes(setmanes.value[0])} – ${diaMes(fi)} ${fi.getFullYear()}`;
});

const dadesPerSetmana = computed(() => {
    const { exterior, indoor } = separa(setmanes.value.map((d) => perSetmana.value.get(isoDia(d))));
    return series(setmanes.value.map(diaMes), exterior, indoor);
});

const opcionsPerSetmana = computed<ChartOptions<'bar'>>(() => ({
    ...opcions.value,
    plugins: {
        ...opcions.value.plugins,
        tooltip: { callbacks: { ...opcions.value.plugins?.tooltip?.callbacks, title: (items) => `Setmana del ${items[0]?.label ?? ''}` } },
    },
}));

// ---- Per mes: l'any triat i uns quants d'anteriors ----
const anyGrafica = ref<number | null>(null);
watch(anysAmbSortides, (anys) => { if (anyGrafica.value === null) anyGrafica.value = anys[0] ?? null; }, { immediate: true });
const anysAnteriors = ref(5);
/** Com a molt set d'anteriors: la paleta categòrica té vuit colors. */
const OPCIONS_ANTERIORS = [0, 1, 2, 3, 4, 5, 6, 7];

/** Els mesos d'un any, amb la mesura triada; `null` si l'any no té cap sortida. */
function mesosDe(any: number) {
    const mesos = new Map((fitxa.value?.per_mes ?? []).filter((m) => m.mes.startsWith(`${any}-`)).map((m) => [Number(m.mes.slice(5)), m]));
    return mesos.size ? separa(NOMS_MES.map((_, i) => mesos.get(i + 1))) : null;
}

/**
 * Una barra de cada any per mes, del més antic al més recent: l'any triat amb el blau de sempre i els
 * anteriors amb els colors següents de la paleta categòrica validada (la de «per bici»). Els rodets van
 * apilats damunt de l'exterior, amb el mateix color en clar.
 */
const dadesPerMes = computed<ChartData<'bar'>>(() => {
    const triat = anyGrafica.value ?? 0;
    const datasets: ChartData<'bar'>['datasets'] = [];
    for (let enrere = anysAnteriors.value; enrere >= 0; enrere--) {
        const any = triat - enrere;
        const mesos = mesosDe(any);
        // Un any sense cap sortida no fa barra; el triat, sí, encara que estigui buit
        if (!mesos && enrere) continue;
        const color = colorBici(enrere);
        const zeros = NOMS_MES.map(() => 0);
        datasets.push({ label: String(any), data: mesos?.exterior ?? zeros, stack: String(any), ...barra(color) });
        if (teIndoor.value) {
            datasets.push({ label: `${any} rodets`, data: mesos?.indoor ?? zeros, stack: String(any), ...barra(`${color}80`) });
        }
    }
    return { labels: NOMS_MES, datasets };
});

const opcionsPerMes = computed<ChartOptions<'bar'>>(() => ({
    ...opcions.value,
    plugins: {
        ...opcions.value.plugins,
        // La llegenda diu els anys; la part clara de cada barra (rodets) l'explica el peu
        legend: { display: true, labels: { color: tinta.value, boxWidth: 12, filter: (l) => !l.text.endsWith('rodets') } },
    },
}));

const opcions = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        // Amb una sola sèrie el títol ja la diu: la llegenda només quan n'hi ha dues
        legend: { display: teIndoor.value, labels: { color: tinta.value, boxWidth: 12 } },
        tooltip: {
            callbacks: {
                label: (c) => `${c.dataset.label}: ${formatMesura(c.parsed.y as number)}`,
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
        <SelectorAltitud v-model="altitud" class="mb-2" />
        <div class="mb-3 flex gap-1 border-b border-gray-200 dark:border-gray-600">
            <button v-for="[clau, etiqueta] in [['anys', 'Per any'], ['sortides', 'Sortides'], ['components', 'Components'], ['grafiques', 'Gràfiques']].filter(([c]) => !(totes && c === 'components'))"
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
                            <th class="pb-1 pr-3 text-right font-medium">Calories (kcal)</th>
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
                            <td class="py-1 pr-3 text-right tabular-nums">{{ nombre(total.calories) }}</td>
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
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ a.calories ? nombre(a.calories) : '—' }}</td>
                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ a.despeses ? euros(a.despeses) : '—' }}</td>
                            <td class="py-1 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ a.cost_km?.toFixed(3) ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    Els quilòmetres, les hores (en moviment) i les calories són els del resum de Garmin Connect; el desnivell,
                    {{ altitud === null ? "amb l'altitud de cada sortida (la desada o la de per defecte)" : altitud === 'dispositiu' ? 'també' : `amb l'altitud ${altitud === 'combinat' ? 'combinada' : 'del terreny'} on n'hi ha (si no, la de Garmin Connect)` }}.
                    Els dels rodets són virtuals i s'hi compten, però es diuen a part.
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
                                <th v-if="totes" class="pb-1 pr-3 font-medium">Bici</th>
                                <th class="pb-1 pr-3 text-right font-medium">Km</th>
                                <th class="pb-1 pr-3 text-right font-medium">Temps</th>
                                <th class="pb-1 pr-3 text-right font-medium">Desnivell</th>
                                <th class="pb-1 pr-3 text-right font-medium">km/h</th>
                                <th class="pb-1 pr-3 text-right font-medium">FC</th>
                                <th class="pb-1 text-right font-medium">kcal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="s in sortides" :key="s.id">
                                <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-700 dark:text-gray-300">
                                    {{ s.data }} <span class="text-xs text-gray-400 dark:text-gray-500">{{ s.hora }}</span>
                                </td>
                                <td class="py-1 pr-3 text-gray-900 dark:text-gray-100">
                                    <Link :href="route('bicis.sortida', s.id)" class="text-sky-700 hover:text-sky-900 hover:underline dark:text-sky-400">{{ s.nom ?? 'Sortida' }}</Link>
                                    <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ etiquetaTipus(s.tipus) }}</span>
                                    <span v-if="!s.amb_recorregut" class="ml-1 text-xs text-gray-400 dark:text-gray-500" title="Garmin no en va donar el fitxer original: hi ha el resum però no els punts">sense recorregut</span>
                                </td>
                                <td v-if="totes" class="whitespace-nowrap py-1 pr-3 text-gray-600 dark:text-gray-400">{{ s.bici }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ s.km !== null ? nombre(s.km, 1) : '—' }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ durada(s.temps_s) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                    {{ s.desnivell !== null ? `${nombre(s.desnivell)} m` : '—' }}
                                    <span v-if="s.desnivell_corregit" class="text-xs text-gray-400" title="Corregit per Garmin Connect: el dispositiu va mesurar una altra cosa">*</span>
                                    <span v-if="s.desnivell_metode !== 'dispositiu'" class="text-xs text-sky-600 dark:text-sky-400"
                                        :title="s.desnivell_metode === 'combinat' ? 'Amb l\'altitud combinada' : 'Amb l\'altitud del terreny'">{{ s.desnivell_metode === 'combinat' ? 'c' : 't' }}</span>
                                </td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ s.kmh !== null ? nombre(s.kmh, 1) : '—' }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ s.fc ?? '—' }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ s.calories ? nombre(s.calories) : '—' }}</td>
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
                                    <span v-else-if="c.muntat" class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">muntat</span>
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
                        Els components es corregeixen i se n'afegeixen de nous a <Link :href="route('bicis.material.index')" class="text-sky-600 hover:underline dark:text-sky-400">Material</Link>.
                        Els km són els de les sortides on el component es va marcar. <strong>Sense marcar</strong> són
                        les sortides de la bici (sense comptar els rodets) entre les dates del component on no s'hi va
                        marcar: si n'hi ha, els km reals poden ser més.
                    </p>
                </template>
            </div>

            <!-- Gràfiques -->
            <div v-if="pestanya === 'grafiques'" class="space-y-8">
                <div class="inline-flex rounded-md border border-gray-300 text-xs dark:border-gray-600">
                    <button v-for="(m, clau) in MESURES" :key="clau" type="button" @click="mesura = clau"
                        class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                        :class="mesura === clau ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                        {{ m.titol }}
                    </button>
                </div>
                <p v-if="mesura === 'desnivell'" class="-mt-6 text-xs text-gray-500 dark:text-gray-400">
                    El desnivell positiu, amb l'altitud triada al selector de dalt.
                </p>
                <GraficaBarres v-if="totes" :titol="`${MESURES[mesura].titol} per any i bici`"
                    :unitat="mesura === 'km' ? 'km (els dels rodets, virtuals)' : MESURES[mesura].unitat"
                    :data="dadesPerBici" :options="opcionsPerBici" alcada="h-72">
                    <template #peu>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                        <th class="pb-1 pr-3 font-medium">Any</th>
                                        <th v-for="b in perBici.bicis" :key="b.id" class="pb-1 pr-3 text-right font-medium">
                                            <span class="mr-1 inline-block h-2 w-2 rounded-sm" :style="{ backgroundColor: colorBici(b.index) }"></span>{{ b.nom }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                                    <tr v-for="a in [...perBici.anys].reverse()" :key="a">
                                        <td class="py-1 pr-3 font-medium text-gray-900 dark:text-gray-100">{{ a }}</td>
                                        <td v-for="b in perBici.bicis" :key="b.id" class="py-1 pr-3 text-right text-gray-700 dark:text-gray-300">
                                            {{ perBici.valor(a, b.id) ? (mesura === 'hores' ? durada(Math.round(perBici.valor(a, b.id) * 3600)) : nombre(perBici.valor(a, b.id))) : '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </GraficaBarres>

                <div class="grid gap-8 lg:grid-cols-2">
                    <GraficaBarres :titol="`${MESURES[mesura].titol} per any`" :unitat="MESURES[mesura].unitat" :data="dadesPerAny" :options="opcions" />

                    <GraficaBarres :titol="`${MESURES[mesura].titol} per setmana`" :unitat="MESURES[mesura].unitat" :data="dadesPerSetmana" :options="opcionsPerSetmana">
                        <template #controls>
                            <button type="button" class="rounded border border-gray-300 px-2 py-0.5 text-sm hover:bg-gray-50 disabled:opacity-40 disabled:hover:bg-transparent dark:border-gray-600 dark:hover:bg-gray-700" :disabled="!hiHaSetmanesAbans"
                                title="Les cinc setmanes anteriors" @click="blocSetmanes++">‹</button>
                            <span class="tabular-nums">{{ rangSetmanes }}</span>
                            <button type="button" class="rounded border border-gray-300 px-2 py-0.5 text-sm hover:bg-gray-50 disabled:opacity-40 disabled:hover:bg-transparent dark:border-gray-600 dark:hover:bg-gray-700" :disabled="blocSetmanes === 0"
                                title="Les cinc setmanes següents" @click="blocSetmanes--">›</button>
                        </template>
                        <template #peu>
                            De dilluns a diumenge; l'última és la setmana d'avui.<template v-if="teIndoor && mesura === 'km'"> Els km dels rodets són virtuals.</template>
                        </template>
                    </GraficaBarres>
                </div>

                <GraficaBarres :titol="`${MESURES[mesura].titol} per mes`" :unitat="MESURES[mesura].unitat" :data="dadesPerMes" :options="opcionsPerMes">
                    <template #controls>
                        <select v-model="anyGrafica" class="rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="a in anysAmbSortides" :key="a" :value="a">{{ a }}</option>
                        </select>
                        <label class="flex items-center gap-1">
                            i
                            <select v-model="anysAnteriors" class="rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="n in OPCIONS_ANTERIORS" :key="n" :value="n">{{ n }}</option>
                            </select>
                            anys anteriors
                        </label>
                    </template>
                    <template #peu>
                        Cada mes, l'any triat<template v-if="anysAnteriors"> i {{ anysAnteriors === 1 ? "l'anterior" : `els ${anysAnteriors} anteriors` }} que tinguin sortides</template>.<template v-if="teIndoor"> La part clara de cada barra són els rodets{{ mesura === 'km' ? ' (km virtuals)' : '' }}.</template>
                    </template>
                </GraficaBarres>
            </div>
        </template>
    </div>
</template>
