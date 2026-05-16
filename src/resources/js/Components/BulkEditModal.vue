<script setup lang="ts">
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { ref, computed, watch } from 'vue';

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
}

export interface BulkEditFormData {
    concepte: string;
    notes: string;
    categoria_id: number | null;
}

interface CategoriaSuggeriment {
    categoria_id: number;
    nom: string;
    full_path: string;
    total: number;
    imports: number[];
}

interface Props {
    open: boolean;
    count: number;
    categories: Categoria[];
    compteCorrentId?: number | null;
    conceptes?: string[];
    suggeriments?: CategoriaSuggeriment[];
    saving?: boolean;
    error?: string;
}

const props = withDefaults(defineProps<Props>(), {
    compteCorrentId: null,
    conceptes: () => [],
    suggeriments: () => [],
    saving: false,
    error: '',
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'submit': [payload: BulkEditFormData];
    'category-created': [categoria: Categoria];
}>();

const form = ref<BulkEditFormData>({ concepte: '', notes: '', categoria_id: null });
const internalCategories = ref<Categoria[]>([...props.categories]);

// ── Creació inline de nova categoria ────────────────────────────
const showNewCategory = ref(false);
const newCatNom = ref('');
const newCatParentId = ref<number | null>(null);
const newCatCreating = ref(false);
const newCatError = ref('');

const flatCategoryOptions = computed(() => {
    const build = (parentId: number | null, prefix: string): { id: number; path: string }[] =>
        internalCategories.value
            .filter(c => c.categoria_pare_id === parentId)
            .sort((a, b) => a.nom.localeCompare(b.nom))
            .flatMap(c => [{ id: c.id, path: prefix + c.nom }, ...build(c.id, prefix + c.nom + ' > ')]);
    return build(null, '');
});

const openNewCategory = () => {
    newCatParentId.value = typeof form.value.categoria_id === 'number' ? form.value.categoria_id : null;
    newCatNom.value = '';
    newCatError.value = '';
    showNewCategory.value = true;
};

const createCategory = async () => {
    if (!newCatNom.value.trim() || !props.compteCorrentId) return;
    newCatCreating.value = true;
    newCatError.value = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const response = await fetch(route('categories.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                compte_corrent_id: props.compteCorrentId,
                nom: newCatNom.value.trim(),
                categoria_pare_id: newCatParentId.value,
            }),
        });
        if (!response.ok) {
            const data = await response.json();
            newCatError.value = Object.values(data.errors ?? {}).flat().join(', ') || 'Error creant la categoria.';
            return;
        }
        const newCat: Categoria = await response.json();
        internalCategories.value = [...internalCategories.value, newCat];
        form.value.categoria_id = newCat.id;
        showNewCategory.value = false;
        emit('category-created', newCat);
    } catch {
        newCatError.value = 'Error de xarxa.';
    } finally {
        newCatCreating.value = false;
    }
};

watch(() => props.open, (val) => {
    if (val) {
        form.value = { concepte: '', notes: '', categoria_id: null };
        internalCategories.value = [...props.categories];
        showNewCategory.value = false;
        newCatNom.value = '';
        newCatError.value = '';
    }
});

const close = () => emit('update:open', false);
const onSubmit = () => emit('submit', { ...form.value });
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="close" />
            <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

            <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                <form @submit.prevent="onSubmit">
                    <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                        <h3 class="mb-1 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                            Edició múltiple
                        </h3>
                        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                            {{ count }} moviment{{ count !== 1 ? 's' : '' }} seleccionat{{ count !== 1 ? 's' : '' }}.
                            Només s'aplicaran els camps que empleneu; la resta es conservarà.
                        </p>

                        <p v-if="error" class="mb-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label>
                                <input
                                    v-model="form.concepte"
                                    type="text"
                                    maxlength="255"
                                    placeholder="— sense canvis —"
                                    :list="conceptes.length ? 'bulk-edit-concepte-options' : undefined"
                                    autocomplete="off"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                />
                                <datalist v-if="conceptes.length" id="bulk-edit-concepte-options">
                                    <option v-for="c in conceptes" :key="c" :value="c" />
                                </datalist>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="2"
                                    maxlength="500"
                                    placeholder="— sense canvis —"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Categoria</label>

                                <!-- Suggeriments basats en historial -->
                                <div v-if="suggeriments.length > 0" class="mb-2 rounded-md border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-2">
                                    <p class="text-xs font-medium text-blue-700 dark:text-blue-300 mb-1.5">Categories usades per aquests conceptes:</p>
                                    <div class="space-y-1">
                                        <button
                                            v-for="sug in suggeriments"
                                            :key="sug.categoria_id"
                                            type="button"
                                            @click="form.categoria_id = sug.categoria_id"
                                            class="flex w-full items-center justify-between rounded px-2 py-1 text-xs transition-colors text-left"
                                            :class="form.categoria_id === sug.categoria_id
                                                ? 'bg-blue-600 text-white'
                                                : 'bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-200 hover:bg-blue-200 dark:hover:bg-blue-700'"
                                            :title="sug.full_path"
                                        >
                                            <span class="font-medium">{{ sug.nom }} <span class="opacity-60">({{ sug.total }})</span></span>
                                            <span class="ml-2 opacity-75 tabular-nums">{{ sug.imports.map(v => v.toLocaleString('ca-ES', { minimumFractionDigits: 2 }) + ' €').join(', ') }}</span>
                                        </button>
                                    </div>
                                </div>

                                <CategoryTreeSelect
                                    :categories="internalCategories"
                                    v-model="form.categoria_id"
                                    :allow-none="true"
                                    placeholder="— sense canvis —"
                                />

                                <!-- Crear nova categoria inline -->
                                <div v-if="compteCorrentId" class="mt-2">
                                    <button
                                        v-if="!showNewCategory"
                                        type="button"
                                        @click="openNewCategory"
                                        class="mt-1 inline-flex items-center gap-1 rounded border border-indigo-300 dark:border-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 px-2 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/50"
                                    >+ Nova categoria</button>

                                    <div v-else class="mt-2 rounded-md border border-indigo-200 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-900/20 p-3 space-y-2">
                                        <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300">Nova categoria</p>

                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Nom</label>
                                            <input
                                                v-model="newCatNom"
                                                type="text"
                                                placeholder="Nom de la categoria"
                                                @keyup.enter="createCategory"
                                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm"
                                            />
                                        </div>

                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Penja de (opcional)</label>
                                            <select
                                                v-model="newCatParentId"
                                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm"
                                            >
                                                <option :value="null">— Arrel —</option>
                                                <option v-for="opt in flatCategoryOptions" :key="opt.id" :value="opt.id">
                                                    {{ opt.path }}
                                                </option>
                                            </select>
                                        </div>

                                        <p v-if="newCatError" class="text-xs text-red-600 dark:text-red-400">{{ newCatError }}</p>

                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                @click="createCategory"
                                                :disabled="!newCatNom.trim() || newCatCreating"
                                                class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                            >{{ newCatCreating ? 'Creant...' : 'Crear i seleccionar' }}</button>
                                            <button
                                                type="button"
                                                @click="showNewCategory = false"
                                                class="inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-1 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50"
                                            >Cancel·lar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                        <button
                            type="submit"
                            :disabled="saving"
                            class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            {{ saving ? 'Guardant…' : 'Aplicar' }}
                        </button>
                        <button
                            type="button"
                            @click="close"
                            class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                        >
                            Cancel·lar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
