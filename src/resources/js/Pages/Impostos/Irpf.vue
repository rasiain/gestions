<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface MovimentDetall {
    data: string;
    import: number;
}

interface DespesesPerCategoria {
    comunitat: number;
    taxes: number;
    assegurança: number;
    compres: number;
    reparacions: number;
    altres: number;
}

interface LloguerIrpf {
    id: number;
    nom: string;
    immoble_adreca: string | null;
    total_ingressos: number;
    total_despeses: number;
    despeses_per_categoria: DespesesPerCategoria;
    resultat_net: number;
    moviments_ingressos: MovimentDetall[];
    moviments_despeses: Record<string, MovimentDetall[]>;
}

interface Totals {
    total_ingressos: number;
    total_despeses: number;
    despeses_per_categoria: DespesesPerCategoria;
    resultat_net: number;
}

interface Props {
    any: number;
    lloguers: LloguerIrpf[];
    totals: Totals;
}

const props = defineProps<Props>();

const categories: (keyof DespesesPerCategoria)[] = [
    'comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'altres',
];

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
}

function canviarAny(event: Event) {
    const target = event.target as HTMLSelectElement;
    router.get(route('impostos.irpf'), { any: target.value }, { preserveState: true });
}

const anyOpcions = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i);

const showDetall = ref(false);
const detallTitol = ref('');
const detallMoviments = ref<MovimentDetall[]>([]);
const detallTotal = ref(0);

function obreDetall(titol: string, moviments: MovimentDetall[], total: number) {
    if (moviments.length === 0) return;
    detallTitol.value = titol;
    detallMoviments.value = moviments;
    detallTotal.value = total;
    showDetall.value = true;
}

function tancaDetall() {
    showDetall.value = false;
}
</script>

<template>
    <Head title="IRPF Lloguers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    IRPF Lloguers
                </h2>
                <select
                    :value="props.any"
                    @change="canviarAny"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                >
                    <option v-for="a in anyOpcions" :key="a" :value="a">{{ a }}</option>
                </select>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                    Lloguer
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                    Immoble
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">
                                    Ingressos
                                </th>
                                <th
                                    v-for="cat in categories"
                                    :key="cat"
                                    class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                >
                                    {{ cat }}
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">
                                    Total Despeses
                                </th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                    Resultat Net
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="lloguer in props.lloguers" :key="lloguer.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ lloguer.nom }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ lloguer.immoble_adreca ?? '—' }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-green-600 dark:text-green-400"
                                    :class="{ 'cursor-pointer hover:underline': lloguer.moviments_ingressos.length > 0 }"
                                    @click="obreDetall(lloguer.nom + ' — Ingressos', lloguer.moviments_ingressos, lloguer.total_ingressos)"
                                >
                                    {{ formatEur(lloguer.total_ingressos) }}
                                </td>
                                <td
                                    v-for="cat in categories"
                                    :key="cat"
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300"
                                    :class="{ 'cursor-pointer hover:underline': (lloguer.moviments_despeses[cat] || []).length > 0 }"
                                    @click="obreDetall(lloguer.nom + ' — ' + cat, lloguer.moviments_despeses[cat] || [], lloguer.despeses_per_categoria[cat])"
                                >
                                    {{ formatEur(lloguer.despeses_per_categoria[cat]) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-red-600 dark:text-red-400">
                                    {{ formatEur(lloguer.total_despeses) }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm font-bold"
                                    :class="lloguer.resultat_net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ formatEur(lloguer.resultat_net) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-gray-50 dark:bg-gray-700">
                            <tr class="font-bold">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100" colspan="2">
                                    Totals
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-green-600 dark:text-green-400">
                                    {{ formatEur(props.totals.total_ingressos) }}
                                </td>
                                <td
                                    v-for="cat in categories"
                                    :key="cat"
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300"
                                >
                                    {{ formatEur(props.totals.despeses_per_categoria[cat]) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-red-600 dark:text-red-400">
                                    {{ formatEur(props.totals.total_despeses) }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right text-sm"
                                    :class="props.totals.resultat_net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ formatEur(props.totals.resultat_net) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal detall moviments -->
        <Modal :show="showDetall" max-width="md" @close="tancaDetall">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ detallTitol }}
                </h3>
                <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Data</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Import</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(mov, idx) in detallMoviments" :key="idx">
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ mov.data }}</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{{ formatEur(mov.import) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr class="font-bold">
                            <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-900 dark:text-gray-100">{{ formatEur(detallTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div class="mt-4 flex justify-end">
                    <button
                        @click="tancaDetall"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500"
                    >
                        Tancar
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
