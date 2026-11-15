<script setup lang="ts">
/**
 * Les despeses d'un vehicle que no són combustible: d'un vehicle a motor (amb els km del comptador)
 * o d'una bici (amb la peça a què van, si n'és d'una). Cada despesa pot venir d'un moviment del banc,
 * sencer o en part: un mateix tiquet pot pagar la bici i altres coses, o dues bicis.
 */
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { num, perAlServidor, queFalta } from '@/formularis';

export interface MovimentDespesa {
    id: number;
    data: string;
    /** En positiu: el que va sortir del compte. */
    import: number;
    /** El que en queda per assignar sense comptar aquesta despesa. */
    pendent: number;
    compte: string | null;
    concepte: string | null;
}

export interface Despesa {
    id: number;
    data: string;
    tipus: string;
    tipus_nom: string;
    import: number;
    km_totals: number | null;
    taller: string | null;
    motiu: string | null;
    moviment_id: number | null;
    moviment: MovimentDespesa | null;
    material_id: number | null;
    material: string | null;
}

export interface Peca {
    id: number;
    nom: string;
    tipus: string;
    estat: string;
    muntat_a_vehicle_id: number | null;
}

const props = defineProps<{
    vehicleId: number;
    despeses: Despesa[];
    tipusDespesa: Record<string, string>;
    /** Fals a les bicis: sense km del comptador, i amb la peça. */
    esMotor: boolean;
    peces?: Peca[];
}>();

const formulariBuit = () => ({
    vehicle_id: props.vehicleId,
    dia: '',
    tipus: props.esMotor ? 'reparacio' : 'components',
    import: '' as string,
    km_totals: '' as string,
    taller: '',
    motiu: '',
    moviment_id: null as number | null,
    material_id: null as number | null,
});
const form = useForm(formulariBuit());

/** Id de la despesa que s'està editant. Null mentre el formulari és d'alta. */
const editant = ref<number | null>(null);
const falta = ref<string | null>(null);

/** El moviment lligat, per mostrar-lo encara que ja no surti a la cerca. */
const movimentTriat = ref<MovimentDespesa | null>(null);
const proposats = ref<MovimentDespesa[] | null>(null);
const cerca = ref('');
const cercant = ref(false);

// Les peces de la bici primer: les que hi van muntades, després la resta
const pecesOrdenades = computed(() => {
    const totes = props.peces ?? [];
    return {
        muntades: totes.filter((p) => p.muntat_a_vehicle_id === props.vehicleId),
        altres: totes.filter((p) => p.muntat_a_vehicle_id !== props.vehicleId),
    };
});

const euros = (v: number) => v.toLocaleString('ca-ES', { style: 'currency', currency: 'EUR' });

