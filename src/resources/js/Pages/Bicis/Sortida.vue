<script setup lang="ts">
/**
 * Una sortida en bici: el mapa del recorregut, els perfils (altitud, velocitat, pulsacions,
 * cadència) i el resum de Garmin al costat del del dispositiu.
 *
 * El mapa i els perfils van lligats per un sol índex de punt: passar per sobre de qualsevol
 * gràfica o del recorregut el marca a tot arreu. Els punts es demanen en obrir la pàgina.
 *
 * L'altitud pot ser la del dispositiu, la del model del terreny o la combinació de totes dues
 * (vegeu App\Support\AltitudCombinada): el servidor envia tots els perfils amb els punts, i el
 * mètode triat canvia el perfil d'altitud, el pendent, el desnivell acumulat i el mapa.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { Chart, ChartData, ChartOptions, Plugin } from 'chart.js';
import zoomPlugin from 'chartjs-plugin-zoom';
import { SERIE_AMBRE, SERIE_BLAU, TRAMS_PENDENT, colorPendent, pendents, rodaEnClicar } from '@/bicis/recorregut';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

ChartJS.register(...registerables, zoomPlugin);

interface Resum {
    km: number | null; temps_s: number | null; moviment_s: number | null; total_s: number | null;
    pujada: number | null; baixada: number | null; alt_min: number | null; alt_max: number | null;
    kmh: number | null; kmh_max: number | null; fc: number | null; fc_max: number | null;
    cadencia: number | null; calories: number | null; temp_min: number | null; temp_max: number | null;
}

interface Punts {
    t: number[]; lat: (number | null)[]; lon: (number | null)[]; alt: (number | null)[];
    km: (number | null)[]; kmh: (number | null)[]; fc: (number | null)[]; cad: (number | null)[]; temp: (number | null)[];
    /** Els perfils del terreny i combinats, per "metode/font" (p. ex. "combinat/icgc"). */
    altituds: Record<string, (number | null)[]>;
}

type Metode = 'dispositiu' | 'terreny' | 'combinat';
interface PreferenciaAltitud { metode: Metode; font: string }

const props = defineProps<{
    sortida: {
        id: number; nom: string | null; tipus: string | null; data: string; hora: string | null; lloc: string | null;
        punts: number; desnivell_corregit: boolean; material_editat: boolean; fitxer: string | null; origen: string; id_extern: string;
        vehicle: { id: number; nom: string; modalitat: string | null };
        material: { id: number; nom: string; tipus: string }[];
    };
    resums: { garmin: Resum; dispositiu: Resum | null };
    altitud: {
        fonts: string[];
        /** Si ja s'ha llegit l'altitud del terreny: una sortida acabada d'importar es llegeix en segon pla. */
        llegida: boolean;
        defecte: PreferenciaAltitud;
        sortida: PreferenciaAltitud | null;
        desnivells: Record<string, { pujada: number; baixada: number }>;
    };
    anterior: number | null;
    seguent: number | null;
    bicis: { id: number; nom: string; actiu: boolean }[];
    peces: { id: number; nom: string; tipus: string; estat: string; data_inici: string | null; data_fi: string | null; muntat_a_vehicle_id: number | null }[];
}>();

const g = props.resums.garmin;

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

