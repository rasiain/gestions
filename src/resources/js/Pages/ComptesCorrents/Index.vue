<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BalancCompteModal from '@/Components/BalancCompteModal.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Titular {
    id: number;
    nom: string;
    cognoms: string;
}

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    entitat_id: number | null;
    ordre: number;
    tipus: string;
    titulars: Titular[];
    saldo_actual: number | null;
    lloguer_nom: string | null;
    lloguer_acronim: string | null;
    /** Només per als comptes de fons d'inversió amb contracte. */
    fons_nom: string | null;
    /** Només per als comptes de pla de pensions amb contracte. */
    pla_nom: string | null;
    created_at: string;
    updated_at: string;
}

interface Entitat {
    id: number;
    nom: string;
}

/** Un compte dins del repartiment d'un titular. */
interface CompteDelTitular {
    nom: string;
    lloguer: string | null;
    saldo: number;
    /** Entre quants es reparteix. */
    titulars: number;
    part: number;
}

interface TotalTitular {
    id: number | null;
    nom: string;
    total: number;
    comptes: CompteDelTitular[];
}

interface Props {
    comptesCorrents: CompteCorrent[];
    /** Saldo de cada titular sumant els comptes corrents, repartit a parts iguals. */
    totalsPerTitular: TotalTitular[];
    titulars: Titular[];
    entitats: Entitat[];
}

const props = defineProps<Props>();

const showModal = ref(false);

// ---- Totals per titular ----
const showTotals = ref(false);
const titularObert = ref<number | string | null>(null);

const totalGeneral = computed(() =>
    props.totalsPerTitular.reduce((suma, t) => suma + t.total, 0)
);

function obreDetall(titular: TotalTitular) {
    const clau = titular.id ?? 'sense';
    titularObert.value = titularObert.value === clau ? null : clau;
}
const isEditing = ref(false);
const editingCompteCorrent = ref<CompteCorrent | null>(null);

const form = useForm({
    compte_corrent: '',
    nom: '',
    entitat_id: null as number | null,
    entitat_nova_nom: '',
    ordre: 0,
    tipus: 'corrent',
    titular_ids: [] as number[],
});

const entitatsNova = ref(false);

const openCreateModal = () => {
    isEditing.value = false;
    editingCompteCorrent.value = null;
    entitatsNova.value = false;
    form.reset();
    showModal.value = true;
};

const openEditModal = (compteCorrent: CompteCorrent) => {
    isEditing.value = true;
    editingCompteCorrent.value = compteCorrent;
    entitatsNova.value = false;
    form.compte_corrent = compteCorrent.compte_corrent;
    form.nom = compteCorrent.nom || '';
    form.entitat_id = compteCorrent.entitat_id ?? null;
    form.entitat_nova_nom = '';
    form.ordre = compteCorrent.ordre;
    form.tipus = compteCorrent.tipus || 'corrent';
    form.titular_ids = compteCorrent.titulars.map(t => t.id);
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    isEditing.value = false;
    editingCompteCorrent.value = null;
};

