<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    ordre: number;
}

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
    fills?: Categoria[];
    created_at: string;
    updated_at: string;
}

interface Props {
    categories: Categoria[];
    comptesCorrents: CompteCorrent[];
    selectedCompteCorrentId: number | null;
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingCategoria = ref<Categoria | null>(null);
const selectedCompteCorrent = ref<number | null>(props.selectedCompteCorrentId);

const form = useForm({
    compte_corrent_id: props.selectedCompteCorrentId as number,
    nom: '',
    categoria_pare_id: null as number | null,
    ordre: 0,
});

// Watch for compte corrent changes and reload the page
const onCompteCorrentChange = () => {
    router.get(route('categories.index'), {
        compte_corrent_id: selectedCompteCorrent.value
    }, {
        preserveState: false,
        preserveScroll: false,
    });
};

// Flatten categories for parent selection dropdown
const allCategories = computed(() => {
    const flatten = (cats: Categoria[], level = 0): Array<Categoria & { level: number }> => {
        let result: Array<Categoria & { level: number }> = [];
        cats.forEach(cat => {
            result.push({ ...cat, level });
            if (cat.fills && cat.fills.length > 0) {
                result = result.concat(flatten(cat.fills, level + 1));
            }
        });
        return result;
    };
    return flatten(props.categories);
});

const openCreateModal = (parentId: number | null = null) => {
    isEditing.value = false;
    editingCategoria.value = null;
    form.reset();
    form.compte_corrent_id = selectedCompteCorrent.value as number;
    form.categoria_pare_id = parentId;
    showModal.value = true;
};

const openEditModal = (categoria: Categoria) => {
    isEditing.value = true;
    editingCategoria.value = categoria;
    form.compte_corrent_id = categoria.compte_corrent_id;
    form.nom = categoria.nom;
    form.categoria_pare_id = categoria.categoria_pare_id;
    form.ordre = categoria.ordre;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    isEditing.value = false;
    editingCategoria.value = null;
};

const submit = () => {
    if (isEditing.value && editingCategoria.value) {
        form.put(route('categories.update', editingCategoria.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('categories.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteCategoria = (categoria: Categoria) => {
    if (confirm(`Estàs segur que vols eliminar la categoria "${categoria.nom}"? Això també eliminarà totes les seves subcategories.`)) {
        router.delete(route('categories.destroy', categoria.id));
    }
};
</script>

<template>
    <Head title="Categories" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Categories
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Compte Corrent Selector -->
                        <div class="mb-6">
                            <label for="compte_corrent_selector" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Selecciona un compte corrent
                            </label>
                            <select
                                id="compte_corrent_selector"
                                v-model="selectedCompteCorrent"
                                @change="onCompteCorrentChange"
                                class="block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
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

                        <!-- Header with Add Button -->
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Arbre de Categories
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestiona les categories d'ingressos i despeses
                                </p>
                            </div>
                            <button
                                @click="openCreateModal()"
                                :disabled="!selectedCompteCorrent"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg
                                    class="-ml-1 mr-2 h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                    />
                                </svg>
                                Afegir Categoria Arrel
                            </button>
                        </div>

                        <!-- Hierarchical Category Tree -->
                        <div v-if="categories.length > 0" class="space-y-2">
                            <template v-for="categoria in categories" :key="categoria.id">
                                <!-- Root Category -->
                                <div class="rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                                    <div class="flex items-center justify-between p-4">
                                        <div class="flex items-center space-x-3">
                                            <span class="text-lg font-semibold">{{ categoria.nom }}</span>
                                            <span class="text-sm text-gray-500">Ordre: {{ categoria.ordre }}</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <button
                                                @click="openCreateModal(categoria.id)"
                                                class="text-sm text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300"
                                            >
                                                + Subcategoria
                                            </button>
                                            <button
                                                @click="openEditModal(categoria)"
                                                class="text-sm text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteCategoria(categoria)"
                                                class="text-sm text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Child Categories -->
                                    <div v-if="categoria.fills && categoria.fills.length > 0" class="border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                                        <div
                                            v-for="child in categoria.fills"
                                            :key="child.id"
                                            class="flex items-center justify-between border-b border-gray-100 p-3 pl-8 last:border-b-0 dark:border-gray-700"
                                        >
                                            <div class="flex items-center space-x-3">
                                                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                                <span>{{ child.nom }}</span>
                                                <span class="text-xs text-gray-500">Ordre: {{ child.ordre }}</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <button
                                                    @click="openEditModal(child)"
                                                    class="text-sm text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    @click="deleteCategoria(child)"
                                                    class="text-sm text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="py-12 text-center">
                            <svg
                                class="mx-auto h-12 w-12 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"
                                />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                No hi ha categories
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Comença afegint la primera categoria.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div
                    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    aria-hidden="true"
                    @click="closeModal"
                ></div>

                <!-- Center modal -->
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div
                    class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle"
                >
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3
                                class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100"
                                id="modal-title"
                            >
                                {{ isEditing ? 'Editar Categoria' : 'Nova Categoria' }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Nom -->
                                <div>
                                    <label
                                        for="nom"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Nom
                                    </label>
                                    <input
                                        id="nom"
                                        v-model="form.nom"
                                        type="text"
                                        required
                                        maxlength="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.nom }}
                                    </p>
                                </div>

                                <!-- Categoria Pare -->
                                <div>
                                    <label
                                        for="categoria_pare_id"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Categoria Pare (opcional)
                                    </label>
                                    <select
                                        id="categoria_pare_id"
                                        v-model="form.categoria_pare_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Cap (categoria arrel)</option>
                                        <option
                                            v-for="cat in allCategories"
                                            :key="cat.id"
                                            :value="cat.id"
                                            :disabled="!!(isEditing && editingCategoria && cat.id === editingCategoria.id)"
                                        >
                                            {{ '—'.repeat(cat.level) }} {{ cat.nom }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.categoria_pare_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.categoria_pare_id }}
                                    </p>
                                </div>

                                <!-- Ordre -->
                                <div>
                                    <label
                                        for="ordre"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Ordre
                                    </label>
                                    <input
                                        id="ordre"
                                        v-model.number="form.ordre"
                                        type="number"
                                        min="0"
                                        max="255"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.ordre" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.ordre }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
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
