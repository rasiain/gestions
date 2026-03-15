<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

interface Immoble {
    id: number;
    adreca: string;
}

interface CompteCorrent {
    id: number;
    nom: string;
}

interface LlogaterBasic {
    id: number;
    nom: string;
    cognoms: string;
}

interface ContracteActiu {
    id: number;
    lloguer_id: number;
    data_inici: string;
    data_fi: string | null;
    llogater_ids: number[];
    llogaters: LlogaterBasic[];
}

interface Lloguer {
    id: number;
    nom: string;
    acronim: string | null;
    immoble_id: number;
    immoble: Immoble | null;
    compte_corrent_id: number;
    compte_corrent: CompteCorrent | null;
    base_euros: string | null;
    contracte_actiu: ContracteActiu | null;
}

interface Props {
    lloguers: Lloguer[];
    immobles: Immoble[];
    comptesCorrents: CompteCorrent[];
    llogaters: LlogaterBasic[];
}

const props = defineProps<Props>();

// ── Lloguer modal ──────────────────────────────────────────────
const showLloguerModal = ref(false);
const isEditingLloguer = ref(false);
const editingLloguer = ref<Lloguer | null>(null);

const lloguerForm = useForm({
    nom: '',
    acronim: '',
    immoble_id: null as number | null,
    compte_corrent_id: null as number | null,
    base_euros: null as number | null,
});

const openCreateLloguerModal = () => {
    isEditingLloguer.value = false;
    editingLloguer.value = null;
    lloguerForm.reset();
    showLloguerModal.value = true;
};

const openEditLloguerModal = (lloguer: Lloguer) => {
    isEditingLloguer.value = true;
    editingLloguer.value = lloguer;
    lloguerForm.nom = lloguer.nom;
    lloguerForm.acronim = lloguer.acronim || '';
    lloguerForm.immoble_id = lloguer.immoble_id;
    lloguerForm.compte_corrent_id = lloguer.compte_corrent_id;
    lloguerForm.base_euros = lloguer.base_euros ? parseFloat(lloguer.base_euros) : null;
    showLloguerModal.value = true;
};

const closeLloguerModal = () => {
    showLloguerModal.value = false;
    lloguerForm.reset();
    isEditingLloguer.value = false;
    editingLloguer.value = null;
};

const submitLloguer = () => {
    if (isEditingLloguer.value && editingLloguer.value) {
        lloguerForm.put(route('lloguers.update', editingLloguer.value.id), {
            onSuccess: () => closeLloguerModal(),
        });
    } else {
        lloguerForm.post(route('lloguers.store'), {
            onSuccess: () => closeLloguerModal(),
        });
    }
};

const deleteLloguer = (lloguer: Lloguer) => {
    if (confirm(`Estàs segur que vols eliminar el lloguer "${lloguer.nom}"?`)) {
        router.delete(route('lloguers.destroy', lloguer.id));
    }
};

// ── Contracte panel ────────────────────────────────────────────
const selectedLloguerId = ref<number | null>(null);

const selectedLloguer = computed(() =>
    props.lloguers.find(l => l.id === selectedLloguerId.value) ?? null
);

const contracteForm = useForm({
    lloguer_id: null as number | null,
    data_inici: '',
    data_fi: '',
    llogater_ids: [] as number[],
});

const selectLloguer = (lloguer: Lloguer) => {
    if (selectedLloguerId.value === lloguer.id) {
        selectedLloguerId.value = null;
        return;
    }
    selectedLloguerId.value = lloguer.id;
    const c = lloguer.contracte_actiu;
    contracteForm.lloguer_id = lloguer.id;
    contracteForm.data_inici = c?.data_inici ?? '';
    contracteForm.data_fi = c?.data_fi ?? '';
    contracteForm.llogater_ids = c?.llogater_ids ? [...c.llogater_ids] : [];
    contracteForm.clearErrors();
};

