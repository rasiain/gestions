<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    bank_type: string | null;
    last_import_type: string | null;
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
    balance_warnings?: string[];
    requires_import_mode_selection?: boolean;
    errors?: string[];
    balance_validation_failed?: boolean;
    preview_limited?: boolean;
    total_movements?: number;
}

interface Props {
    comptesCorrents: CompteCorrent[];
    selectedCompteCorrentId?: number | null;
}

const props = defineProps<Props>();

const selectedFile = ref<File | null>(null);
const selectedCompteCorrent = ref<number | null>(props.selectedCompteCorrentId ?? null);
const selectedBankType = ref<string | null>(null);
const isProcessing = ref<boolean>(false);
const isParsed = ref<boolean>(false);
const parsedData = ref<ParsedData | null>(null);
const errorMessage = ref<string>('');
const successMessage = ref<string>('');
const countWarning = ref<string | null>(null);

const selectedCompte = computed(() => {
    if (!selectedCompteCorrent.value) return null;
    return props.comptesCorrents.find(c => c.id === selectedCompteCorrent.value);
});

const bankTypeOptions = [
    { value: 'caixa_enginyers', label: "Caixa d'Enginyers" },
    { value: 'caixabank',       label: 'CaixaBank' },
    { value: 'kmymoney',        label: 'KMyMoney (QIF)' },
];

const defaultBankType = (compte: CompteCorrent | undefined): string | null =>
    compte?.bank_type ?? compte?.last_import_type ?? null;

// Quan canvia el compte (per navegació Inertia o selecció manual), sincronitzem
// selectedCompteCorrent amb el prop i auto-seleccionem el tipus d'importació.
watch(() => props.selectedCompteCorrentId, (newId) => {
    selectedCompteCorrent.value = newId ?? null;
}, { immediate: false });

