<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';
import 'chartjs-adapter-date-fns';

ChartJS.register(...registerables);

interface Repostatge {
    id: number;
    data: string;
    km_totals: number;
    preu_litre: number;
    cost: number;
    benzinera: string | null;
    diposit_ple: boolean;
    /** Cost dividit pel preu del litre. */
    litres: number | null;
    /** Quilòmetres des del repostatge anterior. */
    km: number | null;
    /** Litres per cada cent quilòmetres. Només entre dos plens. */
    consum: number | null;
    moviment_id: number | null;
    notes: string | null;
}

interface Despesa {
    id: number;
    data: string;
    tipus: string;
    tipus_nom: string;
    import: number;
    km_totals: number | null;
    taller: string | null;
    motiu: string | null;
    moviment_id: number | null;
}

/** El que ha donat un any: quilòmetres, combustible i despeses. */
interface Any {
    any: number;
    /** Entre repostatges, que és el tram que té litres. Null sense lectura anterior. */
    km: number | null;
    /** El mateix, comptant-hi les lectures del taller: el que s'ha rodat de veritat. */
    km_estimats: number | null;
    litres: number;
    consum: number | null;
    carburant: number;
    altres: number;
    total: number;
    cost_km: number | null;
    repostatges: number;
    despeses: number;
    per_tipus: Record<string, number>;
}

interface Vehicle {
    id: number;
    nom: string;
    tipus: string;
    combustible: string | null;
    repostatges: Repostatge[];
    despeses: Despesa[];
    per_any: Any[];
    /** El que diuen tots els repostatges junts. Null amb menys de dos. */
    resum: {
        des_de: string; fins_a: string; repostatges: number; km: number;
        litres: number; cost: number; consum: number | null; cost_100km: number | null;
    } | null;
    marca: string | null;
    model: string | null;
    matricula: string | null;
    any_fabricacio: number | null;
    data_alta: string | null;
    data_baixa: string | null;
    notes: string | null;
    ordre: number;
    /** Fals quan té data de baixa: continua al catàleg però ja no és nostre. */
    actiu: boolean;
}

interface Props {
    vehicles: Vehicle[];
    combustibles: string[];
    tipusDespesa: Record<string, string>;
    /** Tipus que admet aquesta llista: cotxe i moto, o bici. */
    tipus: string[];
    titol: string;
    descripcio: string;
    /** Fals a les bicis: ni matrícula ni any de fabricació. */
    esMotor: boolean;
}

const props = defineProps<Props>();

/**
 * Al formulari el camp de la data es diu `dia`, i al servidor `data`.
 *
 * `data` és un mètode d'`useForm` —`form.data()` són els camps— i un camp que es digui
 * així el trepitja: l'input rep la funció com a valor i l'enviament peta amb «data is not
 * a function», sense que arribi cap petició al servidor.
 */
const perAlServidor = <T extends { dia: string }>({ dia, ...dades }: T) => ({ ...dades, data: dia });

const etiquetaTipus: Record<string, string> = {
    cotxe: 'Cotxe',
    moto: 'Moto',
    bici: 'Bicicleta',
    altres: 'Altres',
};

