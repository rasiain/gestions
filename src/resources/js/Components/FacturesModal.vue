<script setup lang="ts">
import { ref, watch, computed } from 'vue';

interface FacturaLinia {
    id?: number;
    concepte: string;
    descripcio: string | null;
    base: string;
    iva_import: string;
    irpf_import: string;
}

interface MovimentVinculat {
    id: number;
    data_moviment: string;
    import: string;
}

interface Factura {
    id: number;
    lloguer_id: number;
    contracte_id: number | null;
    any: number | null;
    mes: number | null;
    tipus: 'mensual' | 'puntual';
    base: string;
    iva_percentatge: string;
    iva_import: string;
    irpf_percentatge: string;
    irpf_import: string;
    total: string;
    estat: string;
    moviment_id: number | null;
    moviment: MovimentVinculat | null;
    numero_factura: string | null;
    data_emissio: string | null;
    notes: string | null;
    linies: FacturaLinia[];
}

interface Lloguer {
    id: number;
    nom: string;
    base_euros: string | null;
    iva_percentatge: string | null;
    irpf_percentatge: string | null;
    retencio_irpf: boolean;
    compte_corrent_id: number;
}

interface MovimentOption {
    id: number;
    data_moviment: string;
    concepte: string;
    import: string;
}

const props = defineProps<{
    lloguer: Lloguer;
    show: boolean;
}>();

const emit = defineEmits(['close']);

const factures = ref<Factura[]>([]);
const loading = ref(false);
const filterAny = ref(new Date().getFullYear());
const generarForm = ref({ any: new Date().getFullYear(), mes_inici: 1, mes_fi: 12 });
const showGenerar = ref(false);
const generarLoading = ref(false);
const editingFactura = ref<Factura | null>(null);
const showEditModal = ref(false);
const editSaving = ref(false);
const editErrors = ref<Record<string, string>>({});

// Vincular moviment
const vincularFactura = ref<Factura | null>(null);
const showVincularModal = ref(false);
const movimentsDisponibles = ref<MovimentOption[]>([]);
const movimentsLoading = ref(false);
const selectedMovimentId = ref<number | null>(null);

const editForm = ref({
    tipus: 'mensual' as 'mensual' | 'puntual',
    any: null as number | null,
    mes: null as number | null,
    base: 0,
    iva_percentatge: 0,
    iva_import: 0,
    irpf_percentatge: 0,
    irpf_import: 0,
    total: 0,
    estat: 'esborrany',
    numero_factura: '' as string | null,
    data_emissio: '' as string | null,
    notes: '' as string | null,
    linies: [] as { concepte: string; descripcio: string | null; base: number; iva_import: number; irpf_import: number }[],
});

const csrfToken = (): string =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

const formatCurrency = (value: string | null): string => {
    if (value === null) return '-';
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(parseFloat(value));
};

