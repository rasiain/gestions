<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Titol {
    id: number;
    isin: string;
    nom: string;
    emissor: string | null;
}

interface CompteOpcio {
    id: number;
    nom: string;
    compte: string;
    entitat: string;
    titulars: string;
}

interface Valor {
    id: number;
    data: string;
    valor_patrimonial: number;
}

interface Rendibilitat {
    id: number;
    data: string;
    import: number;
    notes: string | null;
}

interface Contracte {
    id: number;
    titol_id: number;
    titol_nom: string;
    isin: string;
    emissor: string | null;
    compte_corrent_id: number;
    compte_nom: string;
    compte_referencia: string;
    compte_rendibilitat_id: number | null;
    compte_rendibilitat_nom: string | null;
    /** El que es va contractar. */
    nominal: number;
    /** El que val ara, del darrer valor desat; si no n'hi ha cap, el nominal. */
    valor: number;
    diferencia: number;
    data_compra: string | null;
    data_venciment: string | null;
    notes: string | null;
    titulars: Array<{ id: number; nom: string }>;
    valors: Valor[];
    rendibilitats: Rendibilitat[];
    rendibilitat_per_any: Record<string, number>;
}

interface TotalTitular {
    id: number | null;
    nom: string;
    total: number;
    contractes: Array<{ titol: string; compte: string; valor: number; titulars: number; part: number }>;
}

interface Props {
    contractes: Contracte[];
    titols: Titol[];
    totalsPerTitular: TotalTitular[];
    comptesTitol: CompteOpcio[];
    comptesRendibilitat: CompteOpcio[];
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

function formatData(iso: string | null): string {
    return iso ? new Intl.DateTimeFormat('ca-ES', { dateStyle: 'medium' }).format(new Date(iso)) : '—';
}

const totalNominal = computed(() => props.contractes.reduce((s, c) => s + c.nominal, 0));
const totalValor = computed(() => props.contractes.reduce((s, c) => s + c.valor, 0));

/** Un venciment a menys de tres mesos val la pena tenir-lo present. */
function venVinent(contracte: Contracte): boolean {
    if (!contracte.data_venciment) return false;
    const limit = new Date();
    limit.setMonth(limit.getMonth() + 3);
    return new Date(contracte.data_venciment) <= limit;
}

const obert = ref<number | null>(null);

function obreDetall(contracte: Contracte) {
    obert.value = obert.value === contracte.id ? null : contracte.id;
}

// ---- Títols ----
const showTitol = ref(false);
const titolEditat = ref<Titol | null>(null);
const titolForm = useForm({ isin: '', nom: '', emissor: '', notes: '' });

function obreTitol(titol: Titol | null) {
    titolEditat.value = titol;
    titolForm.clearErrors();
    titolForm.isin = titol?.isin ?? '';
    titolForm.nom = titol?.nom ?? '';
    titolForm.emissor = titol?.emissor ?? '';
    titolForm.notes = '';
    showTitol.value = true;
}

function desaTitol() {
    const opcions = { preserveScroll: true, onSuccess: () => (showTitol.value = false) };
    if (titolEditat.value) {
        titolForm.put(route('renda-fixa.titols.update', titolEditat.value.id), opcions);
        return;
    }
    titolForm.post(route('renda-fixa.titols.store'), opcions);
}

// ---- Contractes ----
const showContracte = ref(false);
const contracteEditat = ref<Contracte | null>(null);
const contracteForm = useForm({
    titol_id: null as number | null,
    compte_corrent_id: null as number | null,
    compte_rendibilitat_id: null as number | null,
    nominal: null as number | null,
    data_compra: '',
    data_venciment: '',
    notes: '',
});

function obreContracte(contracte: Contracte | null) {
    contracteEditat.value = contracte;
    contracteForm.clearErrors();
    contracteForm.titol_id = contracte?.titol_id ?? props.titols[0]?.id ?? null;
    contracteForm.compte_corrent_id = contracte?.compte_corrent_id ?? props.comptesTitol[0]?.id ?? null;
    contracteForm.compte_rendibilitat_id = contracte?.compte_rendibilitat_id ?? null;
    contracteForm.nominal = contracte?.nominal ?? null;
    contracteForm.data_compra = contracte?.data_compra ?? '';
    contracteForm.data_venciment = contracte?.data_venciment ?? '';
    contracteForm.notes = contracte?.notes ?? '';
    showContracte.value = true;
}

function desaContracte() {
    const opcions = { preserveScroll: true, onSuccess: () => (showContracte.value = false) };
    if (contracteEditat.value) {
        contracteForm.put(route('renda-fixa.contractes.update', contracteEditat.value.id), opcions);
        return;
    }
    contracteForm.post(route('renda-fixa.contractes.store'), opcions);
}

function eliminaContracte(contracte: Contracte) {
    if (!confirm(`Vols eliminar «${contracte.titol_nom}»? També se n'aniran els valors i els cupons.`)) return;
    router.delete(route('renda-fixa.contractes.destroy', contracte.id), { preserveScroll: true });
}

// ---- Valors i cupons ----
const valorForm = useForm({ contracte_id: 0, data: '', valor_patrimonial: null as number | null });
const rendibilitatForm = useForm({ contracte_id: 0, data: '', import: null as number | null, notes: '' });

function afegeixValor(contracte: Contracte) {
    valorForm.contracte_id = contracte.id;
    valorForm.post(route('renda-fixa.valors.store'), {
        preserveScroll: true,
        onSuccess: () => valorForm.reset('data', 'valor_patrimonial'),
    });
}

function eliminaValor(id: number) {
    router.delete(route('renda-fixa.valors.destroy', id), { preserveScroll: true });
}

function afegeixRendibilitat(contracte: Contracte) {
    rendibilitatForm.contracte_id = contracte.id;
    rendibilitatForm.post(route('renda-fixa.rendibilitats.store'), {
        preserveScroll: true,
        onSuccess: () => rendibilitatForm.reset('data', 'import', 'notes'),
    });
}

function eliminaRendibilitat(id: number) {
    router.delete(route('renda-fixa.rendibilitats.destroy', id), { preserveScroll: true });
}

// ---- Totals per titular ----
const showTotals = ref(false);
const titularObert = ref<number | string | null>(null);
const totalGeneral = computed(() => props.totalsPerTitular.reduce((s, t) => s + t.total, 0));

function obreDetallTitular(t: TotalTitular) {
    const clau = t.id ?? 'sense';
    titularObert.value = titularObert.value === clau ? null : clau;
}
</script>

<template>
    <Head title="Renda Fixa" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Renda Fixa</h2>
                <div class="flex items-center gap-2">
                    <button @click="showTotals = true"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        Totals per titular
                    </button>
                    <button @click="obreTitol(null)"
                        class="inline-flex items-center rounded-md border border-green-600 bg-white px-4 py-2 text-sm font-medium text-green-700 shadow-sm hover:bg-green-50 dark:bg-gray-800 dark:text-green-400 dark:hover:bg-gray-700">
                        Nou títol
                    </button>
                    <button @click="obreContracte(null)" :disabled="!props.titols.length || !props.comptesTitol.length"
                        class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-40">
                        Nou contracte
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-xl space-y-6 sm:px-6 lg:px-8">
                <p v-if="!props.comptesTitol.length" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    No hi ha cap compte de tipus <strong>renda fixa</strong>. Dona'l d'alta a Comptes Corrents: d'ell en
                    sortiran els titulars de cada contracte.
                </p>

