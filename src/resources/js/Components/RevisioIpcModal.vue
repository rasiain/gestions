<script setup lang="ts">
import { ref, watch, computed } from 'vue';

interface RevisioIpc {
    id: number;
    lloguer_id: number;
    any_aplicacio: number;
    base_anterior: string;
    base_nova: string;
    ipc_percentatge: string;
    data_efectiva: string;
    mesos_regularitzats: number;
}

interface Lloguer {
    id: number;
    nom: string;
    base_euros: string | null;
}

const props = defineProps<{
    lloguer: Lloguer;
    show: boolean;
}>();

const emit = defineEmits(['close', 'updated']);

const revisions = ref<RevisioIpc[]>([]);
const loading = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const form = ref({
    any_aplicacio: new Date().getFullYear(),
    ipc_percentatge: null as number | null,
    data_efectiva: '',
    regularitzar: false,
});

const baseAnterior = computed(() => props.lloguer.base_euros ? parseFloat(props.lloguer.base_euros) : 0);
const baseNova = computed(() => {
    if (!form.value.ipc_percentatge) return baseAnterior.value;
    return parseFloat((baseAnterior.value * (1 + form.value.ipc_percentatge / 100)).toFixed(2));
});

const csrfToken = (): string =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

const formatCurrency = (value: string | number | null): string => {
    if (value === null) return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(num);
};

const fetchRevisions = async () => {
    loading.value = true;
    try {
        const res = await fetch(`/lloguers/${props.lloguer.id}/revisions-ipc`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();
        revisions.value = json.data;
    } finally {
        loading.value = false;
    }
};

const submitRevisio = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const res = await fetch(`/lloguers/${props.lloguer.id}/revisions-ipc`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(form.value),
        });
        const json = await res.json();
        if (!res.ok) {
            errors.value = json.errors ?? { general: json.message ?? 'Error' };
            return;
        }
        form.value.ipc_percentatge = null;
        form.value.data_efectiva = '';
        form.value.regularitzar = false;
        await fetchRevisions();
        emit('updated');
    } finally {
        saving.value = false;
    }
};

watch(() => props.show, (val) => {
    if (val) {
        fetchRevisions();
    }
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex min-h-screen items-center justify-center px-4 pt-4 pb-20 text-center sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity dark:bg-gray-900 dark:bg-opacity-75" @click="emit('close')"></div>
            <div class="relative inline-block w-full max-w-2xl transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:align-middle">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Revisions IPC — {{ lloguer.nom }}
                    </h3>
                    <button @click="emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Content -->
                <div class="max-h-[60vh] overflow-y-auto px-6 py-4">
                    <!-- Existing revisions -->
                    <div v-if="loading" class="py-4 text-center text-sm text-gray-400">Carregant...</div>
                    <div v-else-if="revisions.length > 0" class="mb-6">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Revisions registrades</h4>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Any</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">IPC %</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Base anterior</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Base nova</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Data efectiva</th>
                                    <th class="px-3 py-2 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Regularitzats</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr v-for="rev in revisions" :key="rev.id">
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ rev.any_aplicacio }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ rev.ipc_percentatge }}%</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(rev.base_anterior) }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(rev.base_nova) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ rev.data_efectiva }}</td>
                                    <td class="px-3 py-2 text-sm text-center text-gray-900 dark:text-gray-100">{{ rev.mesos_regularitzats }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="mb-6 text-sm italic text-gray-400">Cap revisio IPC registrada.</div>

                    <!-- New revision form -->
                    <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Nova revisio IPC</h4>
                        <form @submit.prevent="submitRevisio" class="space-y-4">
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Any</label>
                                    <input v-model.number="form.any_aplicacio" type="number" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <p v-if="errors.any_aplicacio" class="mt-1 text-xs text-red-600">{{ errors.any_aplicacio }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IPC %</label>
                                    <input v-model.number="form.ipc_percentatge" type="number" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <p v-if="errors.ipc_percentatge" class="mt-1 text-xs text-red-600">{{ errors.ipc_percentatge }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data efectiva</label>
                                    <input v-model="form.data_efectiva" type="date" required class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <p v-if="errors.data_efectiva" class="mt-1 text-xs text-red-600">{{ errors.data_efectiva }}</p>
                                </div>
                            </div>

                            <!-- Preview -->
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/40">
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Base anterior</span>
                                    <span class="font-mono">{{ formatCurrency(baseAnterior) }}</span>
                                </div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-400 mt-1">
                                    <span>Base nova (calculada)</span>
                                    <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ formatCurrency(baseNova) }}</span>
                                </div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-400 mt-1">
                                    <span>Diferencia mensual</span>
                                    <span class="font-mono" :class="baseNova - baseAnterior > 0 ? 'text-green-600' : 'text-red-600'">{{ formatCurrency(baseNova - baseAnterior) }}</span>
                                </div>
                            </div>

                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.regularitzar"
                                    class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
                                />
                                Regularitzar factures existents de l'any
                            </label>

                            <p v-if="errors.general" class="text-sm text-red-600">{{ errors.general }}</p>

                            <div class="flex justify-end gap-3">
                                <button
                                    type="submit"
                                    :disabled="saving"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                                >
                                    {{ saving ? 'Desant...' : 'Crear revisio' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Footer -->
                <div class="border-t border-gray-200 px-6 py-3 dark:border-gray-700 flex justify-end">
                    <button
                        @click="emit('close')"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Tancar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
