<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CategoryTreeNode from '@/Components/CategoryTreeNode.vue';
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

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

// Flatten categories with full path (for search + parent dropdown)
const allCategories = computed(() => {
    const flatten = (cats: Categoria[], path: string[] = []): Array<Categoria & { level: number; full_path: string }> => {
        let result: Array<Categoria & { level: number; full_path: string }> = [];
        cats.forEach(cat => {
            const currentPath = [...path, cat.nom];
            result.push({ ...cat, level: path.length, full_path: currentPath.join(' > ') });
            if (cat.fills && cat.fills.length > 0) {
                result = result.concat(flatten(cat.fills, currentPath));
            }
        });
        return result;
    };
    return flatten(props.categories);
});

// ── Cercador ─────────────────────────────────────────────────────────
const cerca = ref('');

const resultsCerca = computed(() => {
    const q = cerca.value.trim().toLowerCase();
    if (!q) return [];
    return allCategories.value.filter(c =>
        c.nom.toLowerCase().includes(q) || c.full_path.toLowerCase().includes(q)
    );
});

// ── Totals ────────────────────────────────────────────────────────────
interface TotalsResult {
    ingressos: number;
    despeses: number;
    net: number;
    total: number;
}

const showTotalsModal = ref(false);
const totalsCategoria = ref<(Categoria & { full_path: string }) | null>(null);
const totalsMode = ref<'any_curs' | 'any_anterior' | 'tot' | 'personalitzat'>('any_curs');
const totalsDataInici = ref('');
const totalsDataFi = ref('');
const totalsLoading = ref(false);
const totalsResult = ref<TotalsResult | null>(null);
const totalsError = ref('');

const obreTotals = (categoria: Categoria) => {
    const flat = allCategories.value.find(c => c.id === categoria.id);
    totalsCategoria.value = flat ?? { ...categoria, level: 0, full_path: categoria.nom };
    totalsMode.value = 'any_curs';
    totalsDataInici.value = '';
    totalsDataFi.value = '';
    totalsResult.value = null;
    totalsError.value = '';
    showTotalsModal.value = true;
};

const calcularTotals = async () => {
    if (!totalsCategoria.value) return;
    totalsLoading.value = true;
    totalsResult.value = null;
    totalsError.value = '';

    const any = new Date().getFullYear();
    let dataInici = totalsDataInici.value;
    let dataFi = totalsDataFi.value;

    if (totalsMode.value === 'any_curs') {
        dataInici = `${any}-01-01`;
        dataFi    = `${any}-12-31`;
    } else if (totalsMode.value === 'any_anterior') {
        dataInici = `${any - 1}-01-01`;
        dataFi    = `${any - 1}-12-31`;
    } else if (totalsMode.value === 'tot') {
        dataInici = '';
        dataFi    = '';
    }

    try {
        const csrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        const res = await fetch(route('categories.totals', totalsCategoria.value.id) + `?data_inici=${dataInici}&data_fi=${dataFi}`, {
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrf ? decodeURIComponent(csrf[1]) : '',
            },
        });
        totalsResult.value = await res.json();
    } catch {
        totalsError.value = 'Error en obtenir els totals.';
    } finally {
        totalsLoading.value = false;
    }
};

watch(totalsMode, () => { totalsResult.value = null; });

const formatCurrency = (v: number) =>
    new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(v);

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

const deleteCategoria = (categoriaId: number) => {
    const categoria = allCategories.value.find(c => c.id === categoriaId);
    if (categoria && confirm(`Estàs segur que vols eliminar la categoria "${categoria.nom}"? Això també eliminarà totes les seves subcategories.`)) {
        router.delete(route('categories.destroy', categoriaId));
    }
};

// Track expanded/collapsed state for each category
const expandedCategories = ref<Set<number>>(new Set());

const toggleCategory = (categoriaId: number) => {
    if (expandedCategories.value.has(categoriaId)) {
        expandedCategories.value.delete(categoriaId);
    } else {
        expandedCategories.value.add(categoriaId);
    }
};

