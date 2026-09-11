<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { num, perAlServidor, queFalta } from '@/formularis';

interface CompteOpcio {
    id: number;
    nom: string;
    compte: string;
    entitat: string;
    titulars: string;
}

/** Els dos números de l'extracte una data, el producte que en surt i d'on ve el canvi. */
interface Valor {
    id: number;
    data: string;
    /** Null quan el certificat només dona el saldo, sense desglossar-lo. */
    titols: number | null;
    valor_unitari: number | null;
    total: number;
    /** Títols nous al preu que hi havia. Null al primer valor, que no té amb què comparar-se. */
    aportacio: number | null;
    /** El que valen més els títols que ja hi eren: al banc, «REVALORACIO TITOLS». */
    revaloracio: number | null;
}

interface Rendiment {
    id: number;
    data: string;
    /** El brut; la retenció va a part, que és com ve al certificat. */
    import: number;
    retencio: number | null;
    net: number;
    notes: string | null;
}

interface Contracte {
    id: number;
    /** El del compte, o el de la cooperativa que emet l'aportació. */
    nom: string;
    emissor: string | null;
    nif: string | null;
    compte_corrent_id: number | null;
    compte_nom: string;
    compte_referencia: string;
    entitat: string | null;
    compte_rendiment_id: number | null;
    compte_rendiment_nom: string | null;
    /** Del darrer valor desat; sense cap valor encara, no se sap res. */
    titols: number | null;
    valor_unitari: number | null;
    valor: number;
    data_valor: string | null;
    data_alta: string | null;
    notes: string | null;
    titulars: Array<{ id: number; nom: string }>;
    /** Els triats a mà, que només tenen les aportacions sense compte. */
    titulars_propis: number[];
    valors: Valor[];
    rendiments: Rendiment[];
    rendiment_per_any: Record<string, number>;
}

interface TotalTitular {
    id: number | null;
    nom: string;
    total: number;
    contractes: Array<{ compte: string; valor: number; titulars: number; part: number }>;
}