const nomMes = (mes: number): string => {
    const noms = ['Gen', 'Feb', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Des'];
    return noms[mes - 1] || '';
};

const estatColor = (estat: string): string => {
    switch (estat) {
        case 'esborrany': return 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
        case 'emesa': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        case 'cobrada': return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
        default: return 'bg-gray-100 text-gray-700';
    }
};

const fetchFactures = async () => {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (filterAny.value) params.set('any', String(filterAny.value));
        const res = await fetch(`/lloguers/${props.lloguer.id}/factures?${params}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();
        factures.value = json.data;
    } finally {
        loading.value = false;
    }
};

const generarFactures = async () => {
    generarLoading.value = true;
    try {
        const res = await fetch(`/lloguers/${props.lloguer.id}/factures/generar`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(generarForm.value),
        });
        if (res.ok) {
            showGenerar.value = false;
            filterAny.value = generarForm.value.any;
            await fetchFactures();
        }
    } finally {
        generarLoading.value = false;
    }
};

// La configuració de les escombraries de l'any: quant se'n repercuteix, en quins
// mesos i si va en una factura a banda. D'aquí les treu el generador.
interface Fraccio { mes: number; import: number | null }

const showEscombraries = ref(false);
const escombrariesLoading = ref(false);
const escombrariesForm = ref({
    import_total: null as number | null,
    factura_separada: false,
    fraccions: [] as Fraccio[],
});
const escombrariesProposta = ref<{ import_total: number; terminis: number; import: number } | null>(null);
const escombrariesMissatge = ref<string | null>(null);

const fetchEscombraries = async () => {
    escombrariesLoading.value = true;
    escombrariesMissatge.value = null;
    try {
        const res = await fetch(`/lloguers/${props.lloguer.id}/escombraries?any=${filterAny.value}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();
        escombrariesProposta.value = json.proposta;

        if (json.data) {
            escombrariesForm.value = {
                import_total: json.data.import_total ? parseFloat(json.data.import_total) : null,
                factura_separada: !!json.data.factura_separada,
                fraccions: (json.data.fraccions ?? []).map((f: any) => ({ mes: f.mes, import: parseFloat(f.import) })),
            };
        } else {
            escombrariesForm.value = { import_total: null, factura_separada: false, fraccions: [] };
        }
    } finally {
        escombrariesLoading.value = false;
    }
};

// El rebut de l'ajuntament diu el total i els terminis; els mesos els posa qui ho
// configura, que és el que s'ha pactat amb el llogater.
const aplicarProposta = () => {
    const p = escombrariesProposta.value;
    if (!p) return;
    escombrariesForm.value.import_total = p.import_total;
    escombrariesForm.value.fraccions = Array.from({ length: p.terminis }, () => ({ mes: 1, import: p.import }));
};

const afegirFraccio = () => {
    escombrariesForm.value.fraccions.push({ mes: 1, import: null });
};

const desarEscombraries = async () => {
    escombrariesLoading.value = true;
    escombrariesMissatge.value = null;
    try {
        const res = await fetch(`/lloguers/${props.lloguer.id}/escombraries`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ any: filterAny.value, ...escombrariesForm.value }),
        });
        escombrariesMissatge.value = res.ok
            ? 'Desat. En generar les factures de l\'any, les fraccions hi aniran.'
            : 'No s\'ha pogut desar.';
    } finally {
        escombrariesLoading.value = false;
    }
};

// El PDF es desa a la carpeta de l'any de l'immoble. Si el fitxer ja hi és, el
// servidor no el trepitja i torna `exists`: es pregunta i es repeteix amb `force`.
const desantPdf = ref<number | null>(null);
const desantAny = ref(false);
const missatgePdf = ref<string | null>(null);

const veurePdf = (factura: Factura) => {
    window.open(`/factures/${factura.id}/pdf`, '_blank');
};

