<script setup lang="ts">
/**
 * Propostes de sortida: voltes fetes amb trams de les sortides pròpies que s'acosten als km i al
 * desnivell demanats (PropostesService). L'inici es tria movent el marcador del mapa —d'entrada, casa—,
 * les propostes es demanen per JSON (triguen uns segons) i cada una es baixa en GPX per seguir-la des de
 * Wikiloc, Garmin Connect o Komoot.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { SERIE_BLAU, TRAMS_PENDENT, pendents, rodaEnClicar, trossosPerPendent } from '@/bicis/recorregut';
import { num } from '@/formularis';
import { baixaGpx } from '@/bicis/gpx';

ChartJS.register(...registerables);

interface Proposta {
    km: number;
    desnivell: number;
    error: number;
    km_antics: number;
    km_repetits: number;
    trams: number;
    /** [lat, lon, alt] */
    punts: [number, number, number | null][];
    sortides: { id: number; km: number; nom: string | null; data: string | null; bici: string | null }[];
}

const props = defineProps<{ casa: { lat: number; lon: number }; quantes: { defecte: number; maxim: number } }>();

// ---- Formulari ----
const km = ref('40');
const desnivell = ref('800');
const tipus = ref<'btt' | 'carretera'>('btt');
const quantes = ref(props.quantes.defecte);
const inici = ref({ ...props.casa });
const aCasa = computed(() => Math.abs(inici.value.lat - props.casa.lat) < 1e-6 && Math.abs(inici.value.lon - props.casa.lon) < 1e-6);

const propostes = shallowRef<Proposta[]>([]);
const demanat = ref<{ km: number; desnivell: number } | null>(null);
const triada = ref(0);
const cercant = ref(false);
const error = ref<string | null>(null);

async function proposa() {
    const k = num(km.value), d = num(desnivell.value);
    const falten = [k === null && 'els quilòmetres', d === null && 'el desnivell'].filter(Boolean);
    if (falten.length) {
        error.value = `Falta ${falten.join(' i ')}.`;
        return;
    }
    error.value = null;
    cercant.value = true;
    const params = new URLSearchParams({
        km: String(k), desnivell: String(d), tipus: tipus.value, lat: String(inici.value.lat), lon: String(inici.value.lon),
        quantes: String(quantes.value),
    });
    try {
        const res = await fetch(route('bicis.propostes.cerca') + '?' + params, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!res.ok) {
            error.value = json.errors ? Object.values(json.errors as Record<string, string[]>).flat().join(' ') : `No s'han pogut buscar les voltes (error ${res.status}).`;
            return;
        }
        propostes.value = json.propostes;
        demanat.value = { km: k!, desnivell: d! };
        triada.value = 0;
        if (!json.propostes.length) error.value = 'No hi ha cap volta per aquí amb aquests trams.';
        else if (json.propostes.length < quantes.value) {
            error.value = `Només n'hi ha ${json.propostes.length} prou diferents entre elles: la xarxa no en dona més per aquí.`;
        }
    } finally {
        cercant.value = false;
    }
}