interface Props {
    contractes: Contracte[];
    totalsPerTitular: TotalTitular[];
    comptesCapital: CompteOpcio[];
    comptesRendiment: CompteOpcio[];
    persones: Array<{ id: number; nom: string }>;
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

function formatData(iso: string | null): string {
    return iso ? new Intl.DateTimeFormat('ca-ES', { dateStyle: 'medium' }).format(new Date(iso)) : '—';
}

const totalValor = computed(() => props.contractes.reduce((s, c) => s + c.valor, 0));
const totalTitols = computed(() => props.contractes.reduce((s, c) => s + (c.titols ?? 0), 0));

/** La retenció de l'any més recent, que és el que es declara. */
const retencioPerAny = computed(() => {
    const anys: Record<string, number> = {};

    for (const contracte of props.contractes) {
        for (const r of contracte.rendiments) {
            if (!r.retencio) continue;
            const any = r.data.slice(0, 4);
            anys[any] = Math.round(((anys[any] ?? 0) + r.retencio) * 100) / 100;
        }
    }

    return Object.entries(anys).sort(([a], [b]) => b.localeCompare(a));
});

/** El que han pujat de preu els títols des que se'n té constància, sumant tots els contractes. */
const totalRevaloracio = computed(() =>
    Math.round(
        props.contractes.reduce(
            (s, c) => s + c.valors.reduce((t, v) => t + (v.revaloracio ?? 0), 0),
            0,
        ) * 100,
    ) / 100,
);

/** Els rendiments de tots els contractes junts, any per any. */
const rendimentPerAny = computed(() => {
    const anys: Record<string, number> = {};

    for (const contracte of props.contractes) {
        for (const [any, import_] of Object.entries(contracte.rendiment_per_any)) {
            anys[any] = Math.round(((anys[any] ?? 0) + import_) * 100) / 100;
        }
    }

    return Object.entries(anys).sort(([a], [b]) => b.localeCompare(a));
});

const obert = ref<number | null>(null);

function obreDetall(contracte: Contracte) {
    obert.value = obert.value === contracte.id ? null : contracte.id;
}

// ---- Contractes ----
const showContracte = ref(false);
const contracteEditat = ref<Contracte | null>(null);
const contracteForm = useForm({
    compte_corrent_id: null as number | null,
    emissor: '',
    nif: '',
    titulars: [] as number[],
    compte_rendiment_id: null as number | null,
    data_alta: '',
    notes: '',
});

/**
 * Una aportació té compte o no en té.
 *
 * Amb compte —una cooperativa de crèdit— els titulars surten d'ell. Sense —una cooperativa
 * de consum, que no és cap compte— cal dir qui l'emet i qui en són els titulars.
 */
const senseCompte = computed({
    get: () => contracteForm.compte_corrent_id === null,
    set: (sense: boolean) => {
        contracteForm.compte_corrent_id = sense ? null : (props.comptesCapital[0]?.id ?? null);
        if (!sense) contracteForm.titulars = [];
    },
});

const alternaTitular = (id: number) => {
    contracteForm.titulars = contracteForm.titulars.includes(id)
        ? contracteForm.titulars.filter(t => t !== id)
        : [...contracteForm.titulars, id];
};

function obreContracte(contracte: Contracte | null) {
    contracteEditat.value = contracte;
    contracteForm.clearErrors();
    contracteForm.compte_corrent_id = contracte
        ? contracte.compte_corrent_id
        : (props.comptesCapital[0]?.id ?? null);
    contracteForm.emissor = contracte?.emissor ?? '';
    contracteForm.nif = contracte?.nif ?? '';
    contracteForm.titulars = [...(contracte?.titulars_propis ?? [])];
    contracteForm.compte_rendiment_id = contracte?.compte_rendiment_id ?? null;
    contracteForm.data_alta = contracte?.data_alta ?? '';
    contracteForm.notes = contracte?.notes ?? '';
    showContracte.value = true;
}

function desaContracte() {
    const opcions = { preserveScroll: true, onSuccess: () => (showContracte.value = false) };
    if (contracteEditat.value) {
        contracteForm.put(route('capital-social.contractes.update', contracteEditat.value.id), opcions);
        return;
    }
    contracteForm.post(route('capital-social.contractes.store'), opcions);
}

function eliminaContracte(contracte: Contracte) {
    if (!confirm(`Vols eliminar el capital social de «${contracte.compte_nom}»? També se n'aniran els valors i els rendiments.`)) return;
    router.delete(route('capital-social.contractes.destroy', contracte.id), { preserveScroll: true });
}

// ---- Valors i rendiments ----
const valorForm = useForm({
    contracte_id: 0,
    dia: '',
    titols: null as number | string | null,
    valor_unitari: null as number | string | null,
    import: null as number | string | null,
});
const rendimentForm = useForm({
    contracte_id: 0,
    dia: '',
    import: null as number | string | null,
    retencio: null as number | string | null,
    notes: '',
});

/** Quin dels dos formats de valor s'està escrivint, per contracte. */
const perTitols = ref(true);

const titolsEscrits = computed(() => num(valorForm.titols));
const unitariEscrit = computed(() => num(valorForm.valor_unitari));
const saldoEscrit = computed(() => num(valorForm.import));

/** El total que sortirà del que s'està escrivint: els dos números són fàcils de confondre. */
const previsualitzaTotal = computed(() =>
    titolsEscrits.value === null || unitariEscrit.value === null
        ? null
        : Math.round(titolsEscrits.value * unitariEscrit.value * 100) / 100,
);

// El que falta per poder desar, dit en veu alta. El botó no es deshabilita mai: un botó
// mort no explica per què no es pot clicar, i aquí el motiu sempre és una cosa concreta.
const avisValor = ref<string | null>(null);
const avisRendiment = ref<string | null>(null);

/** L'avís d'aquí o el que hagi tornat el servidor: mai no es desa en silenci. */
const errorValor = computed(() => avisValor.value ?? Object.values(valorForm.errors)[0] ?? null);
const errorRendiment = computed(() => avisRendiment.value ?? Object.values(rendimentForm.errors)[0] ?? null);

function afegeixValor(contracte: Contracte) {
    avisValor.value = perTitols.value
        ? queFalta(valorForm.dia, [
            [titolsEscrits.value, 'els títols'],
            [unitariEscrit.value, 'el nominal unitari'],
        ])
        : queFalta(valorForm.dia, [[saldoEscrit.value, 'el saldo']]);
    if (avisValor.value) return;

    valorForm.contracte_id = contracte.id;
    valorForm
        // O el desglossament, o el saldo: enviar els dos deixaria dues respostes al mateix
        .transform(dades => ({
            ...perAlServidor(dades),
            titols: perTitols.value ? titolsEscrits.value : null,
            valor_unitari: perTitols.value ? unitariEscrit.value : null,
            import: perTitols.value ? null : saldoEscrit.value,
        }))
        .post(route('capital-social.valors.store'), {
            preserveScroll: true,
            onSuccess: () => valorForm.reset('dia', 'titols', 'valor_unitari', 'import'),
        });
}

function eliminaValor(id: number) {
    router.delete(route('capital-social.valors.destroy', id), { preserveScroll: true });
}

const importEscrit = computed(() => num(rendimentForm.import));
const retencioEscrita = computed(() => num(rendimentForm.retencio));

function afegeixRendiment(contracte: Contracte) {
    avisRendiment.value = queFalta(rendimentForm.dia, [[importEscrit.value, "l'import"]]);
    if (avisRendiment.value) return;

    rendimentForm.contracte_id = contracte.id;
    rendimentForm
        .transform(dades => ({ ...perAlServidor(dades), import: importEscrit.value, retencio: retencioEscrita.value }))
        .post(route('capital-social.rendiments.store'), {
            preserveScroll: true,
            onSuccess: () => rendimentForm.reset('dia', 'import', 'retencio', 'notes'),
        });
}

function eliminaRendiment(id: number) {
    router.delete(route('capital-social.rendiments.destroy', id), { preserveScroll: true });
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
    <Head title="Capital social" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Capital social</h2>
                <div class="flex items-center gap-2">
                    <button @click="showTotals = true"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        Totals per titular
                    </button>
                    <button @click="obreContracte(null)"
                        class="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 disabled:opacity-40">
                        Nou contracte
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-xl space-y-6 sm:px-6 lg:px-8">
                <p v-if="!props.comptesCapital.length && !props.contractes.length" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Les aportacions a una cooperativa de crèdit van en un compte de tipus <strong>capital social</strong>,
                    que es dona d'alta a Comptes Corrents amb el número que diu l'extracte i d'on surten els titulars.
                    Les que no són cap compte —una cooperativa de consum— es poden apuntar aquí mateix.
                </p>

                <!-- Resum -->
                <div v-if="props.contractes.length" class="grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Títols</p>
                        <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ totalTitols || '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Valor nominal</p>
                        <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ formatEur(totalValor) }}</p>
                        <p v-if="totalRevaloracio" class="text-xs text-green-700 dark:text-green-400">
                            {{ totalRevaloracio > 0 ? '+' : '' }}{{ formatEur(totalRevaloracio) }} de revaloració
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Rendiments</p>
                        <p v-if="retencioPerAny.length" class="float-right text-xs text-gray-400 dark:text-gray-500">
                            retenció {{ formatEur(retencioPerAny[0][1]) }}
                        </p>
                        <p v-if="rendimentPerAny.length" class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">
                            {{ formatEur(rendimentPerAny[0][1]) }}
                            <span class="text-xs font-normal text-gray-400 dark:text-gray-500">el {{ rendimentPerAny[0][0] }}</span>
                        </p>
                        <p v-else class="mt-1 text-xl font-bold text-gray-300 dark:text-gray-600">—</p>
                    </div>
                </div>

                <!-- Contractes -->
                <div v-for="c in props.contractes" :key="c.id" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-l-4 border-green-500 bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ c.nom }}
                                <span v-if="c.entitat && c.entitat !== c.nom" class="ml-1 font-normal text-gray-500 dark:text-gray-400">· {{ c.entitat }}</span>
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="c.compte_referencia" class="font-mono">{{ c.compte_referencia }}</span>
                                <span v-else-if="c.nif" class="font-mono">{{ c.nif }}</span>
                                <span v-else class="italic">sense compte</span>
                                <span v-if="c.titulars.length" class="ml-2">· {{ c.titulars.map(t => t.nom).join(', ') }}</span>
                                <span v-if="c.data_valor" class="ml-2">· a {{ formatData(c.data_valor) }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-6 text-sm">
                            <span v-if="c.titols !== null && c.valor_unitari !== null" class="text-gray-500 dark:text-gray-400">
                                {{ c.titols }} títols ×
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatEur(c.valor_unitari) }}</span>
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                Valor: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(c.valor) }}</span>
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
                                Títols i nominal per data
                            </h4>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase text-gray-400 dark:text-gray-500">
                                        <th class="pb-1 font-medium">Data</th>
                                        <th class="w-16 pb-1 text-right font-medium">Títols</th>
                                        <th class="w-24 pb-1 text-right font-medium">Unitari</th>
                                        <th class="w-28 pb-1 text-right font-medium">Total</th>
                                        <th class="w-40 pb-1 text-right font-medium">D'on ve el canvi</th>
                                        <th class="w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="v in c.valors" :key="v.id">
                                        <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ formatData(v.data) }}</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ v.titols ?? '—' }}</td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ v.valor_unitari === null ? '—' : formatEur(v.valor_unitari) }}
                                        </td>
                                        <td class="py-1.5 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(v.total) }}</td>
                                        <td class="whitespace-nowrap py-1.5 text-right text-xs">
                                            <span v-if="v.revaloracio" class="text-green-700 dark:text-green-400">
                                                {{ v.revaloracio > 0 ? '+' : '' }}{{ formatEur(v.revaloracio) }} de revaloració
                                            </span>
                                            <span v-if="v.revaloracio && v.aportacio" class="text-gray-400 dark:text-gray-500"> · </span>
                                            <span v-if="v.aportacio" class="text-gray-600 dark:text-gray-400">
                                                {{ v.aportacio > 0 ? '+' : '' }}{{ formatEur(v.aportacio) }} d'aportació
                                            </span>
                                            <span v-if="!v.revaloracio && !v.aportacio" class="text-gray-300 dark:text-gray-600">
                                                {{ v.aportacio === null ? (v.titols === null ? 'només el saldo' : 'primer valor') : 'sense canvis' }}
                                            </span>
                                        </td>
                                        <td class="py-1.5 text-right">
                                            <button @click="eliminaValor(v.id)" class="text-xs text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!c.valors.length">
                                        <td colspan="6" class="py-2 text-xs text-gray-400 dark:text-gray-500">
                                            Cap valor desat encara: apunta els números que diu l'extracte a 31/12.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input v-model="valorForm.dia" type="date"
                                    class="w-36 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <template v-if="perTitols">
                                    <input v-model="valorForm.titols" type="text" inputmode="numeric" placeholder="Títols"
                                        class="w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <input v-model="valorForm.valor_unitari" type="text" inputmode="decimal" placeholder="Unitari"
                                        class="w-28 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <span v-if="previsualitzaTotal !== null" class="text-xs text-gray-500 dark:text-gray-400">
                                        = {{ formatEur(previsualitzaTotal) }}
                                    </span>
                                </template>
                                <input v-else v-model="valorForm.import" type="text" inputmode="decimal" placeholder="Saldo"
                                    class="w-32 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <button @click="afegeixValor(c)" :disabled="valorForm.processing"
                                    class="rounded-md bg-green-600 px-3 py-1 text-sm text-white hover:bg-green-700 disabled:opacity-40">
                                    Afegeix
                                </button>
                                <button type="button" @click="perTitols = !perTitols"
                                    class="text-xs text-green-700 hover:underline dark:text-green-400">
                                    {{ perTitols ? 'només el saldo' : 'per títols' }}
                                </button>
                            </div>
                            <p v-if="errorValor" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errorValor }}</p>
                        </div>

                        <!-- Rendiments -->
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                Rendiments cobrats
                                <span v-if="c.compte_rendiment_nom" class="ml-1 font-normal normal-case tracking-normal text-gray-400 dark:text-gray-500">
                                    · arriben a {{ c.compte_rendiment_nom }}
                                </span>
                            </h4>

                            <div v-if="Object.keys(c.rendiment_per_any).length" class="mb-2 flex flex-wrap gap-2">
                                <span v-for="(import_, any) in c.rendiment_per_any" :key="any"
                                    class="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                    {{ any }}: {{ formatEur(import_) }}
                                </span>
                            </div>

                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="r in c.rendiments" :key="r.id">
                                        <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ formatData(r.data) }}</td>
                                        <td class="py-1.5 text-xs text-gray-400 dark:text-gray-500">
                                            {{ r.notes }}
                                            <span v-if="r.retencio" class="ml-1">retenció {{ formatEur(r.retencio) }} · net {{ formatEur(r.net) }}</span>
                                        </td>
                                        <td class="py-1.5 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(r.import) }}</td>
                                        <td class="w-8 py-1.5 text-right">
                                            <button @click="eliminaRendiment(r.id)" class="text-xs text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                    <tr v-if="!c.rendiments.length">
                                        <td colspan="4" class="py-2 text-xs text-gray-400 dark:text-gray-500">Cap rendiment cobrat encara.</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-2 flex gap-2">
                                <input v-model="rendimentForm.dia" type="date"
                                    class="w-36 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model="rendimentForm.import" type="text" inputmode="decimal" placeholder="Brut"
                                    class="w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model="rendimentForm.retencio" type="text" inputmode="decimal" placeholder="Retenció"
                                    class="w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model="rendimentForm.notes" type="text" placeholder="Notes"
                                    class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <button @click="afegeixRendiment(c)"
                                    :disabled="rendimentForm.processing"
                                    class="shrink-0 rounded-md bg-green-600 px-3 py-1 text-sm text-white hover:bg-green-700 disabled:opacity-40">
                                    Afegeix
                                </button>
                            </div>
                            <p v-if="errorRendiment" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ errorRendiment }}</p>
                        </div>

                        <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500 lg:col-span-2">
                            <span>
                                <span v-if="c.data_alta">Alta el {{ formatData(c.data_alta) }}</span>
                                <span v-if="c.notes" class="ml-2">{{ c.notes }}</span>
                            </span>
                            <span class="flex gap-3">
                                <button @click="obreContracte(c)" class="text-green-700 hover:underline dark:text-green-400">Edita</button>
                                <button @click="eliminaContracte(c)" class="text-red-600 hover:underline dark:text-red-400">Elimina</button>
                            </span>
                        </div>
                    </div>
                </div>

                <p v-if="!props.contractes.length && props.comptesCapital.length"
                    class="rounded-lg border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                    Encara no hi ha cap contracte de capital social.
                </p>
            </div>
        </div>

        <!-- Contracte -->
        <Modal :show="showContracte" max-width="lg" @close="showContracte = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ contracteEditat ? 'Edita el contracte' : 'Nou contracte' }}
                </h3>
                <div class="space-y-4">
                    <div class="rounded-md border border-gray-200 p-3 dark:border-gray-600">
                        <div class="flex items-baseline justify-between gap-3">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">On és l'aportació</label>
                            <button v-if="props.comptesCapital.length" type="button" @click="senseCompte = !senseCompte"
                                class="text-xs text-green-700 hover:underline dark:text-green-400">
                                {{ senseCompte ? 'és en un compte' : 'no té compte' }}
                            </button>
                        </div>

                        <template v-if="!senseCompte">
                            <select v-model="contracteForm.compte_corrent_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="c in props.comptesCapital" :key="c.id" :value="c.id">{{ c.compte }} — {{ c.nom }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">D'aquest compte en surten els titulars.</p>
                        </template>

                        <div v-else class="mt-1 space-y-3">
                            <!-- Una cooperativa de consum no és cap compte: no té número ni titulars propis -->
                            <div class="flex gap-2">
                                <input v-model="contracteForm.emissor" type="text" placeholder="Qui l'emet — Som Energia, SCCL"
                                    class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                <input v-model="contracteForm.nif" type="text" placeholder="NIF"
                                    class="w-32 rounded-md border-gray-300 font-mono text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <p v-if="contracteForm.errors.emissor" class="text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.emissor }}</p>

                            <div>
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Titulars</span>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="p in props.persones"
                                        :key="p.id"
                                        type="button"
                                        @click="alternaTitular(p.id)"
                                        :class="[
                                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors',
                                            contracteForm.titulars.includes(p.id)
                                                ? 'border-green-600 bg-green-50 font-medium text-green-800 dark:border-green-500 dark:bg-green-900/30 dark:text-green-200'
                                                : 'border-gray-300 bg-white text-gray-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400',
                                        ]"
                                    >
                                        <span class="w-3 text-center">{{ contracteForm.titulars.includes(p.id) ? '✓' : '' }}</span>
                                        {{ p.nom }}
                                    </button>
                                </div>
                                <p v-if="contracteForm.errors.titulars" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.titulars }}</p>
                            </div>
                        </div>

                        <p v-if="contracteForm.errors.compte_corrent_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.compte_corrent_id }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte on arriben els rendiments</label>
                        <select v-model="contracteForm.compte_rendiment_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option :value="null">— cap —</option>
                            <option v-for="c in props.comptesRendiment" :key="c.id" :value="c.id">{{ c.nom }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data d'alta</label>
                        <input v-model="contracteForm.data_alta" type="date"
                            class="mt-1 block w-40 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="contracteForm.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Els títols i el nominal s'apunten al detall del contracte, un joc per data.
                    </p>
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
                    El valor nominal de cada contracte, repartit entre els titulars del seu compte.
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
                                            {{ c.compte }}
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