const desarPdf = async (factura: Factura, force = false) => {
    desantPdf.value = factura.id;
    missatgePdf.value = null;
    try {
        const res = await fetch(`/factures/${factura.id}/desar-pdf${force ? '?force=1' : ''}`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();

        if (json.exists) {
            if (confirm(`«${json.filename}» ja existeix. El vols substituir?`)) {
                await desarPdf(factura, true);
            }
            return;
        }

        if (!res.ok) {
            missatgePdf.value = json.error ?? 'No s\'ha pogut desar el PDF.';
            return;
        }

        missatgePdf.value = `Desat: ${json.filename}`;
        await fetchFactures();
    } finally {
        desantPdf.value = null;
    }
};

const desarPdfAny = async (force = false) => {
    desantAny.value = true;
    missatgePdf.value = null;
    try {
        const params = new URLSearchParams({ any: String(filterAny.value) });
        if (force) params.set('force', '1');
        const res = await fetch(`/lloguers/${props.lloguer.id}/factures/desar-pdf?${params}`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();

        if (!res.ok) {
            missatgePdf.value = json.error ?? 'No s\'han pogut desar els PDF.';
            return;
        }

        if (json.existents?.length && !force) {
            if (confirm(`${json.existents.length} fitxer(s) ja existeixen. Els vols substituir?`)) {
                await desarPdfAny(true);
                return;
            }
        }

        missatgePdf.value = `${json.desades.length} PDF desats`
            + (json.existents?.length && !force ? `, ${json.existents.length} ja hi eren` : '');
        await fetchFactures();
    } finally {
        desantAny.value = false;
    }
};

// L'esborrany s'obre a Gmail amb tot escrit; el PDF es baixa alhora perquè Gmail
// no deixa adjuntar per URL i s'hi ha d'arrossegar.
const preparantCorreu = ref<number | null>(null);

const prepararCorreu = async (factura: Factura) => {
    preparantCorreu.value = factura.id;
    missatgePdf.value = null;
    try {
        const res = await fetch(`/factures/${factura.id}/correu`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();

        if (!json.to) {
            missatgePdf.value = 'El llogater no té correu electrònic: s\'indica a la seva fitxa.';
            return;
        }

        window.open(`/factures/${factura.id}/pdf?baixa=1`, '_blank');
        window.open(json.url, '_blank');
    } finally {
        preparantCorreu.value = null;
    }
};

const openEditFactura = (factura: Factura) => {
    editingFactura.value = factura;
    editForm.value = {
        tipus: factura.tipus,
        any: factura.any,
        mes: factura.mes,
        base: parseFloat(factura.base),
        iva_percentatge: parseFloat(factura.iva_percentatge),
        iva_import: parseFloat(factura.iva_import),
        irpf_percentatge: parseFloat(factura.irpf_percentatge),
        irpf_import: parseFloat(factura.irpf_import),
        total: parseFloat(factura.total),
        estat: factura.estat,
        numero_factura: factura.numero_factura || '',
        data_emissio: factura.data_emissio || '',
        notes: factura.notes || '',
        linies: factura.linies.map(l => ({
            concepte: l.concepte,
            descripcio: l.descripcio,
            base: parseFloat(l.base),
            iva_import: parseFloat(l.iva_import),
            irpf_import: parseFloat(l.irpf_import),
        })),
    };
    editErrors.value = {};
    showEditModal.value = true;
};

const openNewPuntual = () => {
    editingFactura.value = null;
    const avui = new Date();
    const dataEmissio = avui.toISOString().slice(0, 10);
    editForm.value = {
        tipus: 'puntual',
        any: avui.getFullYear(),
        mes: avui.getMonth() + 1,
        base: 0,
        iva_percentatge: parseFloat(props.lloguer.iva_percentatge || '0'),
        iva_import: 0,
        irpf_percentatge: props.lloguer.retencio_irpf ? parseFloat(props.lloguer.irpf_percentatge || '0') : 0,
        irpf_import: 0,
        total: 0,
        estat: 'esborrany',
        numero_factura: '',
        data_emissio: dataEmissio,
        notes: '',
        linies: [{ concepte: 'altres', descripcio: '', base: 0, iva_import: 0, irpf_import: 0 }],
    };
    editErrors.value = {};
    showEditModal.value = true;
};

const recalculateEdit = () => {
    const base = editForm.value.linies.reduce((s, l) => s + (l.base || 0), 0);
    editForm.value.base = parseFloat(base.toFixed(2));
    editForm.value.iva_import = parseFloat((base * editForm.value.iva_percentatge / 100).toFixed(2));
    editForm.value.irpf_import = parseFloat((base * editForm.value.irpf_percentatge / 100).toFixed(2));
    editForm.value.total = parseFloat((base + editForm.value.iva_import - editForm.value.irpf_import).toFixed(2));
    // Recalcular IVA/IRPF per línia proporcionalment
    editForm.value.linies.forEach(l => {
        l.iva_import = base > 0 ? parseFloat(((l.base || 0) * editForm.value.iva_percentatge / 100).toFixed(2)) : 0;
        l.irpf_import = base > 0 ? parseFloat(((l.base || 0) * editForm.value.irpf_percentatge / 100).toFixed(2)) : 0;
    });
};

/**
 * L'import d'una factura surt de les línies: si va a parar a un altre camp
 * —la descripció, posem— la factura es desa a zero sense dir res. Aquests
 * avisos ho fan visible mentre s'edita i demanen confirmació abans de desar.
 */
const avisosFactura = computed<string[]>(() => {
    const avisos: string[] = [];

    const senseImport = editForm.value.linies.filter((l) => !l.base).length;
    if (senseImport > 0) {
        avisos.push(
            senseImport === 1
                ? "Hi ha una línia sense import: l'import va al camp «Base» de la línia."
                : `Hi ha ${senseImport} línies sense import: l'import va al camp «Base» de la línia.`
        );
    }

    if (editForm.value.linies.length === 0) {
        avisos.push('La factura no té cap línia.');
    } else if (editForm.value.base === 0) {
        avisos.push('La base total és 0.');
    }

    return avisos;
});

const addLinia = () => {
    editForm.value.linies.push({ concepte: 'altres', descripcio: '', base: 0, iva_import: 0, irpf_import: 0 });
};

const removeLinia = (index: number) => {
    editForm.value.linies.splice(index, 1);
    recalculateEdit();
};

const submitEditFactura = async () => {
    if (avisosFactura.value.length > 0 && !confirm(`${avisosFactura.value.join('\n')}\n\nVols desar-la igualment?`)) {
        return;
    }

    editSaving.value = true;
    editErrors.value = {};
    try {
        const url = editingFactura.value
            ? `/factures/${editingFactura.value.id}`
            : `/lloguers/${props.lloguer.id}/factures`;
        const method = editingFactura.value ? 'PUT' : 'POST';
        const res = await fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(editForm.value),
        });
        const json = await res.json();
        if (!res.ok) {
            editErrors.value = json.errors ?? { general: json.error ?? 'Error' };
            return;
        }
        showEditModal.value = false;
        await fetchFactures();
    } finally {
        editSaving.value = false;
    }
};

const deleteFactura = async (factura: Factura) => {
    if (!confirm('Estàs segur que vols eliminar aquesta factura?')) return;
    const res = await fetch(`/factures/${factura.id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });
    if (res.ok) {
        await fetchFactures();
    }
};

const openVincularModal = async (factura: Factura) => {
    vincularFactura.value = factura;
    selectedMovimentId.value = factura.moviment_id;
    showVincularModal.value = true;
    movimentsLoading.value = true;
    try {
        const params = new URLSearchParams({ page: '1', per_vincular_factura: String(factura.id) });
        if (filterAny.value) params.set('any', String(filterAny.value));
        const res = await fetch(`/lloguers/${props.lloguer.id}/moviments?${params}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        const json = await res.json();
        movimentsDisponibles.value = json.data
            .filter((m: any) => parseFloat(m.import) > 0)
            .map((m: any) => ({
                id: m.id,
                data_moviment: m.data_moviment,
                concepte: m.concepte,
                import: m.import,
            }));
    } finally {
        movimentsLoading.value = false;
    }
};

const submitVincular = async () => {
    if (!vincularFactura.value) return;
    const res = await fetch(`/factures/${vincularFactura.value.id}/vincular-moviment`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ moviment_id: selectedMovimentId.value }),
    });
    if (res.ok) {
        showVincularModal.value = false;
        await fetchFactures();
    }
};

