<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

interface ScannedFile {
    name: string;
    path: string;
    size: number;
    modified: string;
}

interface PreviewMovement {
    data_moviment: string;
    concepte: string;
    import: number;
    saldo_posterior: number;
    categoria_path: string | null;
    hash: string;
}

interface PreviewData {
    movements: PreviewMovement[];
    to_import_count: number;
    duplicates_skipped: number;
    compte_corrent_id: number;
    compte_nom: string;
    compte_iban: string;
    bank_type: string;
    file_path: string;
    balance_warnings: string[];
    warnings: string[];
}

type Step = 'scan' | 'preview' | 'importing' | 'done';

const step = ref<Step>('scan');
const files = ref<ScannedFile[]>([]);
const scanning = ref(false);
const error = ref('');
const preview = ref<PreviewData | null>(null);
const importResult = ref<{ created: number; skipped: number } | null>(null);

const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;

const scanFiles = async () => {
    scanning.value = true;
    error.value = '';
    try {
        const res = await fetch(route('importar.scan'));
        files.value = await res.json();
        if (files.value.length === 1) {
            await selectFile(files.value[0]);
        }
    } catch (e) {
        error.value = 'Error escanejant fitxers';
    } finally {
        scanning.value = false;
    }
};

const selectFile = async (file: ScannedFile) => {
    scanning.value = true;
    error.value = '';
    try {
        const res = await fetch(route('importar.preview'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ file_path: file.path }),
        });
        const json = await res.json();
        if (json.success) {
            preview.value = json.data;
            step.value = 'preview';
        } else {
            error.value = json.message || json.error || 'Error analitzant el fitxer';
        }
    } catch (e: any) {
        error.value = e?.message || 'Error analitzant el fitxer';
    } finally {
        scanning.value = false;
    }
};

const confirmImport = async () => {
    if (!preview.value) return;
    step.value = 'importing';
    error.value = '';
    try {
        const res = await fetch(route('importar.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({
                file_path: preview.value.file_path,
                compte_corrent_id: preview.value.compte_corrent_id,
                bank_type: preview.value.bank_type,
            }),
        });
        const json = await res.json();
        if (json.success) {
            importResult.value = json.data.stats;
            step.value = 'done';
        } else {
            error.value = json.message || 'Error important';
            step.value = 'preview';
        }
    } catch (e) {
        error.value = 'Error important els moviments';
        step.value = 'preview';
    }
};

const reset = () => {
    step.value = 'scan';
    files.value = [];
    preview.value = null;
    importResult.value = null;
    error.value = '';
    scanFiles();
};

const formatCurrency = (v: number) =>
    v.toLocaleString('ca-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';

const formatSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    return (bytes / 1024).toFixed(0) + ' KB';
};

onMounted(scanFiles);
</script>

<template>
    <Head title="Importar moviments" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Importar moviments
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">

                        <!-- Error -->
                        <div v-if="error" class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">
                            {{ error }}
                        </div>

                        <!-- STEP: Scan -->
                        <div v-if="step === 'scan'">
                            <div v-if="scanning" class="flex items-center gap-2 text-sm text-gray-500">
                                <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Escanejant fitxers...
                            </div>

                            <div v-else-if="files.length === 0" class="text-center py-8">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No s'han trobat fitxers importables a Downloads</p>
                                <button @click="scanFiles" class="mt-4 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    Tornar a escanejar
                                </button>
                            </div>

                            <div v-else>
                                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                                    Fitxers trobats a Downloads:
                                </p>
                                <div class="space-y-2">
                                    <button
                                        v-for="file in files"
                                        :key="file.path"
                                        @click="selectFile(file)"
                                        class="flex w-full items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                    >
                                        <div class="flex items-center gap-3">
                                            <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            <div>
                                                <p class="text-sm font-medium">{{ file.name }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatSize(file.size) }} · {{ file.modified }}</p>
                                            </div>
                                        </div>
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- STEP: Preview -->
                        <div v-if="step === 'preview' && preview">
                            <div class="mb-4 rounded-md bg-blue-50 dark:bg-blue-900/20 p-3">
                                <p class="text-sm font-medium text-blue-800 dark:text-blue-200">{{ preview.compte_nom }}</p>
                                <p class="text-xs text-blue-600 dark:text-blue-400">{{ preview.compte_iban }}</p>
                            </div>

                            <div class="mb-4 flex items-center gap-4 text-sm">
                                <span class="rounded-full bg-green-100 dark:bg-green-900/30 px-3 py-1 font-medium text-green-700 dark:text-green-300">
                                    {{ preview.to_import_count }} nous
                                </span>
                                <span v-if="preview.duplicates_skipped > 0" class="rounded-full bg-gray-100 dark:bg-gray-700 px-3 py-1 text-gray-600 dark:text-gray-400">
                                    {{ preview.duplicates_skipped }} duplicats
                                </span>
                            </div>

                            <!-- Warnings -->
                            <div v-if="preview.balance_warnings?.length" class="mb-4 rounded-md bg-amber-50 dark:bg-amber-900/20 p-3">
                                <p class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-1">Warnings de saldo:</p>
                                <ul class="text-xs text-amber-600 dark:text-amber-400 space-y-0.5">
                                    <li v-for="(w, i) in preview.balance_warnings" :key="i">{{ w }}</li>
                                </ul>
                            </div>

                            <!-- Movements table -->
                            <div v-if="preview.movements.length > 0" class="overflow-x-auto mb-4">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b dark:border-gray-700">
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Data</th>
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Concepte</th>
                                            <th class="pb-2 text-right font-medium text-gray-500 dark:text-gray-400">Import</th>
                                            <th class="pb-2 text-left font-medium text-gray-500 dark:text-gray-400">Categoria</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(m, i) in preview.movements" :key="i" class="border-b dark:border-gray-700/50">
                                            <td class="py-1.5 pr-3 whitespace-nowrap">{{ m.data_moviment }}</td>
                                            <td class="py-1.5 pr-3">{{ m.concepte }}</td>
                                            <td class="py-1.5 pr-3 text-right whitespace-nowrap font-medium" :class="m.import >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                                {{ formatCurrency(m.import) }}
                                            </td>
                                            <td class="py-1.5 text-xs text-gray-500 dark:text-gray-400">{{ m.categoria_path || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div v-else class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                                No hi ha moviments nous per importar.
                            </div>

                            <div class="flex gap-3">
                                <button
                                    v-if="preview.to_import_count > 0"
                                    @click="confirmImport"
                                    class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    Importar {{ preview.to_import_count }} moviment{{ preview.to_import_count !== 1 ? 's' : '' }}
                                </button>
                                <button
                                    @click="reset"
                                    class="inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600"
                                >
                                    Tornar
                                </button>
                            </div>
                        </div>

                        <!-- STEP: Importing -->
                        <div v-if="step === 'importing'" class="flex items-center gap-2 py-8 justify-center text-sm text-gray-500">
                            <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Important moviments...
                        </div>

                        <!-- STEP: Done -->
                        <div v-if="step === 'done' && importResult" class="text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="mt-3 text-lg font-medium">Importació completada</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ importResult.created }} moviment{{ importResult.created !== 1 ? 's' : '' }} creat{{ importResult.created !== 1 ? 's' : '' }}
                                <span v-if="importResult.skipped > 0">, {{ importResult.skipped }} duplicat{{ importResult.skipped !== 1 ? 's' : '' }}</span>
                            </p>
                            <button
                                @click="reset"
                                class="mt-4 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            >
                                Importar un altre fitxer
                            </button>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
