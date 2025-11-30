<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    bank_type: string | null;
    ordre: number;
}

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
}

interface MovimentCompteCorrent {
    id: number;
    compte_corrent_id: number;
    data_moviment: string;
    concepte: string;
    import: number;
    saldo_posterior: number | null;
    categoria_id: number | null;
    hash_moviment: string;
    created_at: string;
    updated_at: string;
    categoria?: Categoria;
    compte_corrent?: CompteCorrent;
}

interface PaginatedMoviments {
    data: MovimentCompteCorrent[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

interface Filters {
    search: string | null;
    categoria_id: number | null;
    data_inici: string | null;
    data_fi: string | null;
    tipus: 'ingressos' | 'despeses' | null;
}

interface Stats {
    total_ingressos: number;
    total_despeses: number;
    saldo_actual: number | null;
}

interface Props {
    comptesCorrents: CompteCorrent[];
    selectedCompteCorrentId: number | null;
    moviments: PaginatedMoviments;
    categories: Categoria[];
    filters: Filters;
    stats: Stats;
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingMoviment = ref<MovimentCompteCorrent | null>(null);
const showDeleteConfirm = ref(false);
const movimentToDelete = ref<MovimentCompteCorrent | null>(null);

const form = useForm({
    compte_corrent_id: props.selectedCompteCorrentId,
    data_moviment: '',
    concepte: '',
    import: 0 as number | string,
    saldo_posterior: null as number | string | null,
    categoria_id: null as number | null,
});

const filterForm = useForm({
    compte_corrent_id: props.selectedCompteCorrentId,
    search: props.filters.search || '',
    categoria_id: props.filters.categoria_id || null,
    data_inici: props.filters.data_inici || '',
    data_fi: props.filters.data_fi || '',
    tipus: props.filters.tipus || null,
});

const selectedCompte = computed(() => {
    if (!props.selectedCompteCorrentId) return null;
    return props.comptesCorrents.find(c => c.id === props.selectedCompteCorrentId);
});

const onCompteCorrentChange = () => {
    router.get('/moviments', {
        compte_corrent_id: filterForm.compte_corrent_id,
    }, {
        preserveState: false,
        preserveScroll: false,
    });
};

const applyFilters = () => {
    router.get('/moviments', filterForm.data(), {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    filterForm.search = '';
    filterForm.categoria_id = null;
    filterForm.data_inici = '';
    filterForm.data_fi = '';
    filterForm.tipus = null;
    applyFilters();
};

const openCreateModal = () => {
    isEditing.value = false;
    editingMoviment.value = null;
    form.reset();
    form.compte_corrent_id = props.selectedCompteCorrentId;
    form.data_moviment = new Date().toISOString().split('T')[0];
    showModal.value = true;
};

const openEditModal = (moviment: MovimentCompteCorrent) => {
    isEditing.value = true;
    editingMoviment.value = moviment;
    form.compte_corrent_id = moviment.compte_corrent_id;
    form.data_moviment = moviment.data_moviment;
    form.concepte = moviment.concepte;
    form.import = moviment.import;
    form.saldo_posterior = moviment.saldo_posterior;
    form.categoria_id = moviment.categoria_id;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
};

const submitForm = () => {
    if (isEditing.value && editingMoviment.value) {
        form.put(route('moviments.update', editingMoviment.value.id), {
            onSuccess: () => {
                closeModal();
            },
        });
    } else {
        form.post(route('moviments.store'), {
            onSuccess: () => {
                closeModal();
            },
        });
    }
};

const confirmDelete = (moviment: MovimentCompteCorrent) => {
    movimentToDelete.value = moviment;
    showDeleteConfirm.value = true;
};

const deleteMoviment = () => {
    if (movimentToDelete.value) {
        router.delete(route('moviments.destroy', movimentToDelete.value.id), {
            onSuccess: () => {
                showDeleteConfirm.value = false;
                movimentToDelete.value = null;
            },
        });
    }
};

const formatCurrency = (amount: number): string => {
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(amount);
};

const formatDate = (date: string): string => {
    return new Date(date).toLocaleDateString('ca-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
};

const getImportClass = (import_val: number): string => {
    if (import_val > 0) return 'text-green-600 dark:text-green-400';
    if (import_val < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};
</script>

<template>
    <Head title="Moviments" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Gestió de Moviments
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Compte Corrent Selector & Stats -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <div class="mb-6">
                            <label for="compte_corrent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Selecciona el compte corrent
                            </label>
                            <select
                                id="compte_corrent"
                                v-model="filterForm.compte_corrent_id"
                                @change="onCompteCorrentChange"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                            >
                                <option
                                    v-for="compte in comptesCorrents"
                                    :key="compte.id"
                                    :value="compte.id"
                                >
                                    {{ compte.nom || compte.compte_corrent }} - {{ compte.entitat }}
                                </option>
                            </select>
                        </div>

                        <!-- Stats Cards -->
                        <div v-if="selectedCompte" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-lg bg-green-50 dark:bg-green-900/20 p-4">
                                <p class="text-xs text-green-600 dark:text-green-400">Total Ingressos</p>
                                <p class="text-2xl font-semibold text-green-700 dark:text-green-300">
                                    {{ formatCurrency(stats.total_ingressos) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4">
                                <p class="text-xs text-red-600 dark:text-red-400">Total Despeses</p>
                                <p class="text-2xl font-semibold text-red-700 dark:text-red-300">
                                    {{ formatCurrency(stats.total_despeses) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4">
                                <p class="text-xs text-blue-600 dark:text-blue-400">Saldo Actual</p>
                                <p class="text-2xl font-semibold text-blue-700 dark:text-blue-300">
                                    {{ stats.saldo_actual !== null ? formatCurrency(stats.saldo_actual) : '-' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Filtres
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <!-- Search -->
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Cerca
                                </label>
                                <input
                                    id="search"
                                    v-model="filterForm.search"
                                    type="text"
                                    placeholder="Cerca per concepte..."
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                />
                            </div>

                            <!-- Categoria -->
                            <div>
                                <label for="categoria_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Categoria
                                </label>
                                <select
                                    id="categoria_id"
                                    v-model="filterForm.categoria_id"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                >
                                    <option :value="null">Totes les categories</option>
                                    <option
                                        v-for="categoria in categories"
                                        :key="categoria.id"
                                        :value="categoria.id"
                                    >
                                        {{ categoria.nom }}
                                    </option>
                                </select>
                            </div>

                            <!-- Tipus -->
                            <div>
                                <label for="tipus" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Tipus
                                </label>
                                <select
                                    id="tipus"
                                    v-model="filterForm.tipus"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                >
                                    <option :value="null">Tots</option>
                                    <option value="ingressos">Ingressos</option>
                                    <option value="despeses">Despeses</option>
                                </select>
                            </div>

                            <!-- Data Inici -->
                            <div>
                                <label for="data_inici" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Data Inici
                                </label>
                                <input
                                    id="data_inici"
                                    v-model="filterForm.data_inici"
                                    type="date"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                />
                            </div>

                            <!-- Data Fi -->
                            <div>
                                <label for="data_fi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Data Fi
                                </label>
                                <input
                                    id="data_fi"
                                    v-model="filterForm.data_fi"
                                    type="date"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                />
                            </div>
                        </div>

                        <div class="mt-4 flex gap-3">
                            <button
                                @click="applyFilters"
                                type="button"
                                class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                            >
                                Aplicar filtres
                            </button>
                            <button
                                @click="clearFilters"
                                type="button"
                                class="inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600"
                            >
                                Netejar filtres
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Moviments List -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                Llistat de Moviments
                            </h3>
                            <button
                                @click="openCreateModal"
                                :disabled="!selectedCompte"
                                class="inline-flex items-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Afegir Moviment
                            </button>
                        </div>

                        <div v-if="moviments.data.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                            No s'han trobat moviments
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Data
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Concepte
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Categoria
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Import
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Saldo
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Accions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr v-for="moviment in moviments.data" :key="moviment.id">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatDate(moviment.data_moviment) }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ moviment.concepte }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ moviment.categoria?.nom || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-right font-semibold" :class="getImportClass(moviment.import)">
                                            {{ formatCurrency(moviment.import) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-right text-gray-900 dark:text-gray-100">
                                            {{ moviment.saldo_posterior !== null ? formatCurrency(moviment.saldo_posterior) : '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                            <button
                                                @click="openEditModal(moviment)"
                                                class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="confirmDelete(moviment)"
                                                class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="moviments.last_page > 1" class="mt-4 flex items-center justify-between">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                Mostrant {{ moviments.data.length }} de {{ moviments.total }} moviments
                            </div>
                            <div class="flex gap-2">
                                <Link
                                    v-for="link in moviments.links"
                                    :key="link.label"
                                    :href="link.url || '#'"
                                    :class="[
                                        'px-3 py-2 text-sm rounded-md',
                                        link.active
                                            ? 'bg-indigo-600 text-white'
                                            : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600',
                                        !link.url ? 'opacity-50 cursor-not-allowed' : ''
                                    ]"
                                    v-html="link.label"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeModal"></div>

                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submitForm">
                        <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100 mb-4">
                                {{ isEditing ? 'Editar Moviment' : 'Afegir Moviment' }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Data -->
                                <div>
                                    <label for="data_moviment" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Data del moviment
                                    </label>
                                    <input
                                        id="data_moviment"
                                        v-model="form.data_moviment"
                                        type="date"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.data_moviment" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.data_moviment }}
                                    </p>
                                </div>

                                <!-- Concepte -->
                                <div>
                                    <label for="concepte" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Concepte
                                    </label>
                                    <input
                                        id="concepte"
                                        v-model="form.concepte"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.concepte" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.concepte }}
                                    </p>
                                </div>

                                <!-- Import -->
                                <div>
                                    <label for="import" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Import (€)
                                    </label>
                                    <input
                                        id="import"
                                        v-model="form.import"
                                        type="number"
                                        step="0.01"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Positiu per ingressos, negatiu per despeses
                                    </p>
                                    <p v-if="form.errors.import" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.import }}
                                    </p>
                                </div>

                                <!-- Saldo Posterior -->
                                <div>
                                    <label for="saldo_posterior" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Saldo posterior (opcional)
                                    </label>
                                    <input
                                        id="saldo_posterior"
                                        v-model="form.saldo_posterior"
                                        type="number"
                                        step="0.01"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.saldo_posterior" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.saldo_posterior }}
                                    </p>
                                </div>

                                <!-- Categoria -->
                                <div>
                                    <label for="categoria_id_modal" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Categoria (opcional)
                                    </label>
                                    <select
                                        id="categoria_id_modal"
                                        v-model="form.categoria_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense categoria</option>
                                        <option
                                            v-for="categoria in categories"
                                            :key="categoria.id"
                                            :value="categoria.id"
                                        >
                                            {{ categoria.nom }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.categoria_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.categoria_id }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-base font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showDeleteConfirm = false"></div>

                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100 mb-4">
                            Confirmar eliminació
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Estàs segur que vols eliminar aquest moviment? Aquesta acció no es pot desfer.
                        </p>
                        <div v-if="movimentToDelete" class="mt-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-md">
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                <strong>Data:</strong> {{ formatDate(movimentToDelete.data_moviment) }}
                            </p>
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                <strong>Concepte:</strong> {{ movimentToDelete.concepte }}
                            </p>
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                <strong>Import:</strong> {{ formatCurrency(movimentToDelete.import) }}
                            </p>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <button
                            type="button"
                            @click="deleteMoviment"
                            class="inline-flex w-full justify-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            Eliminar
                        </button>
                        <button
                            type="button"
                            @click="showDeleteConfirm = false"
                            class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-base font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            Cancel·lar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