watch(() => props.show, (val) => {
    if (val) {
        fetchFactures();
    }
});

watch(filterAny, () => {
    if (props.show) fetchFactures();
});

const anys = computed(() => {
    const current = new Date().getFullYear();
    return Array.from({ length: 5 }, (_, i) => current - 2 + i);
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
            <div class="relative inline-block w-full max-w-5xl transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:align-middle">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Factures — {{ lloguer.nom }}
                    </h3>
                    <button @click="emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Toolbar -->
                <div class="flex items-center gap-4 border-b border-gray-200 px-6 py-3 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-600 dark:text-gray-400">Any:</label>
                        <select
                            v-model="filterAny"
                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        >
                            <option v-for="a in anys" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>
                    <button
                        @click="() => { showGenerar = !showGenerar; if (showGenerar) generarForm.any = filterAny; }"
                        class="inline-flex items-center gap-1 rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-amber-700"
                    >
                        Generar factures
                    </button>
                    <button
                        @click="openNewPuntual"
                        class="inline-flex items-center gap-1 rounded-md border border-amber-600 px-3 py-1.5 text-sm font-medium text-amber-600 shadow-sm hover:bg-amber-50 dark:border-amber-400 dark:text-amber-400 dark:hover:bg-amber-900/20"
                    >
                        Nova factura puntual
                    </button>
                    <button
                        @click="desarPdfAny(false)"
                        :disabled="desantAny || factures.length === 0"
                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        {{ desantAny ? 'Desant...' : 'Desar PDF de l\'any' }}
                    </button>
                    <button
                        @click="() => { showEscombraries = !showEscombraries; if (showEscombraries) fetchEscombraries(); }"
                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Escombraries
                    </button>
                    <span v-if="missatgePdf" class="text-sm text-gray-500 dark:text-gray-400">{{ missatgePdf }}</span>
                </div>

                <!-- Escombraries de l'any -->
                <div v-if="showEscombraries" class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/40">
                    <div class="flex items-end gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Total de l'any</label>
                            <input
                                v-model.number="escombrariesForm.import_total"
                                type="text"
                                class="mt-1 block w-32 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                        </div>
                        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
                            <input v-model="escombrariesForm.factura_separada" type="checkbox" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
                            Factura a banda
                        </label>
                        <button
                            v-if="escombrariesProposta"
                            @click="aplicarProposta"
                            class="pb-2 text-sm text-amber-600 hover:underline dark:text-amber-400"
                        >
                            Proposar del rebut ({{ escombrariesProposta.import_total }} € en {{ escombrariesProposta.terminis }})
                        </button>
                    </div>

                    <div class="mt-3 space-y-2">
                        <div v-for="(fraccio, i) in escombrariesForm.fraccions" :key="i" class="flex items-center gap-2">
                            <span class="w-16 text-sm text-gray-500 dark:text-gray-400">{{ i + 1 }} de {{ escombrariesForm.fraccions.length }}</span>
                            <select v-model.number="fraccio.mes" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="m in 12" :key="m" :value="m">{{ nomMes(m) }}</option>
                            </select>
                            <input
                                v-model.number="fraccio.import"
                                type="text"
                                class="w-32 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                            <button @click="escombrariesForm.fraccions.splice(i, 1)" class="text-sm text-red-600 hover:underline dark:text-red-400">Treure</button>
                        </div>
                        <button @click="afegirFraccio" class="text-sm text-amber-600 hover:underline dark:text-amber-400">Afegir fracció</button>
                    </div>

                    <div class="mt-3 flex items-center gap-3">
                        <button
                            @click="desarEscombraries"
                            :disabled="escombrariesLoading"
                            class="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                        >
                            {{ escombrariesLoading ? 'Desant...' : 'Desar' }}
                        </button>
                        <span v-if="escombrariesMissatge" class="text-sm text-gray-500 dark:text-gray-400">{{ escombrariesMissatge }}</span>
                    </div>
                </div>

                <!-- Generar form -->
                <div v-if="showGenerar" class="border-b border-gray-200 bg-amber-50 px-6 py-4 dark:border-gray-700 dark:bg-amber-900/20">
                    <div class="flex items-end gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Any</label>
                            <input v-model.number="generarForm.any" type="number" class="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Mes inici</label>
                            <select v-model.number="generarForm.mes_inici" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Mes fi</label>
                            <select v-model.number="generarForm.mes_fi" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                        <button
                            @click="generarFactures"
                            :disabled="generarLoading"
                            class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                        >
                            {{ generarLoading ? 'Generant...' : 'Generar' }}
                        </button>
                        <button
                            @click="showGenerar = false"
                            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400"
                        >
                            Cancel·lar
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="max-h-[60vh] overflow-y-auto px-6 py-4">
                    <div v-if="loading" class="py-8 text-center text-sm text-gray-400">Carregant...</div>

                    <div v-else-if="factures.length > 0" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Mes</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Base</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">IVA</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">IRPF</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Total</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Estat</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Num. factura</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr v-for="factura in factures" :key="factura.id">
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                                        <template v-if="factura.tipus === 'puntual'">
                                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                                Puntual
                                            </span>
                                            <span v-if="factura.mes && factura.any" class="ml-1 text-gray-500 dark:text-gray-400">{{ nomMes(factura.mes) }} {{ factura.any }}</span>
                                        </template>
                                        <template v-else>
                                            {{ nomMes(factura.mes!) }} {{ factura.any }}
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(factura.base) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(factura.iva_import) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ parseFloat(factura.irpf_import) > 0 ? formatCurrency(factura.irpf_import) : '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono font-semibold text-gray-900 dark:text-gray-100">{{ formatCurrency(factura.total) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', estatColor(factura.estat)]">
                                            {{ factura.estat }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ factura.numero_factura || '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-sm font-medium">
                                        <button @click="openEditFactura(factura)" class="text-amber-600 hover:text-amber-900 dark:text-amber-400 mr-2">Editar</button>
                                        <button @click="openVincularModal(factura)" :class="factura.moviment_id ? 'text-green-600 hover:text-green-800 dark:text-green-400' : 'text-blue-600 hover:text-blue-900 dark:text-blue-400'" class="mr-2">
                                            {{ factura.moviment_id ? 'Vinculat' : 'Vincular' }}
                                        </button>
                                        <button @click="veurePdf(factura)" class="mr-2 text-gray-600 hover:text-gray-900 dark:text-gray-300">PDF</button>
                                        <button
                                            @click="desarPdf(factura)"
                                            :disabled="desantPdf === factura.id"
                                            class="mr-2 text-gray-600 hover:text-gray-900 disabled:opacity-50 dark:text-gray-300"
                                        >
                                            {{ desantPdf === factura.id ? 'Desant...' : 'Desar' }}
                                        </button>
                                        <button
                                            @click="prepararCorreu(factura)"
                                            :disabled="preparantCorreu === factura.id"
                                            class="mr-2 text-gray-600 hover:text-gray-900 disabled:opacity-50 dark:text-gray-300"
                                        >
                                            Correu
                                        </button>
                                        <button v-if="factura.estat === 'esborrany'" @click="deleteFactura(factura)" class="text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 font-bold">
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">TOTAL</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(factures.reduce((s, f) => s + parseFloat(f.base), 0).toFixed(2)) }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(factures.reduce((s, f) => s + parseFloat(f.iva_import), 0).toFixed(2)) }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(factures.reduce((s, f) => s + parseFloat(f.irpf_import), 0).toFixed(2)) }}</td>
                                    <td class="px-3 py-2 text-sm text-right font-mono font-semibold text-gray-900 dark:text-gray-100">{{ formatCurrency(factures.reduce((s, f) => s + parseFloat(f.total), 0).toFixed(2)) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div v-else class="py-8 text-center text-sm italic text-gray-400">
                        No hi ha factures per a aquest any. Fes servir "Generar factures" per crear-ne.
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

        <!-- Edit Factura Sub-Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 pt-4 pb-20 text-center sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-50" @click="showEditModal = false"></div>
                <div class="relative inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left align-bottom shadow-xl sm:my-8 sm:align-middle">
                    <form @submit.prevent="submitEditFactura">
                        <div class="px-4 pt-5 pb-4 sm:p-6">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">
                                <template v-if="!editingFactura">Nova factura puntual</template>
                                <template v-else-if="editingFactura.tipus === 'puntual'">Editar factura puntual</template>
                                <template v-else>Editar factura — {{ nomMes(editingFactura.mes!) }} {{ editingFactura.any }}</template>
                            </h3>
                            <div class="space-y-4">
                                <!-- Any / Mes (puntuals o creació nova) -->
                                <div v-if="!editingFactura || editForm.tipus === 'puntual'" class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Any</label>
                                        <input
                                            v-model.number="editForm.any"
                                            type="number"
                                            :disabled="!!editingFactura && editingFactura.tipus !== 'puntual'"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 disabled:opacity-50"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Mes</label>
                                        <select
                                            v-model.number="editForm.mes"
                                            :disabled="!!editingFactura && editingFactura.tipus !== 'puntual'"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 disabled:opacity-50"
                                        >
                                            <option v-for="m in 12" :key="m" :value="m">{{ nomMes(m) }}</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Línies -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Línies</label>
                                        <button type="button" @click="addLinia" class="text-xs text-amber-600 hover:text-amber-800 dark:text-amber-400">+ Afegir línia</button>
                                    </div>
                                    <!-- Capçaleres: sense elles, l'import acaba fàcilment a la descripció -->
                                    <div v-if="editForm.linies.length > 0" class="mb-1 flex items-center gap-2 text-[11px] uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                        <span class="w-36">Concepte</span>
                                        <span class="flex-1">Descripció</span>
                                        <span class="w-24 text-right">Base €</span>
                                        <span v-if="editForm.linies.length > 1" class="w-3"></span>
                                    </div>
                                    <div v-for="(linia, idx) in editForm.linies" :key="idx" class="flex items-center gap-2 mb-2">
                                        <select v-model="linia.concepte" class="w-36 rounded-md border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            <option value="lloguer_base">Lloguer base</option>
                                            <option value="escombraries">Escombraries</option>
                                            <option value="regularitzacio_ipc">Regularització IPC</option>
                                            <option value="altres">Altres</option>
                                        </select>
                                        <input v-model="linia.descripcio" type="text" placeholder="Text lliure, sense import" class="flex-1 rounded-md border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                        <input
                                            v-model.number="linia.base"
                                            @input="recalculateEdit"
                                            type="number"
                                            step="0.01"
                                            placeholder="0,00"
                                            class="w-24 rounded-md text-xs shadow-sm text-right dark:bg-gray-700 dark:text-gray-100"
                                            :class="linia.base ? 'border-gray-300 dark:border-gray-600' : 'border-amber-400 dark:border-amber-500'"
                                        />
                                        <button v-if="editForm.linies.length > 1" type="button" @click="removeLinia(idx)" class="text-red-500 hover:text-red-700 text-xs">✕</button>
                                    </div>
                                </div>
                                <!-- Avisos: la factura es pot desar igualment, però no en silenci -->
                                <div v-if="avisosFactura.length > 0" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700 dark:bg-amber-900/30">
                                    <p v-for="avis in avisosFactura" :key="avis" class="text-xs text-amber-800 dark:text-amber-200">{{ avis }}</p>
                                </div>

                                <!-- Totals (tots calculats a partir de les línies i els percentatges) -->
                                <div class="grid grid-cols-2 gap-4 border-t border-gray-200 dark:border-gray-600 pt-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Base total
                                            <span class="font-normal text-xs text-gray-400 dark:text-gray-500">— suma de les línies</span>
                                        </label>
                                        <input :value="editForm.base" type="number" step="0.01" readonly tabindex="-1" class="mt-1 block w-full cursor-not-allowed rounded-md border-gray-300 text-sm shadow-sm bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IVA %</label>
                                        <input v-model.number="editForm.iva_percentatge" @input="recalculateEdit" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IVA import</label>
                                        <input :value="editForm.iva_import" type="number" step="0.01" readonly tabindex="-1" class="mt-1 block w-full cursor-not-allowed rounded-md border-gray-300 text-sm shadow-sm bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IRPF %</label>
                                        <input v-model.number="editForm.irpf_percentatge" @input="recalculateEdit" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IRPF import</label>
                                        <input :value="editForm.irpf_import" type="number" step="0.01" readonly tabindex="-1" class="mt-1 block w-full cursor-not-allowed rounded-md border-gray-300 text-sm shadow-sm bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 font-semibold">Total</label>
                                        <input :value="editForm.total" type="number" step="0.01" readonly tabindex="-1" class="mt-1 block w-full cursor-not-allowed rounded-md border-gray-300 text-sm shadow-sm bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 font-semibold" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Estat</label>
                                        <select v-model="editForm.estat" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            <option value="esborrany">Esborrany</option>
                                            <option value="emesa">Emesa</option>
                                            <option value="cobrada">Cobrada</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Num. factura</label>
                                        <input v-model="editForm.numero_factura" type="text" maxlength="50" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data emissio</label>
                                    <input v-model="editForm.data_emissio" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <textarea v-model="editForm.notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                                </div>
                                <p v-if="editErrors.general" class="text-sm text-red-600">{{ editErrors.general }}</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="editSaving" class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">
                                {{ editSaving ? 'Desant...' : 'Desar' }}
                            </button>
                            <button type="button" @click="showEditModal = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-base font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Vincular Moviment Sub-Modal -->
        <div v-if="showVincularModal" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 pt-4 pb-20 text-center sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-50" @click="showVincularModal = false"></div>
                <div class="relative inline-block w-full max-w-md transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left align-bottom shadow-xl sm:my-8 sm:align-middle">
                    <div class="px-4 pt-5 pb-4 sm:p-6">
                        <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">Vincular moviment bancari</h3>
                        <div v-if="movimentsLoading" class="py-4 text-center text-sm text-gray-400">Carregant moviments...</div>
                        <div v-else>
                            <select v-model="selectedMovimentId" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option :value="null">-- Sense vincular --</option>
                                <option v-for="m in movimentsDisponibles" :key="m.id" :value="m.id">
                                    {{ m.data_moviment }} — {{ formatCurrency(m.import) }} — {{ m.concepte.substring(0, 40) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <button @click="submitVincular" class="inline-flex w-full justify-center rounded-md bg-blue-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-blue-700 sm:ml-3 sm:w-auto sm:text-sm">
                            Vincular
                        </button>
                        <button @click="showVincularModal = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-base font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel·lar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