function edita(d: Despesa) {
    editant.value = d.id;
    form.clearErrors();
    falta.value = null;
    Object.assign(form, {
        dia: d.data,
        tipus: d.tipus,
        import: d.import.toLocaleString('ca-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: false }),
        km_totals: d.km_totals !== null ? String(d.km_totals) : '',
        taller: d.taller ?? '',
        motiu: d.motiu ?? '',
        moviment_id: d.moviment_id,
        material_id: d.material_id,
    });
    movimentTriat.value = d.moviment;
    proposats.value = null;
}

function buida() {
    editant.value = null;
    form.clearErrors();
    falta.value = null;
    Object.assign(form, formulariBuit());
    movimentTriat.value = null;
    proposats.value = null;
    cerca.value = '';
}

async function buscaMoviments() {
    if (!form.dia) {
        falta.value = 'Per buscar el moviment cal la data de la despesa.';
        return;
    }
    falta.value = null;
    cercant.value = true;
    const params = new URLSearchParams({ data: form.dia });
    const imp = num(form.import);
    if (imp !== null) params.set('import', String(imp));
    if (editant.value !== null) params.set('despesa', String(editant.value));
    if (cerca.value.trim()) params.set('q', cerca.value.trim());
    try {
        const res = await fetch(route('vehicles.moviments-despesa') + '?' + params, { headers: { Accept: 'application/json' } });
        proposats.value = res.ok ? await res.json() : [];
    } finally {
        cercant.value = false;
    }
}

function triaMoviment(m: MovimentDespesa) {
    form.moviment_id = m.id;
    movimentTriat.value = m;
    proposats.value = null;
    // Sense import escrit, el que en queda: el cas normal és que el tiquet sigui tot d'aquesta despesa
    if (num(form.import) === null) form.import = m.pendent.toLocaleString('ca-ES', { minimumFractionDigits: 2, useGrouping: false });
    if (!form.motiu && m.concepte) form.motiu = m.concepte;
}

function desvincula() {
    form.moviment_id = null;
    movimentTriat.value = null;
}

function desa() {
    falta.value = queFalta(form.dia, [[num(form.import), "l'import"]]);
    if (falta.value) return;

    const opcions = { preserveScroll: true, preserveState: true, onSuccess: () => buida() };
    const peticio = form.transform((d) => ({
        ...perAlServidor(d),
        import: num(d.import),
        km_totals: props.esMotor ? num(d.km_totals) : null,
        material_id: props.esMotor ? null : d.material_id,
    }));

    if (editant.value !== null) peticio.put(route('vehicles.despeses.update', editant.value), opcions);
    else peticio.post(route('vehicles.despeses.store'), opcions);
}

function elimina(d: Despesa) {
    if (!confirm(`Vols eliminar la despesa del ${d.data}?`)) return;
    if (editant.value === d.id) buida();
    router.delete(route('vehicles.despeses.destroy', d.id), { preserveScroll: true, preserveState: true });
}

const camp = 'mt-0.5 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
</script>

<template>
    <div>
        <div class="mb-3 border-b border-gray-200 pb-3 dark:border-gray-600">
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    Data
                    <input v-model="form.dia" type="date" :class="[camp, 'w-36']" />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    Tipus
                    <select v-model="form.tipus" :class="[camp, 'w-44']">
                        <option v-for="(nom, clau) in tipusDespesa" :key="clau" :value="clau">{{ nom }}</option>
                    </select>
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    Import
                    <input v-model="form.import" type="text" inputmode="decimal" :class="[camp, 'w-24 text-right']" />
                </label>
                <label v-if="esMotor" class="text-xs text-gray-500 dark:text-gray-400">
                    Km totals
                    <input v-model="form.km_totals" type="text" inputmode="numeric" placeholder="opcional" :class="[camp, 'w-28']" />
                </label>
                <label v-else class="text-xs text-gray-500 dark:text-gray-400">
                    Peça
                    <select v-model="form.material_id" :class="[camp, 'w-48']">
                        <option :value="null">— cap (de la bici)</option>
                        <optgroup v-if="pecesOrdenades.muntades.length" label="Muntades a aquesta bici">
                            <option v-for="p in pecesOrdenades.muntades" :key="p.id" :value="p.id">{{ p.nom }}</option>
                        </optgroup>
                        <optgroup v-if="pecesOrdenades.altres.length" label="La resta">
                            <option v-for="p in pecesOrdenades.altres" :key="p.id" :value="p.id">{{ p.nom }}{{ p.estat === 'retirat' ? ' (retirada)' : '' }}</option>
                        </optgroup>
                    </select>
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    {{ esMotor ? 'Taller' : 'Botiga o taller' }}
                    <input v-model="form.taller" type="text" :list="`tallers-${vehicleId}`" :class="[camp, 'w-40']" />
                </label>
                <label class="min-w-[10rem] flex-1 text-xs text-gray-500 dark:text-gray-400">
                    Motiu
                    <input v-model="form.motiu" type="text" :class="[camp, 'w-full']" />
                </label>
                <button type="button" @click="desa" :disabled="form.processing"
                    class="mb-1 rounded-md bg-sky-600 px-3 py-1 text-sm text-white hover:bg-sky-700 disabled:opacity-40">
                    {{ editant === null ? 'Afegeix' : 'Desa' }}
                </button>
                <button v-if="editant !== null" type="button" @click="buida"
                    class="mb-1 px-2 py-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    Cancel·la
                </button>
            </div>

            <!-- D'on ha sortit: el moviment del banc, sencer o en part -->
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-gray-500 dark:text-gray-400">Moviment del banc:</span>
                <template v-if="movimentTriat">
                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200">
                        🔗 {{ movimentTriat.data }} · {{ euros(movimentTriat.import) }} · {{ movimentTriat.compte }}
                        <template v-if="movimentTriat.concepte"> · {{ movimentTriat.concepte }}</template>
                        <template v-if="movimentTriat.pendent < movimentTriat.import - 0.005"> (en queden {{ euros(movimentTriat.pendent) }} sense aquesta)</template>
                    </span>
                    <button type="button" @click="desvincula" class="text-gray-500 hover:text-red-600 dark:text-gray-400">Desvincula</button>
                </template>
                <template v-else>
                    <input v-model="cerca" type="text" placeholder="text del concepte (opcional)" @keydown.enter.prevent="buscaMoviments"
                        class="w-52 rounded-md border-gray-300 py-0.5 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    <button type="button" @click="buscaMoviments" :disabled="cercant"
                        class="rounded-md border border-gray-300 px-2 py-0.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ cercant ? 'Buscant…' : 'Busca' }}
                    </button>
                    <span class="text-gray-400 dark:text-gray-500">
                        {{ cerca.trim() ? 'un any amunt i avall de la data' : 'deu dies amunt i avall de la data' }}, de tots els comptes
                    </span>
                </template>
            </div>
            <div v-if="proposats && !movimentTriat" class="mt-2 space-y-1 text-xs">
                <p v-if="!proposats.length" class="text-gray-400 dark:text-gray-500">
                    Cap moviment amb import per assignar en aquestes dates{{ cerca.trim() ? ' amb aquest text' : '' }}.
                </p>
                <button v-for="m in proposats" :key="m.id" type="button" @click="triaMoviment(m)"
                    class="flex w-full flex-wrap gap-x-3 rounded-md border border-gray-200 px-2 py-1 text-left hover:border-sky-400 hover:bg-sky-50 dark:border-gray-600 dark:hover:bg-sky-900/20">
                    <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ m.data }}</span>
                    <span class="font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ euros(m.import) }}</span>
                    <span v-if="m.pendent < m.import - 0.005" class="text-amber-700 dark:text-amber-400">en queden {{ euros(m.pendent) }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ m.compte }}</span>
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ m.concepte }}</span>
                </button>
            </div>

            <p v-if="falta" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ falta }}</p>
            <p v-for="(e, c) in form.errors" :key="c" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ e }}</p>
        </div>

        <datalist :id="`tallers-${vehicleId}`">
            <option v-for="t in [...new Set(despeses.map(d => d.taller).filter(Boolean))]" :key="t as string" :value="t" />
        </datalist>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        <th class="whitespace-nowrap pb-1 pr-3 font-medium">Data</th>
                        <th class="whitespace-nowrap pb-1 pr-3 font-medium">Tipus</th>
                        <th class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Import</th>
                        <th v-if="esMotor" class="whitespace-nowrap pb-1 pr-3 text-right font-medium">Km totals</th>
                        <th v-else class="whitespace-nowrap pb-1 pr-3 font-medium">Peça</th>
                        <th class="whitespace-nowrap pb-1 pr-3 font-medium">{{ esMotor ? 'Taller' : 'Botiga' }}</th>
                        <th class="w-full pb-1 pr-3 font-medium">Motiu</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-for="d in despeses" :key="d.id" :class="editant === d.id ? 'bg-sky-50 dark:bg-sky-900/20' : ''">
                        <td class="whitespace-nowrap py-1 pr-3 text-gray-600 dark:text-gray-400">{{ d.data }}</td>
                        <td class="whitespace-nowrap py-1 pr-3">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ d.tipus_nom }}</span>
                        </td>
                        <td class="whitespace-nowrap py-1 pr-3 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">
                            {{ euros(d.import) }}
                            <span v-if="d.moviment" class="ml-1 text-xs text-sky-500"
                                :title="`Del moviment del ${d.moviment.data} (${euros(d.moviment.import)}, ${d.moviment.compte ?? ''}): ${d.moviment.concepte ?? ''}`">🔗</span>
                        </td>
                        <td v-if="esMotor" class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                            {{ d.km_totals !== null ? d.km_totals.toLocaleString('ca-ES') : '—' }}
                        </td>
                        <td v-else class="whitespace-nowrap py-1 pr-3 text-xs text-gray-600 dark:text-gray-400">{{ d.material ?? '—' }}</td>
                        <td class="whitespace-nowrap py-1 pr-3 text-xs text-gray-500 dark:text-gray-400">{{ d.taller }}</td>
                        <td class="w-full max-w-0 truncate py-1 pr-3 text-xs text-gray-500 dark:text-gray-400" :title="d.motiu ?? ''">{{ d.motiu }}</td>
                        <td class="whitespace-nowrap py-1 text-right">
                            <button type="button" @click="edita(d)" title="Edita" class="text-xs text-sky-600 hover:text-sky-800 dark:text-sky-400">✎</button>
                            <button type="button" @click="elimina(d)" title="Elimina" class="ml-2 text-xs text-red-500 hover:text-red-700">×</button>
                        </td>
                    </tr>
                    <tr v-if="!despeses.length">
                        <td colspan="7" class="py-2 text-xs text-gray-400 dark:text-gray-500">Encara no hi ha cap despesa.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
