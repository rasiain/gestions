<script setup lang="ts">
/**
 * Una sortida en bici: el mapa del recorregut, els perfils (altitud, velocitat, pulsacions,
 * cadència) i el resum de Garmin al costat del del dispositiu.
 *
 * El mapa i els perfils van lligats per un sol índex de punt: passar per sobre de qualsevol
 * gràfica o del recorregut el marca a tot arreu. Els punts es demanen en obrir la pàgina.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { Chart, ChartData, ChartOptions, Plugin } from 'chart.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

ChartJS.register(...registerables);

interface Resum {
    km: number | null; temps_s: number | null; moviment_s: number | null; total_s: number | null;
    pujada: number | null; baixada: number | null; alt_min: number | null; alt_max: number | null;
    kmh: number | null; kmh_max: number | null; fc: number | null; fc_max: number | null;
    cadencia: number | null; calories: number | null; temp_min: number | null; temp_max: number | null;
}

interface Punts {
    t: number[]; lat: (number | null)[]; lon: (number | null)[]; alt: (number | null)[];
    km: (number | null)[]; kmh: (number | null)[]; fc: (number | null)[]; cad: (number | null)[]; temp: (number | null)[];
}

const props = defineProps<{
    sortida: {
        id: number; nom: string | null; tipus: string | null; data: string; hora: string | null; lloc: string | null;
        punts: number; desnivell_corregit: boolean; material_editat: boolean; fitxer: string | null; origen: string; id_extern: string;
        vehicle: { id: number; nom: string; modalitat: string | null };
        material: { id: number; nom: string; tipus: string }[];
    };
    resums: { garmin: Resum; dispositiu: Resum | null };
    anterior: number | null;
    seguent: number | null;
    bicis: { id: number; nom: string; actiu: boolean }[];
    peces: { id: number; nom: string; tipus: string; estat: string; data_inici: string | null; data_fi: string | null; muntat_a_vehicle_id: number | null }[];
}>();

const ETIQUETA_TIPUS: Record<string, string> = {
    mountain_biking: 'BTT', cycling: 'Ciclisme', road_biking: 'Carretera', gravel_cycling: 'Gravel',
    indoor_cycling: 'Rodets', track_cycling: 'Pista', downhill_biking: 'Descens',
};

// ---- Formats ----
const nombre = (v: number | null | undefined, decimals = 0) =>
    v === null || v === undefined ? '—' : v.toLocaleString('ca-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
function durada(s: number | null | undefined): string {
    if (s === null || s === undefined) return '—';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sg = Math.round(s % 60);
    return `${h}:${String(m).padStart(2, '0')}:${String(sg).padStart(2, '0')}`;
}

// ---- Punts ----
const punts = shallowRef<Punts | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    if (!props.sortida.punts) return;
    const res = await fetch(route('bicis.sortida.punts', props.sortida.id), { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        error.value = `No s'han pogut carregar els punts (error ${res.status}).`;
        return;
    }
    punts.value = await res.json();
    await nextTick();
    dibuixaMapa();
});

/** L'eix de les gràfiques: la distància si n'hi ha (a l'exterior), si no el temps (als rodets). */
const perDistancia = computed(() => {
    const km = punts.value?.km ?? [];
    return km.length > 0 && (km[km.length - 1] ?? 0) > 0.1;
});
const xs = computed<number[]>(() => {
    const p = punts.value;
    if (!p) return [];
    // Un punt sense distància hereta l'anterior: l'eix ha de ser creixent
    let darrer = 0;
    return perDistancia.value ? p.km.map((v) => (darrer = v ?? darrer)) : p.t.map((t) => t / 60);
});

/** L'índex del punt més proper a un valor de l'eix (cerca binària: l'eix creix). */
function indexDeX(x: number): number {
    const v = xs.value;
    let lo = 0, hi = v.length - 1;
    while (lo < hi) {
        const mig = (lo + hi) >> 1;
        if (v[mig] < x) lo = mig + 1; else hi = mig;
    }
    return lo > 0 && Math.abs(v[lo - 1] - x) < Math.abs(v[lo] - x) ? lo - 1 : lo;
}

/** El punt assenyalat, comú al mapa i a totes les gràfiques. */
const actiu = ref<number | null>(null);

const lectura = computed(() => {
    const p = punts.value, i = actiu.value;
    if (!p || i === null) return null;
    return { t: p.t[i], km: p.km[i], alt: p.alt[i], kmh: p.kmh[i], fc: p.fc[i], cad: p.cad[i], temp: p.temp[i] };
});

