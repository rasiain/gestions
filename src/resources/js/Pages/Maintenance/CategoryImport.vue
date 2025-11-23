<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    ordre: number;
}

interface CategoryNode {
    name: string;
    type: string;
    level: number;
    children: CategoryNode[];
}

interface ValidationResult {
    valid: boolean;
    errors: string[];
    warnings: string[];
}

interface ParsedData {
    total_categories: number;
    total_ingressos: number;
    total_despeses: number;
    categories_ingressos: CategoryNode[];
    categories_despeses: CategoryNode[];
    validation: ValidationResult;
}

interface Props {
    comptesCorrents: CompteCorrent[];
}

const props = defineProps<Props>();

const selectedFile = ref<File | null>(null);
const selectedCompteCorrent = ref<number | null>(null);
const isProcessing = ref<boolean>(false);
const isParsed = ref<boolean>(false);
const parsedData = ref<ParsedData | null>(null);
const errorMessage = ref<string>('');
const successMessage = ref<string>('');

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        selectedFile.value = target.files[0];
        isParsed.value = false;
        parsedData.value = null;
        errorMessage.value = '';
        successMessage.value = '';
    }
};

const parseFile = async () => {
    if (!selectedFile.value) {
        errorMessage.value = 'Selecciona un fitxer primer';
        return;
    }

    if (!selectedCompteCorrent.value) {
        errorMessage.value = 'Selecciona un compte corrent';
        return;
    }

    isProcessing.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    parsedData.value = null;

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);
        formData.append('compte_corrent_id', selectedCompteCorrent.value.toString());

        const response = await axios.post('/maintenance/categories/import/parse', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        parsedData.value = response.data.data;
        isParsed.value = true;
    } catch (error: any) {
        if (error.response?.data) {
            errorMessage.value = error.response.data.message || 'Error processant el fitxer';
        } else {
            errorMessage.value = 'Error de xarxa';
        }
        console.error('Error parsing file:', error);
    } finally {
        isProcessing.value = false;
    }
};

const importCategories = async () => {
    if (!selectedFile.value || !selectedCompteCorrent.value) {
        return;
    }

    isProcessing.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);
        formData.append('compte_corrent_id', selectedCompteCorrent.value.toString());

        const response = await axios.post('/maintenance/categories/import', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const stats = response.data.data.stats;
        successMessage.value = `Categories importades: ${stats.total_created} creades, ${stats.total_skipped} omeses`;

        // Reset form
        resetForm();
    } catch (error: any) {
        if (error.response?.data) {
            errorMessage.value = error.response.data.message || 'Error important les categories';
        } else {
            errorMessage.value = 'Error de xarxa';
        }
        console.error('Error importing categories:', error);
    } finally {
        isProcessing.value = false;
    }
};

const resetForm = () => {
    selectedFile.value = null;
    isParsed.value = false;
    parsedData.value = null;
    errorMessage.value = '';
    const fileInput = document.getElementById('file-upload') as HTMLInputElement;
    if (fileInput) fileInput.value = '';
};

const renderCategoryTree = (categories: CategoryNode[], indent: number = 0): string => {
    let result = '';
    categories.forEach((cat) => {
        result += '  '.repeat(indent) + cat.name + '\n';
        if (cat.children && cat.children.length > 0) {
            result += renderCategoryTree(cat.children, indent + 1);
        }
    });
    return result;
};
</script>

