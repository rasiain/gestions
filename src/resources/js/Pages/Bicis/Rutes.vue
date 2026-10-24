<script setup lang="ts">
/**
 * El catàleg de rutes canòniques. Porta les rutes de tots els nivells de tolerància: el selector
 * i els filtres es resolen aquí, sense tornar al servidor. Cada ruta és una targeta (amb la
 * miniatura del traçat) i una línia del mapa; passar per l'una ressalta l'altra.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SelectorAltitud from '@/Components/Bicis/SelectorAltitud.vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { SERIE_AMBRE, SERIE_BLAU, decodifica } from '@/bicis/recorregut';
import { num } from '@/formularis';

interface Ruta {
    id: number;
    nivell: string;
    nom: string;
    sortides: number;
    canonica_id: number;
    km: number;
    desnivell: number;
    primera: string;
    darrera: string;
    bicis: number[];
}

const props = defineProps<{
    nivells: { clau: string; llindar: number }[];
    rutes: Ruta[];
    tracats: Record<number, string | null>;
    bicis: { id: number; nom: string; modalitat: string | null }[];
    /** El mètode de l'altitud demanat; null = el de cada sortida. */
    altitud: string | null;
}>();

// El desnivell es torna a demanar al servidor amb el mètode triat: és qui té els totals desats
const altitud = ref<string | null>(props.altitud);
watch(altitud, (a) => router.get(route('bicis.rutes.index'), { ...(a ? { altitud: a } : {}), nivell: nivell.value },
    { preserveState: true, preserveScroll: true, replace: true, only: ['rutes', 'altitud'] }));
const enllacRuta = (id: number) => route('bicis.rutes.show', altitud.value ? { ruta: id, altitud: altitud.value } : { ruta: id });

const ETIQUETA_NIVELL: Record<string, string> = {
    identiques: 'Idèntiques', mateixa: 'Mateixa ruta', variants: 'Variants', zona: 'Mateixa zona',
};

// ---- Selector i filtres (el nivell va a l'adreça, per tornar-hi des del detall) ----
const enllac = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const nivell = ref(enllac.get('nivell') ?? 'mateixa');
const bici = ref<number | null>(null);
const kmMin = ref('');
const kmMax = ref('');
const desMin = ref('');
const desMax = ref('');
// Desmarcat d'entrada: les sortides fetes un sol cop (una travessa) també interessen per triar
const nomesRepetides = ref(false);
const ordre = ref<'vegades' | 'darrera' | 'km'>('vegades');

watch(nivell, (n) => {
    const url = new URL(window.location.href);
    url.searchParams.set('nivell', n);
    window.history.replaceState(window.history.state, '', url);
});

/** Els filtres menys el nivell: serveixen també per comptar quantes rutes té cada nivell. */
function passaFiltres(r: Ruta): boolean {
    return (!nomesRepetides.value || r.sortides >= 2) && passaLaResta(r);
}

function passaLaResta(r: Ruta): boolean {
    const [k0, k1, d0, d1] = [num(kmMin.value), num(kmMax.value), num(desMin.value), num(desMax.value)];
    return (bici.value === null || r.bicis.includes(bici.value))
        && (k0 === null || r.km >= k0) && (k1 === null || r.km <= k1)
        && (d0 === null || r.desnivell >= d0) && (d1 === null || r.desnivell <= d1);
}

const comptes = computed(() => {
    const c: Record<string, number> = {};
    for (const r of props.rutes) if (passaFiltres(r)) c[r.nivell] = (c[r.nivell] ?? 0) + 1;
    return c;
});

const visibles = computed(() => {
    const llista = props.rutes.filter((r) => r.nivell === nivell.value && passaFiltres(r));
    const per = {
        vegades: (a: Ruta, b: Ruta) => b.sortides - a.sortides || b.darrera.localeCompare(a.darrera),
        darrera: (a: Ruta, b: Ruta) => b.darrera.localeCompare(a.darrera),
        km: (a: Ruta, b: Ruta) => b.km - a.km,
    }[ordre.value];
    return llista.sort(per);
});