const colorTipus: Record<string, string> = {
    cotxe: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    moto: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    bici: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    altres: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

const mostraBaixes = ref(false);

const visibles = computed(() =>
    mostraBaixes.value ? props.vehicles : props.vehicles.filter((v) => v.actiu)
);

const donatsDeBaixa = computed(() => props.vehicles.filter((v) => !v.actiu).length);

// ---- Panell del vehicle ----
const repostatgesOberts = ref<number | null>(null);
const pestanya = ref<'anys' | 'repostatges' | 'despeses' | 'grafiques'>('anys');

const despesaForm = useForm({
    vehicle_id: 0,
    dia: '',
    tipus: 'reparacio',
    import: null as number | null,
    km_totals: null as number | null,
    taller: '',
    motiu: '',
});

/** Id de la despesa que s'està editant. Null mentre el formulari és d'alta. */
const despesaEditant = ref<number | null>(null);

function editaDespesa(d: Despesa) {
    despesaEditant.value = d.id;
    despesaForm.clearErrors();
    // De cop: `form.data` és alhora el camp i el mètode data() d'Inertia, i assignar-l'hi de una en una no tipa
    Object.assign(despesaForm, {
        dia: d.data,
        tipus: d.tipus,
        import: d.import,
        km_totals: d.km_totals,
        taller: d.taller ?? '',
        motiu: d.motiu ?? '',
    });
}

function cancelaEdicioDespesa() {
    despesaEditant.value = null;
    despesaForm.clearErrors();
    despesaForm.reset('dia', 'import', 'km_totals', 'taller', 'motiu');
}

function desaDespesa(vehicle: Vehicle) {
    despesaForm.vehicle_id = vehicle.id;

    const opcions = {
        preserveScroll: true,
        onSuccess: () => {
            despesaEditant.value = null;
            despesaForm.reset('dia', 'import', 'km_totals', 'taller', 'motiu');
        },
    };

    if (despesaEditant.value !== null) {
        despesaForm.transform(perAlServidor).put(route('vehicles.despeses.update', despesaEditant.value), opcions);
        return;
    }

    despesaForm.transform(perAlServidor).post(route('vehicles.despeses.store'), opcions);
}

function eliminaDespesa(d: Despesa) {
    if (!confirm(`Vols eliminar la despesa del ${d.data}?`)) return;
    if (despesaEditant.value === d.id) cancelaEdicioDespesa();
    router.delete(route('vehicles.despeses.destroy', d.id), { preserveScroll: true });
}


function obreRepostatges(vehicle: Vehicle) {
    repostatgesOberts.value = repostatgesOberts.value === vehicle.id ? null : vehicle.id;
    cancelaEdicioDespesa();
    cancelaEdicioRepostatge();
}

const repostatgeForm = useForm({
    vehicle_id: 0,
    dia: '',
    km_totals: null as number | null,
    preu_litre: null as number | null,
    cost: null as number | null,
    benzinera: '',
    diposit_ple: true,
    moviment_id: null as number | null,
    notes: '',
});

/** Moviments de combustible d'aquells dies que encara no són de cap repostatge. */
const movimentsProposats = ref<Array<{ id: number; data: string; import: number; compte: string | null }>>([]);

async function buscaMoviment() {
    movimentsProposats.value = [];
    if (!repostatgeForm.dia) return;

    // En edició cal passar-hi l'id: si no, el seu propi moviment surt com a ocupat i no es pot ni veure ni desvincular
    const url = route('vehicles.moviments-combustible') + '?data=' + repostatgeForm.dia
        + (repostatgeEditant.value !== null ? '&repostatge=' + repostatgeEditant.value : '');
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (res.ok) movimentsProposats.value = await res.json();
}

function triaMoviment(m: { id: number; import: number }) {
    repostatgeForm.moviment_id = repostatgeForm.moviment_id === m.id ? null : m.id;
    // El cost ja el sabem del banc: no cal escriure'l dues vegades
    if (repostatgeForm.moviment_id !== null) repostatgeForm.cost = m.import;
}

/** Id del repostatge que s'està editant. Null mentre el formulari és d'alta. */
const repostatgeEditant = ref<number | null>(null);

function editaRepostatge(r: Repostatge) {
    repostatgeEditant.value = r.id;
    repostatgeForm.clearErrors();
    Object.assign(repostatgeForm, {
        dia: r.data,
        km_totals: r.km_totals,
        preu_litre: r.preu_litre,
        cost: r.cost,
        benzinera: r.benzinera ?? '',
        diposit_ple: r.diposit_ple,
        moviment_id: r.moviment_id,
        notes: r.notes ?? '',
    });
    buscaMoviment();
}

function cancelaEdicioRepostatge() {
    repostatgeEditant.value = null;
    repostatgeForm.clearErrors();
    repostatgeForm.reset('dia', 'km_totals', 'preu_litre', 'cost', 'moviment_id', 'notes');
    movimentsProposats.value = [];
}

function desaRepostatge(vehicle: Vehicle) {
    repostatgeForm.vehicle_id = vehicle.id;

    const opcions = {
        preserveScroll: true,
        onSuccess: () => {
            repostatgeEditant.value = null;
            repostatgeForm.reset('dia', 'km_totals', 'preu_litre', 'cost', 'moviment_id', 'notes');
            movimentsProposats.value = [];
        },
    };

    if (repostatgeEditant.value !== null) {
        repostatgeForm.transform(perAlServidor).put(route('vehicles.repostatges.update', repostatgeEditant.value), opcions);
        return;
    }

    repostatgeForm.transform(perAlServidor).post(route('vehicles.repostatges.store'), opcions);
}

function eliminaRepostatge(r: Repostatge) {
    if (!confirm(`Vols eliminar el repostatge del ${r.data}?`)) return;
    if (repostatgeEditant.value === r.id) cancelaEdicioRepostatge();
    router.delete(route('vehicles.repostatges.destroy', r.id), { preserveScroll: true });
}

function formatEur(v: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(v);
}

// ---- Gràfiques ----

/**
 * Els dos colors de sèrie. Passen les sis comprovacions de paleta (banda de lluminositat,
 * croma, separació per a daltonisme i contrast) tant sobre fons clar com fosc, i per això
 * són els mateixos a les dues aparences: només canvien els textos i la quadrícula.
 */
const SERIE_BLAU  = '#0284c7';
const SERIE_AMBRE = '#d97706';

/** Tailwind va per `prefers-color-scheme`: aquí també, que chart.js pinta en canvas. */
const fosc = ref(false);
let consultaFosc: MediaQueryList | null = null;

function anotaFosc(e: MediaQueryListEvent | MediaQueryList) {
    fosc.value = e.matches;
}

onMounted(() => {
    consultaFosc = window.matchMedia('(prefers-color-scheme: dark)');
    anotaFosc(consultaFosc);
    consultaFosc.addEventListener('change', anotaFosc);
});

onUnmounted(() => consultaFosc?.removeEventListener('change', anotaFosc));

const tinta      = computed(() => (fosc.value ? '#9ca3af' : '#6b7280'));
const quadricula = computed(() => (fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'));

/** Migdiada local, no UTC: així la data no es desplaça un dia segons el fus. */
function msDe(data: string): number {
    return Date.parse(data + 'T00:00:00');
}

function formatData(ms: number): string {
    return new Date(ms).toLocaleDateString('ca-ES');
}

const vehicleObert = computed(() => props.vehicles.find((v) => v.id === repostatgesOberts.value) ?? null);

/** Del més antic al més nou: les gràfiques van cap endavant, la taula cap enrere. */
const cronologics = computed(() => [...(vehicleObert.value?.repostatges ?? [])].reverse());

/**
 * Consum de cada repostatge amb la mitjana de l'any a sobre.
 *
 * La mitjana és un tram pla que va del darrer repostatge de l'any anterior al darrer de
 * l'any: exactament el tram que ha mesurat la pestanya «Per any», ni més ni menys. Entre
 * un any i el següent hi va un punt buit perquè els dos trams no es lliguin amb una diagonal
 * que no vol dir res.
 */
const dadesConsum = computed<ChartData<'line'>>(() => {
    const mitjanes: Array<{ x: number; y: number | null }> = [];

    for (const a of [...(vehicleObert.value?.per_any ?? [])].reverse()) {
        if (a.consum === null) continue;

        const any      = String(a.any);
        const delAny   = cronologics.value.filter((r) => r.data.startsWith(any));
        const anteriors = cronologics.value.filter((r) => r.data < any);
        if (!delAny.length) continue;

        const inici = anteriors.length ? anteriors[anteriors.length - 1].data : delAny[0].data;
        const fi    = delAny[delAny.length - 1].data;

        mitjanes.push(
            { x: msDe(inici), y: a.consum },
            { x: msDe(fi), y: a.consum },
            { x: msDe(fi), y: null },
        );
    }

    return {
        datasets: [
            {
                label: 'Consum del repostatge',
                data: cronologics.value
                    .filter((r) => r.consum !== null)
                    .map((r) => ({ x: msDe(r.data), y: r.consum as number })),
                borderColor: SERIE_BLAU,
                backgroundColor: SERIE_BLAU,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointHitRadius: 10,
                tension: 0,
            },
            {
                label: 'Mitjana de l\'any',
                data: mitjanes,
                borderColor: SERIE_AMBRE,
                backgroundColor: SERIE_AMBRE,
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                pointHitRadius: 0,
                spanGaps: false,
            },
        ],
    };
});

/** Dies passats des del repostatge anterior, comptats al dia que es reposta. */
const dadesDies = computed<ChartData<'line'>>(() => ({
    datasets: [
        {
            label: 'Dies des de l\'anterior',
            data: cronologics.value.slice(1).map((r, i) => ({
                x: msDe(r.data),
                y: Math.round((msDe(r.data) - msDe(cronologics.value[i].data)) / 86400000),
            })),
            borderColor: SERIE_BLAU,
            backgroundColor: SERIE_BLAU,
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointHitRadius: 10,
            tension: 0,
        },
    ],
}));

/** El comptador tal com el diu cada repostatge. Només puja: si baixa, hi ha una errada. */
const dadesKm = computed<ChartData<'line'>>(() => ({
    datasets: [
        {
            label: 'Km totals',
            data: cronologics.value.map((r) => ({ x: msDe(r.data), y: r.km_totals })),
            borderColor: SERIE_BLAU,
            backgroundColor: SERIE_BLAU,
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointHitRadius: 10,
            tension: 0,
        },
    ],
}));

/**
 * Base de les tres: eix de temps proporcional (els repostatges no són a intervals
 * regulars) i creueta que segueix el ratolí.
 */
function opcionsBase(unitat: string, decimals: number, llegenda: boolean): ChartOptions<'line'> {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        plugins: {
            legend: {
                display: llegenda,
                position: 'top',
                align: 'end',
                labels: { color: tinta.value, boxWidth: 12, usePointStyle: true, pointStyle: 'line' },
            },
            tooltip: {
                callbacks: {
                    title: (items) => formatData(Number(items[0]?.parsed.x)),
                    label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.parsed.y).toFixed(decimals)} ${unitat}`,
                },
            },
        },
        scales: {
            x: {
                type: 'time',
                time: { displayFormats: { day: 'dd/MM/yy', month: 'MM/yy', year: 'yyyy' } },
                ticks: { color: tinta.value, maxTicksLimit: 10, maxRotation: 0 },
                grid: { color: quadricula.value },
            },
            y: {
                ticks: {
                    color: tinta.value,
                    callback: (v) => Number(v).toLocaleString('ca-ES'),
                },
                grid: { color: quadricula.value },
            },
        },
    };
}

const opcionsConsum = computed(() => opcionsBase('L/100 km', 2, true));
const opcionsDies   = computed(() => opcionsBase('dies', 0, false));
const opcionsKm     = computed(() => opcionsBase('km', 0, false));

// ---- Alta i edició ----
const showModal = ref(false);
const editant = ref<Vehicle | null>(null);

const form = useForm({
    nom: '',
    tipus: props.tipus[0],
    combustible: '' as string | null,
    marca: '',
    model: '',
    matricula: '',
    any_fabricacio: null as number | null,
    data_alta: '' as string | null,
    data_baixa: '' as string | null,
    notes: '',
    ordre: 0,
});

function obreNou() {
    editant.value = null;
    form.reset();
    form.tipus = props.tipus[0];
    form.clearErrors();
    showModal.value = true;
}

function obreEdicio(vehicle: Vehicle) {
    editant.value = vehicle;
    form.clearErrors();
    form.nom = vehicle.nom;
    form.tipus = vehicle.tipus;
    form.combustible = vehicle.combustible ?? '';
    form.marca = vehicle.marca ?? '';
    form.model = vehicle.model ?? '';
    form.matricula = vehicle.matricula ?? '';
    form.any_fabricacio = vehicle.any_fabricacio;
    form.data_alta = vehicle.data_alta ?? '';
    form.data_baixa = vehicle.data_baixa ?? '';
    form.notes = vehicle.notes ?? '';
    form.ordre = vehicle.ordre;
    showModal.value = true;
}

function desa() {
    const opcions = { onSuccess: () => (showModal.value = false) };

    if (editant.value) {
        form.put(route('vehicles.update', editant.value.id), opcions);
        return;
    }

    form.post(route('vehicles.store'), opcions);
}

function elimina(vehicle: Vehicle) {
    if (!confirm(`Vols eliminar «${vehicle.nom}»?`)) return;
    router.delete(route('vehicles.destroy', vehicle.id));
}
</script>

<template>
    <Head :title="props.titol" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {{ props.titol }}
                </h2>
                <button
                    @click="obreNou"
                    class="inline-flex items-center rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-sky-700"
                >
                    Afegeix-ne un
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ props.descripcio }}</p>
                            <label v-if="donatsDeBaixa" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input v-model="mostraBaixes" type="checkbox"
                                    class="rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700" />
                                Mostra els {{ donatsDeBaixa }} donats de baixa
                            </label>
                        </div>

                        <div v-if="visibles.length" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Vehicle</th>
                                        <th v-if="props.tipus.length > 1" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                        <th v-if="props.esMotor" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Matrícula</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Des de</th>
                                        <th class="w-28 px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <template v-for="v in visibles" :key="v.id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40" :class="{ 'opacity-60': !v.actiu }">
                                        <td class="px-4 py-3">
                                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ v.nom }}</span>
                                            <span v-if="v.marca || v.model" class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                                {{ [v.marca, v.model].filter(Boolean).join(' ') }}
                                                <span v-if="v.any_fabricacio">· {{ v.any_fabricacio }}</span>
                                            </span>
                                            <span v-if="v.combustible" class="ml-2 text-xs text-gray-400 dark:text-gray-500">{{ v.combustible }}</span>
                                            <span v-if="!v.actiu" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                de baixa {{ v.data_baixa }}
                                            </span>
                                        </td>
                                        <td v-if="props.tipus.length > 1" class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="colorTipus[v.tipus] ?? colorTipus.altres">
                                                {{ etiquetaTipus[v.tipus] ?? v.tipus }}
                                            </span>
                                        </td>
                                        <td v-if="props.esMotor" class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ v.matricula ?? '—' }}</td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ v.data_alta ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <button v-if="v.combustible" @click="obreRepostatges(v)" class="mr-3 text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">
                                                {{ repostatgesOberts === v.id ? 'Tanca' : 'Repostatges' }}
                                            </button>
                                            <button @click="obreEdicio(v)" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">Edita</button>
                                            <button @click="elimina(v)" class="ml-3 text-sm text-red-600 hover:text-red-800 dark:text-red-400">Elimina</button>
                                        </td>
                                    </tr>

                                    <!-- Repostatges: només als vehicles que reposten -->
                                    <tr v-if="repostatgesOberts === v.id" class="bg-gray-50 dark:bg-gray-700/30">
                                        <td :colspan="props.tipus.length > 1 ? (props.esMotor ? 5 : 4) : (props.esMotor ? 4 : 3)" class="px-4 py-3">
                                            <div class="mb-3 flex gap-1 border-b border-gray-200 dark:border-gray-600">
                                                <button v-for="[clau, etiqueta] in [['repostatges','Repostatges'],['despeses','Despeses'],['anys','Per any'],['grafiques','Gràfiques']]"
                                                    :key="clau" @click="pestanya = clau as any"
                                                    class="px-3 py-1.5 text-xs font-medium transition-colors"
                                                    :class="pestanya === clau
                                                        ? 'border-b-2 border-sky-500 text-sky-600 dark:text-sky-400'
                                                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'">
                                                    {{ etiqueta }}
                                                </button>
                                            </div>

                                            <!-- Per any: km, combustible i despeses -->
                                            <div v-if="pestanya === 'anys'" class="overflow-x-auto">
                                                <table class="min-w-full text-sm">
                                                    <thead>
                                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                                            <th class="pb-1 pr-3 font-medium">Any</th>
                                                            <th class="pb-1 pr-3 text-right font-medium" title="Del darrer repostatge de l'any al darrer de l'any anterior. És el tram que té litres al costat, i per això és el que dona el consum.">
                                                                Km entre repostatges <span class="text-gray-400">ⓘ</span>
                                                            </th>
                                                            <th class="pb-1 pr-3 text-right font-medium" title="El mateix, però comptant-hi també les lectures del comptador apuntades a les despeses (taller, ITV). És el que s'ha rodat de veritat, i el que divideix el cost.">
                                                                Km rodats <span class="text-gray-400">ⓘ</span>
                                                            </th>
                                                            <th class="pb-1 pr-3 text-right font-medium">Litres</th>
                                                            <th class="pb-1 pr-3 text-right font-medium">L/100 km</th>
                                                            <th class="pb-1 pr-3 text-right font-medium">Combustible</th>
                                                            <th class="pb-1 pr-3 text-right font-medium">Altres despeses</th>
                                                            <th class="pb-1 pr-3 text-right font-medium">Total</th>
                                                            <th class="pb-1 text-right font-medium">€/km</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                        <tr v-for="a in v.per_any" :key="a.any">
                                                            <td class="py-1 pr-3 font-medium text-gray-900 dark:text-gray-100">{{ a.any }}</td>
                                                            <td class="py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                                                {{ a.km !== null ? a.km.toLocaleString('ca-ES') : '—' }}
                                                            </td>
                                                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                                                {{ a.km_estimats !== null ? a.km_estimats.toLocaleString('ca-ES') : '—' }}
                                                            </td>
                                                            <td class="py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ a.litres.toFixed(1) }}</td>
                                                            <td class="py-1 pr-3 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ a.consum?.toFixed(2) ?? '—' }}</td>
                                                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(a.carburant) }}</td>
                                                            <td class="py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(a.altres) }}</td>
                                                            <td class="py-1 pr-3 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(a.total) }}</td>
                                                            <td class="py-1 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ a.cost_km?.toFixed(3) ?? '—' }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                                    Totes dues columnes de quilòmetres són la darrera lectura del comptador de l'any menys la
                                                    darrera d'abans que comencés. <strong>Km entre repostatges</strong> només mira els repostatges:
                                                    es queda curta —del darrer ple a Cap d'Any encara s'hi roda—, però és l'únic tram que té els
                                                    litres al costat i per tant l'únic que dona un <strong>L/100 km</strong> comparable d'un any a
                                                    l'altre. <strong>Km rodats</strong> hi afegeix les lectures apuntades a les despeses (taller,
                                                    ITV): és la millor estimació del que s'ha fet, i la que divideix el cost per a treure els
                                                    <strong>€/km</strong>. El primer any no té cap lectura anterior i per això no se saben.
                                                </p>
                                            </div>

                                            <!-- Gràfiques: el que diuen els repostatges dibuixat -->
                                            <div v-if="pestanya === 'grafiques'">
                                                <p v-if="cronologics.length < 2" class="py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                                                    Amb menys de dos repostatges no hi ha res a dibuixar.
                                                </p>

                                                <div v-else class="space-y-8">
                                                    <div>
                                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                            Consum de cada repostatge
                                                            <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">L/100 km</span>
                                                        </h4>
                                                        <p class="mb-1 text-xs text-gray-400 dark:text-gray-500">
                                                            Cada punt és un ple: els litres que hi han cabut sobre els quilòmetres fets des del ple
                                                            anterior. Els repostatges a mig dipòsit no hi surten, perquè els seus litres no són els
                                                            gastats. El tram pla és la mitjana de l'any de la pestanya «Per any», dibuixada damunt del
                                                            tros que ha mesurat.
                                                        </p>
                                                        <div class="h-64"><Line :data="dadesConsum" :options="opcionsConsum" /></div>
                                                    </div>

                                                    <div>
                                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                            Dies entre repostatges
                                                            <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">dies</span>
                                                        </h4>
                                                        <p class="mb-1 text-xs text-gray-400 dark:text-gray-500">
                                                            Quant s'ha trigat a tornar a omplir, apuntat el dia que es reposta.
                                                        </p>
                                                        <div class="h-56"><Line :data="dadesDies" :options="opcionsDies" /></div>
                                                    </div>

                                                    <div>
                                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                            Comptador
                                                            <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">km totals</span>
                                                        </h4>
                                                        <p class="mb-1 text-xs text-gray-400 dark:text-gray-500">
                                                            La lectura de cada repostatge. Només hi surten les dels repostatges: les del taller, que
                                                            també compten als «Km rodats», no.
                                                        </p>
                                                        <div class="h-56"><Line :data="dadesKm" :options="opcionsKm" /></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <h4 v-if="pestanya === 'repostatges'" class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                                Repostatges de {{ v.combustible }}
                                            </h4>

                                            <!-- El que diuen tots junts -->
                                            <div v-if="v.resum && pestanya === 'repostatges'" class="mb-3 flex flex-wrap gap-4 rounded-md bg-white px-3 py-2 text-xs shadow-sm dark:bg-gray-800">
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    {{ v.resum.repostatges }} repostatges · {{ v.resum.des_de }} → {{ v.resum.fins_a }}
                                                </span>
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    Km: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ v.resum.km.toLocaleString('ca-ES') }}</span>
                                                </span>
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    Litres: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ v.resum.litres.toLocaleString('ca-ES') }}</span>
                                                </span>
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    Consum mitjà: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ v.resum.consum?.toFixed(2) }} L/100 km</span>
                                                </span>
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    Cost: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(v.resum.cost) }}</span>
                                                    <span v-if="v.resum.cost_100km"> · {{ formatEur(v.resum.cost_100km) }}/100 km</span>
                                                </span>
                                            </div>

                                            <datalist id="benzineres">
                                                <option v-for="b in [...new Set(v.repostatges.map(r => r.benzinera).filter(Boolean))]" :key="b as string" :value="b" />
                                            </datalist>

                                            <!-- Apuntar-ne un de nou -->
                                            <div v-if="pestanya === 'repostatges'" class="mb-3 flex flex-wrap items-end gap-2 border-b border-gray-200 pb-3 dark:border-gray-600">
                                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                                    Data
                                                    <input v-model="repostatgeForm.dia" @change="buscaMoviment" type="date"
                                                        class="mt-0.5 block w-36 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                                    Km totals
                                                    <input v-model.number="repostatgeForm.km_totals" type="number"
                                                        class="mt-0.5 block w-28 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                                    Preu/litre
                                                    <input v-model.number="repostatgeForm.preu_litre" type="number" step="0.001"
                                                        class="mt-0.5 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                                    Cost
                                                    <input v-model.number="repostatgeForm.cost" type="number" step="0.01"
                                                        class="mt-0.5 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                                    Benzinera
                                                    <input v-model="repostatgeForm.benzinera" type="text" list="benzineres"
                                                        class="mt-0.5 block w-40 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                </label>
                                                <label class="flex items-center gap-1 pb-2 text-xs text-gray-500 dark:text-gray-400">
                                                    <input v-model="repostatgeForm.diposit_ple" type="checkbox"
                                                        class="rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700" />
                                                    ple
                                                </label>
                                                <button @click="desaRepostatge(v)" :disabled="repostatgeForm.processing || !repostatgeForm.dia || repostatgeForm.km_totals === null"
                                                    class="mb-1 rounded-md bg-sky-600 px-3 py-1 text-sm text-white hover:bg-sky-700 disabled:opacity-40">
                                                    {{ repostatgeEditant === null ? 'Afegeix' : 'Desa' }}
                                                </button>
                                                <button v-if="repostatgeEditant !== null" @click="cancelaEdicioRepostatge"
                                                    class="mb-1 px-2 py-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                                    Cancel·la
                                                </button>
                                            </div>

                                            <!-- El moviment del banc d'aquells dies, per no escriure el cost dues vegades -->
                                            <div v-if="movimentsProposats.length && pestanya === 'repostatges'" class="mt-2 text-xs">
                                                <span class="text-gray-500 dark:text-gray-400">Moviments de combustible d'aquells dies:</span>
                                                <button v-for="m in movimentsProposats" :key="m.id" @click="triaMoviment(m)"
                                                    class="ml-2 rounded-full px-2 py-0.5"
                                                    :class="repostatgeForm.moviment_id === m.id
                                                        ? 'bg-sky-600 text-white'
                                                        : 'bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300'">
                                                    {{ m.data }} · {{ formatEur(m.import) }}<span v-if="m.compte"> · {{ m.compte }}</span>
                                                </button>
                                            </div>

                                            <p v-if="repostatgeForm.errors.km_totals || repostatgeForm.errors.cost || repostatgeForm.errors.preu_litre"
                                                class="mt-1 text-xs text-red-600 dark:text-red-400">
                                                {{ repostatgeForm.errors.km_totals || repostatgeForm.errors.cost || repostatgeForm.errors.preu_litre }}
                                            </p>

                                            <!-- Despeses: reparacions, ITV, assegurança i impost -->
                                            <div v-if="pestanya === 'despeses'">
                                                <div class="mb-3 flex flex-wrap items-end gap-2 border-b border-gray-200 pb-3 dark:border-gray-600">
                                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                                        Data
                                                        <input v-model="despesaForm.dia" type="date"
                                                            class="mt-0.5 block w-36 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                    </label>
                                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                                        Tipus
                                                        <select v-model="despesaForm.tipus"
                                                            class="mt-0.5 block w-44 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                            <option v-for="(nom, clau) in props.tipusDespesa" :key="clau" :value="clau">{{ nom }}</option>
                                                        </select>
                                                    </label>
                                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                                        Import
                                                        <input v-model.number="despesaForm.import" type="number" step="0.01"
                                                            class="mt-0.5 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                    </label>
                                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                                        Km totals
                                                        <input v-model.number="despesaForm.km_totals" type="number" placeholder="opcional"
                                                            class="mt-0.5 block w-28 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                    </label>
                                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                                        Taller
                                                        <input v-model="despesaForm.taller" type="text" list="tallers"
                                                            class="mt-0.5 block w-40 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                    </label>
                                                    <label class="min-w-[10rem] flex-1 text-xs text-gray-500 dark:text-gray-400">
                                                        Motiu
                                                        <input v-model="despesaForm.motiu" type="text"
                                                            class="mt-0.5 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                                    </label>
                                                    <button @click="desaDespesa(v)" :disabled="despesaForm.processing || !despesaForm.dia || despesaForm.import === null"
                                                        class="mb-1 rounded-md bg-sky-600 px-3 py-1 text-sm text-white hover:bg-sky-700 disabled:opacity-40">
                                                        {{ despesaEditant === null ? 'Afegeix' : 'Desa' }}
                                                    </button>
                                                    <button v-if="despesaEditant !== null" @click="cancelaEdicioDespesa"
                                                        class="mb-1 px-2 py-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                                        Cancel·la
                                                    </button>
                                                </div>

                                                <datalist id="tallers">
                                                    <option v-for="t in [...new Set(v.despeses.map(d => d.taller).filter(Boolean))]" :key="t as string" :value="t" />
                                                </datalist>

                                                <div class="overflow-x-auto">
                                                    <table class="min-w-full text-sm">
                                                        <thead>
                                                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                                                <th class="whitespace-nowrap pb-1 pr-3 font-medium">Data</th>
                                                                <th class="whitespace-nowrap pb-1 pr-3 font-medium">Tipus</th>
                                                                <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Import</th>
                                                                <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Km totals</th>
                                                                <th class="whitespace-nowrap pb-1 pr-3 font-medium">Taller</th>
                                                                <th class="w-full pb-1 pr-3 font-medium">Motiu</th>
                                                                <th class="w-16"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                            <tr v-for="d in v.despeses" :key="d.id"
                                                                :class="despesaEditant === d.id ? 'bg-sky-50 dark:bg-sky-900/20' : ''">
                                                                <td class="whitespace-nowrap py-1 pr-3 text-gray-600 dark:text-gray-400">{{ d.data }}</td>
                                                                <td class="whitespace-nowrap py-1 pr-3">
                                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ d.tipus_nom }}</span>
                                                                </td>
                                                                <td class="whitespace-nowrap py-1 pr-3 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(d.import) }}</td>
                                                                <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                                                    {{ d.km_totals !== null ? d.km_totals.toLocaleString('ca-ES') : '—' }}
                                                                </td>
                                                                <td class="whitespace-nowrap py-1 pr-3 text-xs text-gray-500 dark:text-gray-400">{{ d.taller }}</td>
                                                                <td class="w-full max-w-0 truncate py-1 pr-3 text-xs text-gray-500 dark:text-gray-400" :title="d.motiu ?? ''">{{ d.motiu }}</td>
                                                                <td class="whitespace-nowrap py-1 text-right">
                                                                    <button @click="editaDespesa(d)" title="Edita"
                                                                        class="text-xs text-sky-600 hover:text-sky-800 dark:text-sky-400">✎</button>
                                                                    <button @click="eliminaDespesa(d)" title="Elimina"
                                                                        class="ml-2 text-xs text-red-500 hover:text-red-700">×</button>
                                                                </td>
                                                            </tr>
                                                            <tr v-if="!v.despeses.length">
                                                                <td colspan="7" class="py-2 text-xs text-gray-400 dark:text-gray-500">Encara no hi ha cap despesa.</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div v-if="pestanya === 'repostatges'" class="overflow-x-auto">
                                                <table class="min-w-full text-sm">
                                                    <thead>
                                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                                                            <th class="whitespace-nowrap pb-1 pr-3 font-medium">Data</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Km totals</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Km fets</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Preu/litre</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Cost</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Litres</th>
                                                            <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">L/100 km</th>
                                                            <th class="w-full pb-1 pr-3 font-medium">Benzinera</th>
                                                            <th class="w-16"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                        <tr v-for="r in v.repostatges" :key="r.id"
                                                            :class="repostatgeEditant === r.id ? 'bg-sky-50 dark:bg-sky-900/20' : ''">
                                                            <td class="whitespace-nowrap py-1 pr-3 text-gray-600 dark:text-gray-400">
                                                                {{ r.data }}
                                                                <span v-if="r.moviment_id" title="Lligat amb el moviment del banc" class="ml-1 text-xs text-sky-500">🔗</span>
                                                                <span v-if="!r.diposit_ple" title="No es va omplir el dipòsit" class="ml-1 text-xs text-amber-600">½</span>
                                                            </td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ r.km_totals.toLocaleString('ca-ES') }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ r.km ?? '—' }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ r.preu_litre.toFixed(3) }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(r.cost) }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ r.litres?.toFixed(2) ?? '—' }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ r.consum?.toFixed(2) ?? '—' }}</td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-xs text-gray-500 dark:text-gray-400">{{ r.benzinera }}</td>
                                                            <td class="whitespace-nowrap py-1 text-right">
                                                                <button @click="editaRepostatge(r)" title="Edita"
                                                                    class="text-xs text-sky-600 hover:text-sky-800 dark:text-sky-400">✎</button>
                                                                <button @click="eliminaRepostatge(r)" title="Elimina"
                                                                    class="ml-2 text-xs text-red-500 hover:text-red-700">×</button>
                                                            </td>
                                                        </tr>
                                                        <tr v-if="!v.repostatges.length">
                                                            <td colspan="9" class="py-2 text-xs text-gray-400 dark:text-gray-500">Encara no hi ha cap repostatge.</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <p v-else class="rounded-lg border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                            Encara no n'hi ha cap. Comença per afegir-ne un.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="lg" @close="showModal = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editant ? 'Edita el vehicle' : 'Nou vehicle' }}
                </h3>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom</label>
                            <input v-model="form.nom" type="text" placeholder="p. ex. Peugeot Partner"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.nom }}</p>
                        </div>
                        <div v-if="props.tipus.length > 1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                            <select v-model="form.tipus"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="t in props.tipus" :key="t" :value="t">{{ etiquetaTipus[t] ?? t }}</option>
                            </select>
                            <p v-if="form.errors.tipus" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.tipus }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Marca</label>
                            <input v-model="form.marca" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Model</label>
                            <input v-model="form.model" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div v-if="props.esMotor">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Combustible</label>
                            <select v-model="form.combustible"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="">— cap —</option>
                                <option v-for="c in props.combustibles" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <p v-if="form.errors.combustible" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.combustible }}</p>
                        </div>
                        <div v-if="props.esMotor">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Matrícula</label>
                            <input v-model="form.matricula" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Any de fabricació</label>
                            <input v-model.number="form.any_fabricacio" type="number" min="1900" max="2100"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="form.errors.any_fabricacio" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.any_fabricacio }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data d'alta</label>
                            <input v-model="form.data_alta" type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data de baixa</label>
                            <input v-model="form.data_baixa" type="date" :min="form.data_alta || undefined"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Deixa-ho buit mentre sigui teu.</p>
                            <p v-if="form.errors.data_baixa" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.data_baixa }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="form.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showModal = false" :disabled="form.processing"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Cancel·la
                    </button>
                    <button @click="desa" :disabled="form.processing"
                        class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-40">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
