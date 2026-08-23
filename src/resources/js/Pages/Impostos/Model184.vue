<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Comunitat {
    id: number;
    nom: string;
    nif: string | null;
}

interface Comuner {
    persona_id: number;
    nom: string;
    nif: string | null;
    /** Percentatge de titularitat de l'immoble. Null si no s'ha definit. */
    quota: number | null;
    amortitzacio: number;
    /** Quota × base repartible − amortització pròpia. */
    rendiment: number | null;
    /** Surt del rendiment, no de la quota. */
    participacio: number | null;
    retencio: number | null;
}

interface RegistreImmoble {
    immoble_id: number;
    lloguer: string;
    referencia_cadastral: string | null;
    ingressos: number;
    despeses: number;
    rendiment_net: number;
    retencions: number;
    amortitzacio: number;
    base_repartible: number;
    caselles: Record<number, number>;
    comuners: Comuner[];
}

interface Declaracio {
    comunitat: { id: number; nom: string; nif: string | null };
    any: number;
    immobles: RegistreImmoble[];
    retencions: number;
    avisos: string[];
}

interface Props {
    comunitats: Comunitat[];
    comunitatId: number | null;
    any: number;
    /** Exercicis amb factures, del més recent al més antic. */
    anysAmbFactures: number[];
    declaracio: Declaracio | null;
    caselles: Record<number, string>;
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

function formatPct(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(value) + ' %';
}

const anyOpcions = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

/** Un exercici sense factures no té declaració: val la pena dir-ho al mateix selector. */
function etiquetaAny(a: number): string {
    return props.anysAmbFactures.includes(a) ? String(a) : `${a} · sense factures`;
}

function recarrega(camp: 'comunitat_bens_id' | 'any', valor: string) {
    router.get(route('impostos.model-184'), {
        comunitat_bens_id: camp === 'comunitat_bens_id' ? valor : props.comunitatId,
        any: camp === 'any' ? valor : props.any,
    }, { preserveState: true, preserveScroll: true });
}

/** Les caselles que el 184 té però que aquest exercici no fa servir no es mostren. */
function casellesAmbImport(registre: RegistreImmoble): Array<{ num: number; nom: string; import: number }> {
    return Object.entries(registre.caselles)
        .map(([num, imp]) => ({ num: Number(num), nom: props.caselles[Number(num)] ?? '?', import: imp }))
        .sort((a, b) => a.num - b.num);
}

// ---- Quota i amortització d'un comuner ----
const showComuner = ref(false);
const comunerEditat = ref<{ registre: RegistreImmoble; comuner: Comuner } | null>(null);

const comunerForm = useForm({
    immoble_id: 0,
    persona_id: 0,
    any: props.any,
    quota: null as number | null,
    amortitzacio_anual: null as number | null,
});

function obreComuner(registre: RegistreImmoble, comuner: Comuner) {
    comunerEditat.value = { registre, comuner };
    comunerForm.clearErrors();
    comunerForm.immoble_id = registre.immoble_id;
    comunerForm.persona_id = comuner.persona_id;
    comunerForm.any = props.any;
    comunerForm.quota = comuner.quota;
    comunerForm.amortitzacio_anual = comuner.amortitzacio || null;
    showComuner.value = true;
}

function desaComuner() {
    comunerForm.put(route('impostos.model-184.propietaris.update'), {
        preserveScroll: true,
        onSuccess: () => (showComuner.value = false),
    });
}

const capImmoble = computed(() => (props.declaracio?.immobles.length ?? 0) === 0);
</script>

<template>
    <Head title="Model 184" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Model 184 — atribució de rendes
                </h2>
                <div class="flex items-center gap-3">
                    <select
                        :value="props.comunitatId ?? ''"
                        @change="recarrega('comunitat_bens_id', ($event.target as HTMLSelectElement).value)"
                        class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="c in props.comunitats" :key="c.id" :value="c.id">{{ c.nom }}</option>
                    </select>
                    <select
                        :value="props.any"
                        @change="recarrega('any', ($event.target as HTMLSelectElement).value)"
                        class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="a in anyOpcions" :key="a" :value="a">{{ etiquetaAny(a) }}</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-xl space-y-6 sm:px-6 lg:px-8">
                <p v-if="!props.declaracio" class="text-sm text-gray-500 dark:text-gray-400">
                    No hi ha cap comunitat de béns donada d'alta.
                </p>