const sortidesVisibles = computed(() => visibles.value.reduce((t, r) => t + r.sortides, 0));

/** Les rutes d'una sola sortida del nivell, que «Només les que he repetit» amaga. */
const dUnaSortida = computed(() => props.rutes.filter((r) => r.nivell === nivell.value && r.sortides === 1 && passaLaResta(r)).length);

// ---- Formats ----
const nombre = (v: number, d = 0) => v.toLocaleString('ca-ES', { minimumFractionDigits: d, maximumFractionDigits: d });
function fa(data: string): string {
    const dies = Math.round((Date.now() - Date.parse(data + 'T12:00:00')) / 86400000);
    if (dies < 1) return 'avui';
    if (dies < 14) return `fa ${dies} dies`;
    if (dies < 60) return `fa ${Math.round(dies / 7)} setmanes`;
    if (dies < 730) return `fa ${Math.round(dies / 30)} mesos`;
    return `fa ${Math.round(dies / 365)} anys`;
}
const nomBici = (id: number) => props.bicis.find((b) => b.id === id)?.nom ?? '';

// ---- Miniatures: el traçat en un SVG petit, sense mapa ----
const punts = new Map<number, [number, number][]>();
function traca(canonica: number): [number, number][] {
    if (!punts.has(canonica)) punts.set(canonica, props.tracats[canonica] ? decodifica(props.tracats[canonica]!) : []);
    return punts.get(canonica)!;
}

function miniatura(canonica: number, ample = 72, alt = 52, marge = 4): string {
    const p = traca(canonica);
    if (p.length < 2) return '';
    const cos = Math.cos((p[0][0] * Math.PI) / 180);
    const xs = p.map(([, lo]) => lo * cos), ys = p.map(([la]) => -la);
    const [x0, x1, y0, y1] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const escala = Math.min((ample - 2 * marge) / (x1 - x0 || 1e-9), (alt - 2 * marge) / (y1 - y0 || 1e-9));
    const dx = (ample - (x1 - x0) * escala) / 2, dy = (alt - (y1 - y0) * escala) / 2;
    return xs.map((x, i) => `${i ? 'L' : 'M'}${((x - x0) * escala + dx).toFixed(1)},${((ys[i] - y0) * escala + dy).toFixed(1)}`).join('');
}

// ---- Mapa ----
const contenidorMapa = ref<HTMLElement | null>(null);
let mapa: L.Map | null = null;
const capa = L.layerGroup();
const linies = new Map<number, L.Polyline>();
/** La ruta sota el cursor (de passada) i la seleccionada amb un clic (fixa fins a un altre clic). */
const ressaltada = ref<number | null>(null);
const seleccionada = ref<number | null>(null);

// Blau i prou gruixudes perquè una ruta sola es vegi sobre el mapa; la seleccionada, més que la de passada
const ESTIL_NORMAL = { color: SERIE_BLAU, weight: 3, opacity: 0.6 };
const ESTIL_PASSADA = { color: SERIE_AMBRE, weight: 4, opacity: 0.9 };
const ESTIL_SELECCIONADA = { color: SERIE_AMBRE, weight: 6, opacity: 1 };

const estil = (id: number) =>
    id === seleccionada.value ? ESTIL_SELECCIONADA : id === ressaltada.value ? ESTIL_PASSADA : ESTIL_NORMAL;