const addLlogater = (event: Event) => {
    const id = parseInt((event.target as HTMLSelectElement).value);
    if (id && !contracteForm.llogater_ids.includes(id)) {
        contracteForm.llogater_ids.push(id);
    }
    (event.target as HTMLSelectElement).value = '';
};

const removeLlogater = (id: number) => {
    const idx = contracteForm.llogater_ids.indexOf(id);
    if (idx !== -1) contracteForm.llogater_ids.splice(idx, 1);
};

const selectedLlogaters = computed(() =>
    props.llogaters.filter(l => contracteForm.llogater_ids.includes(l.id))
);

const availableLlogaters = computed(() =>
    props.llogaters.filter(l => !contracteForm.llogater_ids.includes(l.id))
);

const submitContracte = () => {
    const contracte = selectedLloguer.value?.contracte_actiu;
    if (contracte) {
        contracteForm.put(route('contractes.update', contracte.id), { preserveScroll: true });
    } else {
        contracteForm.post(route('contractes.store'), { preserveScroll: true });
    }
};

const deleteContracte = () => {
    const contracte = selectedLloguer.value?.contracte_actiu;
    if (!contracte) return;
    if (confirm('Estàs segur que vols eliminar aquest contracte?')) {
        router.delete(route('contractes.destroy', contracte.id), { preserveScroll: true });
    }
};

// ── Moviments ──────────────────────────────────────────────────
interface Moviment {
    id: number;
    data_moviment: string;
    concepte: string;
    import: string;
    saldo_posterior: string | null;
    exclou_lloguer: boolean;
}

const moviments = ref<Moviment[]>([]);
const movimentsPage = ref(1);
const movimentsTotal = ref(0);
const movimentsHasMore = ref(false);
const movimentsLoading = ref(false);

const csrfToken = (): string =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

