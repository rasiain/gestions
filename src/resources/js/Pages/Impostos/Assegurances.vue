<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MovimentDetall {
    data: string;
    import: number;
    compte: string | null;
    /** Darrers 4 dígits de l'IBAN: identifica el compte quan el nom no hi cap. */
    compte_digits: string | null;
}

/** Comparació d'una pòlissa amb l'any anterior. */
interface EstatPolissa {
    pagat: number;
    pagaments: number;
    /** Any anterior retallat al mateix dia: l'única comparació honesta d'un any començat. */
    anterior_a_data: number;
    anterior_total: number;
    any_incomplet: boolean;
    variacio: number;
    variacio_pct: number | null;
    periodicitat: string;
    carrecs_any: number | null;
    /** Càrrec més gran dels últims dotze mesos: el rebut de la pòlissa. */
    prima: number | null;
    data_prima: string | null;
    prima_anterior: number | null;
    prima_variacio_pct: number | null;
    /** Indemnitzacions i retorns de prima. Mai no es resten del pagat. */
    retornat: number;
    /** Pagaments d'un immoble de lloguer que no passen per les despeses. */
    sense_classificar: number;
}

interface Polissa {
    tipus: string;
    companyies: string[];
    estat: EstatPolissa | null;
    moviments_actual: MovimentDetall[];
    moviments_anterior: MovimentDetall[];
}

interface ObjecteGrup {
    objecte: string;
    poblacio: string | null;
    lloguer: string | null;
    grup: string;
    immoble_id: number | null;
    seccio: 'lloguer' | 'identificat' | 'resta';
    /** Path complet de les categories: com s'ha detectat la pòlissa. */
    paths: string[];
    polisses: Polissa[];
    total_actual: number;
    total_anterior: number;
    total_a_data: number;
}

interface PoblacioGrup {
    poblacio: string | null;
    objectes: ObjecteGrup[];
}

interface Seccio {
    clau: 'lloguer' | 'identificat' | 'resta';
    titol: string;
    descripcio: string;
    poblacions: PoblacioGrup[];
}

interface MovimentLlista {
    id: number;
    compte_corrent_id: number;
    data: string;
    compte: string | null;
    objecte: string;
    tipus: string;
    companyia: string | null;
    concepte: string;
    concepte_editable: string | null;
    concepte_id: number | null;
    notes: string | null;
    import: number;
    categoria_id: number | null;
    categoria_nom: string | null;
}

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
    full_path?: string;
}