// ---- Mapa ----
const SERIE_BLAU = '#0284c7';
const SERIE_AMBRE = '#d97706';

const contenidorMapa = ref<HTMLElement | null>(null);
let mapa: L.Map | null = null;
let marcador: L.CircleMarker | null = null;
const teGps = computed(() => (punts.value?.lat ?? []).some((v) => v !== null));

function dibuixaMapa() {
    const p = punts.value;
    if (!p || !teGps.value || !contenidorMapa.value) return;

    const coords: L.LatLngExpression[] = [];
    p.lat.forEach((lat, i) => { if (lat !== null && p.lon[i] !== null) coords.push([lat, p.lon[i] as number]); });

    mapa = L.map(contenidorMapa.value, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);

    const traca = L.polyline(coords, { color: SERIE_BLAU, weight: 3, opacity: 0.9 }).addTo(mapa);
    mapa.fitBounds(traca.getBounds(), { padding: [16, 16] });

    const extrem = (ll: L.LatLngExpression, titol: string) =>
        L.circleMarker(ll, { radius: 6, color: '#ffffff', weight: 2, fillColor: SERIE_BLAU, fillOpacity: 1 }).bindTooltip(titol).addTo(mapa!);
    extrem(coords[0], 'Inici');
    extrem(coords[coords.length - 1], 'Final');

    // Amagat fins que s'assenyali un punt
    marcador = L.circleMarker(coords[0], { radius: 7, color: '#ffffff', weight: 2, fillColor: SERIE_AMBRE, opacity: 0, fillOpacity: 0 }).addTo(mapa);

    // Passar pel mapa assenyala el punt del recorregut més proper, si és a prop del cursor
    mapa.on('mousemove', (e: L.LeafletMouseEvent) => {
        let millor = -1, dist = Infinity;
        for (let i = 0; i < p.lat.length; i++) {
            const lat = p.lat[i], lon = p.lon[i];
            if (lat === null || lon === null) continue;
            const d = (lat - e.latlng.lat) ** 2 + (lon - e.latlng.lng) ** 2;
            if (d < dist) { dist = d; millor = i; }
        }
        if (millor < 0) return;
        const pixels = mapa!.latLngToContainerPoint([p.lat[millor]!, p.lon[millor]!]).distanceTo(e.containerPoint);
        actiu.value = pixels < 24 ? millor : null;
    });
    mapa.on('mouseout', () => (actiu.value = null));
}

watch(actiu, (i) => {
    const p = punts.value;
    if (marcador && p) {
        const visible = i !== null && p.lat[i] !== null;
        if (visible) marcador.setLatLng([p.lat[i!]!, p.lon[i!]!]);
        marcador.setStyle({ opacity: visible ? 1 : 0, fillOpacity: visible ? 1 : 0 });
    }
    // Les gràfiques redibuixen la línia vertical
    for (const g of grafiques.value) (g as unknown as { chart?: Chart })?.chart?.draw();
});

onUnmounted(() => mapa?.remove());

// ---- Gràfiques ----
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

const grafiques = ref<unknown[]>([]);

/** La línia vertical del punt assenyalat, a totes les gràfiques. */
const creuer: Plugin<'line'> = {
    id: 'creuer',
    afterDatasetsDraw(chart) {
        const i = actiu.value;
        if (i === null || !xs.value.length) return;
        const x = chart.scales.x.getPixelForValue(xs.value[i]);
        const { top, bottom } = chart.chartArea;
        const ctx = chart.ctx;
        ctx.save();
        ctx.strokeStyle = SERIE_AMBRE;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(x, top);
        ctx.lineTo(x, bottom);
        ctx.stroke();
        ctx.restore();
    },
    afterEvent(chart, args) {
        const e = args.event;
        if (e.type === 'mouseout') {
            actiu.value = null;
        } else if (e.type === 'mousemove' && e.x !== null && args.inChartArea) {
            actiu.value = indexDeX(chart.scales.x.getValueForPixel(e.x) as number);
        }
    },
};

interface Perfil { clau: keyof Punts; titol: string; unitat: string; decimals: number; farcit?: boolean }

const PERFILS: Perfil[] = [
    { clau: 'alt', titol: 'Altitud', unitat: 'm', decimals: 0, farcit: true },
    { clau: 'kmh', titol: 'Velocitat', unitat: 'km/h', decimals: 1 },
    { clau: 'fc', titol: 'Pulsacions', unitat: 'ppm', decimals: 0 },
    { clau: 'cad', titol: 'Cadència', unitat: 'rpm', decimals: 0 },
];