function dibuixa(enquadra = true) {
    if (!mapa) return;
    capa.clearLayers();
    linies.clear();
    const limits = L.latLngBounds([]);
    for (const r of visibles.value) {
        const p = traca(r.canonica_id);
        if (p.length < 2) continue;
        const linia = L.polyline(p, estil(r.id))
            .bindTooltip(`${r.nom} · ${r.sortides} ${r.sortides === 1 ? 'vegada' : 'vegades'}`, { sticky: true })
            .on('mouseover', () => (ressaltada.value = r.id))
            .on('mouseout', () => (ressaltada.value = null))
            .on('click', (e: L.LeafletMouseEvent) => {
                // Que no arribi al mapa, que el clic al buit treu la selecció
                L.DomEvent.stop(e);
                selecciona(r.id, false);
            })
            .addTo(capa);
        linies.set(r.id, linia);
        limits.extend(linia.getBounds());
    }
    if (seleccionada.value !== null) linies.get(seleccionada.value)?.bringToFront();
    if (enquadra && limits.isValid()) mapa.fitBounds(limits, { padding: [16, 16] });
}

/** Selecciona una ruta: des de la llista, el mapa hi va; des del mapa, la llista hi baixa. */
function selecciona(id: number, enquadra: boolean) {
    seleccionada.value = id;
    const linia = linies.get(id);
    if (enquadra && linia && mapa) mapa.flyToBounds(linia.getBounds(), { padding: [40, 40], maxZoom: 15, duration: 0.6 });
    if (!enquadra) document.getElementById(`ruta-${id}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

watch([ressaltada, seleccionada], ([r, s], [r0, s0]) => {
    for (const id of new Set([r, s, r0, s0])) if (id !== null && id !== undefined) linies.get(id)?.setStyle(estil(id));
    if (r !== null) linies.get(r)?.bringToFront();
    if (s !== null) linies.get(s)?.bringToFront();
});

// Si els filtres amaguen la seleccionada, deixa d'estar-ho
watch(visibles, (llista) => {
    if (seleccionada.value !== null && !llista.some((r) => r.id === seleccionada.value)) seleccionada.value = null;
});

watch(visibles, () => dibuixa());

onMounted(async () => {
    await nextTick();
    if (!contenidorMapa.value) return;
    // Centenars de línies: el llenç va més de pressa que l'SVG
    // Una tolerància de 8 px fa que les línies, primes, s'encertin amb el cursor
    mapa = L.map(contenidorMapa.value, { renderer: L.canvas({ tolerance: 8 }), scrollWheelZoom: false });
    mapa.on('click', () => (seleccionada.value = null));
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);
    capa.addTo(mapa);
    dibuixa();
});
onUnmounted(() => mapa?.remove());

</script>

<template>
    <Head title="Rutes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('bicis.index')" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Rutes</h2>
            </div>
            <SelectorAltitud v-model="altitud" class="mt-2" />
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-full space-y-4 px-4 sm:px-6 lg:px-8">
                <!-- Selector de tolerància: quantes rutes queden a cada nivell, amb els filtres d'ara -->
                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-sm text-gray-500 dark:text-gray-400">Tolerància</span>
                        <button v-for="n in nivells" :key="n.clau" @click="nivell = n.clau"
                            class="rounded-md border px-3 py-1.5 text-sm"
                            :class="nivell === n.clau
                                ? 'border-sky-600 bg-sky-600 text-white'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
                            :title="`Totes les sortides d'una ruta comparteixen almenys un ${Math.round(n.llindar * 100)} % del recorregut`">
                            {{ ETIQUETA_NIVELL[n.clau] ?? n.clau }}
                            <span class="ml-1 tabular-nums" :class="nivell === n.clau ? 'text-sky-100' : 'text-gray-400'">{{ comptes[n.clau] ?? 0 }}</span>
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap items-end gap-3 text-sm">
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Bici
                            <select v-model="bici" class="mt-1 block rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option :value="null">Totes</option>
                                <option v-for="b in bicis" :key="b.id" :value="b.id">{{ b.nom }}</option>
                            </select>
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Km
                            <span class="mt-1 flex items-center gap-1">
                                <input v-model="kmMin" type="text" inputmode="decimal" placeholder="mín" class="w-16 rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                –
                                <input v-model="kmMax" type="text" inputmode="decimal" placeholder="màx" class="w-16 rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </span>
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Desnivell (m)
                            <span class="mt-1 flex items-center gap-1">
                                <input v-model="desMin" type="text" inputmode="decimal" placeholder="mín" class="w-16 rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                –
                                <input v-model="desMax" type="text" inputmode="decimal" placeholder="màx" class="w-16 rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </span>
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Ordena
                            <select v-model="ordre" class="mt-1 block rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="vegades">Més vegades</option>
                                <option value="darrera">Més recents</option>
                                <option value="km">Més llargues</option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2 pb-1 text-sm text-gray-600 dark:text-gray-400">
                            <input v-model="nomesRepetides" type="checkbox" class="rounded border-gray-300 text-sky-600 dark:border-gray-600 dark:bg-gray-700" />
                            Només les que he repetit
                            <span class="text-xs text-gray-400">({{ nomesRepetides ? `n'amaga ${dUnaSortida}` : `${dUnaSortida} d'una sola sortida` }})</span>
                        </label>
                        <span class="ml-auto pb-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ nombre(visibles.length) }} rutes · {{ nombre(sortidesVisibles) }} sortides
                        </span>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-5">
                    <!-- Targetes -->
                    <div class="space-y-2 lg:col-span-2 lg:max-h-[calc(100vh-16rem)] lg:overflow-y-auto lg:pr-1">
                        <p v-if="!visibles.length" class="rounded-lg bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                            Cap ruta amb aquests filtres.
                        </p>
                        <div v-for="r in visibles" :key="r.id" :id="`ruta-${r.id}`" role="button" tabindex="0"
                            @click="selecciona(r.id, true)" @keydown.enter="selecciona(r.id, true)"
                            @mouseenter="ressaltada = r.id" @mouseleave="ressaltada = null"
                            class="flex w-full cursor-pointer items-center gap-3 rounded-lg p-3 text-left shadow-sm transition-colors"
                            :class="seleccionada === r.id
                                ? 'bg-amber-50 ring-2 ring-amber-600 dark:bg-amber-900/20'
                                : ressaltada === r.id
                                    ? 'bg-white ring-1 ring-amber-500 dark:bg-gray-800'
                                    : 'bg-white hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/50'">
                            <svg viewBox="0 0 72 52" class="h-[52px] w-[72px] shrink-0 rounded bg-gray-50 dark:bg-gray-700/50" aria-hidden="true">
                                <path :d="miniatura(r.canonica_id)" fill="none" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"
                                    :stroke="seleccionada === r.id || ressaltada === r.id ? SERIE_AMBRE : SERIE_BLAU" />
                            </svg>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ r.nom }}</span>
                                <span class="block text-sm tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ nombre(r.km, 1) }} km · {{ nombre(r.desnivell) }} m
                                </span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    <strong class="font-semibold text-gray-700 dark:text-gray-300">{{ r.sortides }}</strong>
                                    {{ r.sortides === 1 ? 'vegada' : 'vegades' }} · l'última {{ fa(r.darrera) }}
                                    <template v-if="r.bicis.length === 1"> · {{ nomBici(r.bicis[0]) }}</template>
                                    <template v-else> · {{ r.bicis.length }} bicis</template>
                                </span>
                            </span>
                            <Link :href="enllacRuta(r.id)" @click.stop
                                class="shrink-0 self-center rounded-md border border-gray-300 px-2 py-1 text-xs text-sky-700 hover:bg-gray-50 dark:border-gray-600 dark:text-sky-400 dark:hover:bg-gray-700">
                                Obre →
                            </Link>
                        </div>
                    </div>

                    <!-- Mapa -->
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800 lg:col-span-3">
                        <div ref="contenidorMapa" class="h-[32rem] w-full lg:h-[calc(100vh-16rem)]"></div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