watch(selectedCompteCorrent, (newId) => {
    const compte = props.comptesCorrents.find(c => c.id === newId);
    selectedBankType.value = defaultBankType(compte);
    isParsed.value = false;
    parsedData.value = null;
}, { immediate: true });

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        selectedFile.value = target.files[0];
        isParsed.value = false;
        parsedData.value = null;
        errorMessage.value = '';
        successMessage.value = '';
        countWarning.value = null;

        // Auto-select bank type based on file extension if not already selected
        if (!selectedBankType.value) {
            const fileName = target.files[0].name.toLowerCase();
            if (fileName.endsWith('.qif')) {
                selectedBankType.value = 'kmymoney';
            } else if (selectedCompte.value?.bank_type) {
                selectedBankType.value = selectedCompte.value.bank_type;
            }
        }
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

    if (!selectedBankType.value) {
        errorMessage.value = 'Selecciona el tipus d\'importació';
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
        formData.append('bank_type', selectedBankType.value);

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
    if (!selectedFile.value || !selectedCompteCorrent.value || !selectedBankType.value) {
        return;
    }

    isProcessing.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);
        formData.append('compte_corrent_id', selectedCompteCorrent.value.toString());
        formData.append('bank_type', selectedBankType.value);
        // Enviar tots els hashes exclosos: els actuals (sense recalcular) + els de rondes anteriors
        const allExcluded = new Set([...excludedHashes.value, ...committedExcludedHashes.value]);
        allExcluded.forEach(hash => formData.append('excluded_hashes[]', hash));

        const response = await axios.post('/maintenance/movements/import', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const stats = response.data.data.stats;
        successMessage.value = `Moviments importats: ${stats.created} creats`;
        countWarning.value = response.data.data.count_warning ?? null;

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
    errorMessage.value = '';
    previewOrdre.value = 'desc';
    previewDataInici.value = '';
    previewDataFi.value = '';
    excludedHashes.value = new Set();
    committedExcludedHashes.value = new Set();
    const fileInput = document.getElementById('file-upload') as HTMLInputElement;
    if (fileInput) fileInput.value = '';
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

const previewOrdre = ref<'asc' | 'desc'>('desc');
const previewDataInici = ref<string>('');
const previewDataFi = ref<string>('');
const excludedHashes = ref<Set<string>>(new Set());
// Hashes exclosos confirmats (acumulats a través de múltiples rondes de recàlcul)
const committedExcludedHashes = ref<Set<string>>(new Set());

const toggleExcluded = (hash: string) => {
    const s = new Set(excludedHashes.value);
    if (s.has(hash)) {
        s.delete(hash);
    } else {
        s.add(hash);
    }
    excludedHashes.value = s;
};

// Recalcula el saldo per a cada moviment no exclòs, en ordre cronològic,
// partint del saldo_posterior de l'últim moviment exclòs (o del darrer moviment de la BD).
const computedSaldos = computed((): Record<string, number | null> => {
    const all = [...(parsedData.value?.movements ?? [])].sort((a, b) =>
        a.data_moviment.localeCompare(b.data_moviment)
    );

    const rawBase = parsedData.value?.last_db_movement?.saldo_posterior;
    let base: number | null = rawBase != null ? Number(rawBase) : null;
    const result: Record<string, number | null> = {};

    for (const m of all) {
        const importVal = Number(m.import);
        const saldoVal = m.saldo_posterior != null ? Number(m.saldo_posterior) : null;

        if (excludedHashes.value.has(m.hash)) {
            if (saldoVal !== null) {
                base = saldoVal;
            } else if (base !== null) {
                base = base + importVal;
            }
            result[m.hash] = null;
        } else {
            if (base !== null) {
                base = base + importVal;
                result[m.hash] = base;
            } else if (saldoVal !== null) {
                result[m.hash] = saldoVal;
                base = saldoVal;
            } else {
                result[m.hash] = null;
            }
        }
    }

    return result;
});

const filteredMovements = computed(() => {
    if (!parsedData.value?.movements) return [];
    return parsedData.value.movements.filter(m => {
        if (previewDataInici.value && m.data_moviment < previewDataInici.value) return false;
        if (previewDataFi.value && m.data_moviment > previewDataFi.value) return false;
        return true;
    });
});

const sortedMovements = computed(() => {
    return [...filteredMovements.value].sort((a, b) => {
        const cmp = a.data_moviment.localeCompare(b.data_moviment);
        return previewOrdre.value === 'asc' ? cmp : -cmp;
    });
});

const activeCount = computed(() =>
    (parsedData.value?.movements ?? []).filter(m => !excludedHashes.value.has(m.hash)).length
);

const recalcularSaldos = () => {
    if (!parsedData.value) return;
    const saldos = computedSaldos.value;
    // Acumular els hashes exclosos al conjunt persistent
    excludedHashes.value.forEach(h => committedExcludedHashes.value.add(h));
    // Actualitzar saldos i eliminar exclosos de la llista de previsualització
    parsedData.value.movements = parsedData.value.movements
        .filter(m => !excludedHashes.value.has(m.hash))
        .map(m => ({
            ...m,
            saldo_posterior: saldos[m.hash] !== undefined ? saldos[m.hash] : m.saldo_posterior,
        }));
    parsedData.value.total_movements = parsedData.value.movements.length;
    excludedHashes.value = new Set();
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
                    :href="route('moviments.index')"
                    class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600"
                >
                    ← Tornar a Moviments
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8 space-y-6">
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

                            <!-- Bank Type Selector -->
                            <div>
                                <label for="bank_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Tipus d'importació
                                </label>
                                <select
                                    id="bank_type"
                                    v-model="selectedBankType"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                >
                                    <option :value="null">Selecciona el format del fitxer</option>
                                    <option
                                        v-for="option in bankTypeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Selecciona el format del fitxer que vols importar (pot ser diferent del banc del compte)
                                </p>
                            </div>

                            <!-- File Input -->
                            <div>
                                <label for="file-upload" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Fitxer de moviments
                                </label>
                                <input
                                    id="file-upload"
                                    type="file"
                                    accept=".xls,.xlsx,.csv,.txt,.qif,.html"
                                    @change="handleFileChange"
                                    class="block w-full text-sm text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer bg-gray-50 dark:bg-gray-700"
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Formats suportats: .xls, .xlsx, .csv, .txt, .qif, .html (Màxim: 100MB)
                                </p>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3">
                                <button
                                    @click="parseFile"
                                    :disabled="!selectedFile || !selectedCompteCorrent || !selectedBankType || isProcessing"
                                    class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span v-if="isProcessing">Analitzant...</span>
                                    <span v-else">Analitzar fitxer</span>
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

                            <!-- Count Warning -->
                            <div
                                v-if="countWarning"
                                class="rounded-md bg-amber-50 dark:bg-amber-900/20 p-4"
                            >
                                <div class="flex items-start gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                                    </svg>
                                    <p class="text-sm text-amber-800 dark:text-amber-200">
                                        <strong>Discrepància en el recompte:</strong> {{ countWarning }}
                                    </p>
                                </div>
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
                            Vista prèvia de moviments a importar
                        </h3>

                        <!-- Balance Warnings (no bloquejants) -->
                        <div
                            v-if="parsedData.balance_warnings && parsedData.balance_warnings.length > 0"
                            class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 p-4 border-2 border-yellow-400 dark:border-yellow-600"
                        >
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200 mb-2">
                                Avis: discrepancies de saldo detectades
                            </p>
                            <p class="text-xs text-yellow-700 dark:text-yellow-300 mb-2">
                                Els saldos del fitxer no coincideixen amb els calculats per l'aplicació. La importacio continuara igualment, pero convé verificar les dades.
                            </p>
                            <ul class="list-disc list-inside text-xs text-yellow-700 dark:text-yellow-300 space-y-1">
                                <li v-for="(warning, index) in parsedData.balance_warnings" :key="index">
                                    {{ warning }}
                                </li>
                            </ul>
                        </div>

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
                            <div class="mb-3 flex flex-wrap items-end gap-4">
                                <div>
                                    <label for="preview-data-inici" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Data inici</label>
                                    <input
                                        id="preview-data-inici"
                                        v-model="previewDataInici"
                                        type="date"
                                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label for="preview-data-fi" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Data fi</label>
                                    <input
                                        id="preview-data-fi"
                                        v-model="previewDataFi"
                                        type="date"
                                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label for="preview-ordre" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ordre</label>
                                    <select
                                        id="preview-ordre"
                                        v-model="previewOrdre"
                                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option value="desc">Més recent primer</option>
                                        <option value="asc">Més antic primer</option>
                                    </select>
                                </div>
                                <div class="flex items-end gap-3">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Mostrant {{ sortedMovements.length }} de {{ parsedData.total_movements }} moviments
                                        <span v-if="excludedHashes.size > 0" class="text-amber-600 dark:text-amber-400">
                                            · {{ excludedHashes.size }} exclosos
                                        </span>
                                    </p>
                                    <button
                                        v-if="excludedHashes.size > 0"
                                        @click="recalcularSaldos"
                                        type="button"
                                        class="inline-flex items-center rounded-md border border-amber-400 bg-amber-50 dark:bg-amber-900/20 px-3 py-1.5 text-sm font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/40"
                                    >
                                        Aplicar exclusions i recalcular saldos
                                    </button>
                                </div>
                            </div>
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-3 w-8"></th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Data</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Concepte</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Import</th>
                                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Saldo</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Categoria</th>
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr
                                        v-for="(movement, index) in sortedMovements"
                                        :key="index"
                                        :class="excludedHashes.has(movement.hash) ? 'opacity-40' : ''"
                                    >
                                        <td class="px-3 py-2 text-center">
                                            <input
                                                type="checkbox"
                                                :checked="!excludedHashes.has(movement.hash)"
                                                @change="toggleExcluded(movement.hash)"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                                title="Desmarcar per excloure de la importació"
                                            />
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm" :class="excludedHashes.has(movement.hash) ? 'line-through text-gray-400' : 'text-gray-900 dark:text-gray-100'">
                                            {{ formatDate(movement.data_moviment) }}
                                        </td>
                                        <td class="px-3 py-2 text-sm" :class="excludedHashes.has(movement.hash) ? 'line-through text-gray-400' : 'text-gray-900 dark:text-gray-100'">
                                            {{ movement.concepte }}
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right" :class="excludedHashes.has(movement.hash) ? 'line-through text-gray-400' : movement.import >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                            {{ formatCurrency(movement.import) }}
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right" :class="excludedHashes.has(movement.hash) ? 'text-gray-400' : 'text-gray-900 dark:text-gray-100'">
                                            {{ computedSaldos[movement.hash] !== null && computedSaldos[movement.hash] !== undefined ? formatCurrency(computedSaldos[movement.hash]!) : '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">
                                            {{ movement.categoria_path || '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 max-w-xs truncate">
                                            {{ movement.notes }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Import Button -->
                        <div v-if="parsedData.movements && parsedData.movements.length > 0">
                            <button
                                @click="importMovements"
                                :disabled="isProcessing"
                                class="inline-flex justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span v-if="isProcessing">Important...</span>
                                <span v-else>Confirmar i importar {{ activeCount }} moviments<span v-if="excludedHashes.size > 0"> ({{ excludedHashes.size }} exclosos)</span></span>
                            </button>
                            <p v-if="sortedMovements.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                Cap moviment coincideix amb el filtre de dates. La importació inclourà tots igualment.
                            </p>
                        </div>
                        <div v-else class="text-sm text-gray-600 dark:text-gray-400">
                            No hi ha moviments per importar
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </AuthenticatedLayout>
</template>
