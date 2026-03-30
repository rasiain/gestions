<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface FacturaDetall {
    data: string;
    base: number;
    iva: number;
    numero: string | null;
}

interface DespesaIvaDetall {
    data: string;
    base_imposable: number;
    iva_import: number;
    categoria: string | null;
    notes: string | null;
}

interface TrimesteDades {
    base: number;
    iva_repercutit: number;
    iva_suportat: number;
    resultat: number;
    factures: FacturaDetall[];
    despeses_iva: DespesaIvaDetall[];
}

interface LloguerIva {
    id: number;
    nom: string;
    immoble_adreca: string | null;
    trimestres: Record<number, TrimesteDades>;
    total_base: number;
    total_iva_repercutit: number;
    total_iva_suportat: number;
    total_resultat: number;
}

interface TotalsTrimestrals {
    base: number;
    iva_repercutit: number;
    iva_suportat: number;
    resultat: number;
}

interface Totals {
    trimestres: Record<number, TotalsTrimestrals>;
    total_base: number;
    total_iva_repercutit: number;
    total_iva_suportat: number;
    total_resultat: number;
}

interface Props {
    any: number;
    lloguers: LloguerIva[];
    totals: Totals;
}

const props = defineProps<Props>();

const trimestres = [1, 2, 3, 4];

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
}

function canviarAny(event: Event) {
    const target = event.target as HTMLSelectElement;
    router.get(route('impostos.iva'), { any: target.value }, { preserveState: true });
}

const anyOpcions = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i);

// ── Modal detall ────────────────────────────────────────────────
type DetallTipus = 'repercutit' | 'suportat';

const showDetall = ref(false);
const detallTitol = ref('');
const detallTipus = ref<DetallTipus>('repercutit');
const detallFactures = ref<FacturaDetall[]>([]);
const detallDespeses = ref<DespesaIvaDetall[]>([]);
const detallTotal = ref(0);

function obreDetallRepercutit(lloguer: LloguerIva, trimestre: number) {
    const dades = lloguer.trimestres[trimestre];
    if (!dades || dades.factures.length === 0) return;
    detallTitol.value = `${lloguer.nom} — ${trimestre}T IVA repercutit`;
    detallTipus.value = 'repercutit';
    detallFactures.value = dades.factures;
    detallTotal.value = dades.iva_repercutit;
    showDetall.value = true;
}

function obreDetallSuportat(lloguer: LloguerIva, trimestre: number) {
    const dades = lloguer.trimestres[trimestre];
    if (!dades || dades.despeses_iva.length === 0) return;
    detallTitol.value = `${lloguer.nom} — ${trimestre}T IVA suportat`;
    detallTipus.value = 'suportat';
    detallDespeses.value = dades.despeses_iva;
    detallTotal.value = dades.iva_suportat;
    showDetall.value = true;
}

function tancaDetall() {
    showDetall.value = false;
}
</script>