// ---- Formats ----
const nombre = (v: number, decimals = 0) => v.toLocaleString('ca-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const signe = (v: number, decimals = 0) => (v > 0 ? '+' : v < 0 ? '−' : '±') + nombre(Math.abs(v), decimals);

/** La distància acumulada de cada punt, en km. */
function distancies(punts: Proposta['punts']): number[] {
    const km: number[] = [0];
    for (let i = 1; i < punts.length; i++) {
        const [la0, lo0] = punts[i - 1], [la1, lo1] = punts[i];
        const dy = (la1 - la0) * 111.32, dx = (lo1 - lo0) * 111.32 * Math.cos((la0 * Math.PI) / 180);
        km.push(km[i - 1] + Math.hypot(dx, dy));
    }
    return km;
}

// ---- GPX ----
function baixa(p: Proposta, n: number) {
    baixaGpx(
        `Proposta ${nombre(p.km, 1)} km ${p.desnivell} m (${n + 1})`,
        p.punts.map(([lat, lon, ele]) => ({ lat, lon, ele })),
        `proposta-${Math.round(p.km)}km-${p.desnivell}m-${n + 1}.gpx`,
    );
}

// ---- Mapa ----
const contenidorMapa = ref<HTMLElement | null>(null);
let mapa: L.Map | null = null;
let marcador: L.Marker | null = null;
const capa = L.layerGroup();

onMounted(() => {
    if (!contenidorMapa.value) return;
    mapa = L.map(contenidorMapa.value, { scrollWheelZoom: false }).setView([props.casa.lat, props.casa.lon], 12);
    rodaEnClicar(mapa);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);
    capa.addTo(mapa);
    // Dibuixat amb CSS: la icona per defecte de Leaflet són imatges que el bundle no troba
    const icona = L.divIcon({
        className: '',
        html: '<div style="width:18px;height:18px;border-radius:9999px;background:#111827;border:3px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.4)"></div>',
        iconSize: [18, 18],
        iconAnchor: [9, 9],
    });
    marcador = L.marker([inici.value.lat, inici.value.lon], { draggable: true, icon: icona, title: "Inici: arrossega'l per sortir d'un altre lloc" })
        .bindTooltip('Inici')
        .addTo(mapa);
    marcador.on('dragend', () => {
        const ll = marcador!.getLatLng();
        inici.value = { lat: Math.round(ll.lat * 1e6) / 1e6, lon: Math.round(ll.lng * 1e6) / 1e6 };
    });
});
onUnmounted(() => mapa?.remove());

function tornaACasa() {
    inici.value = { ...props.casa };
    marcador?.setLatLng([props.casa.lat, props.casa.lon]);
    mapa?.setView([props.casa.lat, props.casa.lon], mapa.getZoom());
}

/**
 * Com es pinta la triada. D'entrada, d'un sol color: per pendent, a ciutat i al pla quasi tot és el gris
 * de «pla», que es confon amb les teselles.
 */
const modeMapa = ref<'simple' | 'pendent'>('simple');
/** Les altres propostes comparteixen molts trams amb la triada: es poden amagar perquè no l'emboliquin. */
const mostraAltres = ref(true);

/** Les propostes al mapa: la triada gruixuda i amb vora fosca i blanca; les altres, fines i transparents. */
function dibuixa() {
    if (!mapa) return;
    capa.clearLayers();
    if (mostraAltres.value) {
        propostes.value.forEach((p, n) => {
            if (n === triada.value) return;
            L.polyline(p.punts.map(([la, lo]) => [la, lo] as [number, number]), { color: '#6b7280', weight: 2, opacity: 0.45, dashArray: '4 6' })
                .bindTooltip(`Proposta ${n + 1}: ${nombre(p.km, 1)} km, ${p.desnivell} m`)
                .on('click', () => (triada.value = n))
                .addTo(capa);
        });
    }
    const p = propostes.value[triada.value];
    if (!p) return;
    const tot = p.punts.map(([la, lo]) => [la, lo] as [number, number]);
    // Vora fosca i vora blanca: destaca sobre teselles clares i fosques
    L.polyline(tot, { color: '#0f172a', weight: 11, opacity: 0.55 }).addTo(capa);
    L.polyline(tot, { color: '#ffffff', weight: 8, opacity: 1 }).addTo(capa);
    if (modeMapa.value === 'simple') {
        L.polyline(tot, { color: SERIE_BLAU, weight: 5, opacity: 1 }).addTo(capa);
    } else {
        const lat = p.punts.map((q) => q[0]), lon = p.punts.map((q) => q[1]);
        for (const t of trossosPerPendent(lat, lon, pendents(distancies(p.punts), p.punts.map((q) => q[2])))) {
            L.polyline(t.punts, { color: t.color, weight: 5, opacity: 1 }).addTo(capa);
        }
    }
    marcador?.setZIndexOffset(1000);
}
watch([modeMapa, mostraAltres], dibuixa);
// En triar una altra proposta, el mapa l'enquadra
watch([propostes, triada], () => {
    dibuixa();
    const p = propostes.value[triada.value];
    if (mapa && p) mapa.fitBounds(L.latLngBounds(p.punts.map(([la, lo]) => [la, lo] as [number, number])), { padding: [24, 24] });
});

