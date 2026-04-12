<script setup lang="ts">
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import { ref, watch } from 'vue';

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

interface Props {
    open: boolean;
    count: number;
    categories: Categoria[];
    conceptes?: string[];
    saving?: boolean;
    error?: string;
}

const props = withDefaults(defineProps<Props>(), {
    conceptes: () => [],
    saving: false,
    error: '',
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'submit': [payload: BulkEditFormData];
}>();

const form = ref<BulkEditFormData>({ concepte: '', notes: '', categoria_id: null });

watch(() => props.open, (val) => {
    if (val) form.value = { concepte: '', notes: '', categoria_id: null };
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
                                <CategoryTreeSelect
                                    :categories="categories"
                                    v-model="form.categoria_id"
                                    :allow-none="true"
                                    placeholder="— sense canvis —"
                                />
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
