<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import BalancCategoriaFila from '@/Components/BalancCategoriaFila.vue';
import { ref, watch, computed } from 'vue';
import { Bar } from 'vue-chartjs';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Tooltip,
    Legend,
} from 'chart.js';
import type { TooltipItem } from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    Tooltip,
    Legend,
);

interface BalancCategoria {
    id: number;
    nom: string;
    ingressos: number;
    despeses: number;
    net: number;
    fills: BalancCategoria[];
}

interface Periode {
    etiqueta: string;
    ingressos: number;
    despeses: number;
    net: number;
}

interface BalancData {
    compte: { id: number; nom: string };
    vista: string;
    data_inici: string;
    data_fi: string;
    periodes: Periode[];
    totals: { ingressos: number; despeses: number; net: number };
    categories: BalancCategoria[];
}

const props = defineProps<{
    show: boolean;
    compteCorrentId: number | null;
    compteCorrentNom: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const avui = () => new Date().toISOString().slice(0, 10);
const primerDiaAny = () => `${new Date().getFullYear()}-01-01`;

const vista = ref<'mensual' | 'anual'>('mensual');
const dataInici = ref<string>(primerDiaAny());
const dataFi = ref<string>(avui());
const loading = ref(false);
const error = ref<string | null>(null);
const dades = ref<BalancData | null>(null);
const mostrarCategories = ref(false);

const xsrfToken = () => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
};

const carregarDades = async () => {
    if (!props.compteCorrentId) return;
    if (!dataInici.value || !dataFi.value) return;
    loading.value = true;
    error.value = null;
    try {
        const params = new URLSearchParams({
            vista: vista.value,
            data_inici: dataInici.value,
            data_fi: dataFi.value,
        });
        const url = route('comptes-corrents.balanc', props.compteCorrentId) + '?' + params.toString();
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
        if (!res.ok) throw new Error('Error en la resposta del servidor');
        dades.value = await res.json();
    } catch {
        error.value = 'Error en obtenir les dades del balanc.';
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            vista.value = 'mensual';
            dataInici.value = primerDiaAny();
            dataFi.value = avui();
            dades.value = null;
            mostrarCategories.value = false;
            carregarDades();
        }
    },
);

watch(vista, () => carregarDades());
watch(dataInici, () => carregarDades());
watch(dataFi, () => carregarDades());

const formatEur = (val: number): string =>
    new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(val);

const chartData = computed(() => {
    if (!dades.value) return { labels: [], datasets: [] };
    const periodes = dades.value.periodes;
    return {
        labels: periodes.map(p => p.etiqueta),
        datasets: [
            {
                label: 'Ingressos',
                data: periodes.map(p => p.ingressos),
                backgroundColor: 'rgba(34, 197, 94, 0.7)',
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 1,
            },
            {
                label: 'Despeses',
                data: periodes.map(p => Math.abs(p.despeses)),
                backgroundColor: 'rgba(239, 68, 68, 0.7)',
                borderColor: 'rgba(239, 68, 68, 1)',
                borderWidth: 1,
            },
        ],
    };
});

const chartBalancData = computed(() => {
    if (!dades.value) return { labels: [], datasets: [] };
    const periodes = dades.value.periodes;
    return {
        labels: periodes.map(p => p.etiqueta),
        datasets: [
            {
                label: 'Balanç net',
                data: periodes.map(p => p.net),
                backgroundColor: periodes.map(p =>
                    p.net >= 0 ? 'rgba(59, 130, 246, 0.7)' : 'rgba(249, 115, 22, 0.7)'
                ),
                borderColor: periodes.map(p =>
                    p.net >= 0 ? 'rgba(59, 130, 246, 1)' : 'rgba(249, 115, 22, 1)'
                ),
                borderWidth: 1,
            },
        ],
    };
});

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top' as const,
        },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'bar'>) =>
                    `${ctx.dataset.label}: ${formatEur(Number(ctx.parsed.y))}`,
            },
        },
    },
    scales: {
        y: {
            ticks: {
                callback: (value: number | string) => formatEur(Number(value)),
            },
        },
    },
}));
</script>