// ---- Perfil ----
const fosc = ref(false);
let consultaFosc: MediaQueryList | null = null;
const anotaFosc = (e: MediaQueryListEvent | MediaQueryList) => (fosc.value = e.matches);
onMounted(() => {
    consultaFosc = window.matchMedia('(prefers-color-scheme: dark)');
    anotaFosc(consultaFosc);
    consultaFosc.addEventListener('change', anotaFosc);
});
onUnmounted(() => consultaFosc?.removeEventListener('change', anotaFosc));

function perfil(p: Proposta): ChartData<'line'> {
    const km = distancies(p.punts);
    return {
        datasets: [{
            data: km.map((x, i) => ({ x, y: p.punts[i][2] })) as { x: number; y: number }[],
            borderColor: SERIE_BLAU,
            backgroundColor: 'rgba(2,132,199,0.15)',
            fill: 'start',
            borderWidth: 2,
            pointRadius: 0,
            spanGaps: true,
        }],
    };
}

const opcionsPerfil = computed<ChartOptions<'line'>>(() => {
    const tinta = fosc.value ? '#9ca3af' : '#6b7280';
    const quadricula = fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        parsing: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false }, decimation: { enabled: true, algorithm: 'lttb', samples: 200 } },
        scales: {
            x: { type: 'linear', grid: { color: quadricula }, ticks: { color: tinta, maxTicksLimit: 6, callback: (v) => `${nombre(Number(v))} km` } },
            y: { grid: { color: quadricula }, border: { display: false }, ticks: { color: tinta, maxTicksLimit: 4 } },
        },
    };
});
</script>