<template>
    <Head title="IVA Lloguers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    IVA Lloguers
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

                <div v-if="props.lloguers.length === 0" class="rounded-lg bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No hi ha lloguers no-habitatge registrats.
                    </p>
                </div>

                <div v-else class="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th rowspan="2" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                    Lloguer
                                </th>
                                <th rowspan="2" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                    Immoble
                                </th>
                                <th
                                    v-for="t in trimestres"
                                    :key="t"
                                    colspan="4"
                                    class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300 border-l border-gray-200 dark:border-gray-600"
                                >
                                    {{ t }}T
                                </th>
                                <th colspan="4" class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-gray-700 dark:text-gray-200 border-l border-gray-200 dark:border-gray-600">
                                    Total anual
                                </th>
                            </tr>
                            <tr>
                                <template v-for="t in trimestres" :key="t">
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400 border-l border-gray-200 dark:border-gray-600">
                                        Base
                                    </th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">
                                        IVA rep.
                                    </th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">
                                        IVA sup.
                                    </th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                        Resultat
                                    </th>
                                </template>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400 border-l border-gray-200 dark:border-gray-600">
                                    Base
                                </th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">
                                    IVA rep.
                                </th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">
                                    IVA sup.
                                </th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-700 dark:text-gray-200">
                                    Resultat
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr
                                v-for="lloguer in props.lloguers"
                                :key="lloguer.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            >
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ lloguer.nom }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ lloguer.immoble_adreca ?? '—' }}
                                </td>

                                <template v-for="t in trimestres" :key="t">
                                    <!-- Base -->
                                    <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-gray-500 dark:text-gray-400 border-l border-gray-100 dark:border-gray-700">
                                        {{ lloguer.trimestres[t].base > 0 ? formatEur(lloguer.trimestres[t].base) : '—' }}
                                    </td>
                                    <!-- IVA repercutit -->
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-right text-sm text-green-600 dark:text-green-400"
                                        :class="{ 'cursor-pointer hover:underline': lloguer.trimestres[t].factures.length > 0 }"
                                        @click="obreDetallRepercutit(lloguer, t)"
                                    >
                                        {{ lloguer.trimestres[t].iva_repercutit > 0 ? formatEur(lloguer.trimestres[t].iva_repercutit) : '—' }}
                                    </td>
                                    <!-- IVA suportat -->
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-right text-sm text-red-600 dark:text-red-400"
                                        :class="{ 'cursor-pointer hover:underline': lloguer.trimestres[t].despeses_iva.length > 0 }"
                                        @click="obreDetallSuportat(lloguer, t)"
                                    >
                                        {{ lloguer.trimestres[t].iva_suportat > 0 ? formatEur(lloguer.trimestres[t].iva_suportat) : '—' }}
                                    </td>
                                    <!-- Resultat trimestral -->
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-right text-sm font-medium"
                                        :class="lloguer.trimestres[t].resultat >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                    >
                                        {{ (lloguer.trimestres[t].iva_repercutit > 0 || lloguer.trimestres[t].iva_suportat > 0)
                                            ? formatEur(lloguer.trimestres[t].resultat) : '—' }}
                                    </td>
                                </template>

                                <!-- Total anual -->
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-gray-500 dark:text-gray-400 border-l border-gray-200 dark:border-gray-600">
                                    {{ lloguer.total_base > 0 ? formatEur(lloguer.total_base) : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm font-medium text-green-600 dark:text-green-400">
                                    {{ lloguer.total_iva_repercutit > 0 ? formatEur(lloguer.total_iva_repercutit) : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm font-medium text-red-600 dark:text-red-400">
                                    {{ lloguer.total_iva_suportat > 0 ? formatEur(lloguer.total_iva_suportat) : '—' }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-3 py-3 text-right text-sm font-bold"
                                    :class="lloguer.total_resultat >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ formatEur(lloguer.total_resultat) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-gray-50 dark:bg-gray-700">
                            <tr class="font-bold">
                                <td colspan="2" class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    Totals
                                </td>
                                <template v-for="t in trimestres" :key="t">
                                    <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-gray-500 dark:text-gray-400 border-l border-gray-200 dark:border-gray-600">
                                        {{ formatEur(props.totals.trimestres[t].base) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-green-600 dark:text-green-400">
                                        {{ formatEur(props.totals.trimestres[t].iva_repercutit) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">
                                        {{ formatEur(props.totals.trimestres[t].iva_suportat) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-right text-sm"
                                        :class="props.totals.trimestres[t].resultat >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                    >
                                        {{ formatEur(props.totals.trimestres[t].resultat) }}
                                    </td>
                                </template>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-gray-500 dark:text-gray-400 border-l border-gray-200 dark:border-gray-600">
                                    {{ formatEur(props.totals.total_base) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-green-600 dark:text-green-400">
                                    {{ formatEur(props.totals.total_iva_repercutit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">
                                    {{ formatEur(props.totals.total_iva_suportat) }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-3 py-3 text-right text-sm"
                                    :class="props.totals.total_resultat >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ formatEur(props.totals.total_resultat) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal detall -->
        <Modal :show="showDetall" max-width="lg" @close="tancaDetall">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ detallTitol }}
                </h3>

                <!-- Factures (IVA repercutit) -->
                <table v-if="detallTipus === 'repercutit'" class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Data</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Factura</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Base</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-green-600 dark:text-green-400">IVA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(f, idx) in detallFactures" :key="idx">
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ f.data }}</td>
                            <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ f.numero ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{{ formatEur(f.base) }}</td>
                            <td class="px-3 py-2 text-right text-sm text-green-600 dark:text-green-400">{{ formatEur(f.iva) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr class="font-bold">
                            <td colspan="3" class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total IVA repercutit</td>
                            <td class="px-3 py-2 text-right text-sm text-green-600 dark:text-green-400">{{ formatEur(detallTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Despeses IVA suportat -->
                <table v-else class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Data</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Categoria</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Base imp.</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-red-600 dark:text-red-400">IVA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(d, idx) in detallDespeses" :key="idx">
                            <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">{{ d.data }}</td>
                            <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ d.categoria ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{{ formatEur(d.base_imposable) }}</td>
                            <td class="px-3 py-2 text-right text-sm text-red-600 dark:text-red-400">{{ formatEur(d.iva_import) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr class="font-bold">
                            <td colspan="3" class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">Total IVA suportat</td>
                            <td class="px-3 py-2 text-right text-sm text-red-600 dark:text-red-400">{{ formatEur(detallTotal) }}</td>
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
