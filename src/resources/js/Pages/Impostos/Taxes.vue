<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MovimentDetall {
    data: string;
    import: number;
    compte: string | null;
    /** Darrers 4 dígits de l'IBAN: identifica el compte quan el nom no hi cap. */
    compte_digits: string | null;
}

/** Estat d'una taxa quan se n'ha definit el total del rebut. */
interface EstatTaxa {
    /** Null quan encara no s'ha definit el total del rebut. */
    rebut_id: number | null;
    total: number | null;
    pagat: number;
    pendent: number | null;
    percentatge: number | null;
    terminis_fets: number;
    terminis_previstos: number | null;
    sobrepagat: boolean;
    notes: string | null;
    repercutible: boolean;
    repercutit?: number;
    pendent_llogater?: number | null;
    percentatge_llogater?: number | null;
    /** Positiu: el llogater ha avançat. Negatiu: el propietari finança. */
    saldo?: number;
    /** Cap import repercutit registrat: als lloguers sense factura encara no és dada estructurada. */
    repercussio_parcial?: boolean;
}

interface TipusImmoble {
    tipus: string;
    actual: number;
    anterior: number;
    moviments_actual: MovimentDetall[];
    moviments_anterior: MovimentDetall[];
    estat: EstatTaxa | null;
}

interface ImmobleGrup {
    immoble: string;
    poblacio: string | null;
    lloguer: string | null;
    /** Clau d'agrupació i immoble real: identifiquen el rebut. */
    grup: string;
    immoble_id: number | null;
    seccio: 'lloguer' | 'identificat' | 'resta';
    /** Path complet de les categories: pista per als no identificats. */
    paths: string[];
    tipus: TipusImmoble[];
    total_actual: number;
    total_anterior: number;
}

interface PoblacioGrup {
    poblacio: string | null;
    immobles: ImmobleGrup[];
}

interface Seccio {
    clau: 'lloguer' | 'identificat' | 'resta';
    titol: string;
    poblacions: PoblacioGrup[];
}