                <template v-else>
                    <div class="rounded-lg bg-white px-6 py-4 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Declarant
                            <span class="ml-2 font-semibold text-gray-900 dark:text-gray-100">{{ props.declaracio.comunitat.nom }}</span>
                            <span v-if="props.declaracio.comunitat.nif" class="ml-2 font-mono text-xs text-gray-400 dark:text-gray-500">
                                {{ props.declaracio.comunitat.nif }}
                            </span>
                            <span class="ml-4">exercici <span class="font-semibold text-gray-900 dark:text-gray-100">{{ props.declaracio.any }}</span></span>
                        </p>
                    </div>

                    <!-- El que cal repassar abans de declarar -->
                    <div v-if="props.declaracio.avisos.length" class="rounded-lg border-l-4 border-amber-400 bg-amber-50 px-4 py-3 dark:bg-amber-900/20">
                        <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Abans de declarar</h3>
                        <ul class="mt-1 list-inside list-disc text-sm text-amber-800 dark:text-amber-200">
                            <li v-for="(avis, i) in props.declaracio.avisos" :key="i">{{ avis }}</li>
                        </ul>
                    </div>

                    <p v-if="capImmoble" class="text-sm text-gray-500 dark:text-gray-400">
                        Aquesta comunitat no consta com a arrendadora de cap contracte.
                    </p>