const isCategoryExpanded = (categoriaId: number) => {
    return expandedCategories.value.has(categoriaId);
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
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
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

                        <!-- Cercador -->
                        <div class="mb-4">
                            <input
                                v-model="cerca"
                                type="search"
                                placeholder="Cercar categoria..."
                                class="block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                            />
                        </div>

                        <!-- Resultats de cerca -->
                        <div v-if="cerca.trim()" class="mb-6">
                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ resultsCerca.length }} resultat{{ resultsCerca.length !== 1 ? 's' : '' }}
                            </p>
                            <div v-if="resultsCerca.length" class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                                <div
                                    v-for="cat in resultsCerca"
                                    :key="cat.id"
                                    class="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                >
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ cat.full_path }}</span>
                                    <div class="flex items-center gap-2 ml-4 shrink-0">
                                        <button
                                            @click="obreTotals(cat)"
                                            class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                            title="Calcular totals"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                        </button>
                                        <button @click="openCreateModal(cat.id)" class="rounded-md bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700">Afegir subcategoria</button>
                                        <button @click="openEditModal(cat)" class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700">Editar</button>
                                        <button @click="deleteCategoria(cat.id)" class="rounded-md bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700">Eliminar</button>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Cap categoria coincideix amb la cerca.
                            </div>
                        </div>

                        <!-- Header with Add Button -->
                        <div v-if="!cerca.trim()" class="mb-6 flex items-center justify-between">
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
                        <div v-if="!cerca.trim() && categories.length > 0" class="space-y-2">
                            <CategoryTreeNode
                                v-for="categoria in categories"
                                :key="categoria.id"
                                :categoria="categoria"
                                :level="0"
                                :is-expanded="isCategoryExpanded(categoria.id)"
                                :expanded-categories="expandedCategories"
                                @toggle="toggleCategory"
                                @create-child="openCreateModal"
                                @edit="openEditModal"
                                @delete="deleteCategoria"
                                @calcular-totals="obreTotals"
                            />
                        </div>

                        <!-- Empty State -->
                        <div v-else-if="!cerca.trim()" class="py-12 text-center">
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
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Categoria Pare (opcional)
                                    </label>
                                    <CategoryTreeSelect
                                        :categories="allCategories"
                                        v-model="form.categoria_pare_id"
                                        :allow-none="true"
                                        placeholder="Cap (categoria arrel)"
                                        :disabled-id="isEditing && editingCategoria ? editingCategoria.id : undefined"
                                    />
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
        <!-- Modal totals -->
        <div v-if="showTotalsModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showTotalsModal = false"></div>
                <div class="relative w-full max-w-md rounded-lg bg-white dark:bg-gray-800 shadow-xl">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-medium text-gray-900 dark:text-gray-100">Totals de categoria</h3>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ totalsCategoria?.full_path }}</p>
                        </div>
                        <button @click="showTotalsModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-xl leading-none">&times;</button>
                    </div>
                    <div class="px-6 py-4 space-y-4">
                        <!-- Selector de període -->
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="totalsMode" value="any_curs" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Any en curs ({{ new Date().getFullYear() }})</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="totalsMode" value="any_anterior" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Any anterior ({{ new Date().getFullYear() - 1 }})</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="totalsMode" value="tot" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Tots els temps</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="totalsMode" value="personalitzat" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Rang personalitzat</span>
                            </label>
                        </div>

                        <!-- Dates personalitzades -->
                        <div v-if="totalsMode === 'personalitzat'" class="flex gap-3">
                            <div class="flex-1">
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Des de</label>
                                <input type="date" v-model="totalsDataInici" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                            </div>
                            <div class="flex-1">
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Fins a</label>
                                <input type="date" v-model="totalsDataFi" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                            </div>
                        </div>

                        <!-- Resultat -->
                        <div v-if="totalsLoading" class="text-center text-sm text-gray-500 dark:text-gray-400 py-4">Calculant...</div>
                        <div v-else-if="totalsError" class="rounded-md bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">{{ totalsError }}</div>
                        <div v-else-if="totalsResult" class="rounded-md bg-gray-50 dark:bg-gray-700/50 p-4 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Ingressos</span>
                                <span class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(totalsResult.ingressos) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Despeses</span>
                                <span class="font-medium text-red-600 dark:text-red-400">{{ formatCurrency(totalsResult.despeses) }}</span>
                            </div>
                            <div class="flex justify-between text-sm border-t border-gray-200 dark:border-gray-600 pt-2 mt-2">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Net</span>
                                <span class="font-semibold" :class="totalsResult.net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(totalsResult.net) }}</span>
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500 pt-1">{{ totalsResult.total }} moviment{{ totalsResult.total !== 1 ? 's' : '' }}</p>
                        </div>
                    </div>
                    <div class="px-6 py-3 border-t border-gray-200 dark:border-gray-700 flex justify-between">
                        <button @click="showTotalsModal = false" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600">
                            Tancar
                        </button>
                        <button
                            @click="calcularTotals"
                            :disabled="totalsLoading || (totalsMode === 'personalitzat' && (!totalsDataInici || !totalsDataFi))"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Calcular
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