async function carregaPunts(): Promise<boolean> {
    const res = await fetch(route('bicis.sortida.punts', props.sortida.id), { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        error.value = `No s'han pogut carregar els punts (error ${res.status}).`;
        return false;
    }
    punts.value = await res.json();
    return true;
}
/** Quan arriba l'altitud del terreny d'una sortida acabada d'importar: els perfils nous són als punts. */
const recarregaPunts = () => carregaPunts();

onMounted(async () => {
    if (!props.sortida.punts) return;
    if (!(await carregaPunts())) return;
    await nextTick();
    dibuixaMapa();
});

// ---- Altitud: dispositiu, terreny o combinada ----
const ETIQUETA_METODE: Record<Metode, string> = { dispositiu: 'Dispositiu', terreny: 'Model del terreny', combinat: 'Combinat' };
const AJUDA_METODE: Record<Metode, string> = {
    dispositiu: "L'altímetre del dispositiu, tal com es va gravar.",
    terreny: "L'altitud del terreny al punt, del model (ICGC 2 m o IGN 5 m). En un pont hi posa el fons de la vall.",
    combinat: "El nivell del model i el detall de l'altímetre on és de fiar: corregeix el calibratge, la deriva i el soroll.",
};
const ETIQUETA_FONT: Record<string, string> = { icgc: 'ICGC', ign: 'IGN' };

/** Una preferència portada a la sortida: la font que demana si la cobreix, si no la millor; sense cap, el dispositiu. */
function resol(p: PreferenciaAltitud): { metode: Metode; font: string | null } {
    const fonts = props.altitud.fonts;
    if (p.metode === 'dispositiu' || fonts.length === 0) return { metode: 'dispositiu', font: null };
    return { metode: p.metode, font: fonts.includes(p.font) ? p.font : fonts[0] };
}
const igual = (a: { metode: Metode; font: string | null }, b: { metode: Metode; font: string | null }) =>
    a.metode === b.metode && (a.metode === 'dispositiu' || a.font === b.font);

const triat = ref(resol(props.altitud.sortida ?? props.altitud.defecte));

// Acabada d'importar, l'altitud del terreny encara s'està llegint: es torna a demanar fins que hi és
let esperaAltitud: ReturnType<typeof setInterval> | null = null;
let intents = 0;
const esperaEsgotada = ref(false);
onMounted(() => {
    if (props.altitud.llegida || !props.sortida.punts) return;
    esperaAltitud = setInterval(() => {
        // Com a molt un minut: si la lectura en segon pla ha fallat, no s'ha de preguntar per sempre
        if (++intents > 20) {
            clearInterval(esperaAltitud!);
            esperaAltitud = null;
            esperaEsgotada.value = true;
            return;
        }
        router.reload({
        only: ['altitud'],
        onSuccess: () => {
            if (!props.altitud.llegida) return;
            clearInterval(esperaAltitud!);
            esperaAltitud = null;
            triat.value = resol(props.altitud.sortida ?? props.altitud.defecte);
            recarregaPunts();
        },
        });
    }, 3000);
});
onUnmounted(() => { if (esperaAltitud) clearInterval(esperaAltitud); });
const clauPerfil = computed(() => (triat.value.metode === 'dispositiu' ? 'dispositiu/dispositiu' : `${triat.value.metode}/${triat.value.font}`));

/** L'altitud de cada punt amb el mètode triat. */
const alt = computed<(number | null)[]>(() => {
    const p = punts.value;
    if (!p) return [];
    return triat.value.metode === 'dispositiu' ? p.alt : (p.altituds?.[clauPerfil.value] ?? p.alt);
});

const desada = computed(() => resol(props.altitud.sortida ?? props.altitud.defecte));
const perDefecte = computed(() => resol(props.altitud.defecte));

function triaMetode(metode: Metode) {
    triat.value = resol({ metode, font: triat.value.font ?? props.altitud.fonts[0] ?? 'millor' });
}
const opcionsDesar = { preserveScroll: true, preserveState: true };
const desaPerSortida = () => router.put(route('bicis.sortida.altitud', props.sortida.id), { ...triat.value }, opcionsDesar);
const tornaAlDefecte = () => {
    router.put(route('bicis.sortida.altitud', props.sortida.id), { metode: null }, opcionsDesar);
    triat.value = perDefecte.value;
};
const desaPerDefecte = () => router.put(route('bicis.altitud-defecte'), { ...triat.value }, opcionsDesar);

/** Els desnivells de cada perfil, per comparar-los: el triat, ressaltat. */
const desnivells = computed(() => {
    const d = props.altitud.desnivells;
    const files: { clau: string; etiqueta: string; pujada: number | null; baixada: number | null }[] = [];
    files.push({ clau: 'garmin', etiqueta: 'Garmin Connect', pujada: g.pujada, baixada: g.baixada });
    if (d['dispositiu/dispositiu']) files.push({ clau: 'dispositiu/dispositiu', etiqueta: 'Dispositiu', ...d['dispositiu/dispositiu'] });
    for (const metode of ['combinat', 'terreny'] as Metode[]) {
        for (const font of props.altitud.fonts) {
            const t = d[`${metode}/${font}`];
            if (t) files.push({ clau: `${metode}/${font}`, etiqueta: `${ETIQUETA_METODE[metode]} · ${ETIQUETA_FONT[font] ?? font}`, ...t });
        }
    }
    return files;
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

/** El pendent de cada punt (vegeu `pendents()`), només amb distància real. */
const pendent = computed<(number | null)[]>(() =>
    punts.value && perDistancia.value ? pendents(xs.value, alt.value) : []);

/** Quant ha de pujar l'altitud des de l'últim punt de referència perquè compti com a pujada. */
const LLINDAR_PUJADA_M = 2.5;

/**
 * Desnivell positiu acumulat fins a cada punt. Sumar cada pujadeta del perfil donaria un 30 %
 * més que el dispositiu (el soroll de l'altímetre també puja); amb un llindar de 2,5 m la
 * diferència queda en pocs punts (mesurat sobre 40 sortides). Amb l'altitud del dispositiu, la
 * corba s'escala perquè acabi exactament en el desnivell del resum de Garmin; amb el terreny o la
 * combinada no cal, perquè el total desat surt d'aquest mateix càlcul (App\Support\Desnivell).
 */
const acumulat = computed<(number | null)[]>(() => {
    const p = punts.value;
    if (!p || !perDistancia.value) return [];
    const alts = alt.value;
    const sortida: (number | null)[] = new Array(alts.length).fill(null);
    let ref: number | null = null, total = 0;
    alts.forEach((a, i) => {
        if (a !== null) {
            if (ref === null || a < ref) ref = ref === null || ref - a >= LLINDAR_PUJADA_M ? a : ref;
            if (a - ref >= LLINDAR_PUJADA_M) { total += a - ref; ref = a; }
        }
        sortida[i] = total;
    });
    const resum = triat.value.metode === 'dispositiu' ? props.resums.garmin.pujada : null;
    const factor = resum && total > 0 ? resum / total : 1;
    return sortida.map((v) => (v === null ? null : Math.round(v * factor)));
});

const lectura = computed(() => {
    const p = punts.value, i = actiu.value;
    if (!p || i === null) return null;
    return {
        t: p.t[i], km: p.km[i], alt: alt.value[i] ?? null, pendent: pendent.value[i] ?? null, acumulat: acumulat.value[i] ?? null,
        kmh: p.kmh[i], fc: p.fc[i], cad: p.cad[i], temp: p.temp[i],
    };
});

// ---- Mapa ----

const contenidorMapa = ref<HTMLElement | null>(null);
let mapa: L.Map | null = null;
let marcador: L.CircleMarker | null = null;
let limitsTraca: L.LatLngBounds | null = null;
/** El recorregut dibuixat (d'un color o per trams de pendent), per poder-lo redibuixar. */
const capaTraca = L.layerGroup();
/** Els marcadors (inici, final, punt assenyalat), que han de quedar sempre per sobre del recorregut. */
const marcadors: L.Path[] = [];


/** Com es pinta el recorregut. Per pendent només quan n'hi ha (no als rodets). */
const modeMapa = ref<'pendent' | 'simple'>('pendent');
const potPendent = computed(() => pendent.value.some((v) => v !== null));

function pintaTraca() {
    const p = punts.value;
    if (!p || !mapa) return;
    capaTraca.clearLayers();

    const tots: [number, number][] = [];
    p.lat.forEach((lat, i) => { if (lat !== null && p.lon[i] !== null) tots.push([lat, p.lon[i]!]); });

    // La vora blanca, sota de tot
    L.polyline(tots, { color: '#ffffff', weight: 7, opacity: 0.9 }).addTo(capaTraca);

    if (modeMapa.value === 'simple' || !potPendent.value) {
        L.polyline(tots, { color: SERIE_BLAU, weight: 4, opacity: 0.95 }).addTo(capaTraca);
        marcadors.forEach((m) => m.bringToFront());
        return;
    }

    // Un tros per cada canvi de tram; cada tros comença on acaba l'anterior perquè no quedin forats
    let tros: [number, number][] = [];
    let color: string | null = null;
    p.lat.forEach((lat, i) => {
        if (lat === null || p.lon[i] === null) return;
        const punt: [number, number] = [lat, p.lon[i]!];
        const c = colorPendent(pendent.value[i] ?? null);
        if (color !== null && c !== color) {
            tros.push(punt);
            L.polyline(tros, { color, weight: 4, opacity: 1 }).addTo(capaTraca);
            tros = [];
        }
        color = c;
        tros.push(punt);
    });
    if (color !== null && tros.length > 1) L.polyline(tros, { color, weight: 4, opacity: 1 }).addTo(capaTraca);
    marcadors.forEach((m) => m.bringToFront());
}
/** El tram ampliat a les gràfiques, ressaltat al mapa. */
let tramAmpliat: L.Polyline | null = null;
const teGps = computed(() => (punts.value?.lat ?? []).some((v) => v !== null));

function dibuixaMapa() {
    const p = punts.value;
    if (!p || !teGps.value || !contenidorMapa.value) return;

    const coords: L.LatLngExpression[] = [];
    p.lat.forEach((lat, i) => { if (lat !== null && p.lon[i] !== null) coords.push([lat, p.lon[i] as number]); });

    mapa = L.map(contenidorMapa.value, { scrollWheelZoom: false });
    rodaEnClicar(mapa);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);

    capaTraca.addTo(mapa);
    pintaTraca();
    limitsTraca = L.latLngBounds(coords);
    mapa.fitBounds(limitsTraca, { padding: [16, 16] });

    const extrem = (ll: L.LatLngExpression, titol: string) =>
        marcadors.push(L.circleMarker(ll, { radius: 6, color: '#ffffff', weight: 2, fillColor: '#111827', fillOpacity: 1 }).bindTooltip(titol).addTo(mapa!));
    extrem(coords[0], 'Inici');
    extrem(coords[coords.length - 1], 'Final');

    // Amagat fins que s'assenyali un punt
    marcador = L.circleMarker(coords[0], { radius: 7, color: '#ffffff', weight: 2, fillColor: SERIE_AMBRE, opacity: 0, fillOpacity: 0 }).addTo(mapa);
    marcadors.push(marcador);

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

// ---- Zoom ----
/**
 * El tram de l'eix ampliat, comú a totes les gràfiques: ampliar-ne una les amplia totes i
 * enquadra el tram al mapa. S'amplia arrossegant sobre una gràfica, o amb la roda i Ctrl
 * (el pessic del trackpad): la roda sola ha de continuar desplaçant la pàgina, que hi ha
 * moltes gràfiques una sota l'altra.
 */
const rang = ref<{ min: number; max: number } | null>(null);

function reiniciaZoom() {
    rang.value = null;
    for (const g of grafiques.value) (g as unknown as { chart?: Chart & { resetZoom?: () => void } })?.chart?.resetZoom?.();
}

function ressaltaTram(r: { min: number; max: number } | null, enquadra = true) {
    const p = punts.value;
    if (!mapa || !p) return;
    tramAmpliat?.remove();
    tramAmpliat = null;
    if (r === null) {
        if (enquadra && limitsTraca) mapa.fitBounds(limitsTraca, { padding: [16, 16] });
        return;
    }
    const coords: L.LatLngExpression[] = [];
    xs.value.forEach((x, i) => {
        if (x >= r.min && x <= r.max && p.lat[i] !== null && p.lon[i] !== null) coords.push([p.lat[i]!, p.lon[i]!]);
    });
    if (coords.length < 2) return;
    // Pintat per pendent, l'ambre ja vol dir pujada: el tram es ressalta amb una ombra fosca per sota
    tramAmpliat = modeMapa.value === 'pendent' && potPendent.value
        ? L.polyline(coords, { color: '#111827', weight: 12, opacity: 0.35 }).addTo(mapa).bringToBack()
        : L.polyline(coords, { color: SERIE_AMBRE, weight: 5, opacity: 0.9 }).addTo(mapa);
    if (enquadra) mapa.fitBounds(tramAmpliat.getBounds(), { padding: [24, 24] });
}

watch(rang, (r) => ressaltaTram(r));
watch([modeMapa, pendent], () => {
    pintaTraca();
    ressaltaTram(rang.value, false);
});

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

interface Perfil { clau: keyof Punts | 'pendent' | 'acumulat'; titol: string; unitat: string; decimals: number; farcit?: boolean; signe?: boolean }

const PERFILS: Perfil[] = [
    { clau: 'alt', titol: 'Altitud', unitat: 'm', decimals: 0, farcit: true },
    { clau: 'pendent', titol: 'Pendent', unitat: '%, en trams de 100 m', decimals: 1, signe: true },
    { clau: 'acumulat', titol: 'Desnivell positiu acumulat', unitat: 'm', decimals: 0, farcit: true },
    { clau: 'kmh', titol: 'Velocitat', unitat: 'km/h', decimals: 1 },
    { clau: 'fc', titol: 'Pulsacions', unitat: 'ppm', decimals: 0 },
    { clau: 'cad', titol: 'Cadència', unitat: 'rpm', decimals: 0 },
    { clau: 'temp', titol: 'Temperatura', unitat: '°C', decimals: 0 },
];

/** Els valors d'un perfil: els del dispositiu o, el pendent, calculat. */
const serie = (clau: Perfil['clau']): (number | null)[] =>
    clau === 'pendent' ? pendent.value
        : clau === 'acumulat' ? acumulat.value
        : clau === 'alt' ? alt.value
        : ((punts.value?.[clau] ?? []) as (number | null)[]);

/** Només els perfils que tenen dades en aquesta sortida. */
const perfils = computed(() => PERFILS.filter((f) => serie(f.clau).some((v) => v !== null && v !== 0)));

function dades(f: Perfil): ChartData<'line'> {
    const y = serie(f.clau);
    return {
        datasets: [{
            label: f.titol,
            data: xs.value.map((x, i) => ({ x, y: y[i] })) as { x: number; y: number }[],
            // El pendent té signe: pujada en ambre per sobre del zero, baixada en blau per sota
            borderColor: f.signe ? tinta.value : SERIE_BLAU,
            backgroundColor: f.farcit ? 'rgba(2,132,199,0.15)' : SERIE_BLAU,
            fill: f.signe
                ? { target: 'origin', above: 'rgba(217,119,6,0.35)', below: 'rgba(2,132,199,0.35)' }
                : f.farcit ? 'start' : false,
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
        zoom: {
            zoom: {
                wheel: { enabled: true, modifierKey: 'ctrl' },
                pinch: { enabled: true },
                drag: { enabled: true, backgroundColor: 'rgba(217,119,6,0.15)', borderColor: 'rgba(217,119,6,0.6)', borderWidth: 1 },
                mode: 'x',
                onZoomComplete: ({ chart }) => (rang.value = { min: chart.scales.x.min, max: chart.scales.x.max }),
            },
            pan: { enabled: false },
            limits: { x: { min: 'original', max: 'original' } },
        },
    },
    scales: {
        x: {
            type: 'linear',
            min: rang.value?.min ?? xs.value[0],
            max: rang.value?.max ?? xs.value[xs.value.length - 1],
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
const xifres = computed(() => [
    { etiqueta: 'Distància', valor: `${nombre(g.km, 1)} km` },
    { etiqueta: 'En moviment', valor: durada(g.moviment_s ?? g.temps_s) },
    // Amb el dispositiu, el de Garmin Connect (el de sempre); amb un altre mètode, el seu
    triat.value.metode === 'dispositiu'
        ? { etiqueta: 'Desnivell +', valor: `${nombre(g.pujada)} m${props.sortida.desnivell_corregit ? ' *' : ''}` }
        : { etiqueta: `Desnivell + (${ETIQUETA_METODE[triat.value.metode].toLowerCase()})`, valor: `${nombre(props.altitud.desnivells[clauPerfil.value]?.pujada ?? null)} m` },
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
                    <div class="flex gap-3 text-sm">
                        <Link :href="route('bicis.index')" class="text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                        <Link :href="route('bicis.index', { fitxa: sortida.vehicle.id, pestanya: 'sortides', any: sortida.data.slice(0, 4) })"
                            class="text-sky-600 hover:text-sky-800 dark:text-sky-400">← Sortides de la {{ sortida.vehicle.nom }} ({{ sortida.data.slice(0, 4) }})</Link>
                    </div>
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
                    Aquesta sortida no té recorregut: l'exportació de Garmin no en va portar el fitxer original, només el resum.
                    <span class="mt-2 block">
                        <a v-if="sortida.origen === 'garmin' && /^\d+$/.test(sortida.id_extern)" :href="`https://connect.garmin.com/modern/activity/${sortida.id_extern}`"
                            target="_blank" rel="noopener" class="text-sky-700 hover:underline dark:text-sky-400">Baixa'n l'original de Garmin Connect ↗</a>
                        i <Link :href="route('bicis.importa.index')" class="text-sky-700 hover:underline dark:text-sky-400">afegeix-l'hi</Link>.
                    </span>
                </p>

                <template v-else>
                    <!-- Mapa -->
                    <div v-if="!punts || teGps" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div v-if="punts && potPendent" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-2 dark:border-gray-700">
                            <div class="inline-flex rounded-md border border-gray-300 text-xs dark:border-gray-600">
                                <button v-for="[clau, etiqueta] in [['pendent', 'Per pendent'], ['simple', 'Un color']]" :key="clau"
                                    @click="modeMapa = clau as any"
                                    class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                                    :class="modeMapa === clau ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                    {{ etiqueta }}
                                </button>
                            </div>
                            <!-- Llegenda: el color no va mai sol -->
                            <div v-if="modeMapa === 'pendent'" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                                <span v-for="t in TRAMS_PENDENT" :key="t.etiqueta" class="inline-flex items-center gap-1">
                                    <span class="inline-block h-2 w-4 rounded-sm" :style="{ backgroundColor: t.color }"></span>{{ t.etiqueta }}
                                </span>
                            </div>
                        </div>
                        <div ref="contenidorMapa" class="h-[28rem] w-full">
                            <p v-if="!punts" class="py-6 text-center text-xs text-gray-400">Carregant el recorregut…</p>
                        </div>
                    </div>

                    <!-- Perfils -->
                    <div v-if="punts" class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <!-- Quina altitud: canvia el perfil, el pendent, el desnivell acumulat i el mapa -->
                        <div v-if="altitud.fonts.length" class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-200 pb-3 dark:border-gray-700">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Altitud</span>
                            <div class="inline-flex rounded-md border border-gray-300 text-xs dark:border-gray-600">
                                <button v-for="m in (['dispositiu', 'combinat', 'terreny'] as Metode[])" :key="m"
                                    @click="triaMetode(m)" :title="AJUDA_METODE[m]"
                                    class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                                    :class="triat.metode === m ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                    {{ ETIQUETA_METODE[m] }}
                                </button>
                            </div>
                            <div v-if="triat.metode !== 'dispositiu' && altitud.fonts.length > 1" class="inline-flex rounded-md border border-gray-300 text-xs dark:border-gray-600">
                                <button v-for="f in altitud.fonts" :key="f" @click="triat = { metode: triat.metode, font: f }"
                                    class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                                    :class="triat.font === f ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                                    {{ ETIQUETA_FONT[f] ?? f }}
                                </button>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ AJUDA_METODE[triat.metode] }}</span>
                            <div class="ml-auto flex flex-wrap items-center gap-2 text-xs">
                                <span v-if="altitud.sortida && igual(triat, desada)" class="text-gray-400 dark:text-gray-500">desada per a aquesta sortida</span>
                                <button v-if="!igual(triat, desada) || !altitud.sortida && !igual(triat, perDefecte)" @click="desaPerSortida"
                                    class="rounded-md border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                    Desa-la per a aquesta sortida
                                </button>
                                <button v-if="altitud.sortida" @click="tornaAlDefecte"
                                    class="rounded-md border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                    Torna a la de per defecte ({{ ETIQUETA_METODE[perDefecte.metode].toLowerCase() }})
                                </button>
                                <button v-if="!igual(triat, perDefecte)" @click="desaPerDefecte"
                                    class="rounded-md border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                    Fes-la la de per defecte
                                </button>
                            </div>
                        </div>
                        <p v-else-if="teGps && !altitud.llegida" class="mb-3 border-b border-gray-200 pb-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <template v-if="!esperaEsgotada">
                                S'està llegint l'altitud del terreny d'aquesta sortida, en segon pla. La pàgina s'actualitzarà sola
                                d'aquí a uns segons.
                            </template>
                            <template v-else>
                                L'altitud del terreny encara no ha arribat (mira <code>storage/logs/corregeix-altitud.log</code>); de
                                moment és la del dispositiu.
                            </template>
                        </p>
                        <p v-else-if="teGps" class="mb-3 border-b border-gray-200 pb-3 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            L'altitud és la del dispositiu: cap model del terreny no cobreix aquesta sortida sencera.
                        </p>

                        <!-- Lectura del punt assenyalat: fa de tooltip comú a totes les gràfiques -->
                        <div class="mb-3 flex min-h-[1.5rem] flex-wrap gap-x-5 gap-y-1 text-sm tabular-nums text-gray-700 dark:text-gray-300">
                            <template v-if="lectura">
                                <span><span class="text-gray-400">Temps</span> {{ durada(lectura.t) }}</span>
                                <span v-if="lectura.km !== null"><span class="text-gray-400">Km</span> {{ nombre(lectura.km, 2) }}</span>
                                <span v-if="lectura.alt !== null"><span class="text-gray-400">Altitud</span> {{ nombre(lectura.alt) }} m</span>
                                <span v-if="lectura.pendent !== null"><span class="text-gray-400">Pendent</span> {{ nombre(lectura.pendent, 1) }} %</span>
                                <span v-if="lectura.acumulat !== null"><span class="text-gray-400">Pujat</span> {{ nombre(lectura.acumulat) }} m</span>
                                <span v-if="lectura.kmh !== null"><span class="text-gray-400">Velocitat</span> {{ nombre(lectura.kmh, 1) }} km/h</span>
                                <span v-if="lectura.fc !== null"><span class="text-gray-400">FC</span> {{ lectura.fc }} ppm</span>
                                <span v-if="lectura.cad !== null"><span class="text-gray-400">Cadència</span> {{ lectura.cad }} rpm</span>
                                <span v-if="lectura.temp !== null"><span class="text-gray-400">Temp.</span> {{ lectura.temp }} °C</span>
                            </template>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">
                                Passa per sobre d'una gràfica o del recorregut per veure-hi el punt. Arrossega sobre una
                                gràfica (o Ctrl + roda) per ampliar un tram; doble clic per tornar a la sortida sencera.
                            </span>
                            <button v-if="rang" @click="reiniciaZoom"
                                class="ml-auto rounded-md border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                Reinicia el zoom ({{ nombre(rang.min, perDistancia ? 1 : 0) }}–{{ nombre(rang.max, perDistancia ? 1 : 0) }} {{ perDistancia ? 'km' : 'min' }})
                            </button>
                        </div>

                        <div class="space-y-4">
                            <div v-for="f in perfils" :key="f.clau">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ f.titol }} <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">{{ f.unitat }}</span>
                                </h4>
                                <div :class="f.clau === 'alt' ? 'h-44' : 'h-32'" @dblclick="reiniciaZoom">
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

                        <template v-if="desnivells.length > 2">
                            <h3 class="mb-2 mt-5 text-sm font-semibold text-gray-700 dark:text-gray-300">Desnivell segons l'altitud</h3>
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                        <th class="pb-1 pr-3 font-medium"></th>
                                        <th class="pb-1 pr-3 text-right font-medium">Desnivell +</th>
                                        <th class="pb-1 text-right font-medium">Desnivell −</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="f in desnivells" :key="f.clau" :class="f.clau === clauPerfil ? 'font-semibold' : ''">
                                        <td class="py-1 pr-3 text-gray-600 dark:text-gray-400">
                                            {{ f.etiqueta }}
                                            <span v-if="f.clau === clauPerfil" class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-normal text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">el de les gràfiques</span>
                                        </td>
                                        <td class="py-1 pr-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(f.pujada) }} m</td>
                                        <td class="py-1 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(f.baixada) }} m</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                Tots menys el de Garmin Connect es calculen igual: una pujada compta quan supera en 2,5 m el
                                punt de referència. Per això el del dispositiu pot diferir una mica del que va calcular ell.
                            </p>
                        </template>
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