<template>
    <Head title="Importar Categories" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Importar Categories des de KMyMoney
                </h2>
                <Link
                    :href="route('dashboard')"
                    class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600"
                >
                    ← Tornar al Dashboard
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Upload Section -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Seleccionar fitxer de categories KMyMoney
                        </h3>

                        <div class="space-y-4">
                            <!-- Compte Corrent Selector -->
                            <div>
                                <label for="compte_corrent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Compte corrent
                                </label>
                                <select
                                    id="compte_corrent"
                                    v-model="selectedCompteCorrent"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                >
                                    <option :value="null">Selecciona un compte corrent</option>
                                    <option
                                        v-for="compte in comptesCorrents"
                                        :key="compte.id"
                                        :value="compte.id"
                                    >
                                        {{ compte.nom || compte.compte_corrent }} - {{ compte.entitat }}
                                    </option>
                                </select>
                            </div>

                            <!-- File Input -->
                            <div>
                                <label for="file-upload" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Fitxer de categories
                                </label>
                                <input
                                    id="file-upload"
                                    type="file"
                                    accept=".txt,.csv,.qif"
                                    @change="handleFileChange"
                                    class="block w-full text-sm text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer bg-gray-50 dark:bg-gray-700"
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Formats suportats: .txt, .csv, .qif (Màxim: 10MB)
                                </p>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3">
                                <button
                                    @click="parseFile"
                                    :disabled="!selectedFile || !selectedCompteCorrent || isProcessing"
                                    class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span v-if="isProcessing">Analitzant...</span>
                                    <span v-else>Analitzar fitxer</span>
                                </button>
                                <button
                                    @click="resetForm"
                                    type="button"
                                    class="inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600"
                                >
                                    Netejar
                                </button>
                            </div>

                            <!-- Success Message -->
                            <div
                                v-if="successMessage"
                                class="rounded-md bg-green-50 dark:bg-green-900/20 p-4"
                            >
                                <p class="text-sm text-green-800 dark:text-green-200">
                                    {{ successMessage }}
                                </p>
                            </div>

                            <!-- Error Message -->
                            <div
                                v-if="errorMessage"
                                class="rounded-md bg-red-50 dark:bg-red-900/20 p-4"
                            >
                                <p class="text-sm text-red-800 dark:text-red-200">
                                    {{ errorMessage }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preview Section -->
                <div
                    v-if="isParsed && parsedData"
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Vista prèvia de categories a importar
                        </h3>

                        <!-- Validation Errors -->
                        <div
                            v-if="parsedData.validation.errors.length > 0"
                            class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4"
                        >
                            <p class="text-sm font-medium text-red-800 dark:text-red-200 mb-2">Errors:</p>
                            <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300">
                                <li v-for="(error, index) in parsedData.validation.errors" :key="index">
                                    {{ error }}
                                </li>
                            </ul>
                        </div>

                        <!-- Validation Warnings -->
                        <div
                            v-if="parsedData.validation.warnings.length > 0"
                            class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 p-4"
                        >
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200 mb-2">Avisos:</p>
                            <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300">
                                <li v-for="(warning, index) in parsedData.validation.warnings" :key="index">
                                    {{ warning }}
                                </li>
                            </ul>
                        </div>

                        <!-- Stats -->
                        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Total</p>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    {{ parsedData.total_categories }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-green-50 dark:bg-green-900/20 p-4">
                                <p class="text-xs text-green-600 dark:text-green-400">Ingressos</p>
                                <p class="text-2xl font-semibold text-green-700 dark:text-green-300">
                                    {{ parsedData.total_ingressos }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4">
                                <p class="text-xs text-red-600 dark:text-red-400">Despeses</p>
                                <p class="text-2xl font-semibold text-red-700 dark:text-red-300">
                                    {{ parsedData.total_despeses }}
                                </p>
                            </div>
                        </div>

                        <!-- Category Trees -->
                        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <!-- Ingressos -->
                            <div v-if="parsedData.total_ingressos > 0">
                                <p class="text-sm font-medium text-green-700 dark:text-green-300 mb-2">Categories d'Ingressos:</p>
                                <pre class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg text-sm text-gray-900 dark:text-gray-100 overflow-x-auto">{{ renderCategoryTree(parsedData.categories_ingressos) }}</pre>
                            </div>

                            <!-- Despeses -->
                            <div v-if="parsedData.total_despeses > 0">
                                <p class="text-sm font-medium text-red-700 dark:text-red-300 mb-2">Categories de Despeses:</p>
                                <pre class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg text-sm text-gray-900 dark:text-gray-100 overflow-x-auto">{{ renderCategoryTree(parsedData.categories_despeses) }}</pre>
                            </div>
                        </div>

                        <!-- Import Button -->
                        <div v-if="parsedData.validation.valid">
                            <button
                                @click="importCategories"
                                :disabled="isProcessing"
                                class="inline-flex justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="isProcessing">Important...</span>
                                <span v-else>Confirmar i importar categories</span>
                            </button>
                        </div>
                        <div v-else class="text-sm text-red-600 dark:text-red-400">
                            No es pot importar per errors de validació
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