const fetchMoviments = async (lloguer: Lloguer, page: number, append = false) => {
    movimentsLoading.value = true;
    try {
        const res = await fetch(`/lloguers/${lloguer.id}/moviments?page=${page}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();
        moviments.value = append ? [...moviments.value, ...json.data] : json.data;
        movimentsTotal.value = json.total;
        movimentsHasMore.value = json.has_more;
        movimentsPage.value = page;
    } finally {
        movimentsLoading.value = false;
    }
};

const loadMore = () => {
    if (selectedLloguer.value && movimentsHasMore.value) {
        fetchMoviments(selectedLloguer.value, movimentsPage.value + 1, true);
    }
};

const toggleExclou = async (moviment: Moviment) => {
    const res = await fetch(`/moviments/${moviment.id}/exclou-lloguer`, {
        method: 'PATCH',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
    const json = await res.json();
    moviment.exclou_lloguer = json.exclou_lloguer;
};

watch(selectedLloguerId, (newId) => {
    moviments.value = [];
    movimentsTotal.value = 0;
    movimentsHasMore.value = false;
    movimentsPage.value = 1;
    if (newId) {
        const lloguer = props.lloguers.find(l => l.id === newId);
        if (lloguer) fetchMoviments(lloguer, 1);
    }
});

// ── Helpers ────────────────────────────────────────────────────
const formatCurrency = (value: string | null): string => {
    if (value === null) return '-';
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(parseFloat(value));
};
</script>

<template>
    <Head title="Lloguers" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Lloguers
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8 space-y-6">

                <!-- Lloguers table -->
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">Llistat de Lloguers</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Fes clic en una fila per gestionar el seu contracte
                                </p>
                            </div>
                            <button
                                @click="openCreateLloguerModal"
                                class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                            >
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Afegir Lloguer
                            </button>
                        </div>

                        <div v-if="lloguers.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Nom</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Acrònim</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Immoble</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Base (€/mes)</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Contracte actiu</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="lloguer in lloguers"
                                        :key="lloguer.id"
                                        @click="selectLloguer(lloguer)"
                                        class="cursor-pointer transition-colors"
                                        :class="selectedLloguerId === lloguer.id
                                            ? 'bg-amber-50 dark:bg-amber-900/20'
                                            : 'hover:bg-gray-50 dark:hover:bg-gray-700'"
                                    >
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ lloguer.nom }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ lloguer.acronim || '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ lloguer.immoble?.adreca || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(lloguer.base_euros) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span v-if="lloguer.contracte_actiu" class="text-gray-900 dark:text-gray-100">
                                                {{ lloguer.contracte_actiu.data_inici }}
                                                → {{ lloguer.contracte_actiu.data_fi ?? 'indefinit' }}
                                            </span>
                                            <span v-else class="italic text-gray-400 dark:text-gray-500">Sense contracte</span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium" @click.stop>
                                            <button
                                                @click="openEditLloguerModal(lloguer)"
                                                class="mr-3 text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteLloguer(lloguer)"
                                                class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-12 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No hi ha lloguers</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Comença afegint el primer lloguer.</p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateLloguerModal"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700"
                                >
                                    <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Afegir Lloguer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contracte panel (shown when a lloguer is selected) -->
                <div
                    v-if="selectedLloguer"
                    class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg border-l-4 border-amber-400"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Contracte — {{ selectedLloguer.nom }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ selectedLloguer.contracte_actiu ? 'Contracte actiu' : 'No hi ha contracte actiu' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <button
                                    v-if="selectedLloguer.contracte_actiu"
                                    @click="deleteContracte"
                                    class="text-sm text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    Eliminar contracte
                                </button>
                                <button
                                    @click="selectedLloguerId = null"
                                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                >
                                    ✕ Tancar
                                </button>
                            </div>
                        </div>

                        <form @submit.prevent="submitContracte">
                            <input type="hidden" v-model="contracteForm.lloguer_id" />

                            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <!-- Data inici -->
                                <div>
                                    <label for="data_inici" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Data d'inici *
                                    </label>
                                    <input
                                        id="data_inici"
                                        v-model="contracteForm.data_inici"
                                        type="date"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="contracteForm.errors.data_inici" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.data_inici }}
                                    </p>
                                </div>

                                <!-- Data fi -->
                                <div>
                                    <label for="data_fi" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Data de finalització
                                    </label>
                                    <input
                                        id="data_fi"
                                        v-model="contracteForm.data_fi"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="contracteForm.errors.data_fi" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.data_fi }}
                                    </p>
                                </div>

                                <!-- Llogaters -->
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Llogaters signants
                                    </label>

                                    <!-- Selected tags -->
                                    <div v-if="selectedLlogaters.length > 0" class="mb-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="llogater in selectedLlogaters"
                                            :key="llogater.id"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                                        >
                                            {{ llogater.cognoms }}, {{ llogater.nom }}
                                            <button
                                                type="button"
                                                @click="removeLlogater(llogater.id)"
                                                class="ml-1 rounded-full text-amber-600 hover:text-amber-900 dark:text-amber-300 dark:hover:text-amber-100 focus:outline-none"
                                            >
                                                ✕
                                            </button>
                                        </span>
                                    </div>

                                    <!-- Dropdown to add -->
                                    <select
                                        v-if="availableLlogaters.length > 0"
                                        @change="addLlogater"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option value="">Afegir llogater…</option>
                                        <option v-for="llogater in availableLlogaters" :key="llogater.id" :value="llogater.id">
                                            {{ llogater.cognoms }}, {{ llogater.nom }}
                                        </option>
                                    </select>
                                    <p v-else-if="llogaters.length === 0" class="text-sm italic text-gray-400">
                                        No hi ha llogaters. <a :href="route('llogaters.index')" class="text-amber-600 hover:underline">Afegeix-ne</a>.
                                    </p>
                                    <p v-else class="text-sm italic text-gray-400">Tots els llogaters ja estan afegits.</p>

                                    <p v-if="contracteForm.errors.llogater_ids" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.llogater_ids }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="contracteForm.processing"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50"
                                >
                                    {{ selectedLloguer.contracte_actiu ? 'Actualitzar contracte' : 'Crear contracte' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Moviments panel -->
                <div
                    v-if="selectedLloguer"
                    class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Moviments — {{ selectedLloguer.compte_corrent?.nom ?? 'Compte corrent' }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ movimentsTotal }} moviments en total
                                </p>
                            </div>
                        </div>

                        <div v-if="movimentsLoading && moviments.length === 0" class="py-8 text-center text-sm text-gray-400">
                            Carregant…
                        </div>

                        <div v-else-if="moviments.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="w-8 px-3 py-3"></th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Data</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Saldo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="moviment in moviments"
                                        :key="moviment.id"
                                        :class="moviment.exclou_lloguer ? 'opacity-40' : ''"
                                        class="transition-opacity"
                                    >
                                        <td class="px-3 py-3 text-center">
                                            <input
                                                type="checkbox"
                                                :checked="moviment.exclou_lloguer"
                                                @change="toggleExclou(moviment)"
                                                title="Exclou del lloguer"
                                                class="rounded border-gray-300 text-red-500 focus:ring-red-400"
                                            />
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {{ moviment.data_moviment }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 max-w-xs truncate">
                                            {{ moviment.concepte }}
                                        </td>
                                        <td
                                            class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium"
                                            :class="parseFloat(moviment.import) >= 0
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-red-600 dark:text-red-400'"
                                        >
                                            {{ formatCurrency(moviment.import) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                            {{ moviment.saldo_posterior ? formatCurrency(moviment.saldo_posterior) : '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-if="movimentsHasMore" class="mt-4 flex justify-center">
                                <button
                                    @click="loadMore"
                                    :disabled="movimentsLoading"
                                    class="rounded-md border border-amber-300 px-4 py-2 text-sm text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-600 dark:text-amber-400 dark:hover:bg-amber-900/20"
                                >
                                    {{ movimentsLoading ? 'Carregant…' : `Mostrar-ne més (${movimentsTotal - moviments.length} restants)` }}
                                </button>
                            </div>
                        </div>

                        <div v-else class="py-8 text-center text-sm italic text-gray-400">
                            No hi ha moviments per a aquest compte corrent.
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Lloguer Modal -->
        <div
            v-if="showLloguerModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="closeLloguerModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submitLloguer">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3 class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                                {{ isEditingLloguer ? 'Editar Lloguer' : 'Nou Lloguer' }}
                            </h3>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="nom" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom *</label>
                                    <input
                                        id="nom"
                                        v-model="lloguerForm.nom"
                                        type="text"
                                        required
                                        maxlength="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.nom }}</p>
                                </div>

                                <div>
                                    <label for="acronim" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Acrònim</label>
                                    <input
                                        id="acronim"
                                        v-model="lloguerForm.acronim"
                                        type="text"
                                        maxlength="20"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.acronim" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.acronim }}</p>
                                </div>

                                <div>
                                    <label for="base_euros" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Base (€/mes)</label>
                                    <input
                                        id="base_euros"
                                        v-model="lloguerForm.base_euros"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.base_euros" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.base_euros }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="immoble_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Immoble *</label>
                                    <select
                                        id="immoble_id"
                                        v-model="lloguerForm.immoble_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona un immoble</option>
                                        <option v-for="immoble in immobles" :key="immoble.id" :value="immoble.id">{{ immoble.adreca }}</option>
                                    </select>
                                    <p v-if="lloguerForm.errors.immoble_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.immoble_id }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="compte_corrent_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte corrent *</label>
                                    <select
                                        id="compte_corrent_id"
                                        v-model="lloguerForm.compte_corrent_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona un compte corrent</option>
                                        <option v-for="cc in comptesCorrents" :key="cc.id" :value="cc.id">{{ cc.nom }}</option>
                                    </select>
                                    <p v-if="lloguerForm.errors.compte_corrent_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.compte_corrent_id }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="lloguerForm.processing"
                                class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditingLloguer ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeLloguerModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