                <!-- Resum -->
                <div v-if="props.contractes.length" class="grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Nominal</p>
                        <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ formatEur(totalNominal) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Valor patrimonial</p>
                        <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ formatEur(totalValor) }}</p>
                    </div>
                    <div class="rounded-lg p-4 text-center shadow-sm"
                        :class="totalValor - totalNominal >= 0 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-orange-50 dark:bg-orange-900/20'">
                        <p class="text-xs font-medium uppercase tracking-wide"
                            :class="totalValor - totalNominal >= 0 ? 'text-green-600 dark:text-green-400' : 'text-orange-600 dark:text-orange-400'">
                            Diferència
                        </p>
                        <p class="mt-1 text-xl font-bold"
                            :class="totalValor - totalNominal >= 0 ? 'text-green-700 dark:text-green-300' : 'text-orange-700 dark:text-orange-300'">
                            {{ formatEur(totalValor - totalNominal) }}
                        </p>
                    </div>
                </div>

                <!-- Contractes -->
                <div v-for="c in props.contractes" :key="c.id" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-l-4 border-green-500 bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ c.titol_nom }}
                                <span v-if="venVinent(c)" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                    venç el {{ formatData(c.data_venciment) }}
                                </span>
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-mono">{{ c.isin }}</span>
                                <span class="mx-2">·</span>
                                {{ c.compte_nom }}
                                <span class="ml-1 font-mono text-gray-400 dark:text-gray-500">{{ c.compte_referencia }}</span>
                                <span v-if="c.titulars.length" class="ml-2">· {{ c.titulars.map(t => t.nom).join(', ') }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-6 text-sm">
                            <span class="text-gray-500 dark:text-gray-400">
                                Nominal: <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(c.nominal) }}</span>
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                Valor: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(c.valor) }}</span>
                            </span>
                            <span v-if="c.diferencia !== 0"
                                :class="c.diferencia > 0 ? 'text-green-600 dark:text-green-400' : 'text-orange-600 dark:text-orange-400'">
                                {{ c.diferencia > 0 ? '+' : '' }}{{ formatEur(c.diferencia) }}
                            </span>
                            <button @click="obreDetall(c)" class="text-sm text-green-700 hover:underline dark:text-green-400">
                                {{ obert === c.id ? 'Tanca' : 'Detall' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="obert === c.id" class="grid gap-6 p-4 lg:grid-cols-2">
                        <!-- Valors -->
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                Valor patrimonial per data
                            </h4>
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="v in c.valors" :key="v.id">
                                        <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ formatData(v.data) }}</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(v.valor_patrimonial) }}</td>
                                        <td class="w-8 py-1.5 text-right">
                                            <button @click="eliminaValor(v.id)" class="text-xs text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!c.valors.length">
                                        <td colspan="3" class="py-2 text-xs text-gray-400 dark:text-gray-500">
                                            Cap valor desat: es pren el nominal.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-2 flex gap-2">
                                <input v-model="valorForm.data" type="date"
                                    class="w-40 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model.number="valorForm.valor_patrimonial" type="number" step="0.01" placeholder="Valor"
                                    class="w-32 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <button @click="afegeixValor(c)" :disabled="!valorForm.data || valorForm.valor_patrimonial === null"
                                    class="rounded-md bg-green-600 px-3 py-1 text-sm text-white hover:bg-green-700 disabled:opacity-40">
                                    Afegeix
                                </button>
                            </div>
                        </div>

                        <!-- Cupons -->
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                Cupons cobrats
                                <span v-if="c.compte_rendibilitat_nom" class="ml-1 font-normal normal-case tracking-normal text-gray-400 dark:text-gray-500">
                                    · arriben a {{ c.compte_rendibilitat_nom }}
                                </span>
                            </h4>

                            <div v-if="Object.keys(c.rendibilitat_per_any).length" class="mb-2 flex flex-wrap gap-2">
                                <span v-for="(import_, any) in c.rendibilitat_per_any" :key="any"
                                    class="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                    {{ any }}: {{ formatEur(import_) }}
                                </span>
                            </div>

                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="r in c.rendibilitats" :key="r.id">
                                        <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ formatData(r.data) }}</td>
                                        <td class="py-1.5 text-xs text-gray-400 dark:text-gray-500">{{ r.notes }}</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(r.import) }}</td>
                                        <td class="w-8 py-1.5 text-right">
                                            <button @click="eliminaRendibilitat(r.id)" class="text-xs text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!c.rendibilitats.length">
                                        <td colspan="4" class="py-2 text-xs text-gray-400 dark:text-gray-500">Cap cupó cobrat encara.</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-2 flex gap-2">
                                <input v-model="rendibilitatForm.data" type="date"
                                    class="w-36 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model.number="rendibilitatForm.import" type="number" step="0.01" placeholder="Import"
                                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model="rendibilitatForm.notes" type="text" placeholder="Notes"
                                    class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <button @click="afegeixRendibilitat(c)" :disabled="!rendibilitatForm.data || rendibilitatForm.import === null"
                                    class="shrink-0 rounded-md bg-green-600 px-3 py-1 text-sm text-white hover:bg-green-700 disabled:opacity-40">
                                    Afegeix
                                </button>
                            </div>
                        </div>

                        <div class="lg:col-span-2 flex items-center justify-between border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <span>
                                Compra: {{ formatData(c.data_compra) }} · Venciment: {{ formatData(c.data_venciment) }}
                                <span v-if="c.notes" class="ml-2">· {{ c.notes }}</span>
                            </span>
                            <span class="flex gap-3">
                                <button @click="obreContracte(c)" class="text-green-700 hover:underline dark:text-green-400">Edita</button>
                                <button @click="eliminaContracte(c)" class="text-red-600 hover:underline dark:text-red-400">Elimina</button>
                            </span>
                        </div>
                    </div>
                </div>

                <p v-if="!props.contractes.length && props.comptesTitol.length"
                    class="rounded-lg border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                    Encara no hi ha cap contracte de renda fixa.
                </p>

                <!-- Catàleg de títols -->
                <div v-if="props.titols.length" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="p-4">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Títols</h3>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="t in props.titols" :key="t.id">
                                    <td class="w-36 py-1.5 font-mono text-xs text-gray-500 dark:text-gray-400">{{ t.isin }}</td>
                                    <td class="py-1.5 text-gray-800 dark:text-gray-200">{{ t.nom }}</td>
                                    <td class="py-1.5 text-xs text-gray-400 dark:text-gray-500">{{ t.emissor }}</td>
                                    <td class="w-16 py-1.5 text-right">
                                        <button @click="obreTitol(t)" class="text-xs text-green-700 hover:underline dark:text-green-400">Edita</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Títol -->
        <Modal :show="showTitol" max-width="lg" @close="showTitol = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ titolEditat ? 'Edita el títol' : 'Nou títol' }}
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ISIN</label>
                        <input v-model="titolForm.isin" type="text" maxlength="12" placeholder="XS2952043110"
                            class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm uppercase shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="titolForm.errors.isin" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ titolForm.errors.isin }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom del valor</label>
                        <input v-model="titolForm.nom" type="text" placeholder="BNP ESTRUCT EUROSTOXX 50 3A"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="titolForm.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ titolForm.errors.nom }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Emissor</label>
                        <input v-model="titolForm.emissor" type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showTitol = false" class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200">Cancel·la</button>
                    <button @click="desaTitol" :disabled="titolForm.processing" class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-40">Desa</button>
                </div>
            </div>
        </Modal>

        <!-- Contracte -->
        <Modal :show="showContracte" max-width="lg" @close="showContracte = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ contracteEditat ? 'Edita el contracte' : 'Nou contracte' }}
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Títol</label>
                        <select v-model="contracteForm.titol_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="t in props.titols" :key="t.id" :value="t.id">{{ t.isin }} — {{ t.nom }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contracte (compte del títol)</label>
                        <select v-model="contracteForm.compte_corrent_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="c in props.comptesTitol" :key="c.id" :value="c.id">{{ c.compte }} — {{ c.nom }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">D'aquest compte en surten els titulars.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte on arriben els cupons</label>
                        <select v-model="contracteForm.compte_rendibilitat_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option :value="null">— cap —</option>
                            <option v-for="c in props.comptesRendibilitat" :key="c.id" :value="c.id">{{ c.nom }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nominal</label>
                            <input v-model.number="contracteForm.nominal" type="number" step="0.01"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="contracteForm.errors.nominal" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.nominal }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compra</label>
                            <input v-model="contracteForm.data_compra" type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Venciment</label>
                            <input v-model="contracteForm.data_venciment" type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="contracteForm.errors.data_venciment" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.data_venciment }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="contracteForm.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showContracte = false" class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200">Cancel·la</button>
                    <button @click="desaContracte" :disabled="contracteForm.processing" class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-40">Desa</button>
                </div>
            </div>
        </Modal>

        <!-- Totals per titular -->
        <Modal :show="showTotals" max-width="2xl" @close="showTotals = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Totals per titular</h3>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    El valor patrimonial de cada contracte, repartit entre els titulars del seu compte.
                </p>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th class="pb-2 font-medium">Titular</th>
                            <th class="w-28 pb-2 text-right font-medium">Contractes</th>
                            <th class="w-40 pb-2 text-right font-medium">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <template v-for="t in props.totalsPerTitular" :key="t.id ?? 'sense'">
                            <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40" @click="obreDetallTitular(t)">
                                <td class="py-2 font-medium text-gray-900 dark:text-gray-100">
                                    <span class="mr-1 inline-block w-3 text-gray-400">{{ titularObert === (t.id ?? 'sense') ? '▾' : '▸' }}</span>
                                    {{ t.nom }}
                                </td>
                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{{ t.contractes.length }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(t.total) }}</td>
                            </tr>
                            <tr v-if="titularObert === (t.id ?? 'sense')" class="bg-gray-50 dark:bg-gray-700/30">
                                <td colspan="3" class="px-3 py-2">
                                    <div v-for="(c, i) in t.contractes" :key="i" class="flex items-baseline justify-between gap-4 py-0.5 text-xs">
                                        <span class="text-gray-600 dark:text-gray-400">
                                            {{ c.titol }}
                                            <span class="ml-1 text-gray-400 dark:text-gray-500">· {{ c.compte }}</span>
                                            <span v-if="c.titulars > 1" class="ml-1 text-gray-400 dark:text-gray-500">
                                                {{ formatEur(c.valor) }} entre {{ c.titulars }}
                                            </span>
                                        </span>
                                        <span class="shrink-0 tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(c.part) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!props.totalsPerTitular.length">
                            <td colspan="3" class="py-4 text-center text-sm text-gray-400 dark:text-gray-500">Encara no hi ha res.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="props.totalsPerTitular.length" class="border-t-2 border-gray-200 dark:border-gray-600">
                        <tr class="font-bold">
                            <td colspan="2" class="py-2 text-gray-900 dark:text-gray-100">Total</td>
                            <td class="py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(totalGeneral) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="mt-6 flex justify-end">
                    <button @click="showTotals = false" class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200">Tancar</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