<template>
    <Modal :show="show" max-width="5xl" @close="emit('close')">
        <div class="px-6 py-5">
            <!-- Capçalera -->
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    Balanç: {{ compteCorrentNom }}
                </h3>
                <button
                    @click="emit('close')"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Controls -->
            <div class="mb-4 flex flex-wrap items-center gap-4">
                <!-- Toggle mensual/anual -->
                <div class="flex rounded-md shadow-sm">
                    <button
                        @click="vista = 'mensual'"
                        :class="[
                            'px-4 py-2 text-sm font-medium rounded-l-md border',
                            vista === 'mensual'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600',
                        ]"
                    >Mensual</button>
                    <button
                        @click="vista = 'anual'"
                        :class="[
                            'px-4 py-2 text-sm font-medium rounded-r-md border-t border-b border-r',
                            vista === 'anual'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600',
                        ]"
                    >Anual</button>
                </div>

                <!-- Rang de dates -->
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600 dark:text-gray-400">De:</label>
                    <input
                        type="date"
                        v-model="dataInici"
                        :max="dataFi"
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                    <label class="text-sm text-gray-600 dark:text-gray-400">fins:</label>
                    <input
                        type="date"
                        v-model="dataFi"
                        :min="dataInici"
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                </div>
            </div>

            <!-- Loading / Error -->
            <div v-if="loading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Carregant...
            </div>
            <div v-else-if="error" class="py-4 text-center text-sm text-red-600 dark:text-red-400">
                {{ error }}
            </div>

            <template v-else-if="dades">
                <!-- Resum numeric -->
                <div class="mb-6 grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-green-50 p-4 text-center dark:bg-green-900/20">
                        <p class="text-xs font-medium uppercase tracking-wide text-green-600 dark:text-green-400">Ingressos</p>
                        <p class="mt-1 text-xl font-bold text-green-700 dark:text-green-300">{{ formatEur(dades.totals.ingressos) }}</p>
                    </div>
                    <div class="rounded-lg bg-red-50 p-4 text-center dark:bg-red-900/20">
                        <p class="text-xs font-medium uppercase tracking-wide text-red-600 dark:text-red-400">Despeses</p>
                        <p class="mt-1 text-xl font-bold text-red-700 dark:text-red-300">{{ formatEur(dades.totals.despeses) }}</p>
                    </div>
                    <div
                        class="rounded-lg p-4 text-center"
                        :class="dades.totals.net >= 0 ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-orange-50 dark:bg-orange-900/20'"
                    >
                        <p
                            class="text-xs font-medium uppercase tracking-wide"
                            :class="dades.totals.net >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600 dark:text-orange-400'"
                        >Balanç Net</p>
                        <p
                            class="mt-1 text-xl font-bold"
                            :class="dades.totals.net >= 0 ? 'text-blue-700 dark:text-blue-300' : 'text-orange-700 dark:text-orange-300'"
                        >{{ formatEur(dades.totals.net) }}</p>
                    </div>
                </div>

                <!-- Grafica ingressos / despeses -->
                <div class="mb-4 h-64">
                    <Bar :data="chartData" :options="chartOptions" />
                </div>

                <!-- Grafica balanç net -->
                <div class="mb-6 h-48">
                    <Bar :data="chartBalancData" :options="chartOptions" />
                </div>

                <!-- Toggle categories -->
                <div class="mb-3 flex items-center gap-2">
                    <button
                        @click="mostrarCategories = !mostrarCategories"
                        class="flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    >
                        <svg
                            class="h-4 w-4 transition-transform"
                            :class="mostrarCategories ? 'rotate-90' : ''"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        Desglossament per categories
                    </button>
                </div>

                <!-- Taula jerarquica de categories -->
                <div v-if="mostrarCategories">
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Categoria</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Ingressos</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">Despeses</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-blue-600 dark:text-blue-400">Net</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <template v-for="cat in dades.categories" :key="cat.id">
                                    <BalancCategoriaFila :cat="cat" :nivell="0" :format-eur="formatEur" />
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </Modal>
</template>