const submit = () => {
    if (isEditing.value && editingCompteCorrent.value) {
        form.put(route('comptes-corrents.update', editingCompteCorrent.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('comptes-corrents.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteCompteCorrent = (compteCorrent: CompteCorrent) => {
    if (confirm(`Estàs segur que vols eliminar el compte "${compteCorrent.compte_corrent}"?`)) {
        router.delete(route('comptes-corrents.destroy', compteCorrent.id));
    }
};

const getTitularsNames = (titulars: Titular[]): string => {
    if (titulars.length === 0) return 'Sense titulars';
    return titulars.map(t => `${t.nom} ${t.cognoms}`).join(', ');
};

const formatSaldo = (saldo: number | null): string => {
    if (saldo === null) return '-';
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(saldo);
};

/** Fons d'inversió i plans de pensions: dues coses diferents, un sol grup. */
const esInversio = (c: CompteCorrent) => c.tipus === 'fons_inversio' || c.tipus === 'pla_pensions';

const comptesCorrents = computed(() => props.comptesCorrents.filter(c => !c.lloguer_nom && !esInversio(c)));
const comptesLloguers = computed(() => props.comptesCorrents.filter(c => !!c.lloguer_nom));
const comptesInversions = computed(() => props.comptesCorrents.filter(esInversio));

// Clicar la fila obre els moviments del compte, com a la llista de lloguers
const obrirMoviments = (compte: CompteCorrent) => {
    router.visit(route('moviments.index', { compte_corrent_id: compte.id }));
};

// Els comptes d'inversió no tenen moviments bancaris: van a les aportacions
// del fons o del pla, segons què hi hagi contractat
const obrirInversio = (compte: CompteCorrent) => {
    if (compte.fons_nom) {
        router.visit(route('fons-inversio.index', { compte_corrent_id: compte.id }));
        return;
    }

    if (compte.pla_nom) {
        router.visit(route('plans-pensions.index', { compte_corrent_id: compte.id }));
    }
};

const showBalancModal = ref(false);
const balancCompteId = ref<number | null>(null);
const balancCompteNom = ref<string>('');

const openBalancModal = (compte: CompteCorrent) => {
    balancCompteId.value = compte.id;
    balancCompteNom.value = compte.nom ?? compte.compte_corrent;
    showBalancModal.value = true;
};

const closeBalancModal = () => {
    showBalancModal.value = false;
    balancCompteId.value = null;
    balancCompteNom.value = '';
};
</script>

<template>
    <Head title="Comptes Corrents" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Comptes Corrents
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Header with Add Button -->
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Llistat de Comptes Corrents
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestiona els comptes corrents i els seus titulars
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                            <button
                                @click="showTotals = true"
                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                Totals per titular
                            </button>
                            <button
                                @click="openCreateModal"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                <svg
                                    class="-ml-1 mr-2 h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                    />
                                </svg>
                                Afegir Compte Corrent
                            </button>
                            </div>
                        </div>

                        <!-- Taula unificada -->
                        <div v-if="comptesCorrents.length > 0" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3"></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Compte Corrent</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Entitat</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Titulars</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Saldo Actual</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">

                                    <!-- Grup: Corrents -->
                                    <template v-if="comptesCorrents.length">
                                        <tr class="bg-gray-50 dark:bg-gray-900/40">
                                            <td colspan="6" class="px-6 py-1.5 text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Corrents</td>
                                        </tr>
                                        <tr
                                            v-for="compte in comptesCorrents"
                                            :key="compte.id"
                                            @click="obrirMoviments(compte)"
                                            class="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-gray-700"
                                        >
                                            <td class="px-6 py-4"></td>
                                            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ compte.nom || compte.compte_corrent }}
                                                <span class="ml-1 font-mono text-xs tracking-widest text-gray-400 dark:text-gray-500">···· {{ compte.compte_corrent.slice(-4) }}</span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">{{ compte.entitat }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ getTitularsNames(compte.titulars) }}</td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium" :class="compte.saldo_actual !== null && compte.saldo_actual < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'">{{ formatSaldo(compte.saldo_actual) }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-medium" @click.stop>
                                                <Link :href="route('maintenance.movements.import', { compte_corrent_id: compte.id })" class="mr-3 text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300">Importar</Link>
                                                <button @click="openBalancModal(compte)" class="mr-3 text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300">Balanç</button>
                                                <button @click="openEditModal(compte)" class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</button>
                                                <button @click="deleteCompteCorrent(compte)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Eliminar</button>
                                            </td>
                                        </tr>
                                    </template>

                                    <!-- Grup: Lloguers -->
                                    <template v-if="comptesLloguers.length">
                                        <tr class="bg-amber-50 dark:bg-amber-900/20">
                                            <td colspan="6" class="px-6 py-1.5 text-xs font-semibold uppercase tracking-widest text-amber-500 dark:text-amber-400">Lloguers</td>
                                        </tr>
                                        <tr
                                            v-for="compte in comptesLloguers"
                                            :key="compte.id"
                                            @click="obrirMoviments(compte)"
                                            class="cursor-pointer transition-colors hover:bg-amber-50 dark:hover:bg-amber-900/10"
                                        >
                                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-amber-700 dark:text-amber-300">{{ compte.lloguer_acronim || compte.lloguer_nom }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                                {{ compte.nom || compte.compte_corrent }}
                                                <span class="ml-1 font-mono text-xs tracking-widest text-gray-400 dark:text-gray-500">···· {{ compte.compte_corrent.slice(-4) }}</span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">{{ compte.entitat }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ getTitularsNames(compte.titulars) }}</td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium" :class="compte.saldo_actual !== null && compte.saldo_actual < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'">{{ formatSaldo(compte.saldo_actual) }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-medium" @click.stop>
                                                <Link :href="route('maintenance.movements.import', { compte_corrent_id: compte.id })" class="mr-3 text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300">Importar</Link>
                                                <button @click="openBalancModal(compte)" class="mr-3 text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300">Balanç</button>
                                                <button @click="openEditModal(compte)" class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</button>
                                                <button @click="deleteCompteCorrent(compte)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Eliminar</button>
                                            </td>
                                        </tr>
                                    </template>

                                    <!-- Grup: Inversions (fons d'inversió i plans de pensions) -->
                                    <template v-if="comptesInversions.length">
                                        <tr class="bg-emerald-50 dark:bg-emerald-900/20">
                                            <td colspan="6" class="px-6 py-1.5 text-xs font-semibold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">Inversions</td>
                                        </tr>
                                        <tr
                                            v-for="compte in comptesInversions"
                                            :key="compte.id"
                                            @click="obrirInversio(compte)"
                                            class="transition-colors hover:bg-emerald-50 dark:hover:bg-emerald-900/10"
                                            :class="compte.fons_nom || compte.pla_nom ? 'cursor-pointer' : ''"
                                        >
                                            <td class="px-6 py-4 text-sm" @click.stop>
                                                <Link
                                                    v-if="compte.fons_nom"
                                                    :href="route('fons-inversio.index', { compte_corrent_id: compte.id })"
                                                    class="text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-300"
                                                >{{ compte.fons_nom }}</Link>
                                                <Link
                                                    v-else-if="compte.pla_nom"
                                                    :href="route('plans-pensions.index', { compte_corrent_id: compte.id })"
                                                    class="text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-300"
                                                >
                                                    {{ compte.pla_nom }}
                                                    <span class="ml-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">pla</span>
                                                </Link>
                                                <span v-else class="text-xs italic text-gray-400 dark:text-gray-500">
                                                    {{ compte.tipus === 'pla_pensions' ? 'Sense pla' : 'Sense fons' }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ compte.nom || compte.compte_corrent }}
                                                <span class="ml-1 font-mono text-xs tracking-widest text-gray-400 dark:text-gray-500">{{ compte.compte_corrent }}</span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">{{ compte.entitat }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ getTitularsNames(compte.titulars) }}</td>
                                            <td class="px-6 py-4 text-center text-sm text-gray-400">—</td>
                                            <td class="px-6 py-4 text-right text-sm font-medium" @click.stop>
                                                <button @click="openEditModal(compte)" class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</button>
                                                <button @click="deleteCompteCorrent(compte)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Eliminar</button>
                                            </td>
                                        </tr>
                                    </template>

                                </tbody>
                            </table>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="py-12 text-center">
                            <svg
                                class="mx-auto h-12 w-12 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                                />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                No hi ha comptes corrents
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Comença afegint el primer compte corrent.
                            </p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateModal"
                                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <svg
                                        class="-ml-1 mr-2 h-5 w-5"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                        />
                                    </svg>
                                    Afegir Compte Corrent
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Balanç -->
        <!-- Totals per titular -->
        <Modal :show="showTotals" max-width="2xl" @close="showTotals = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Totals per titular</h3>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Els comptes corrents, personals i de lloguer. Els d'inversió no hi entren: el seu valor
                    es mira a la seva pantalla.
                </p>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th class="pb-2 font-medium">Titular</th>
                            <th class="w-24 pb-2 text-right font-medium">Comptes</th>
                            <th class="w-40 pb-2 text-right font-medium">Import</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <template v-for="t in props.totalsPerTitular" :key="t.id ?? 'sense'">
                            <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40" @click="obreDetall(t)">
                                <td class="py-2 font-medium text-gray-900 dark:text-gray-100">
                                    <span class="mr-1 inline-block w-3 text-gray-400">{{ titularObert === (t.id ?? 'sense') ? '▾' : '▸' }}</span>
                                    {{ t.nom }}
                                </td>
                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{{ t.comptes.length }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatSaldo(t.total) }}</td>
                            </tr>
                            <!-- El detall: de quin compte ve cada part -->
                            <tr v-if="titularObert === (t.id ?? 'sense')" class="bg-gray-50 dark:bg-gray-700/30">
                                <td colspan="3" class="px-3 py-2">
                                    <div v-for="(c, i) in t.comptes" :key="i" class="flex items-baseline justify-between gap-4 py-0.5 text-xs">
                                        <span class="text-gray-600 dark:text-gray-400">
                                            {{ c.nom }}
                                            <span v-if="c.lloguer" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">lloguer</span>
                                            <span v-if="c.titulars > 1" class="ml-1 text-gray-400 dark:text-gray-500">
                                                {{ formatSaldo(c.saldo) }} entre {{ c.titulars }}
                                            </span>
                                        </span>
                                        <span class="shrink-0 tabular-nums text-gray-700 dark:text-gray-300">{{ formatSaldo(c.part) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 dark:border-gray-600">
                        <tr class="font-bold">
                            <td colspan="2" class="py-2 text-gray-900 dark:text-gray-100">Total</td>
                            <td class="py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatSaldo(totalGeneral) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                    El saldo de cada compte es reparteix a parts iguals entre els seus titulars: no se'n desa cap
                    percentatge. La suma de les parts sempre quadra amb el saldo del compte.
                </p>

                <div class="mt-6 flex justify-end">
                    <button @click="showTotals = false"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Tancar
                    </button>
                </div>
            </div>
        </Modal>

        <BalancCompteModal
            :show="showBalancModal"
            :compte-corrent-id="balancCompteId"
            :compte-corrent-nom="balancCompteNom"
            @close="closeBalancModal"
        />

        <!-- Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div
                    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    aria-hidden="true"
                    @click="closeModal"
                ></div>

                <!-- Center modal -->
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div
                    class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle"
                >
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3
                                class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100"
                                id="modal-title"
                            >
                                {{ isEditing ? 'Editar Compte Corrent' : 'Nou Compte Corrent' }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Compte Corrent -->
                                <div>
                                    <label
                                        for="compte_corrent"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Compte Corrent (IBAN/CCC)
                                    </label>
                                    <input
                                        id="compte_corrent"
                                        v-model="form.compte_corrent"
                                        type="text"
                                        required
                                        maxlength="24"
                                        placeholder="ES1234567890123456789012"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.compte_corrent" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.compte_corrent }}
                                    </p>
                                </div>

                                <!-- Nom -->
                                <div>
                                    <label
                                        for="nom"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Nom (opcional)
                                    </label>
                                    <input
                                        id="nom"
                                        v-model="form.nom"
                                        type="text"
                                        maxlength="100"
                                        placeholder="P. ex: Compte personal, Negoci, etc."
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.nom }}
                                    </p>
                                </div>

                                <!-- Entitat -->
                                <div>
                                    <div class="flex items-center justify-between">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Entitat bancària</label>
                                        <button type="button" @click="entitatsNova = !entitatsNova; form.entitat_id = null; form.entitat_nova_nom = ''" class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                            {{ entitatsNova ? '← Seleccionar existent' : '+ Entitat nova' }}
                                        </button>
                                    </div>
                                    <div v-if="!entitatsNova" class="mt-1">
                                        <select v-model="form.entitat_id" class="block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                            <option :value="null">— Selecciona una entitat —</option>
                                            <option v-for="e in props.entitats" :key="e.id" :value="e.id">{{ e.nom }}</option>
                                        </select>
                                    </div>
                                    <div v-else class="mt-1">
                                        <input v-model="form.entitat_nova_nom" type="text" maxlength="200" placeholder="p.ex. Caixa Enginyers"
                                            class="block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                    </div>
                                    <p v-if="form.errors.entitat_id || form.errors.entitat_nova_nom" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.entitat_id || form.errors.entitat_nova_nom }}
                                    </p>
                                </div>

                                <!-- Tipus -->
                                <div>
                                    <label for="tipus" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                                    <select id="tipus" v-model="form.tipus" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                        <option value="corrent">Corrent</option>
                                        <option value="fons_inversio">Fons d'inversió</option>
                                        <option value="pla_pensions">Pla de pensions</option>
                                    </select>
                                    <p v-if="form.errors.tipus" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.tipus }}</p>
                                </div>

                                <!-- Ordre -->
                                <div>
                                    <label
                                        for="ordre"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Ordre
                                    </label>
                                    <input
                                        id="ordre"
                                        v-model.number="form.ordre"
                                        type="number"
                                        min="0"
                                        max="255"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.ordre" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.ordre }}
                                    </p>
                                </div>

                                <!-- Titulars -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Titulars
                                    </label>
                                    <div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-md border border-gray-300 p-3 dark:border-gray-600">
                                        <div
                                            v-for="titular in titulars"
                                            :key="titular.id"
                                            class="flex items-center"
                                        >
                                            <input
                                                :id="`titular-${titular.id}`"
                                                v-model="form.titular_ids"
                                                :value="titular.id"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <label
                                                :for="`titular-${titular.id}`"
                                                class="ml-2 text-sm text-gray-700 dark:text-gray-300"
                                            >
                                                {{ titular.nom }} {{ titular.cognoms }}
                                            </label>
                                        </div>
                                        <div v-if="titulars.length === 0" class="text-center text-sm text-gray-500 dark:text-gray-400">
                                            No hi ha titulars disponibles
                                        </div>
                                    </div>
                                    <p v-if="form.errors.titular_ids" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.titular_ids }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
