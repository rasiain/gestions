<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MovimentDetall {
    data: string;
    import: number;
}

interface TipusImmoble {
    tipus: string;
    actual: number;
    anterior: number;
    moviments_actual: MovimentDetall[];
    moviments_anterior: MovimentDetall[];
}

interface ImmobleGrup {
    immoble: string;
    poblacio: string | null;
    lloguer: string | null;
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
                        <h2 class="mb-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ seccio.titol }}</h2>
                        <p v-if="seccio.clau === 'resta'" class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                            No s'ha pogut deduir l'immoble ni de l'arbre de categories ni de les despeses de lloguer.
                            Sota cada bloc hi ha la categoria completa amb tots els seus pares.
                        </p>

                    <div v-for="pob in seccio.poblacions" :key="pob.poblacio ?? '—'" class="mt-4 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-widest" :class="pob.poblacio ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'">
                            {{ pob.poblacio ?? 'Sense població' }}
                        </h3>

                    <div
                        v-for="grup in pob.immobles"
                        :key="grup.immoble + grup.paths[0]"
                        class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800"
                    >
                        <div class="flex items-center justify-between border-l-4 border-red-400 bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
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
                                    <th class="w-2/5 px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                    <th class="w-1/5 px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-900 dark:text-gray-100">{{ props.anyActual }}</th>
                                    <th class="w-1/5 px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ props.anyAnterior }}</th>
                                    <th class="w-1/5 px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Variació</th>
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
        <Modal :show="showDetall" max-width="md" @close="showDetall = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ detallTitol }}</h3>
                <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Data</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Import</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(mov, idx) in detallMoviments" :key="idx">
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ mov.data }}</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{{ formatEur(mov.import) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr class="font-bold">
                            <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total</td>
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