interface MovimentLlista {
    id: number;
    compte_corrent_id: number;
    data: string;
    compte: string | null;
    immoble: string;
    tipus: string;
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
    totalGeneral: number;
    categoriesPerCompte: Record<number, Categoria[]>;
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

function canviarAny(event: Event) {
    const target = event.target as HTMLSelectElement;
    router.get(route('impostos.taxes'), { any: target.value }, { preserveState: true, preserveScroll: true });
}

const anyOpcions = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

// ---- Tabs ----
const tab = ref<'immoble' | 'moviments'>('immoble');

// ---- Vista 1 ----
const seccionsAmbDades = computed(() => props.seccions.filter(s => s.poblacions.length > 0));
const capImmoble = computed(() => seccionsAmbDades.value.length === 0);

/**
 * Identitat visual de cada secció. L'ambre és el color dels lloguers a la resta
 * de l'aplicació: qui mira la pàgina ha de veure d'un cop d'ull quins immobles
 * són de lloguer i quins no.
 */
const estilSeccio: Record<Seccio['clau'], {
    vora: string;
    pastilla: string;
    titol: string;
    icona: string;
    descripcio: string;
    recompte: (n: number) => string;
}> = {
    lloguer: {
        vora: 'border-amber-400',
        pastilla: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        titol: 'text-amber-800 dark:text-amber-300',
        // Casa
        icona: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        descripcio: 'Lligats a un lloguer per les despeses classificades dels seus moviments.',
        recompte: (n) => `${n} ${n === 1 ? 'immoble' : 'immobles'}`,
    },
    identificat: {
        vora: 'border-slate-400',
        pastilla: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
        titol: 'text-slate-700 dark:text-slate-200',
        // Edifici
        icona: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        descripcio: "L'immoble surt de l'arbre de categories, però cap despesa de lloguer no l'hi lliga.",
        recompte: (n) => `${n} ${n === 1 ? 'immoble' : 'immobles'}`,
    },
    resta: {
        vora: 'border-gray-300 dark:border-gray-600',
        pastilla: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
        titol: 'text-gray-600 dark:text-gray-300',
        // Interrogant
        icona: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        descripcio: "Ni l'arbre ni les despeses no diuen l'immoble; sota cada bloc hi ha la categoria completa.",
        recompte: (n) => `${n} ${n === 1 ? 'grup' : 'grups'}`,
    },
};

function immoblesDeSeccio(seccio: Seccio): ImmobleGrup[] {
    return seccio.poblacions.flatMap((p) => p.immobles);
}

function totalSeccio(seccio: Seccio, camp: 'total_actual' | 'total_anterior'): number {
    return immoblesDeSeccio(seccio).reduce((acc, g) => acc + g[camp], 0);
}

// ---- Modal detall ----
const showDetall = ref(false);
const detallTitol = ref('');
const detallMoviments = ref<MovimentDetall[]>([]);
const detallTotal = ref(0);

function obreDetall(titol: string, moviments: MovimentDetall[], total: number) {
    if (moviments.length === 0) return;
    detallTitol.value = titol;
    detallMoviments.value = moviments;
    detallTotal.value = total;
    showDetall.value = true;
}

// ---- Rebut: total anual d'una taxa ----
const showRebut = ref(false);
const rebutContext = ref<{ grup: ImmobleGrup; tipus: TipusImmoble } | null>(null);

const rebutForm = useForm({
    grup: '',
    immoble_id: null as number | null,
    tipus: '',
    any: props.anyActual,
    import_total: null as number | null,
    terminis_previstos: null as number | null,
    repercutible: false,
    concepte_repercussio: 'escombraries',
    notes: '' as string | null,
});

function obreRebut(grup: ImmobleGrup, tipus: TipusImmoble) {
    rebutContext.value = { grup, tipus };
    rebutForm.clearErrors();
    rebutForm.grup = grup.grup;
    rebutForm.immoble_id = grup.immoble_id;
    rebutForm.tipus = tipus.tipus;
    rebutForm.any = props.anyActual;

    const estat = tipus.estat;
    rebutForm.import_total = estat?.total ?? null;
    rebutForm.terminis_previstos = estat?.terminis_previstos ?? null;
    // L'escombraries d'un immoble de lloguer és, per defecte, cosa del llogater
    rebutForm.repercutible = estat?.repercutible ?? (tipus.tipus === 'Escombraries' && grup.immoble_id !== null);
    rebutForm.concepte_repercussio = 'escombraries';
    rebutForm.notes = estat?.notes ?? '';

    showRebut.value = true;
}

/** Els rebuts es repeteixen gairebé iguals: l'any passat és la millor proposta. */
function proposaDeLAnyAnterior() {
    const tipus = rebutContext.value?.tipus;
    if (!tipus) return;
    rebutForm.import_total = Math.abs(tipus.anterior);
    rebutForm.terminis_previstos = tipus.moviments_anterior.length || null;
}

function desaRebut() {
    const rebutId = rebutContext.value?.tipus.estat?.rebut_id;
    const opcions = { preserveScroll: true, onSuccess: () => (showRebut.value = false) };

    // Sense rebut previ (una fila que només mostrava el retorn del llogater) es crea
    if (rebutId == null) {
        rebutForm.post(route('impostos.taxes.rebuts.store'), opcions);
        return;
    }

    rebutForm.put(route('impostos.taxes.rebuts.update', rebutId), opcions);
}

function esborraRebut() {
    const rebutId = rebutContext.value?.tipus.estat?.rebut_id;
    if (rebutId == null || !confirm('Vols esborrar el total definit per a aquesta taxa?')) return;

    rebutForm.delete(route('impostos.taxes.rebuts.destroy', rebutId), {
        preserveScroll: true,
        onSuccess: () => (showRebut.value = false),
    });
}

/** Amplada de barra, acotada al 100 % perquè un sobrepagament no la desbordi. */
function ampladaBarra(percentatge: number | null | undefined): string {
    return `${Math.min(Math.max(percentatge ?? 0, 0), 100)}%`;
}

// ---- Filtres Vista 2 ----
const filtreAny = ref<string>('');
const filtreTipus = ref<string>('');
const filtreCompte = ref<string>('');
const filtreConcepte = ref<string>('');

function normalitza(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const tipusDisponibles = computed(() =>
    [...new Set(props.moviments.map((m) => m.tipus))].sort()
);
const comptesDisponibles = computed(() =>
    [...new Set(props.moviments.map((m) => m.compte).filter((c): c is string => !!c))].sort()
);

// Concepte que es mostra: el net (MovimentConcepte), amb fallback al text brut.
function conceptePresentat(m: MovimentLlista): string {
    return m.concepte_editable ?? m.concepte;
}

const movimentsFiltrats = computed(() => {
    const cerca = normalitza(filtreConcepte.value.trim());
    return props.moviments.filter((m) => {
        if (filtreAny.value && !m.data.startsWith(filtreAny.value)) return false;
        if (filtreTipus.value && m.tipus !== filtreTipus.value) return false;
        if (filtreCompte.value && m.compte !== filtreCompte.value) return false;
        // Cerca tant al concepte net com al text brut del banc.
        if (cerca && !normalitza(m.concepte_editable ?? '').includes(cerca) && !normalitza(m.concepte).includes(cerca)) return false;
        return true;
    });
});

const totalFiltrat = computed(() =>
    movimentsFiltrats.value.reduce((acc, m) => acc + m.import, 0)
);

function variacio(actual: number, anterior: number): number | null {
    if (anterior === 0) return null;
    return ((actual - anterior) / Math.abs(anterior)) * 100;
}

// ---- Edició de moviment (mateix flux que la llista de moviments) ----
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
    <Head title="Taxes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Taxes — impostos municipals
                </h2>
                <div class="flex items-center gap-3">
                    <select
                        v-if="tab === 'immoble'"
                        :value="props.anyActual"
                        @change="canviarAny"
                        class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="a in anyOpcions" :key="a" :value="a">{{ a }}</option>
                    </select>
                    <Link
                        :href="route('impostos.taxes.patrons')"
                        class="text-sm text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
                    >
                        Configura patrons →
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="mb-6 flex gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button
                        @click="tab = 'immoble'"
                        :class="tab === 'immoble'
                            ? 'border-b-2 border-red-500 text-red-600 dark:text-red-400'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                    >
                        Per immoble
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

                <!-- Vista 1 — Per immoble -->
                <div v-if="tab === 'immoble'" class="space-y-10">
                    <p v-if="capImmoble" class="text-sm text-gray-500 dark:text-gray-400">
                        No s'han trobat impostos per als anys {{ props.anyAnterior }}–{{ props.anyActual }}.
                    </p>

                    <section v-for="seccio in seccionsAmbDades" :key="seccio.clau">
                        <!-- Capçalera de secció: el que separa els immobles de lloguer de la resta -->
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
                                                {{ estilSeccio[seccio.clau].recompte(immoblesDeSeccio(seccio).length) }}
                                            </span>
                                        </h2>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ estilSeccio[seccio.clau].descripcio }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-6 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ props.anyActual }}: <span class="font-bold text-gray-900 dark:text-gray-100">{{ formatEur(totalSeccio(seccio, 'total_actual')) }}</span>
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ props.anyAnterior }}: <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(totalSeccio(seccio, 'total_anterior')) }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>

                    <div v-for="pob in seccio.poblacions" :key="pob.poblacio ?? '—'" class="mt-4 space-y-4">
                        <h3 class="flex items-center gap-3 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                            {{ pob.poblacio ?? 'Sense població' }}
                            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                        </h3>

                    <div
                        v-for="grup in pob.immobles"
                        :key="grup.immoble + grup.paths[0]"
                        class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800"
                    >
                        <!-- La vora repeteix el color de la secció: cada targeta es reconeix fora de context -->
                        <div class="flex items-center justify-between border-l-4 bg-gray-50 px-4 py-3 dark:bg-gray-700/50" :class="estilSeccio[seccio.clau].vora">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ grup.immoble }}
                                <span v-if="grup.lloguer && grup.lloguer !== grup.immoble" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                    {{ grup.lloguer }}
                                </span>
                            </h3>
                            <div class="flex gap-6 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ props.anyActual }}: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(grup.total_actual) }}</span>
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ props.anyAnterior }}: <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(grup.total_anterior) }}</span>
                                </span>
                            </div>
                        </div>
                        <!-- table-fixed + amples explícits: les columnes queden alineades entre immobles -->
                        <table class="min-w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="w-1/5 px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                    <th class="w-[13%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-900 dark:text-gray-100">{{ props.anyActual }}</th>
                                    <th class="w-[13%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ props.anyAnterior }}</th>
                                    <th class="w-[10%] px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Variació</th>
                                    <th class="w-1/3 px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Estat {{ props.anyActual }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="t in grup.tipus" :key="t.tipus" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="truncate px-4 py-2 text-sm font-medium text-gray-800 dark:text-gray-200">{{ t.tipus }}</td>
                                    <td
                                        class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-gray-900 dark:text-gray-100"
                                        :class="{ 'cursor-pointer hover:underline': t.moviments_actual.length > 0 }"
                                        @click="obreDetall(grup.immoble + ' — ' + t.tipus + ' (' + props.anyActual + ')', t.moviments_actual, t.actual)"
                                    >
                                        {{ formatEur(t.actual) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-4 py-2 text-right text-sm text-gray-600 dark:text-gray-400"
                                        :class="{ 'cursor-pointer hover:underline': t.moviments_anterior.length > 0 }"
                                        @click="obreDetall(grup.immoble + ' — ' + t.tipus + ' (' + props.anyAnterior + ')', t.moviments_anterior, t.anterior)"
                                    >
                                        {{ formatEur(t.anterior) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right text-xs">
                                        <span
                                            v-if="variacio(t.actual, t.anterior) !== null"
                                            :class="variacio(t.actual, t.anterior)! > 0.5
                                                ? 'text-red-600 dark:text-red-400'
                                                : (variacio(t.actual, t.anterior)! < -0.5 ? 'text-green-600 dark:text-green-400' : 'text-gray-400')"
                                        >
                                            {{ variacio(t.actual, t.anterior)! > 0 ? '+' : '' }}{{ variacio(t.actual, t.anterior)!.toFixed(1) }}%
                                        </span>
                                        <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                    </td>
                                    <!-- Estat: quant del rebut s'ha pagat i, si és de lloguer, quant n'ha retornat el llogater -->
                                    <td class="px-4 py-2">
                                        <button
                                            v-if="!t.estat"
                                            @click="obreRebut(grup, t)"
                                            class="text-xs text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400"
                                        >
                                            ─ definir total
                                        </button>
                                        <button v-else @click="obreRebut(grup, t)" class="block w-full text-left" title="Edita el total del rebut">
                                            <!-- Amb rebut definit: quant se n'ha pagat -->
                                            <template v-if="t.estat.total !== null">
                                                <div class="flex items-center gap-2">
                                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-600">
                                                        <div
                                                            class="h-full rounded-full"
                                                            :class="t.estat.sobrepagat ? 'bg-red-500' : (t.estat.percentatge !== null && t.estat.percentatge >= 99.5 ? 'bg-green-500' : 'bg-blue-500')"
                                                            :style="{ width: ampladaBarra(t.estat.percentatge) }"
                                                        ></div>
                                                    </div>
                                                    <span class="whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
                                                        <span v-if="t.estat.terminis_previstos">{{ t.estat.terminis_fets }}/{{ t.estat.terminis_previstos }} · </span>{{ t.estat.percentatge }} %
                                                    </span>
                                                </div>
                                                <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                                    de {{ formatEur(t.estat.total) }}
                                                    <span v-if="t.estat.sobrepagat" class="ml-1 text-red-600 dark:text-red-400">· pagat de més, revisa el total</span>
                                                    <span v-else-if="t.estat.pendent !== null && t.estat.pendent > 0">· en queden {{ formatEur(t.estat.pendent) }}</span>
                                                </div>
                                            </template>
                                            <span v-else class="text-xs text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400">─ definir total</span>

                                            <!-- Part del llogater: l'import retornat és cert encara que no hi hagi total definit -->
                                            <template v-if="t.estat.repercutible">
                                                <div class="mt-1 flex items-center gap-2">
                                                    <div v-if="t.estat.total !== null" class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-600">
                                                        <div class="h-full rounded-full bg-amber-400" :style="{ width: ampladaBarra(t.estat.percentatge_llogater) }"></div>
                                                    </div>
                                                    <span class="whitespace-nowrap text-xs text-amber-700 dark:text-amber-400">
                                                        llogater {{ formatEur(t.estat.repercutit ?? 0) }}<span v-if="t.estat.percentatge_llogater !== null"> · {{ t.estat.percentatge_llogater }} %</span>
                                                    </span>
                                                </div>
                                                <div class="mt-0.5 text-xs">
                                                    <span v-if="t.estat.repercussio_parcial" class="text-gray-400 dark:text-gray-500">
                                                        cap import repercutit registrat
                                                    </span>
                                                    <span v-else-if="t.estat.saldo! > 0" class="text-green-600 dark:text-green-400">
                                                        ↑ ha avançat {{ formatEur(t.estat.saldo!) }}
                                                    </span>
                                                    <span v-else-if="t.estat.saldo! < 0" class="text-amber-700 dark:text-amber-400">
                                                        ↓ li queden {{ formatEur(-t.estat.saldo!) }} per retornar
                                                    </span>
                                                    <span v-else class="text-gray-400 dark:text-gray-500">al dia</span>
                                                </div>
                                            </template>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Pista d'on està classificada la despesa -->
                        <div v-if="seccio.clau === 'resta'" class="border-t border-gray-100 bg-gray-50 px-4 py-2 dark:border-gray-700 dark:bg-gray-900/40">
                            <p v-for="path in grup.paths" :key="path" class="truncate font-mono text-xs text-gray-500 dark:text-gray-400" :title="path">
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
                        <select v-model="filtreAny" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Tots els anys</option>
                            <option v-for="a in props.anysDisponibles" :key="a" :value="String(a)">{{ a }}</option>
                        </select>
                        <select v-model="filtreTipus" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Tots els tipus</option>
                            <option v-for="t in tipusDisponibles" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <select v-model="filtreCompte" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Tots els comptes</option>
                            <option v-for="c in comptesDisponibles" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <div class="relative">
                            <input
                                v-model="filtreConcepte"
                                type="search"
                                placeholder="Cerca al concepte…"
                                class="w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                            />
                        </div>
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
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Immoble</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Edita</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="m in movimentsFiltrats" :key="m.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ m.data }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ m.compte ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ m.immoble }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm">
                                        <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ m.tipus }}</span>
                                    </td>
                                    <td class="max-w-xs truncate px-4 py-2 text-sm text-gray-500 dark:text-gray-400" :title="m.concepte">{{ conceptePresentat(m) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatEur(m.import) }}</td>
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
                            <td class="whitespace-nowrap px-3 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{{ formatEur(mov.import) }}</td>
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

        <!-- Modal: total anual del rebut -->
        <Modal :show="showRebut" max-width="lg" @close="showRebut = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Total a pagar {{ rebutForm.any }}
                </h3>
                <p v-if="rebutContext" class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ rebutContext.grup.immoble }} — {{ rebutContext.tipus.tipus }}
                </p>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Import total del rebut</label>
                            <input v-model.number="rebutForm.import_total" type="number" step="0.01"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="rebutForm.errors.import_total" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ rebutForm.errors.import_total }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Terminis previstos</label>
                            <input v-model.number="rebutForm.terminis_previstos" type="number" min="1" max="12" placeholder="opcional"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="rebutForm.errors.terminis_previstos" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ rebutForm.errors.terminis_previstos }}</p>
                        </div>
                    </div>

                    <button
                        v-if="rebutContext && rebutContext.tipus.anterior !== 0"
                        @click="proposaDeLAnyAnterior"
                        class="text-xs text-red-600 hover:underline dark:text-red-400"
                    >
                        Proposa des del {{ props.anyAnterior }}: {{ formatEur(Math.abs(rebutContext.tipus.anterior)) }}
                        ({{ rebutContext.tipus.moviments_anterior.length }} pagaments)
                    </button>

                    <label class="flex items-start gap-2">
                        <input v-model="rebutForm.repercutible" type="checkbox" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            El llogater el retorna
                            <span class="block text-xs text-gray-400 dark:text-gray-500">
                                Es compta amb les línies «escombraries» de les factures del lloguer.
                            </span>
                        </span>
                    </label>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="rebutForm.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-between gap-2">
                    <button
                        v-if="rebutContext?.tipus.estat?.rebut_id"
                        @click="esborraRebut"
                        :disabled="rebutForm.processing"
                        class="rounded-md px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30"
                    >
                        Esborra el total
                    </button>
                    <div class="ml-auto flex gap-2">
                        <button @click="showRebut = false" :disabled="rebutForm.processing"
                            class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                            Cancel·la
                        </button>
                        <button @click="desaRebut" :disabled="rebutForm.processing"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                            Desa
                        </button>
                    </div>
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
                            Si canvies a una categoria que no és taxa, el moviment desapareixerà d'aquesta llista.
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