<template>
    <Head title="Proposa una sortida" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('bicis.index')" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Proposa una sortida</h2>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <!-- Què es demana -->
                <form @submit.prevent="proposa" class="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <label class="text-xs text-gray-500 dark:text-gray-400">
                        Quilòmetres
                        <input v-model="km" type="text" inputmode="decimal" class="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </label>
                    <label class="text-xs text-gray-500 dark:text-gray-400">
                        Desnivell (m)
                        <input v-model="desnivell" type="text" inputmode="numeric" class="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </label>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Per on
                        <div class="mt-1 inline-flex rounded-md border border-gray-300 text-sm dark:border-gray-600">
                            <button v-for="[valor, etiqueta, ajuda] in [['btt', 'BTT', 'Tots els trams'], ['carretera', 'Carretera', 'Només els trams per on ha passat una bici de carretera o urbana']]"
                                :key="valor" type="button" @click="tipus = valor as any" :title="ajuda"
                                class="px-3 py-1.5 first:rounded-l-md last:rounded-r-md"
                                :class="tipus === valor ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                {{ etiqueta }}
                            </button>
                        </div>
                    </div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">
                        Propostes
                        <select v-model.number="quantes" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="n in props.quantes.maxim" :key="n" :value="n">{{ n }}</option>
                        </select>
                    </label>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Inici
                        <div class="mt-1 flex items-center gap-2 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                            <span v-if="aCasa">Casa</span>
                            <span v-else class="tabular-nums">{{ inici.lat.toFixed(4) }}, {{ inici.lon.toFixed(4) }}</span>
                            <button v-if="!aCasa" type="button" @click="tornaACasa" class="text-xs text-sky-600 hover:underline dark:text-sky-400">torna a casa</button>
                        </div>
                    </div>
                    <button type="submit" :disabled="cercant"
                        class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-sky-700 disabled:opacity-60">
                        {{ cercant ? 'Buscant voltes…' : 'Proposa' }}
                    </button>
                    <p class="w-full text-xs text-gray-400 dark:text-gray-500">
                        Arrossega el marcador del mapa per sortir d'un altre lloc. Les voltes es fan amb trams de les teves
                        sortides, combinant-ne de diferents, i són les que més s'acosten al que demanes.
                    </p>
                    <p v-if="error" class="w-full text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                </form>

                <!-- Mapa -->
                <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div v-if="propostes.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-200 px-4 py-2 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-400">
                        <div class="inline-flex rounded-md border border-gray-300 dark:border-gray-600">
                            <button v-for="[clau, etiqueta] in [['simple', 'Un color'], ['pendent', 'Per pendent']]" :key="clau" type="button"
                                @click="modeMapa = clau as any" class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                                :class="modeMapa === clau ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                {{ etiqueta }}
                            </button>
                        </div>
                        <template v-if="modeMapa === 'pendent'">
                            <span v-for="t in TRAMS_PENDENT" :key="t.etiqueta" class="inline-flex items-center gap-1">
                                <span class="inline-block h-2 w-4 rounded-sm" :style="{ backgroundColor: t.color }"></span>{{ t.etiqueta }}
                            </span>
                        </template>
                        <label v-if="propostes.length > 1" class="ml-auto inline-flex items-center gap-1.5">
                            <input type="checkbox" v-model="mostraAltres" class="rounded border-gray-300 text-sky-600 dark:border-gray-600 dark:bg-gray-700" />
                            <span class="inline-block h-0 w-4 border-t-2 border-dashed border-gray-500"></span>
                            les altres propostes (clica-les per triar-les)
                        </label>
                    </div>
                    <div ref="contenidorMapa" class="h-[28rem] w-full"></div>
                </div>

                <!-- Propostes -->
                <div v-if="propostes.length && demanat" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="(p, n) in propostes" :key="n" @click="triada = n"
                        class="cursor-pointer rounded-lg bg-white p-4 shadow-sm ring-2 dark:bg-gray-800"
                        :class="triada === n ? 'ring-sky-500' : 'ring-transparent hover:ring-gray-200 dark:hover:ring-gray-700'">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Proposta {{ n + 1 }}</h3>
                            <button type="button" @click.stop="baixa(p, n)"
                                class="rounded-md border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                Baixa el GPX
                            </button>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-4 text-sm tabular-nums">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ nombre(p.km, 1) }} km</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ nombre(p.desnivell) }} m</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" title="Respecte del que has demanat">
                                {{ signe(p.km - demanat.km, 1) }} km · {{ signe(p.desnivell - demanat.desnivell) }} m
                            </span>
                        </div>
                        <div class="mt-2 h-24">
                            <Line :data="perfil(p)" :options="opcionsPerfil" />
                        </div>
                        <p v-if="p.km_repetits || p.km_antics" class="mt-2 space-x-2 text-xs">
                            <span v-if="p.km_repetits" class="text-gray-600 dark:text-gray-400" title="Trams que la volta fa dues vegades, d'anada i de tornada">
                                {{ nombre(p.km_repetits, 1) }} km d'anar i tornar
                            </span>
                            <span v-if="p.km_antics" class="text-amber-700 dark:text-amber-400" title="Trams que fa més de tres anys que no es fan: potser han canviat">
                                {{ nombre(p.km_antics, 1) }} km sense fer fa més de 3 anys
                            </span>
                        </p>
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Surt sobretot de:</div>
                        <ul class="mt-0.5 space-y-0.5 text-xs">
                            <li v-for="s in p.sortides.slice(0, 5)" :key="s.id" class="flex justify-between gap-2">
                                <Link :href="route('bicis.sortida', s.id)" @click.stop class="truncate text-sky-700 hover:underline dark:text-sky-400">
                                    {{ s.nom ?? 'Sortida' }}
                                </Link>
                                <span class="shrink-0 tabular-nums text-gray-400">{{ s.data }} · {{ nombre(s.km, 1) }} km</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <p v-else-if="cercant" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Buscant voltes per la xarxa de camins… uns segons.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