interface Props {
    seccions: Seccio[];
    moviments: MovimentLlista[];
    anyActual: number;
    anyAnterior: number;
    anysDisponibles: number[];
    dataReferencia: string;
    categoriesPerCompte: Record<number, Categoria[]>;
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

/** "2026-08-20" → "20 d'agost" */
function formatDiaMes(iso: string): string {
    return new Intl.DateTimeFormat('ca-ES', { day: 'numeric', month: 'long' }).format(new Date(iso));
}

function canviarAny(event: Event) {
    const target = event.target as HTMLSelectElement;
    router.get(route('impostos.assegurances'), { any: target.value }, { preserveState: true, preserveScroll: true });
}

const anyOpcions = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

const tab = ref<'polissa' | 'moviments'>('polissa');

// ---- Vista 1 ----
const seccionsAmbDades = computed(() => props.seccions.filter((s) => s.poblacions.length > 0));
const capPolissa = computed(() => seccionsAmbDades.value.length === 0);

/** L'any en curs encara no s'ha acabat: la comparativa és a data. */
const anyEnCurs = computed(() => !props.dataReferencia.endsWith('-12-31'));

const estilSeccio: Record<Seccio['clau'], { vora: string; pastilla: string; titol: string; icona: string }> = {
    lloguer: {
        vora: 'border-amber-400',
        pastilla: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        titol: 'text-amber-800 dark:text-amber-300',
        // Casa
        icona: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    },
    identificat: {
        vora: 'border-slate-400',
        pastilla: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
        titol: 'text-slate-700 dark:text-slate-200',
        // Edifici
        icona: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    },
    resta: {
        vora: 'border-gray-300 dark:border-gray-600',
        pastilla: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
        titol: 'text-gray-600 dark:text-gray-300',
        // Escut
        icona: 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
    },
};

function objectesDeSeccio(seccio: Seccio): ObjecteGrup[] {
    return seccio.poblacions.flatMap((p) => p.objectes);
}

function totalSeccio(seccio: Seccio, camp: 'total_actual' | 'total_anterior' | 'total_a_data'): number {
    return objectesDeSeccio(seccio).reduce((acc, g) => acc + g[camp], 0);
}

const etiquetaPeriodicitat: Record<string, string> = {
    mensual: 'mensual',
    bimestral: 'bimestral',
    trimestral: 'trimestral',
    quadrimestral: 'quadrimestral',
    semestral: 'semestral',
    anual: 'anual',
    irregular: 'irregular',
    inactiva: 'sense càrrecs',
};

/** Pujar de preu és vermell; abaratir-se, verd. Sota mig punt, ni una cosa ni l'altra. */
function colorVariacio(pct: number | null): string {
    if (pct === null) return 'text-gray-300 dark:text-gray-600';
    if (pct > 0.5) return 'text-red-600 dark:text-red-400';
    if (pct < -0.5) return 'text-green-600 dark:text-green-400';
    return 'text-gray-400';
}

function signe(valor: number): string {
    return valor > 0 ? '+' : '';
}

// ---- Modal detall ----
const showDetall = ref(false);
const detallTitol = ref('');
const detallMoviments = ref<MovimentDetall[]>([]);
const detallTotal = ref(0);

function obreDetall(titol: string, moviments: MovimentDetall[]) {
    if (moviments.length === 0) return;
    detallTitol.value = titol;
    detallMoviments.value = moviments;
    detallTotal.value = moviments.reduce((acc, m) => acc + m.import, 0);
    showDetall.value = true;
}

// ---- Filtres Vista 2 ----
const filtreAny = ref<string>('');
const filtreTipus = ref<string>('');
const filtreCompte = ref<string>('');
const filtreConcepte = ref<string>('');

function normalitza(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const tipusDisponibles = computed(() => [...new Set(props.moviments.map((m) => m.tipus))].sort());
const comptesDisponibles = computed(() =>
    [...new Set(props.moviments.map((m) => m.compte).filter((c): c is string => !!c))].sort()
);

function conceptePresentat(m: MovimentLlista): string {
    return m.concepte_editable ?? m.concepte;
}

const movimentsFiltrats = computed(() => {
    const cerca = normalitza(filtreConcepte.value.trim());
    return props.moviments.filter((m) => {
        if (filtreAny.value && !m.data.startsWith(filtreAny.value)) return false;
        if (filtreTipus.value && m.tipus !== filtreTipus.value) return false;
        if (filtreCompte.value && m.compte !== filtreCompte.value) return false;
        if (
            cerca &&
            !normalitza(m.concepte_editable ?? '').includes(cerca) &&
            !normalitza(m.concepte).includes(cerca) &&
            !normalitza(m.objecte).includes(cerca)
        ) {
            return false;
        }
        return true;
    });
});

const totalFiltrat = computed(() => movimentsFiltrats.value.reduce((acc, m) => acc + m.import, 0));

// ---- Edició de moviment ----
const showEdit = ref(false);
const editMov = ref<MovimentLlista | null>(null);

const editForm = useForm({
    compte_corrent_id: 0,
    data_moviment: '',
    concepte: '',
    notes: '' as string | null,
    import: 0 as number | string,
    categoria_id: null as number | null,
});

const categoriesEditCompte = computed<Categoria[]>(() =>
    editMov.value ? (props.categoriesPerCompte[editMov.value.compte_corrent_id] ?? []) : []
);

function obreEdicio(m: MovimentLlista) {
    editMov.value = m;
    editForm.clearErrors();
    editForm.compte_corrent_id = m.compte_corrent_id;
    editForm.data_moviment = m.data;
    editForm.concepte = m.concepte_editable ?? m.concepte;
    editForm.notes = m.notes;
    editForm.import = m.import;
    editForm.categoria_id = m.categoria_id;
    showEdit.value = true;
}

function tancaEdicio() {
    showEdit.value = false;
    editMov.value = null;
    editForm.reset();
    editForm.clearErrors();
}

function desaEdicio() {
    if (!editMov.value) return;
    editForm.put(route('moviments.update', editMov.value.id), {
        preserveScroll: true,
        onSuccess: () => tancaEdicio(),
    });
}
</script>

<template>
    <Head title="Assegurances" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Assegurances — pòlisses de tots els comptes
                </h2>
                <select
                    v-if="tab === 'polissa'"
                    :value="props.anyActual"
                    @change="canviarAny"
                    class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                >
                    <option v-for="a in anyOpcions" :key="a" :value="a">{{ a }}</option>
                </select>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="mb-6 flex gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button
                        @click="tab = 'polissa'"
                        :class="tab === 'polissa'
                            ? 'border-b-2 border-red-500 text-red-600 dark:text-red-400'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                    >
                        Per pòlissa
                    </button>
                    <button
                        @click="tab = 'moviments'"
                        :class="tab === 'moviments'
                            ? 'border-b-2 border-red-500 text-red-600 dark:text-red-400'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                    >
                        Tots els moviments
                    </button>
                </div>

                <!-- Vista 1 — Per pòlissa -->
                <div v-if="tab === 'polissa'" class="space-y-10">
                    <p v-if="capPolissa" class="text-sm text-gray-500 dark:text-gray-400">
                        No s'ha trobat cap assegurança per als anys {{ props.anyAnterior }}–{{ props.anyActual }}.
                    </p>

                    <p v-else-if="anyEnCurs" class="rounded-md bg-blue-50 px-4 py-2 text-sm text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
                        L'any {{ props.anyActual }} encara corre: la columna de comparació retalla el
                        {{ props.anyAnterior }} al {{ formatDiaMes(props.dataReferencia) }}, perquè els dos
                        períodes siguin el mateix. L'any {{ props.anyAnterior }} sencer hi és a la columna del costat.
                    </p>

                    <section v-for="seccio in seccionsAmbDades" :key="seccio.clau">
                        <div
                            class="rounded-lg border-l-8 bg-white px-4 py-3 shadow-sm dark:bg-gray-800"
                            :class="estilSeccio[seccio.clau].vora"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" :class="estilSeccio[seccio.clau].pastilla">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="estilSeccio[seccio.clau].icona" />
                                        </svg>
                                    </span>
                                    <div>
                                        <h2 class="text-lg font-bold leading-tight" :class="estilSeccio[seccio.clau].titol">
                                            {{ seccio.titol }}
                                            <span class="ml-1 align-middle text-xs font-medium text-gray-400 dark:text-gray-500">
                                                {{ objectesDeSeccio(seccio).length }}
                                                {{ objectesDeSeccio(seccio).length === 1 ? 'grup' : 'grups' }}
                                            </span>
                                        </h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ seccio.descripcio }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-6 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ props.anyActual }}:
                                        <span class="font-bold text-gray-900 dark:text-gray-100">{{ formatEur(totalSeccio(seccio, 'total_actual')) }}</span>
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ props.anyAnterior }}:
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(totalSeccio(seccio, 'total_anterior')) }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div v-for="pob in seccio.poblacions" :key="pob.poblacio ?? '—'" class="mt-4 space-y-4">
                            <h3
                                v-if="pob.poblacio"
                                class="flex items-center gap-3 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400"
                            >
                                {{ pob.poblacio }}
                                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                            </h3>

                            <div
                                v-for="grup in pob.objectes"
                                :key="grup.grup"
                                class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800"
                            >
                                <div class="flex items-center justify-between border-l-4 bg-gray-50 px-4 py-3 dark:bg-gray-700/50" :class="estilSeccio[seccio.clau].vora">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ grup.objecte }}
                                        <span
                                            v-if="grup.lloguer && grup.lloguer !== grup.objecte"
                                            class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                                        >
                                            {{ grup.lloguer }}
                                        </span>
                                    </h3>
                                    <div class="flex gap-6 text-sm">
                                        <span class="text-gray-500 dark:text-gray-400">
                                            {{ props.anyActual }}:
                                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(grup.total_actual) }}</span>
                                        </span>
                                        <span class="text-gray-500 dark:text-gray-400">
                                            {{ props.anyAnterior }}:
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(grup.total_anterior) }}</span>
                                        </span>
                                    </div>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="w-[14%] px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Pòlissa</th>
                                                <th class="w-[18%] px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Companyia</th>
                                                <th class="w-[11%] px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Periodicitat</th>
                                                <th class="w-[13%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-900 dark:text-gray-100">Pagat {{ props.anyActual }}</th>
                                                <th class="w-[13%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                                    {{ props.anyAnterior }}<span v-if="anyEnCurs"> a data</span>
                                                </th>
                                                <th class="w-[10%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Variació</th>
                                                <th class="w-[10%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ props.anyAnterior }} sencer</th>
                                                <th class="w-[11%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Prima</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <tr v-for="p in grup.polisses" :key="p.tipus" class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                                <td class="px-4 py-2 text-sm font-medium text-gray-800 dark:text-gray-200">
                                                    {{ p.tipus }}
                                                    <span
                                                        v-if="grup.seccio === 'lloguer' && p.estat && p.estat.sense_classificar > 0"
                                                        class="mt-0.5 block text-xs font-normal text-amber-700 dark:text-amber-400"
                                                        :title="'Pagaments d\'un immoble de lloguer que no estan classificats com a despesa: no es dedueixen a l\'IRPF'"
                                                    >
                                                        ⚠ {{ p.estat.sense_classificar }} sense classificar
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                                                    <span v-if="p.companyies.length">{{ p.companyies.join(' · ') }}</span>
                                                    <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                                </td>
                                                <td class="px-4 py-2 text-sm">
                                                    <span
                                                        v-if="p.estat"
                                                        class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                                    >
                                                        {{ etiquetaPeriodicitat[p.estat.periodicitat] ?? p.estat.periodicitat }}
                                                    </span>
                                                </td>
                                                <td
                                                    class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-gray-900 dark:text-gray-100"
                                                    :class="{ 'cursor-pointer hover:underline': p.moviments_actual.length > 0 }"
                                                    @click="obreDetall(grup.objecte + ' — ' + p.tipus + ' (' + props.anyActual + ')', p.moviments_actual)"
                                                >
                                                    {{ formatEur(p.estat?.pagat ?? 0) }}
                                                    <span v-if="p.estat && p.estat.pagaments" class="block text-xs font-normal text-gray-400 dark:text-gray-500">
                                                        {{ p.estat.pagaments }} {{ p.estat.pagaments === 1 ? 'càrrec' : 'càrrecs' }}
                                                    </span>
                                                    <span v-if="p.estat && p.estat.retornat > 0" class="block text-xs font-normal text-green-600 dark:text-green-400">
                                                        ↩ {{ formatEur(p.estat.retornat) }} retornats
                                                    </span>
                                                </td>
                                                <td
                                                    class="whitespace-nowrap px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400"
                                                    :class="{ 'cursor-pointer hover:underline': p.moviments_anterior.length > 0 }"
                                                    @click="obreDetall(grup.objecte + ' — ' + p.tipus + ' (' + props.anyAnterior + ')', p.moviments_anterior)"
                                                >
                                                    {{ formatEur(p.estat?.anterior_a_data ?? 0) }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm">
                                                    <template v-if="p.estat && p.estat.variacio_pct !== null">
                                                        <span :class="colorVariacio(p.estat.variacio_pct)">
                                                            {{ signe(p.estat.variacio_pct) }}{{ p.estat.variacio_pct.toFixed(1) }}%
                                                        </span>
                                                        <span class="block text-xs" :class="colorVariacio(p.estat.variacio_pct)">
                                                            {{ signe(p.estat.variacio) }}{{ formatEur(p.estat.variacio) }}
                                                        </span>
                                                    </template>
                                                    <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-gray-400 dark:text-gray-500">
                                                    {{ formatEur(p.estat?.anterior_total ?? 0) }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm">
                                                    <template v-if="p.estat?.prima !== null && p.estat">
                                                        <span class="text-gray-800 dark:text-gray-200">{{ formatEur(p.estat.prima!) }}</span>
                                                        <span
                                                            v-if="p.estat.prima_variacio_pct !== null"
                                                            class="block text-xs"
                                                            :class="colorVariacio(p.estat.prima_variacio_pct)"
                                                        >
                                                            {{ signe(p.estat.prima_variacio_pct) }}{{ p.estat.prima_variacio_pct.toFixed(1) }}%
                                                        </span>
                                                    </template>
                                                    <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Com s'ha detectat: sense immoble identificat és l'única pista -->
                                <div v-if="grup.seccio === 'resta'" class="border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                                    <p v-for="path in grup.paths" :key="path" class="font-mono text-xs text-gray-400 dark:text-gray-500">
                                        {{ path }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Vista 2 — Tots els moviments -->
                <div v-else>
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <select v-model="filtreAny" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Tots els anys</option>
                            <option v-for="a in props.anysDisponibles" :key="a" :value="String(a)">{{ a }}</option>
                        </select>
                        <select v-model="filtreTipus" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Totes les pòlisses</option>
                            <option v-for="t in tipusDisponibles" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <select v-model="filtreCompte" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Tots els comptes</option>
                            <option v-for="c in comptesDisponibles" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <input
                            v-model="filtreConcepte"
                            type="search"
                            placeholder="Cerca al concepte o a l'objecte…"
                            class="w-64 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                        />
                        <span class="ml-auto text-sm text-gray-500 dark:text-gray-400">
                            {{ movimentsFiltrats.length }} moviments · Total:
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(totalFiltrat) }}</span>
                        </span>
                    </div>

                    <div class="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Compte</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Objecte</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Pòlissa</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Edita</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="m in movimentsFiltrats" :key="m.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ m.data }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ m.compte ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        {{ m.objecte }}
                                        <span v-if="m.companyia" class="ml-1 text-xs text-gray-400 dark:text-gray-500">{{ m.companyia }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm">
                                        <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ m.tipus }}</span>
                                    </td>
                                    <td class="max-w-xs truncate px-4 py-2 text-sm text-gray-500 dark:text-gray-400" :title="m.concepte">{{ conceptePresentat(m) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium" :class="m.import > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-gray-100'">
                                        {{ formatEur(m.import) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-center">
                                        <button
                                            @click="obreEdicio(m)"
                                            title="Edita el moviment"
                                            class="inline-flex items-center rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700 dark:hover:text-red-400"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="movimentsFiltrats.length === 0">
                                    <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">Cap moviment amb aquests filtres.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal detall moviments -->
        <Modal :show="showDetall" max-width="lg" @close="showDetall = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ detallTitol }}</h3>
                <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Data</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Compte</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Import</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(mov, idx) in detallMoviments" :key="idx">
                            <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ mov.data }}</td>
                            <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                <span v-if="mov.compte_digits" class="font-mono tracking-widest text-gray-400 dark:text-gray-500">···· {{ mov.compte_digits }}</span>
                                <span v-if="mov.compte" class="ml-2 text-xs">{{ mov.compte }}</span>
                                <span v-if="!mov.compte && !mov.compte_digits" class="text-gray-300 dark:text-gray-600">—</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right text-sm" :class="mov.import > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-700 dark:text-gray-300'">
                                {{ formatEur(mov.import) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr class="font-bold">
                            <td colspan="2" class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-900 dark:text-gray-100">{{ formatEur(detallTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div class="mt-4 flex justify-end">
                    <button @click="showDetall = false" class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Tancar
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Modal edició de moviment -->
        <Modal :show="showEdit" max-width="lg" @close="tancaEdicio">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Edita moviment
                    <span v-if="editMov" class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400">— {{ editMov.compte }}</span>
                </h3>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data</label>
                            <input v-model="editForm.data_moviment" type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="editForm.errors.data_moviment" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ editForm.errors.data_moviment }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Import</label>
                            <input v-model="editForm.import" type="number" step="0.01"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="editForm.errors.import" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ editForm.errors.import }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label>
                        <input v-model="editForm.concepte" type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="editMov" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            Text brut del banc (immutable): {{ editMov.concepte }}
                        </p>
                        <p v-if="editForm.errors.concepte" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ editForm.errors.concepte }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="editForm.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Categoria</label>
                        <CategoryTreeSelect
                            :categories="categoriesEditCompte"
                            v-model="editForm.categoria_id"
                            :allow-none="true"
                            placeholder="Selecciona una categoria..."
                            class="mt-1"
                        />
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            Si canvies a una categoria que no és assegurança, el moviment desapareixerà d'aquesta llista.
                        </p>
                        <p v-if="editForm.errors.categoria_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ editForm.errors.categoria_id }}</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="tancaEdicio" :disabled="editForm.processing"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Cancel·la
                    </button>
                    <button @click="desaEdicio" :disabled="editForm.processing"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