/** Només els perfils que tenen dades en aquesta sortida. */
const perfils = computed(() => PERFILS.filter((f) => (punts.value?.[f.clau] as (number | null)[] | undefined)?.some((v) => v !== null && v !== 0)));

function dades(f: Perfil): ChartData<'line'> {
    const y = (punts.value?.[f.clau] ?? []) as (number | null)[];
    return {
        datasets: [{
            label: f.titol,
            data: xs.value.map((x, i) => ({ x, y: y[i] })) as { x: number; y: number }[],
            borderColor: SERIE_BLAU,
            backgroundColor: f.farcit ? 'rgba(2,132,199,0.15)' : SERIE_BLAU,
            fill: f.farcit ? 'start' : false,
            borderWidth: 2,
            pointRadius: 0,
            spanGaps: true,
        }],
    };
}

const opcions = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    parsing: false,
    normalized: true,
    events: ['mousemove', 'mouseout'],
    plugins: {
        legend: { display: false },
        tooltip: { enabled: false },
        // Milers de punts: es dibuixen els que es veuen, sense perdre els pics
        decimation: { enabled: true, algorithm: 'lttb', samples: 600 },
    },
    scales: {
        x: {
            type: 'linear',
            min: xs.value[0],
            max: xs.value[xs.value.length - 1],
            grid: { color: quadricula.value },
            ticks: { color: tinta.value, callback: (v) => `${nombre(Number(v), 0)} ${perDistancia.value ? 'km' : 'min'}` },
        },
        y: { grid: { color: quadricula.value }, border: { display: false }, ticks: { color: tinta.value } },
    },
}));

// ---- Material ----
const editantMaterial = ref(false);
const formMaterial = useForm({
    vehicle_id: props.sortida.vehicle.id,
    material_ids: [] as number[],
});

function editaMaterial() {
    formMaterial.vehicle_id = props.sortida.vehicle.id;
    // La bici no és a la llista de peces: va pel selector
    formMaterial.material_ids = props.sortida.material.filter((m) => m.tipus !== 'bici').map((m) => m.id);
    formMaterial.clearErrors();
    editantMaterial.value = true;
}

function desaMaterial() {
    formMaterial.put(route('bicis.sortida.material', props.sortida.id), {
        preserveScroll: true,
        onSuccess: () => (editantMaterial.value = false),
    });
}

/** Una peça que no era en ús el dia de la sortida: es pot marcar igualment, però es diu. */
const foraDeDates = (p: { data_inici: string | null; data_fi: string | null }) =>
    (p.data_inici !== null && props.sortida.data < p.data_inici) || (p.data_fi !== null && props.sortida.data > p.data_fi);

const ETIQUETA_PECA: Record<string, string> = { component: 'Components', sabates: 'Sabates', altres: 'Altres' };

/** Muntada a una altra bici que la triada: es pot marcar, però va al final. */
const daltraBici = (p: { muntat_a_vehicle_id: number | null }) =>
    p.muntat_a_vehicle_id !== null && p.muntat_a_vehicle_id !== formMaterial.vehicle_id;

/** Les peces per tipus: primer les que eren en ús el dia de la sortida i no són d'una altra bici. */
const pecesPerTipus = computed(() => {
    const grups = new Map<string, typeof props.peces>();
    const pes = (p: (typeof props.peces)[number]) => Number(foraDeDates(p)) * 2 + Number(daltraBici(p));
    for (const p of [...props.peces].sort((a, b) => pes(a) - pes(b) || a.nom.localeCompare(b.nom))) {
        grups.set(p.tipus, [...(grups.get(p.tipus) ?? []), p]);
    }
    return [...grups.entries()];
});

// ---- Resum ----
const g = props.resums.garmin;
const xifres = computed(() => [
    { etiqueta: 'Distància', valor: `${nombre(g.km, 1)} km` },
    { etiqueta: 'En moviment', valor: durada(g.moviment_s ?? g.temps_s) },
    { etiqueta: 'Desnivell +', valor: `${nombre(g.pujada)} m${props.sortida.desnivell_corregit ? ' *' : ''}` },
    { etiqueta: 'Velocitat mitjana', valor: `${nombre(g.kmh, 1)} km/h` },
    { etiqueta: 'Pulsacions mitjanes', valor: g.fc ? `${g.fc} ppm` : '—' },
    { etiqueta: 'Calories', valor: g.calories ? `${nombre(g.calories)} kcal` : '—' },
]);

