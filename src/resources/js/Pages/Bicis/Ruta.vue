<script setup lang="ts">
/**
 * Una ruta canònica: el representant gruixut i pintat per pendent, amb les sortides del grup
 * fines i translúcides a sota —on se'n separen, es veuen les variants—; el perfil del
 * representant i la llista de sortides amb quant comparteixen amb ell.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { SERIE_AMBRE, SERIE_BLAU, TRAMS_PENDENT, decodifica, pendents, trossosPerPendent } from '@/bicis/recorregut';

ChartJS.register(...registerables);

interface Membre {
    id: number;
    data: string;
    nom: string | null;
    bici: string | null;
    km: number | null;
    desnivell: number | null;
    temps_s: number | null;
    semblanca: number;
    canonica: boolean;
    tracat: string | null;
}

const props = defineProps<{
    ruta: { id: number; nivell: string; llindar: number | null; nom: string; sortides: number };
    canonica: { id: number; punts: number };
    membres: Membre[];
    altres_nivells: { id: number; nivell: string; sortides: number }[];
}>();

const ETIQUETA_NIVELL: Record<string, string> = {
    identiques: 'Idèntiques', mateixa: 'Mateixa ruta', variants: 'Variants', zona: 'Mateixa zona',
};

const nombre = (v: number | null | undefined, d = 0) =>
    v === null || v === undefined ? '—' : v.toLocaleString('ca-ES', { minimumFractionDigits: d, maximumFractionDigits: d });
function durada(s: number | null): string {
    if (s === null) return '—';
    return `${Math.floor(s / 3600)}:${String(Math.round((s % 3600) / 60)).padStart(2, '0')}`;
}

const canonic = computed(() => props.membres.find((m) => m.canonica)!);
const periode = computed(() => {
    const anys = props.membres.map((m) => m.data.slice(0, 4)).sort();
    return anys[0] === anys[anys.length - 1] ? anys[0] : `${anys[0]}–${anys[anys.length - 1]}`;
});
const bicis = computed(() => [...new Set(props.membres.map((m) => m.bici).filter(Boolean))]);

// ---- Llista: aquí aniran els selectors (més recents, variacions…) ----
const ordre = ref<'recents' | 'variants' | 'km'>('recents');
const llista = computed(() => {
    const m = [...props.membres];
    if (ordre.value === 'variants') return m.sort((a, b) => a.semblanca - b.semblanca);
    if (ordre.value === 'km') return m.sort((a, b) => (b.km ?? 0) - (a.km ?? 0));
    return m.sort((a, b) => b.data.localeCompare(a.data));
});

// ---- Punts del canònic: perfil i pendent ----
interface Punts { km: (number | null)[]; alt: (number | null)[]; lat: (number | null)[]; lon: (number | null)[] }
const punts = shallowRef<Punts | null>(null);

const kmCreixent = computed<number[]>(() => {
    let darrer = 0;
    return (punts.value?.km ?? []).map((v) => (darrer = v ?? darrer));
});

// ---- Mapa ----
const contenidorMapa = ref<HTMLElement | null>(null);
let mapa: L.Map | null = null;
const liniesMembres = new Map<number, L.Polyline>();
const assenyalada = ref<number | null>(null);
const ESTIL_MEMBRE = { color: '#6b7280', weight: 2, opacity: 0.35 };

function dibuixaMapa() {
    if (!contenidorMapa.value) return;
    mapa = L.map(contenidorMapa.value, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);

    // Les sortides del grup, a sota
    for (const m of props.membres) {
        if (m.canonica || !m.tracat) continue;
        const linia = L.polyline(decodifica(m.tracat), ESTIL_MEMBRE)
            .bindTooltip(`${m.data} · ${Math.round(m.semblanca * 100)} % en comú`, { sticky: true })
            .on('mouseover', () => (assenyalada.value = m.id))
            .on('mouseout', () => (assenyalada.value = null))
            .addTo(mapa);
        liniesMembres.set(m.id, linia);
    }

    // El canònic, a sobre: amb vora blanca i, quan arriben els punts, pintat per pendent
    const tracat = canonic.value.tracat ? decodifica(canonic.value.tracat) : [];
    if (tracat.length > 1) {
        L.polyline(tracat, { color: '#ffffff', weight: 8, opacity: 0.9 }).addTo(mapa);
        capaCanonic = L.polyline(tracat, { color: SERIE_BLAU, weight: 5, opacity: 1 }).addTo(mapa);
        mapa.fitBounds(L.latLngBounds(tracat), { padding: [16, 16] });
    }
}
let capaCanonic: L.Layer | null = null;

function pintaPendent() {
    const p = punts.value;
    if (!mapa || !p) return;
    const pend = pendents(kmCreixent.value, p.alt);
    if (!pend.some((v) => v !== null)) return;
    capaCanonic?.remove();
    const grup = L.layerGroup();
    for (const t of trossosPerPendent(p.lat, p.lon, pend)) L.polyline(t.punts, { color: t.color, weight: 5, opacity: 1 }).addTo(grup);
    capaCanonic = grup.addTo(mapa);
}

watch(assenyalada, (id, abans) => {
    if (abans !== null && abans !== undefined) liniesMembres.get(abans)?.setStyle(ESTIL_MEMBRE);
    if (id !== null) liniesMembres.get(id)?.setStyle({ color: SERIE_AMBRE, weight: 4, opacity: 1 }).bringToFront();
});

onMounted(async () => {
    await nextTick();
    dibuixaMapa();
    if (!props.canonica.punts) return;
    const res = await fetch(route('bicis.sortida.punts', props.canonica.id), { headers: { Accept: 'application/json' } });
    if (res.ok) {
        punts.value = await res.json();
        pintaPendent();
    }
});
onUnmounted(() => mapa?.remove());

// ---- Perfil d'altitud del canònic ----
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

const dadesPerfil = computed<ChartData<'line'>>(() => ({
    datasets: [{
        label: 'Altitud',
        data: kmCreixent.value.map((x, i) => ({ x, y: punts.value!.alt[i] })) as { x: number; y: number }[],
        borderColor: SERIE_BLAU,
        backgroundColor: 'rgba(2,132,199,0.15)',
        fill: 'start',
        borderWidth: 2,
        pointRadius: 0,
        spanGaps: true,
    }],
}));

const opcionsPerfil = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    parsing: false,
    normalized: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        decimation: { enabled: true, algorithm: 'lttb', samples: 600 },
        tooltip: { callbacks: { title: (c) => `${nombre(c[0].parsed.x, 1)} km`, label: (c) => `${nombre(c.parsed.y)} m` } },
    },
    scales: {
        x: { type: 'linear', min: 0, max: kmCreixent.value[kmCreixent.value.length - 1], grid: { color: quadricula.value }, ticks: { color: tinta.value, callback: (v) => `${nombre(Number(v))} km` } },
        y: { grid: { color: quadricula.value }, border: { display: false }, ticks: { color: tinta.value } },
    },
}));
</script>

<template>
    <Head :title="ruta.nom" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <Link :href="route('bicis.rutes.index', { nivell: ruta.nivell })" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Rutes</Link>
                    <h2 class="truncate text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">{{ ruta.nom }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ ruta.sortides }} {{ ruta.sortides === 1 ? 'vegada' : 'vegades' }} · {{ periode }} · {{ bicis.join(', ') }}
                    </p>
                </div>
                <!-- La mateixa ruta a cada nivell: com creix o s'aprima el grup -->
                <div class="flex flex-wrap gap-1">
                    <Link v-for="n in altres_nivells" :key="n.id" :href="route('bicis.rutes.show', n.id)"
                        class="rounded-md border px-2.5 py-1 text-xs"
                        :class="n.id === ruta.id
                            ? 'border-sky-600 bg-sky-600 text-white'
                            : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'">
                        {{ ETIQUETA_NIVELL[n.nivell] ?? n.nivell }} <span class="tabular-nums opacity-75">{{ n.sortides }}</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="x in [
                        { e: 'Distància', v: `${nombre(canonic.km, 1)} km` },
                        { e: 'Desnivell +', v: `${nombre(canonic.desnivell)} m` },
                        { e: 'Vegades', v: String(ruta.sortides) },
                        { e: 'Comparteixen', v: ruta.llindar ? `≥ ${Math.round(ruta.llindar * 100)} %` : '—' },
                    ]" :key="x.e" class="rounded-lg bg-white px-4 py-3 shadow-sm dark:bg-gray-800">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ x.e }}</div>
                        <div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ x.v }}</div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-2 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-400">
                        <span>
                            <span class="mr-1 inline-block h-1.5 w-5 rounded-sm align-middle" :style="{ backgroundColor: SERIE_BLAU }"></span>Representant (pintat per pendent)
                            <span class="ml-3 mr-1 inline-block h-0.5 w-5 align-middle bg-gray-500/50"></span>Les altres sortides del grup
                        </span>
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span v-for="t in TRAMS_PENDENT" :key="t.etiqueta" class="inline-flex items-center gap-1">
                                <span class="inline-block h-2 w-4 rounded-sm" :style="{ backgroundColor: t.color }"></span>{{ t.etiqueta }}
                            </span>
                        </span>
                    </div>
                    <div ref="contenidorMapa" class="h-[30rem] w-full"></div>
                </div>

                <div v-if="punts" class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Altitud del representant <span class="ml-1 font-normal text-gray-400">m</span></h3>
                    <div class="h-40"><Line :data="dadesPerfil" :options="opcionsPerfil" /></div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Sortides del grup</h3>
                        <select v-model="ordre" class="rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="recents">Més recents</option>
                            <option value="variants">Les que més se'n desvien</option>
                            <option value="km">Més llargues</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                    <th class="pb-1 pr-3 font-medium">Data</th>
                                    <th class="pb-1 pr-3 font-medium">Sortida</th>
                                    <th class="pb-1 pr-3 font-medium">Bici</th>
                                    <th class="pb-1 pr-3 text-right font-medium">Km</th>
                                    <th class="pb-1 pr-3 text-right font-medium">Desnivell</th>
                                    <th class="pb-1 pr-3 text-right font-medium">Temps</th>
                                    <th class="pb-1 text-right font-medium">En comú</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="m in llista" :key="m.id" @mouseenter="assenyalada = m.canonica ? null : m.id" @mouseleave="assenyalada = null"
                                    :class="assenyalada === m.id ? 'bg-amber-50 dark:bg-amber-900/20' : ''">
                                    <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-700 dark:text-gray-300">{{ m.data }}</td>
                                    <td class="py-1 pr-3">
                                        <Link :href="route('bicis.sortida', m.id)" class="text-sky-700 hover:underline dark:text-sky-400">{{ m.nom ?? 'Sortida' }}</Link>
                                        <span v-if="m.canonica" class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">representant</span>
                                    </td>
                                    <td class="py-1 pr-3 text-gray-600 dark:text-gray-400">{{ m.bici }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(m.km, 1) }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(m.desnivell) }} m</td>
                                    <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ durada(m.temps_s) }}</td>
                                    <td class="py-1 text-right tabular-nums" :class="m.canonica ? 'text-gray-400' : 'text-gray-900 dark:text-gray-100'">
                                        {{ m.canonica ? '—' : `${Math.round(m.semblanca * 100)} %` }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                        «En comú» és la part del recorregut que comparteix amb el representant, comptada pel costat que en
                        comparteix menys. Passa per sobre d'una fila per veure-la al mapa.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