                    <!-- Un registre de clau C per immoble -->
                    <section v-for="registre in props.declaracio.immobles" :key="registre.immoble_id" class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-l-4 border-red-400 bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    Clau C · subclau 01 — {{ registre.lloguer }}
                                </h3>
                                <p v-if="registre.referencia_cadastral" class="font-mono text-xs text-gray-400 dark:text-gray-500">
                                    {{ registre.referencia_cadastral }}
                                </p>
                            </div>
                            <div class="flex gap-6 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">
                                    Ingressos íntegres:
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(registre.ingressos) }}</span>
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    Rendiment net:
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(registre.rendiment_net) }}</span>
                                </span>
                            </div>
                        </div>

                        <div class="grid gap-6 p-4 lg:grid-cols-3">
                            <!-- Caselles de despesa -->
                            <div class="lg:col-span-1">
                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Despeses deduïbles</h4>
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr v-for="c in casellesAmbImport(registre)" :key="c.num">
                                            <td class="w-8 py-1.5 text-xs text-gray-400 dark:text-gray-500">{{ c.num }}</td>
                                            <td class="py-1.5 text-gray-700 dark:text-gray-300">{{ c.nom }}</td>
                                            <td class="whitespace-nowrap py-1.5 text-right font-medium text-gray-900 dark:text-gray-100">{{ formatEur(c.import) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t-2 border-gray-200 dark:border-gray-600">
                                        <tr class="font-semibold">
                                            <td colspan="2" class="py-1.5 text-gray-900 dark:text-gray-100">Total</td>
                                            <td class="whitespace-nowrap py-1.5 text-right text-gray-900 dark:text-gray-100">{{ formatEur(registre.despeses) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    La casella 8 surt de les amortitzacions dels comuners; la resta, de les despeses
                                    classificades del lloguer.
                                </p>
                            </div>

                            <!-- Comuners -->
                            <div class="lg:col-span-2 overflow-x-auto">
                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                                    Atribució als comuners
                                </h4>
                                <table class="w-full min-w-[38rem] table-fixed text-sm">
                                    <thead>
                                        <tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                            <th class="w-[26%] pb-2 text-left font-medium">Comuner</th>
                                            <th class="w-[12%] pb-2 text-right font-medium">Quota</th>
                                            <th class="w-[15%] pb-2 text-right font-medium">Amortització</th>
                                            <th class="w-[15%] pb-2 text-right font-medium">Rendiment</th>
                                            <th class="w-[15%] pb-2 text-right font-medium">Participació</th>
                                            <th class="w-[13%] pb-2 text-right font-medium">Retenció</th>
                                            <th class="w-[4%]"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr v-for="c in registre.comuners" :key="c.persona_id">
                                            <td class="py-1.5 pr-2 align-top text-gray-700 dark:text-gray-300">
                                                <span class="block truncate" :title="c.nom">{{ c.nom }}</span>
                                                <span v-if="c.nif" class="block font-mono text-xs text-gray-400 dark:text-gray-500">{{ c.nif }}</span>
                                            </td>
                                            <td class="whitespace-nowrap py-1.5 text-right align-top tabular-nums" :class="c.quota === null ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300'">
                                                {{ c.quota === null ? '—' : formatPct(c.quota) }}
                                            </td>
                                            <td class="whitespace-nowrap py-1.5 text-right align-top tabular-nums" :class="c.amortitzacio ? 'text-gray-700 dark:text-gray-300' : 'text-amber-600 dark:text-amber-400'">
                                                {{ c.amortitzacio ? formatEur(c.amortitzacio) : '—' }}
                                            </td>
                                            <td class="whitespace-nowrap py-1.5 text-right align-top font-medium tabular-nums text-gray-900 dark:text-gray-100">
                                                {{ c.rendiment === null ? '—' : formatEur(c.rendiment) }}
                                            </td>
                                            <td class="whitespace-nowrap py-1.5 text-right align-top tabular-nums text-gray-700 dark:text-gray-300">{{ formatPct(c.participacio) }}</td>
                                            <td class="whitespace-nowrap py-1.5 text-right align-top tabular-nums text-gray-700 dark:text-gray-300">
                                                {{ c.retencio === null ? '—' : formatEur(c.retencio) }}
                                            </td>
                                            <td class="py-1.5 text-right align-top">
                                                <button @click="obreComuner(registre, c)" class="text-xs text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400">
                                                    edita
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    La participació surt del rendiment de cadascú, no de la quota: és el que canvia quan
                                    cada comuner amortitza una cosa diferent. Les retencions, en canvi, es reparteixen
                                    per quota.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Registre de retencions -->
                    <div v-if="!capImmoble" class="rounded-lg bg-white px-4 py-3 shadow-sm dark:bg-gray-800">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Clau K · subclau 02 — suma de retencions i ingressos a compte:
                            <span class="ml-1 font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(props.declaracio.retencions) }}</span>
                        </span>
                    </div>
                </template>
            </div>
        </div>

        <!-- Quota i amortització -->
        <Modal :show="showComuner" max-width="lg" @close="showComuner = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Quota i amortització</h3>
                <p v-if="comunerEditat" class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ comunerEditat.comuner.nom }} — {{ comunerEditat.registre.lloguer }} · {{ props.any }}
                </p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quota de titularitat (%)</label>
                        <input v-model.number="comunerForm.quota" type="number" step="0.0001" min="0" max="100"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="comunerForm.errors.quota" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ comunerForm.errors.quota }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amortització anual (€)</label>
                        <input v-model.number="comunerForm.amortitzacio_anual" type="number" step="0.01" min="0"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            És fixa d'any en any mentre no hi hagi canvis de comuners, una millora o l'esgotament de la
                            base amortitzable. Es desa a la titularitat vigent durant {{ props.any }}.
                        </p>
                        <p v-if="comunerForm.errors.amortitzacio_anual" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ comunerForm.errors.amortitzacio_anual }}</p>
                    </div>
                    <p v-if="comunerForm.errors.persona_id" class="text-sm text-red-600 dark:text-red-400">{{ comunerForm.errors.persona_id }}</p>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showComuner = false" :disabled="comunerForm.processing"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Cancel·la
                    </button>
                    <button @click="desaComuner" :disabled="comunerForm.processing"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