/** Garmin i dispositiu, fila a fila; es marca on difereixen més d'un 5 %. */
const comparacio = computed(() => {
    const d = props.resums.dispositiu;
    const files: [string, keyof Resum, (v: number | null) => string][] = [
        ['Distància', 'km', (v) => `${nombre(v, 2)} km`],
        ['Temps (cronòmetre)', 'temps_s', durada],
        ['Temps en moviment', 'moviment_s', durada],
        ['Temps total', 'total_s', durada],
        ['Desnivell +', 'pujada', (v) => `${nombre(v)} m`],
        ['Desnivell −', 'baixada', (v) => `${nombre(v)} m`],
        ['Altitud mínima', 'alt_min', (v) => `${nombre(v)} m`],
        ['Altitud màxima', 'alt_max', (v) => `${nombre(v)} m`],
        ['Velocitat mitjana', 'kmh', (v) => `${nombre(v, 1)} km/h`],
        ['Velocitat màxima', 'kmh_max', (v) => `${nombre(v, 1)} km/h`],
        ['Pulsacions mitjanes', 'fc', (v) => (v ? `${v} ppm` : '—')],
        ['Pulsacions màximes', 'fc_max', (v) => (v ? `${v} ppm` : '—')],
        ['Cadència mitjana', 'cadencia', (v) => (v ? `${v} rpm` : '—')],
        ['Calories', 'calories', (v) => (v ? `${nombre(v)} kcal` : '—')],
        ['Temperatura mínima', 'temp_min', (v) => (v !== null ? `${v} °C` : '—')],
        ['Temperatura màxima', 'temp_max', (v) => (v !== null ? `${v} °C` : '—')],
    ];
    return files
        .map(([etiqueta, clau, format]) => {
            const a = g[clau], b = d?.[clau] ?? null;
            const difereix = a !== null && b !== null && a !== 0 && Math.abs((b - a) / a) > 0.05;
            return { etiqueta, garmin: format(a), dispositiu: d ? format(b) : '—', difereix, buida: a === null && b === null };
        })
        .filter((f) => !f.buida);
});
</script>

