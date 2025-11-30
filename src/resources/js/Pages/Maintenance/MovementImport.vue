<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    bank_type: string | null;
    ordre: number;
}

interface Movement {
    data_moviment: string;
    concepte: string;
    import: number;
    saldo_posterior: number | null;
    notes: string | null;
    categoria_id: number | null;
    categoria_path: string | null;
    hash: string;
}

interface ParsedData {
    movements: Movement[];
    last_hash_found: boolean;
    last_db_movement: any | null;
    duplicates_skipped: number;
    to_import_count: number;
    warnings: string[];
    requires_import_mode_selection?: boolean;
    errors?: string[];
    balance_validation_failed?: boolean;
}

interface Props {
    comptesCorrents: CompteCorrent[];
}

const props = defineProps<Props>();

const selectedFile = ref<File | null>(null);
const selectedCompteCorrent = ref<number | null>(null);
const selectedImportMode = ref<string | null>(null);
const isProcessing = ref<boolean>(false);
const isParsed = ref<boolean>(false);
const parsedData = ref<ParsedData | null>(null);
const editedMovements = ref<Record<number, Partial<Movement>>>({});
const errorMessage = ref<string>('');
const successMessage = ref<string>('');

const selectedCompte = computed(() => {
    if (!selectedCompteCorrent.value) return null;
    return props.comptesCorrents.find(c => c.id === selectedCompteCorrent.value);
});

const bankTypeLabel = computed(() => {
    if (!selectedCompte.value?.bank_type) return '';
    const labels: Record<string, string> = {
        'caixa_enginyers': 'Caixa d\'Enginyers',
        'caixabank': 'CaixaBank',
        'kmymoney': 'KMyMoney'
    };
    return labels[selectedCompte.value.bank_type] || selectedCompte.value.bank_type;
});

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        selectedFile.value = target.files[0];
        isParsed.value = false;
        parsedData.value = null;
        editedMovements.value = {};
        selectedImportMode.value = null;
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

    if (!selectedCompte.value?.bank_type) {
        errorMessage.value = 'El compte corrent seleccionat no té tipus de banc assignat';
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
        formData.append('bank_type', selectedCompte.value.bank_type);

        const response = await axios.post('/maintenance/movements/import/parse', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        parsedData.value = response.data.data;
        isParsed.value = true;
    } catch (error: any) {
        if (error.response?.data) {
            errorMessage.value = error.response.data.message || 'Error processant el fitxer';
            if (error.response.data.data?.errors) {
                parsedData.value = { ...error.response.data.data };
            }
        } else {
            errorMessage.value = 'Error de xarxa';
        }
        console.error('Error parsing file:', error);
    } finally {
        isProcessing.value = false;
    }
};

const importMovements = async () => {
    if (!selectedFile.value || !selectedCompteCorrent.value || !selectedCompte.value?.bank_type) {
        return;
    }

    if (parsedData.value?.requires_import_mode_selection && !selectedImportMode.value) {
        errorMessage.value = 'Selecciona un mode d\'importació';
        return;
    }

    isProcessing.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);
        formData.append('compte_corrent_id', selectedCompteCorrent.value.toString());
        formData.append('bank_type', selectedCompte.value.bank_type);

        if (selectedImportMode.value) {
            formData.append('import_mode', selectedImportMode.value);
        }

        // Add edited movements
        if (Object.keys(editedMovements.value).length > 0) {
            formData.append('edited_movements', JSON.stringify(editedMovements.value));
        }

        const response = await axios.post('/maintenance/movements/import', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const stats = response.data.data.stats;
        successMessage.value = `Moviments importats: ${stats.created} creats`;

        // Reset form
        resetForm();
    } catch (error: any) {
        if (error.response?.data) {
            errorMessage.value = error.response.data.message || 'Error important els moviments';
        } else {
            errorMessage.value = 'Error de xarxa';
        }
        console.error('Error importing movements:', error);
    } finally {
        isProcessing.value = false;
    }
};