<template>
    <Head :title="sortida.nom ?? 'Sortida'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <Link :href="route('bicis.index')" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                    <h2 class="truncate text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        {{ sortida.nom ?? 'Sortida' }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ sortida.data }} {{ sortida.hora }}
                        · {{ sortida.vehicle.nom }}
                        · {{ ETIQUETA_TIPUS[sortida.tipus ?? ''] ?? sortida.tipus }}
                        <template v-if="sortida.lloc"> · {{ sortida.lloc }}</template>
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link v-if="anterior" :href="route('bicis.sortida', anterior)"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        ← Anterior
                    </Link>
                    <Link v-if="seguent" :href="route('bicis.sortida', seguent)"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        Següent →
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Xifres principals -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="x in xifres" :key="x.etiqueta" class="rounded-lg bg-white px-4 py-3 shadow-sm dark:bg-gray-800">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ x.etiqueta }}</div>
                        <div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ x.valor }}</div>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                <p v-else-if="!sortida.punts" class="rounded-lg bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                    Aquesta sortida no té recorregut: Garmin no en va donar el fitxer original, només el resum.
                </p>

                <template v-else>
                    <!-- Mapa -->
                    <div v-if="!punts || teGps" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div ref="contenidorMapa" class="h-[28rem] w-full">
                            <p v-if="!punts" class="py-6 text-center text-xs text-gray-400">Carregant el recorregut…</p>
                        </div>
                    </div>

                    <!-- Perfils -->
                    <div v-if="punts" class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <!-- Lectura del punt assenyalat: fa de tooltip comú a totes les gràfiques -->
                        <div class="mb-3 flex min-h-[1.5rem] flex-wrap gap-x-5 gap-y-1 text-sm tabular-nums text-gray-700 dark:text-gray-300">
                            <template v-if="lectura">
                                <span><span class="text-gray-400">Temps</span> {{ durada(lectura.t) }}</span>
                                <span v-if="lectura.km !== null"><span class="text-gray-400">Km</span> {{ nombre(lectura.km, 2) }}</span>
                                <span v-if="lectura.alt !== null"><span class="text-gray-400">Altitud</span> {{ nombre(lectura.alt) }} m</span>
                                <span v-if="lectura.kmh !== null"><span class="text-gray-400">Velocitat</span> {{ nombre(lectura.kmh, 1) }} km/h</span>
                                <span v-if="lectura.fc !== null"><span class="text-gray-400">FC</span> {{ lectura.fc }} ppm</span>
                                <span v-if="lectura.cad !== null"><span class="text-gray-400">Cadència</span> {{ lectura.cad }} rpm</span>
                                <span v-if="lectura.temp !== null"><span class="text-gray-400">Temp.</span> {{ lectura.temp }} °C</span>
                            </template>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">
                                Passa per sobre d'una gràfica o del recorregut per veure-hi el punt.
                            </span>
                        </div>

                        <div class="space-y-4">
                            <div v-for="f in perfils" :key="f.clau">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ f.titol }} <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">{{ f.unitat }}</span>
                                </h4>
                                <div :class="f.clau === 'alt' ? 'h-44' : 'h-32'">
                                    <Line ref="grafiques" :data="dades(f)" :options="opcions" :plugins="[creuer]" />
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Garmin vs dispositiu -->
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800 lg:col-span-2">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Resum</h3>
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                    <th class="pb-1 pr-3 font-medium"></th>
                                    <th class="pb-1 pr-3 text-right font-medium">Garmin Connect</th>
                                    <th class="pb-1 text-right font-medium">Dispositiu</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="f in comparacio" :key="f.etiqueta">
                                    <td class="py-1 pr-3 text-gray-600 dark:text-gray-400">{{ f.etiqueta }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ f.garmin }}</td>
                                    <td class="py-1 text-right tabular-nums" :class="f.difereix ? 'font-semibold text-amber-700 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300'">
                                        {{ f.dispositiu }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            Les xifres de l'aplicació són les de Garmin Connect. Les del dispositiu són les que va calcular en
                            gravar la sortida; en ambre, on difereixen més d'un 5 %.
                            <template v-if="sortida.desnivell_corregit"> * Garmin Connect va corregir el desnivell d'aquesta sortida.</template>
                        </p>
                    </div>

                    <!-- Material -->
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                Material
                                <span v-if="sortida.material_editat" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-normal text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                                    title="Corregit a mà: una importació nova de Garmin ja no el canviarà">corregit a mà</span>
                            </h3>
                            <button v-if="!editantMaterial" @click="editaMaterial" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">Edita</button>
                        </div>

                        <ul v-if="!editantMaterial" class="space-y-1 text-sm">
                            <li v-for="m in sortida.material" :key="m.id" class="flex items-center justify-between gap-2">
                                <span class="text-gray-900 dark:text-gray-100">{{ m.nom }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ m.tipus }}</span>
                            </li>
                            <li v-if="!sortida.material.length" class="text-xs text-gray-400 dark:text-gray-500">Cap peça.</li>
                        </ul>

                        <div v-else class="space-y-3">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                                Bici
                                <select v-model="formMaterial.vehicle_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                    <option v-for="b in bicis" :key="b.id" :value="b.id">{{ b.nom }}{{ b.actiu ? '' : ' (de baixa)' }}</option>
                                </select>
                            </label>
                            <p v-if="formMaterial.errors.vehicle_id" class="text-sm text-red-600 dark:text-red-400">{{ formMaterial.errors.vehicle_id }}</p>

                            <div v-for="[tipus, llista] in pecesPerTipus" :key="tipus">
                                <div class="mb-1 text-xs font-medium text-gray-600 dark:text-gray-400">{{ ETIQUETA_PECA[tipus] ?? tipus }}</div>
                                <div class="max-h-56 space-y-0.5 overflow-y-auto">
                                    <label v-for="p in llista" :key="p.id" class="flex items-start gap-2 text-sm" :class="foraDeDates(p) ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-gray-100'">
                                        <input type="checkbox" :value="p.id" v-model="formMaterial.material_ids"
                                            class="mt-0.5 rounded border-gray-300 text-sky-600 dark:border-gray-600 dark:bg-gray-700" />
                                        <span>
                                            {{ p.nom }}
                                            <span v-if="foraDeDates(p)" class="block text-xs" :title="`En ús del ${p.data_inici ?? '…'} al ${p.data_fi ?? '…'}`">
                                                no era en ús aquell dia
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <p v-if="formMaterial.errors.material_ids" class="text-sm text-red-600 dark:text-red-400">{{ formMaterial.errors.material_ids }}</p>

                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                Un cop desat, la sortida queda com a corregida a mà: si es torna a importar de Garmin, se n'actualitza
                                el resum però no el material.
                            </p>
                            <div class="flex justify-end gap-2">
                                <button @click="editantMaterial = false" :disabled="formMaterial.processing"
                                    class="rounded-md bg-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200">
                                    Cancel·la
                                </button>
                                <button @click="desaMaterial"
                                    class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700">
                                    Desa
                                </button>
                            </div>
                        </div>

                        <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                            {{ nombre(sortida.punts) }} punts<template v-if="sortida.fitxer"> · original a <code>storage/app/sortides/{{ sortida.fitxer }}</code></template>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