const resetForm = () => {
    selectedFile.value = null;
    isParsed.value = false;
    parsedData.value = null;
    editedMovements.value = {};
    selectedImportMode.value = null;
    errorMessage.value = '';
    const fileInput = document.getElementById('file-upload') as HTMLInputElement;
    if (fileInput) fileInput.value = '';
};

const handleMovementEdit = (index: number, field: keyof Movement, value: any) => {
    if (!editedMovements.value[index]) {
        editedMovements.value[index] = {};
    }
    editedMovements.value[index][field] = value;
};

const getEditedValue = (index: number, field: keyof Movement, originalValue: any) => {
    return editedMovements.value[index]?.[field] ?? originalValue;
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR'
    }).format(amount);
};

const formatDate = (date: string) => {
    const [year, month, day] = date.split('-');
    return `${day}/${month}/${year}`;
};
</script>

<template>
    <Head title="Importar Moviments Bancaris" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Importar Moviments Bancaris
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
                            Seleccionar fitxer de moviments bancaris
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

                            <!-- Bank Type Display -->
                            <div v-if="selectedCompte?.bank_type">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Tipus de banc
                                </label>
                                <div class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-900/20 px-3 py-1 text-sm font-medium text-indigo-700 dark:text-indigo-300">
                                    {{ bankTypeLabel }}
                                </div>
                            </div>

                            <!-- File Input -->
                            <div>
                                <label for="file-upload" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Fitxer de moviments
                                </label>
                                <input
                                    id="file-upload"
                                    type="file"
                                    accept=".xls,.xlsx,.csv,.txt,.qif"
                                    @change="handleFileChange"
                                    class="block w-full text-sm text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer bg-gray-50 dark:bg-gray-700"
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Formats suportats: .xls, .xlsx, .csv, .txt, .qif (Màxim: 10MB)
                                </p>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3">
                                <button
                                    @click="parseFile"
                                    :disabled="!selectedFile || !selectedCompteCorrent || !selectedCompte?.bank_type || isProcessing"
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

                <!-- Import Mode Selection -->
                <div
                    v-if="isParsed && parsedData?.requires_import_mode_selection"
                    class="overflow-hidden bg-yellow-50 dark:bg-yellow-900/20 shadow-sm sm:rounded-lg border-2 border-yellow-200 dark:border-yellow-800"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-yellow-900 dark:text-yellow-100 mb-4">
                            ⚠️ Selecciona el mode d'importació
                        </h3>
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input
                                    type="radio"
                                    v-model="selectedImportMode"
                                    value="from_beginning"
                                    class="mr-2"
                                />
                                <span class="text-sm text-yellow-900 dark:text-yellow-100">
                                    Importar des del principi del fitxer
                                </span>
                            </label>
                            <label class="flex items-center">
                                <input
                                    type="radio"
                                    v-model="selectedImportMode"
                                    value="from_last_db"
                                    class="mr-2"
                                />
                                <span class="text-sm text-yellow-900 dark:text-yellow-100">
                                    Importar des de l'última data a la base de dades
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Preview Section -->
                <div
                    v-if="isParsed && parsedData && !parsedData.balance_validation_failed"
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Vista prèvia de moviments a importar
                        </h3>

                        <!-- Balance Validation Errors -->
                        <div
                            v-if="parsedData.errors && parsedData.errors.length > 0"
                            class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4"
                        >
                            <p class="text-sm font-medium text-red-800 dark:text-red-200 mb-2">
                                ❌ Errors de validació de saldos:
                            </p>
                            <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300">
                                <li v-for="(error, index) in parsedData.errors" :key="index">
                                    {{ error }}
                                </li>
                            </ul>
                            <p class="mt-2 text-sm text-red-800 dark:text-red-200">
                                La importació s'ha aturat. Comprova el fitxer abans de continuar.
                            </p>
                        </div>

                        <!-- Warnings -->
                        <div
                            v-if="parsedData.warnings && parsedData.warnings.length > 0"
                            class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 p-4 border-2 border-yellow-300 dark:border-yellow-700"
                        >
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200 mb-2">
                                ⚠️ Advertències:
                            </p>
                            <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300">
                                <li v-for="(warning, index) in parsedData.warnings" :key="index">
                                    {{ warning }}
                                </li>
                            </ul>
                        </div>

                        <!-- Stats -->
                        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Duplicats omesos</p>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    {{ parsedData.duplicates_skipped }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4">
                                <p class="text-xs text-blue-600 dark:text-blue-400">A importar</p>
                                <p class="text-2xl font-semibold text-blue-700 dark:text-blue-300">
                                    {{ parsedData.to_import_count }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-indigo-50 dark:bg-indigo-900/20 p-4">
                                <p class="text-xs text-indigo-600 dark:text-indigo-400">Hash trobat</p>
                                <p class="text-lg font-semibold text-indigo-700 dark:text-indigo-300">
                                    {{ parsedData.last_hash_found ? 'Sí' : 'No' }}
                                </p>
                            </div>
                            <div v-if="parsedData.last_db_movement" class="rounded-lg bg-green-50 dark:bg-green-900/20 p-4">
                                <p class="text-xs text-green-600 dark:text-green-400">Últim moviment BD</p>
                                <p class="text-sm font-semibold text-green-700 dark:text-green-300">
                                    {{ formatDate(parsedData.last_db_movement.data_moviment) }}
                                </p>
                            </div>
                        </div>

                        <!-- Movements Table -->
                        <div v-if="parsedData.movements && parsedData.movements.length > 0" class="mb-6 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Data</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Concepte</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Import</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Saldo</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Categoria</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="(movement, index) in parsedData.movements.slice(0, 50)" :key="index">
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatDate(movement.data_moviment) }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                                            <input
                                                type="text"
                                                :value="getEditedValue(index, 'concepte', movement.concepte)"
                                                @input="(e) => handleMovementEdit(index, 'concepte', (e.target as HTMLInputElement).value)"
                                                class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs"
                                            />
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right" :class="movement.import >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                            {{ formatCurrency(movement.import) }}
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-gray-900 dark:text-gray-100">
                                            {{ movement.saldo_posterior !== null ? formatCurrency(movement.saldo_posterior) : '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">
                                            {{ movement.categoria_path || '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">
                                            {{ movement.notes || '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="parsedData.movements.length > 50" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Mostrant els primers 50 de {{ parsedData.movements.length }} moviments
                            </p>
                        </div>

                        <!-- Import Button -->
                        <div v-if="parsedData.movements && parsedData.movements.length > 0">
                            <button
                                @click="importMovements"
                                :disabled="isProcessing || (parsedData.requires_import_mode_selection && !selectedImportMode)"
                                class="inline-flex justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="isProcessing">Important...</span>
                                <span v-else>Confirmar i importar moviments</span>
                            </button>
                        </div>
                        <div v-else class="text-sm text-gray-600 dark:text-gray-400">
                            No hi ha moviments per importar
                        </div>
                    </div>
                </div>

                <!-- Balance Validation Failed Section -->
                <div
                    v-if="isParsed && parsedData?.balance_validation_failed"
                    class="overflow-hidden bg-red-50 dark:bg-red-900/20 shadow-sm sm:rounded-lg border-2 border-red-300 dark:border-red-700"
                >
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-red-900 dark:text-red-100 mb-4">
                            ❌ Error crític: Validació de saldos fallida
                        </h3>
                        <div v-if="parsedData.errors && parsedData.errors.length > 0">
                            <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300 space-y-1">
                                <li v-for="(error, index) in parsedData.errors" :key="index">
                                    {{ error }}
                                </li>
                            </ul>
                            <p class="mt-4 text-sm text-red-800 dark:text-red-200">
                                Els saldos del fitxer no coincideixen amb els calculats. Comprova el fitxer abans de continuar.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
