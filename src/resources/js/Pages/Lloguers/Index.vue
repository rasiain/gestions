<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CategoryTreeSelect from '@/Components/CategoryTreeSelect.vue';
import BulkEditModal from '@/Components/BulkEditModal.vue';
import FacturesModal from '@/Components/FacturesModal.vue';
import RevisioIpcModal from '@/Components/RevisioIpcModal.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, Transition } from 'vue';

interface Immoble {
    id: number;
    adreca: string;
}

interface CompteCorrent {
    id: number;
    nom: string;
}

interface Proveidor {
    id: number;
    nom_rao_social: string;
}

interface PersonaBasic {
    id: number;
    nom: string;
}

interface ComunitatBensBasic {
    id: number;
    nom: string;
}

interface Arrendadorable {
    id: number;
    nom: string;
}

interface Arrendador {
    id: number;
    arrendadorable_type: 'persona' | 'comunitat_bens';
    arrendadorable: Arrendadorable | null;
}

interface LlogaterBasic {
    id: number;
    nom: string;
    cognoms: string;
}

interface ContracteActiu {
    id: number;
    lloguer_id: number;
    data_inici: string;
    data_fi: string | null;
    llogater_ids: number[];
    llogaters: LlogaterBasic[];
    arrendador_id: number | null;
    arrendador: Arrendador | null;
}

interface Lloguer {
    id: number;
    nom: string;
    acronim: string | null;
    immoble_id: number;
    immoble: Immoble | null;
    compte_corrent_id: number;
    compte_corrent: CompteCorrent | null;
    base_euros: string | null;
    proveidor_gestoria_id: number | null;
    gestoria_percentatge: string | null;
    es_habitatge: boolean;
    retencio_irpf: boolean;
    iva_percentatge: string | null;
    irpf_percentatge: string | null;
    ruta_descarrega: string | null;
    ruta_export: string | null;
    gestoria: Proveidor | null;
    propietaris: PersonaBasic[];
    contracte_actiu: ContracteActiu | null;
}

interface Props {
    lloguers: Lloguer[];
    immobles: Immoble[];
    comptesCorrents: CompteCorrent[];
    llogaters: LlogaterBasic[];
    proveidors: Proveidor[];
    arrendadors: Arrendador[];
    persones: PersonaBasic[];
    comunitatsBens: ComunitatBensBasic[];
}

const props = defineProps<Props>();

// ── Lloguer modal ──────────────────────────────────────────────
const showLloguerModal = ref(false);
const isEditingLloguer = ref(false);
const editingLloguer = ref<Lloguer | null>(null);

const lloguerForm = useForm({
    nom: '',
    acronim: '',
    immoble_id: null as number | null,
    compte_corrent_id: null as number | null,
    base_euros: null as number | null,
    proveidor_gestoria_id: null as number | null,
    gestoria_percentatge: null as number | null,
    es_habitatge: false,
    retencio_irpf: false,
    iva_percentatge: 21.00 as number | null,
    irpf_percentatge: 19.00 as number | null,
    ruta_descarrega: '' as string,
    ruta_export: '' as string,
});

const openCreateLloguerModal = () => {
    isEditingLloguer.value = false;
    editingLloguer.value = null;
    lloguerForm.reset();
    showLloguerModal.value = true;
};

const openEditLloguerModal = (lloguer: Lloguer) => {
    isEditingLloguer.value = true;
    editingLloguer.value = lloguer;
    lloguerForm.nom = lloguer.nom;
    lloguerForm.acronim = lloguer.acronim || '';
    lloguerForm.immoble_id = lloguer.immoble_id;
    lloguerForm.compte_corrent_id = lloguer.compte_corrent_id;
    lloguerForm.base_euros = lloguer.base_euros ? parseFloat(lloguer.base_euros) : null;
    lloguerForm.proveidor_gestoria_id = lloguer.proveidor_gestoria_id;
    lloguerForm.gestoria_percentatge = lloguer.gestoria_percentatge ? parseFloat(lloguer.gestoria_percentatge) : null;
    lloguerForm.es_habitatge = lloguer.es_habitatge;
    lloguerForm.retencio_irpf = lloguer.retencio_irpf;
    lloguerForm.iva_percentatge = lloguer.iva_percentatge ? parseFloat(lloguer.iva_percentatge) : 21.00;
    lloguerForm.irpf_percentatge = lloguer.irpf_percentatge ? parseFloat(lloguer.irpf_percentatge) : 19.00;
    lloguerForm.ruta_descarrega = lloguer.ruta_descarrega || '';
    lloguerForm.ruta_export = lloguer.ruta_export || '';
    showLloguerModal.value = true;
};

const closeLloguerModal = () => {
    showLloguerModal.value = false;
    lloguerForm.reset();
    isEditingLloguer.value = false;
    editingLloguer.value = null;
};

const submitLloguer = () => {
    if (isEditingLloguer.value && editingLloguer.value) {
        lloguerForm.put(route('lloguers.update', editingLloguer.value.id), {
            onSuccess: () => closeLloguerModal(),
        });
    } else {
        lloguerForm.post(route('lloguers.store'), {
            onSuccess: () => closeLloguerModal(),
        });
    }
};

const llibreIvaMsg = ref<{ text: string; ok: boolean } | null>(null);

function mostrarLlibreIvaMsg(text: string, ok: boolean) {
    llibreIvaMsg.value = { text, ok };
    setTimeout(() => { llibreIvaMsg.value = null; }, 4000);
}

async function desarFitxer(postUrl: string, force = false): Promise<any> {
    return fetch(force ? postUrl + '&force=1' : postUrl, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfToken() },
    }).then(r => r.json()).catch(() => null);
}

async function exportarLlibreIva(lloguerEl: Lloguer, any: number) {
    const postUrl = `/lloguers/${lloguerEl.id}/desar-llibre-iva?any=${any}`;

    let result = await desarFitxer(postUrl);
    if (!result) { mostrarLlibreIvaMsg('Error de comunicació.', false); return; }

    if (result.exists) {
        if (!confirm(`El fitxer "${result.filename}" ja existeix.\n\nVols sobreescriure'l?`)) return;
        result = await desarFitxer(postUrl, true);
    }

    mostrarLlibreIvaMsg(
        result?.saved ? `Fitxer desat: ${result.filename}` : 'Error en desar el fitxer.',
        result?.saved ?? false
    );
}

async function exportarResum(lloguerEl: Lloguer, any: number | null) {
    const anyParam = any ? `?any=${any}` : '';
    const postUrl = `/lloguers/${lloguerEl.id}/desar-export${anyParam}`;

    let result = await desarFitxer(postUrl);
    if (!result) { mostrarLlibreIvaMsg('Error de comunicació.', false); return; }

    if (result.exists) {
        if (!confirm(`El fitxer "${result.filename}" ja existeix.\n\nVols sobreescriure'l?`)) return;
        result = await desarFitxer(postUrl, true);
    }

    mostrarLlibreIvaMsg(
        result?.saved ? `Fitxer desat: ${result.filename}` : 'Error en desar el fitxer.',
        result?.saved ?? false
    );
}

const deleteLloguer = (lloguer: Lloguer) => {
    if (confirm(`Estàs segur que vols eliminar el lloguer "${lloguer.nom}"?`)) {
        router.delete(route('lloguers.destroy', lloguer.id));
    }
};

// ── Contracte panel ────────────────────────────────────────────
const selectedLloguerId = ref<number | null>(null);

const selectedLloguer = computed(() =>
    props.lloguers.find(l => l.id === selectedLloguerId.value) ?? null
);

const contracteForm = useForm({
    lloguer_id: null as number | null,
    data_inici: '',
    data_fi: '',
    llogater_ids: [] as number[],
    arrendador_id: null as number | null,
    tancar_contracte_anterior_id: null as number | null,
    data_fi_anterior: '',
});

// ── Nou contracte ───────────────────────────────────────────────
const creantNouContracte = ref(false);
const dataFiContracteAntic = ref('');

const iniciarNouContracte = () => {
    const c = selectedLloguer.value?.contracte_actiu;
    dataFiContracteAntic.value = c?.data_fi ?? new Date().toISOString().split('T')[0];
    creantNouContracte.value = true;
    contracteForm.data_inici = '';
    contracteForm.data_fi = '';
    contracteForm.llogater_ids = [];
    contracteForm.arrendador_id = suggerirArrendadorPerDefecte();
    contracteForm.clearErrors();
};

const cancellarNouContracte = () => {
    creantNouContracte.value = false;
    dataFiContracteAntic.value = '';
    const c = selectedLloguer.value?.contracte_actiu;
    contracteForm.data_inici = c?.data_inici ?? '';
    contracteForm.data_fi = c?.data_fi ?? '';
    contracteForm.llogater_ids = c?.llogater_ids ? [...c.llogater_ids] : [];
    contracteForm.arrendador_id = c?.arrendador_id ?? null;
    contracteForm.clearErrors();
};

const selectLloguer = (lloguer: Lloguer) => {
    if (selectedLloguerId.value === lloguer.id) {
        selectedLloguerId.value = null;
        return;
    }
    selectedLloguerId.value = lloguer.id;
    creantNouContracte.value = false;
    dataFiContracteAntic.value = '';
    const c = lloguer.contracte_actiu;
    contracteForm.lloguer_id = lloguer.id;
    contracteForm.data_inici = c?.data_inici ?? '';
    contracteForm.data_fi = c?.data_fi ?? '';
    contracteForm.llogater_ids = c?.llogater_ids ? [...c.llogater_ids] : [];
    contracteForm.arrendador_id = c?.arrendador_id ?? suggerirArrendadorPerDefecte();
    contracteForm.clearErrors();
};

const addLlogater = (event: Event) => {
    const id = parseInt((event.target as HTMLSelectElement).value);
    if (id && !contracteForm.llogater_ids.includes(id)) {
        contracteForm.llogater_ids.push(id);
    }
    (event.target as HTMLSelectElement).value = '';
};

const removeLlogater = (id: number) => {
    const idx = contracteForm.llogater_ids.indexOf(id);
    if (idx !== -1) contracteForm.llogater_ids.splice(idx, 1);
};

const selectedLlogaters = computed(() =>
    props.llogaters.filter(l => contracteForm.llogater_ids.includes(l.id))
);

const availableLlogaters = computed(() =>
    props.llogaters.filter(l => !contracteForm.llogater_ids.includes(l.id))
);

const submitContracte = () => {
    const contracte = selectedLloguer.value?.contracte_actiu;
    if (creantNouContracte.value) {
        contracteForm.tancar_contracte_anterior_id = contracte?.id ?? null;
        contracteForm.data_fi_anterior = dataFiContracteAntic.value;
        contracteForm.post(route('contractes.store'), {
            preserveScroll: true,
            onSuccess: () => { creantNouContracte.value = false; dataFiContracteAntic.value = ''; },
        });
    } else if (contracte) {
        contracteForm.put(route('contractes.update', contracte.id), { preserveScroll: true });
    } else {
        contracteForm.post(route('contractes.store'), { preserveScroll: true });
    }
};

const deleteContracte = () => {
    const contracte = selectedLloguer.value?.contracte_actiu;
    if (!contracte) return;
    if (confirm('Estàs segur que vols eliminar aquest contracte?')) {
        router.delete(route('contractes.destroy', contracte.id), { preserveScroll: true });
    }
};

const deleteArrendador = () => {
    if (!contracteForm.arrendador_id) return;
    const arrendador = props.arrendadors.find((a: Arrendador) => a.id === contracteForm.arrendador_id);
    const nom = arrendador?.arrendadorable?.nom ?? 'aquest arrendador';
    if (confirm(`Estàs segur que vols eliminar "${nom}"?`)) {
        const id = contracteForm.arrendador_id;
        contracteForm.arrendador_id = null;
        router.delete(route('arrendadors.destroy', id), { preserveScroll: true });
    }
};

// ── Moviments ──────────────────────────────────────────────────
interface Categoria {
    id: number;
    nom: string;
    compte_corrent_id: number;
    categoria_pare_id: number | null;
    ordre: number;
}

interface MovimentDespesa {
    id: number;
    lloguer_id: number;
    numero_factura: string | null;
    concepte: string | null;
    categoria: string;
    proveidor_id: number | null;
    tipus_despesa_fiscal_id: number | null;
    notes: string | null;
    base_imposable: number | null;
    iva_percentatge: number | null;
    iva_import: number | null;
}

interface MovimentIngresLinia {
    id: number;
    tipus: string;
    descripcio: string;
    import: string;
    proveidor_id: number | null;
}

interface MovimentIngres {
    id: number;
    lloguer_id: number;
    base_lloguer: string;
    notes: string | null;
    linies: MovimentIngresLinia[];
}

interface MovimentFactura {
    id: number;
    numero_factura: string | null;
    total: string;
}

interface Moviment {
    id: number;
    compte_corrent_id: number;
    data_moviment: string;
    concepte: string;
    notes: string | null;
    import: string;
    saldo_posterior: string | null;
    exclou_lloguer: boolean;
    categoria_id: number | null;
    categoria_nom: string | null;
    despesa: MovimentDespesa | null;
    ingres: MovimentIngres | null;
    factura: MovimentFactura | null;
}

const moviments = ref<Moviment[]>([]);
const movimentsPage = ref(1);
const movimentsTotal = ref(0);
const movimentsHasMore = ref(false);
const movimentsLoading = ref(false);
const movimentsFilterAny = ref<number | null>(new Date().getFullYear());
const movimentsFilterClassificats = ref(false);
const movimentsFilterPendents = ref(false);
const movimentsFilterCerca = ref('');
const movimentsAnys = ref<number[]>([]);
const movimentCategories = ref<Categoria[]>([]);

interface TipusDespesaFiscal {
    id: number;
    codi: string;
    descripcio: string;
}
const tipusDespesaFiscalOpcions = ref<TipusDespesaFiscal[]>([]);
const categoriaMapping = ref<Record<string, number>>({});

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

const fetchMoviments = async (lloguer: Lloguer, page: number, append = false) => {
    movimentsLoading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page) });
        if (movimentsFilterAny.value) params.set('any', String(movimentsFilterAny.value));
        if (movimentsFilterClassificats.value) params.set('classificats', '1');
        if (movimentsFilterPendents.value) params.set('pendents', '1');
        if (movimentsFilterCerca.value.trim()) params.set('cerca', movimentsFilterCerca.value.trim());

        const res = await fetch(`/lloguers/${lloguer.id}/moviments?${params}`, {
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        const json = await res.json();
        moviments.value = append ? [...moviments.value, ...json.data] : json.data;
        movimentsTotal.value = json.total;
        movimentsHasMore.value = json.has_more;
        movimentsPage.value = page;
        if (json.categories) movimentCategories.value = json.categories;
        if (json.anys) movimentsAnys.value = json.anys;
        if (json.tipusDespesaFiscal) tipusDespesaFiscalOpcions.value = json.tipusDespesaFiscal;
        if (json.categoriaMapping) categoriaMapping.value = json.categoriaMapping;
    } finally {
        movimentsLoading.value = false;
    }
};

const loadMore = () => {
    if (selectedLloguer.value && movimentsHasMore.value) {
        fetchMoviments(selectedLloguer.value, movimentsPage.value + 1, true);
    }
};

const toggleExclou = async (moviment: Moviment) => {
    const index = moviments.value.findIndex(m => m.id === moviment.id);
    if (index === -1) return;

    const newValue = !moviments.value[index].exclou_lloguer;
    // Reemplaça l'element a l'array (garanteix detecció de canvi per Vue)
    moviments.value[index] = { ...moviments.value[index], exclou_lloguer: newValue };

    try {
        const res = await fetch(`/moviments/${moviment.id}/exclou-lloguer`, {
            method: 'PATCH',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
        const json = await res.json();
        moviments.value[index] = { ...moviments.value[index], exclou_lloguer: !!json.exclou_lloguer };
    } catch {
        // Si falla, reverteix
        moviments.value[index] = { ...moviments.value[index], exclou_lloguer: !newValue };
    }
};

// ── Edició de moviments ────────────────────────────────────────
const showMovimentEditModal = ref(false);
const movimentEditSaving = ref(false);
const movimentEditErrors = ref<Record<string, string>>({});
const editingMovimentForEdit = ref<Moviment | null>(null);
const movimentEditForm = ref({
    compte_corrent_id: 0,
    data_moviment: '',
    concepte: '',
    notes: null as string | null,
    import: 0 as number,
    saldo_posterior: null as number | null,
    categoria_id: null as number | null,
});

const openMovimentEditModal = (moviment: Moviment) => {
    editingMovimentForEdit.value = moviment;
    movimentEditForm.value = {
        compte_corrent_id: moviment.compte_corrent_id,
        data_moviment: moviment.data_moviment,
        concepte: moviment.concepte,
        notes: moviment.notes,
        import: parseFloat(moviment.import),
        saldo_posterior: moviment.saldo_posterior !== null ? parseFloat(moviment.saldo_posterior) : null,
        categoria_id: moviment.categoria_id,
    };
    movimentEditErrors.value = {};
    showMovimentEditModal.value = true;
};

const closeMovimentEditModal = () => {
    showMovimentEditModal.value = false;
    editingMovimentForEdit.value = null;
};

const submitMovimentEdit = async () => {
    const moviment = editingMovimentForEdit.value;
    if (!moviment) return;
    movimentEditSaving.value = true;
    movimentEditErrors.value = {};
    try {
        const res = await fetch(`/moviments/${moviment.id}`, {
            method: 'PUT',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(movimentEditForm.value),
        });
        const json = await res.json();
        if (!res.ok) {
            movimentEditErrors.value = json.errors ?? {};
            return;
        }
        // Update the moviment in the local array
        const index = moviments.value.findIndex(m => m.id === moviment.id);
        if (index !== -1) {
            moviments.value[index] = {
                ...moviments.value[index],
                data_moviment: json.data_moviment,
                concepte: json.concepte,
                notes: json.notes,
                import: json.import,
                saldo_posterior: json.saldo_posterior,
                categoria_id: json.categoria_id,
                categoria_nom: json.categoria_nom,
            };
        }
        closeMovimentEditModal();
    } finally {
        movimentEditSaving.value = false;
    }
};

// ── Classificació de moviments ─────────────────────────────────
const showClassificacioModal = ref(false);
const classificacioMoviment = ref<Moviment | null>(null);
const classificacioTipus = ref<'despesa' | 'ingres'>('despesa');
const classificacioSaving = ref(false);
const classificacioErrors = ref<Record<string, string>>({});

// ── Classificació múltiple ─────────────────────────────────────
const selectedMovimentIds = ref<Set<number>>(new Set());
const showBulkModal = ref(false);
const bulkSaving = ref(false);
const bulkErrors = ref<Record<string, string>>({});
const bulkDespesa = ref({
    categoria:               '' as string,
    proveidor_id:            null as number | null,
    tipus_despesa_fiscal_id: null as number | null,
    numero_factura:          '' as string,
    concepte:                '' as string,
    notes:                   '' as string,
    base_imposable:          null as number | null,
    iva_percentatge:         null as number | null,
    iva_import:              null as number | null,
});

const toggleSeleccio = (moviment: { id: number }) => {
    if (selectedMovimentIds.value.has(moviment.id)) {
        selectedMovimentIds.value.delete(moviment.id);
    } else {
        selectedMovimentIds.value.add(moviment.id);
    }
    // Força reactivitat
    selectedMovimentIds.value = new Set(selectedMovimentIds.value);
};

const openBulkModal = () => {
    bulkDespesa.value = { categoria: '', proveidor_id: null, tipus_despesa_fiscal_id: null, numero_factura: '', concepte: '', notes: '', base_imposable: null, iva_percentatge: null, iva_import: null };
    bulkErrors.value = {};
    showBulkModal.value = true;
};

const closeBulkModal = () => { showBulkModal.value = false; };

// ── Edició múltiple ────────────────────────────────────────────
const showBulkEditModal = ref(false);
const bulkEditSaving = ref(false);
const bulkEditError = ref('');

const openBulkEditModal = () => {
    bulkEditError.value = '';
    showBulkEditModal.value = true;
};

const handleBulkEdit = async (payload: { concepte: string; notes: string; categoria_id: number | null }) => {
    bulkEditSaving.value = true;
    bulkEditError.value = '';
    try {
        const body: Record<string, unknown> = {
            moviment_ids: Array.from(selectedMovimentIds.value),
        };
        if (payload.concepte)              body.concepte     = payload.concepte;
        if (payload.notes !== '')          body.notes        = payload.notes;
        if (payload.categoria_id !== null) body.categoria_id = payload.categoria_id;

        const res = await fetch('/moviments/edicio-multiple', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body:    JSON.stringify(body),
        });
        const json = await res.json();
        if (!res.ok) {
            bulkEditError.value = json.errors?.general ?? json.error ?? json.message ?? 'Error desconegut';
            return;
        }
        showBulkEditModal.value = false;
        selectedMovimentIds.value = new Set();
        if (selectedLloguer.value) await fetchMoviments(selectedLloguer.value, 1);
    } finally {
        bulkEditSaving.value = false;
    }
};

const submitBulk = async () => {
    if (!selectedLloguer.value) return;
    bulkSaving.value = true;
    bulkErrors.value = {};
    try {
        const body: Record<string, unknown> = {
            lloguer_id:   selectedLloguer.value.id,
            moviment_ids: Array.from(selectedMovimentIds.value),
        };
        // Només enviem els camps que l'usuari ha tocat
        if (bulkDespesa.value.categoria)               body.categoria               = bulkDespesa.value.categoria;
        if (bulkDespesa.value.proveidor_id !== null)    body.proveidor_id            = bulkDespesa.value.proveidor_id;
        if (bulkDespesa.value.tipus_despesa_fiscal_id !== null) body.tipus_despesa_fiscal_id = bulkDespesa.value.tipus_despesa_fiscal_id;
        if (bulkDespesa.value.numero_factura)           body.numero_factura          = bulkDespesa.value.numero_factura;
        if (bulkDespesa.value.concepte)                 body.concepte               = bulkDespesa.value.concepte;
        if (bulkDespesa.value.notes)                    body.notes                  = bulkDespesa.value.notes;
        if (bulkDespesa.value.base_imposable !== null)  body.base_imposable         = bulkDespesa.value.base_imposable;
        if (bulkDespesa.value.iva_percentatge !== null) body.iva_percentatge        = bulkDespesa.value.iva_percentatge;
        if (bulkDespesa.value.iva_import !== null)      body.iva_import             = bulkDespesa.value.iva_import;

        const res = await fetch('/moviments/classificacio-multiple', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body:    JSON.stringify(body),
        });
        const json = await res.json();
        if (!res.ok) {
            bulkErrors.value = json.errors ?? { general: json.error ?? json.message ?? 'Error desconegut' };
            return;
        }
        closeBulkModal();
        selectedMovimentIds.value = new Set();
        // Recarrega els moviments per reflectir els canvis
        if (selectedLloguer.value) await fetchMoviments(selectedLloguer.value, 1);
        if (json.skipped_ingressos > 0) {
            alert(`${json.updated + json.created} moviment(s) actualitzat(s). ${json.skipped_ingressos} moviment(s) classificat(s) com a ingressos no s'han modificat.`);
        }
    } finally {
        bulkSaving.value = false;
    }
};

const classificacioDespesa = ref({
    numero_factura: '' as string,
    concepte: '' as string,
    categoria: '',
    proveidor_id: null as number | null,
    tipus_despesa_fiscal_id: null as number | null,
    notes: '',
    base_imposable: null as number | null,
    iva_percentatge: null as number | null,
    iva_import: null as number | null,
});

const classificacioIngres = ref({
    base_lloguer: null as number | null,
    notes: '',
    linies: [] as { tipus: string; descripcio: string; import: number | null; proveidor_id: number | null }[],
});


const categoriesDespesa = [
    { value: 'comunitat',   label: 'Comunitat' },
    { value: 'taxes',       label: 'Taxes' },
    { value: 'assegurança', label: 'Assegurança' },
    { value: 'compres',     label: 'Compres' },
    { value: 'reparacions', label: 'Reparacions' },
    { value: 'comissions',  label: 'Comissions bancàries' },
    { value: 'altres',      label: 'Altres' },
];

// Auto-suggereix el tipus de despesa fiscal quan canvia la categoria
const onCategoriaChange = () => {
    const nova = classificacioDespesa.value.categoria;
    if (!nova) return;
    const tipusId = categoriaMapping.value[nova] ?? null;
    if (tipusId !== null && tipusId !== undefined) {
        classificacioDespesa.value.tipus_despesa_fiscal_id = tipusId;
    }
};

const onBulkCategoriaChange = () => {
    const nova = bulkDespesa.value.categoria;
    if (!nova) return;
    const tipusId = categoriaMapping.value[nova] ?? null;
    if (tipusId !== null && tipusId !== undefined) {
        bulkDespesa.value.tipus_despesa_fiscal_id = tipusId;
    }
};

const categoriesIngresLinia = [
    ...categoriesDespesa,
    { value: 'gestoria', label: 'Gestoria' },
    { value: 'comissions', label: 'Comissions bancàries' },
];

const ingresNoQuadra = (moviment: Moviment): boolean => {
    if (!moviment.ingres || moviment.ingres.lloguer_id !== selectedLloguerId.value) return false;
    const base = parseFloat(moviment.ingres.base_lloguer) || 0;
    const linies = moviment.ingres.linies.reduce((s, l) => s + (parseFloat(l.import) || 0), 0);
    const netCalculat = (base - linies).toFixed(2);
    const importBanc = parseFloat(moviment.import).toFixed(2);
    return netCalculat !== importBanc;
};

const classificacioThisLloguer = (moviment: Moviment) => {
    if (moviment.despesa?.lloguer_id === selectedLloguerId.value) return { tipus: 'despesa' as const, data: moviment.despesa };
    if (moviment.ingres?.lloguer_id === selectedLloguerId.value) return { tipus: 'ingres' as const, data: moviment.ingres };
    return null;
};

const classificacioAltresLloguer = (moviment: Moviment): boolean => {
    return (!!moviment.despesa && moviment.despesa.lloguer_id !== selectedLloguerId.value) ||
           (!!moviment.ingres && moviment.ingres.lloguer_id !== selectedLloguerId.value);
};

const classificacioLabel = (moviment: Moviment): string => {
    if (moviment.despesa?.lloguer_id === selectedLloguerId.value) {
        return categoriesDespesa.find(c => c.value === moviment.despesa!.categoria)?.label ?? moviment.despesa.categoria ?? 'Despesa';
    }
    if (moviment.ingres?.lloguer_id === selectedLloguerId.value) return 'Ingrés';
    return '';
};

const IVA_RATE = 0.21;

// Reconciliació: base − línies = net calculat vs. import al banc
const ingresNetCalculat = computed(() => {
    const base = classificacioIngres.value.base_lloguer ?? 0;
    const linies = classificacioIngres.value.linies.reduce((s, l) => s + (l.import ?? 0), 0);
    return parseFloat((base - linies).toFixed(2));
});

const ingresDiferencia = computed(() => {
    const importBanc = classificacioMoviment.value ? parseFloat(classificacioMoviment.value.import) : 0;
    return parseFloat((ingresNetCalculat.value - importBanc).toFixed(2));
});

const computedGestoriaImport = computed(() => {
    const lloguer = selectedLloguer.value;
    if (!lloguer?.gestoria_percentatge || !lloguer?.base_euros) return null;
    // Calcula el total amb IVA directament
    const net = parseFloat(lloguer.base_euros) * parseFloat(lloguer.gestoria_percentatge) / 100;
    return parseFloat((net * (1 + IVA_RATE)).toFixed(2));
});

const openClassificacioModal = (moviment: Moviment) => {
    classificacioMoviment.value = moviment;
    classificacioErrors.value = {};

    const cls = classificacioThisLloguer(moviment);
    if (cls?.tipus === 'despesa') {
        classificacioTipus.value = 'despesa';
        classificacioDespesa.value = {
            numero_factura: cls.data.numero_factura ?? '',
            concepte: cls.data.concepte ?? '',
            categoria: cls.data.categoria,
            proveidor_id: cls.data.proveidor_id,
            tipus_despesa_fiscal_id: cls.data.tipus_despesa_fiscal_id ?? null,
            notes: cls.data.notes ?? '',
            base_imposable: cls.data.base_imposable ?? null,
            iva_percentatge: cls.data.iva_percentatge ?? null,
            iva_import: cls.data.iva_import ?? null,
        };
    } else if (cls?.tipus === 'ingres') {
        classificacioTipus.value = 'ingres';
        classificacioIngres.value = {
            base_lloguer: parseFloat(cls.data.base_lloguer),
            notes: cls.data.notes ?? '',
            linies: cls.data.linies.map(l => ({
                tipus: l.tipus,
                descripcio: l.descripcio,
                import: parseFloat(l.import),
                proveidor_id: l.proveidor_id,
            })),
        };
    } else {
        classificacioTipus.value = parseFloat(moviment.import) >= 0 ? 'ingres' : 'despesa';
        classificacioDespesa.value = { numero_factura: '', concepte: '', categoria: '', proveidor_id: null, tipus_despesa_fiscal_id: null, notes: '', base_imposable: null, iva_percentatge: null, iva_import: null };
        const liniesInicials: { tipus: string; descripcio: string; import: number | null; proveidor_id: number | null }[] = [];
        if (computedGestoriaImport.value) {
            liniesInicials.push({
                tipus: 'gestoria',
                descripcio: 'Comissió gestoria',
                import: computedGestoriaImport.value,
                proveidor_id: selectedLloguer.value?.proveidor_gestoria_id ?? null,
            });
        }
        classificacioIngres.value = {
            base_lloguer: selectedLloguer.value?.base_euros ? parseFloat(selectedLloguer.value.base_euros) : null,
            notes: '',
            linies: liniesInicials,
        };
    }

    showClassificacioModal.value = true;
};

const closeClassificacioModal = () => {
    showClassificacioModal.value = false;
    classificacioMoviment.value = null;
};

const submitClassificacio = async () => {
    const moviment = classificacioMoviment.value;
    if (!moviment || !selectedLloguerId.value) return;

    classificacioErrors.value = {};
    classificacioSaving.value = true;

    const isEditing = classificacioThisLloguer(moviment) !== null;

    const body: Record<string, unknown> = {
        tipus: classificacioTipus.value,
        lloguer_id: selectedLloguerId.value,
    };

    if (classificacioTipus.value === 'despesa') {
        body.numero_factura = classificacioDespesa.value.numero_factura || null;
        body.concepte = classificacioDespesa.value.concepte || null;
        body.categoria = classificacioDespesa.value.categoria;
        body.proveidor_id = classificacioDespesa.value.proveidor_id || null;
        body.tipus_despesa_fiscal_id = classificacioDespesa.value.tipus_despesa_fiscal_id || null;
        body.notes = classificacioDespesa.value.notes || null;
        body.base_imposable = classificacioDespesa.value.base_imposable ?? null;
        body.iva_percentatge = classificacioDespesa.value.iva_percentatge ?? null;
        body.iva_import = classificacioDespesa.value.iva_import ?? null;
    } else {
        body.base_lloguer = classificacioIngres.value.base_lloguer;
        body.notes = classificacioIngres.value.notes || null;
        body.linies = classificacioIngres.value.linies;
    }

    try {
        const res = await fetch(`/moviments/${moviment.id}/classificacio`, {
            method: isEditing ? 'PUT' : 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });

        const json = await res.json();

        if (!res.ok) {
            if (json.errors) classificacioErrors.value = json.errors;
            else classificacioErrors.value = { general: json.error ?? json.message ?? 'Error desconegut' };
            return;
        }

        const index = moviments.value.findIndex(m => m.id === moviment.id);
        if (index !== -1) {
            moviments.value[index] = { ...moviments.value[index], despesa: json.despesa, ingres: json.ingres };
        }
        closeClassificacioModal();
    } finally {
        classificacioSaving.value = false;
    }
};

const deleteClassificacio = async (moviment: Moviment) => {
    if (!confirm('Estàs segur que vols eliminar la classificació?')) return;

    try {
        const res = await fetch(`/moviments/${moviment.id}/classificacio`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        const json = await res.json();
        if (res.ok) {
            const index = moviments.value.findIndex(m => m.id === moviment.id);
            if (index !== -1) {
                moviments.value[index] = { ...moviments.value[index], despesa: json.despesa, ingres: json.ingres };
            }
        }
    } catch { /* ignore */ }
};

const addLinia = () => {
    classificacioIngres.value.linies.push({ tipus: '', descripcio: '', import: null, proveidor_id: null });
};

const removeLinia = (index: number) => {
    classificacioIngres.value.linies.splice(index, 1);
};

watch(movimentsFilterClassificats, (val) => {
    if (val) movimentsFilterPendents.value = false;
});
watch(movimentsFilterPendents, (val) => {
    if (val) movimentsFilterClassificats.value = false;
});
watch([movimentsFilterAny, movimentsFilterClassificats, movimentsFilterPendents, movimentsFilterCerca], () => {
    if (selectedLloguerId.value) {
        const lloguer = props.lloguers.find(l => l.id === selectedLloguerId.value);
        if (lloguer) fetchMoviments(lloguer, 1);
    }
});

watch(selectedLloguerId, (newId) => {
    moviments.value = [];
    movimentsTotal.value = 0;
    movimentsHasMore.value = false;
    movimentsPage.value = 1;
    movimentsFilterAny.value = new Date().getFullYear();
    movimentsFilterClassificats.value = false;
    movimentsFilterPendents.value = false;
    movimentsFilterCerca.value = '';
    selectedMovimentIds.value = new Set();
    if (newId) {
        const lloguer = props.lloguers.find(l => l.id === newId);
        if (lloguer) fetchMoviments(lloguer, 1);
    }
});

// ── Resum modal ─────────────────────────────────────────────────
interface ResumIngres {
    data: string;
    concepte: string;
    base: number;
    despeses: number | null;
    net_calculat: number;
    import_banc: number;
    diferencia: number;
    notes: string;
}

interface ResumDespesa {
    data: string;
    categoria: string;
    concepte: string;
    proveidor: string;
    nif: string;
    import: number;
    notes: string;
}

interface ResumData {
    ingressos: ResumIngres[];
    despeses: ResumDespesa[];
    total_base: number;
    total_despeses: number;
    resultat_net: number;
    lloguer_nom: string;
    immoble_adreca: string;
    any: number | null;
}

const showResumModal = ref(false);
const resumData = ref<ResumData | null>(null);
const resumLoading = ref(false);
const resumTab = ref<'resum' | 'ingressos' | 'despeses'>('resum');

const openResum = async () => {
    if (!selectedLloguer.value) return;
    resumLoading.value = true;
    showResumModal.value = true;
    resumTab.value = 'resum';
    try {
        const params = new URLSearchParams();
        if (movimentsFilterAny.value) params.set('any', String(movimentsFilterAny.value));
        const res = await fetch(`/lloguers/${selectedLloguer.value.id}/resum?${params}`, {
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        });
        resumData.value = await res.json();
    } catch {
        resumData.value = null;
    } finally {
        resumLoading.value = false;
    }
};

const closeResumModal = () => {
    showResumModal.value = false;
    resumData.value = null;
};

// ── Factures i Revisions IPC ─────────────────────────────────
const showFacturesModal = ref(false);
const showRevisioIpcModal = ref(false);

// ── Arrendador: suggerir propietaris de l'immoble ───────────────
const suggerirArrendadorPerDefecte = (): number | null => {
    const lloguer = selectedLloguer.value;
    if (!lloguer) return null;
    // Buscar si algun arrendador existent correspon a un propietari de l'immoble
    const propietariIds = lloguer.propietaris.map(p => p.id);
    const arrendadorPropietari = props.arrendadors.find(a =>
        a.arrendadorable_type === 'persona' && a.arrendadorable && propietariIds.includes(a.arrendadorable.id)
    );
    return arrendadorPropietari?.id ?? null;
};

// ── Nou arrendador (mini-formulari inline) ─────────────────────
const showNouArrendadorForm = ref(false);
const nouArrendadorSaving = ref(false);
const nouArrendadorErrors = ref<Record<string, string>>({});
const nouArrendadorForm = ref({
    arrendadorable_type: 'persona' as 'persona' | 'comunitat_bens',
    arrendadorable_id: null as number | null,
});

const openNouArrendadorForm = () => {
    nouArrendadorForm.value = { arrendadorable_type: 'persona', arrendadorable_id: null };
    nouArrendadorErrors.value = {};
    showNouArrendadorForm.value = true;
};

const cancelNouArrendador = () => {
    showNouArrendadorForm.value = false;
    nouArrendadorErrors.value = {};
};

const submitNouArrendador = async () => {
    nouArrendadorSaving.value = true;
    nouArrendadorErrors.value = {};
    try {
        const res = await fetch('/arrendadors', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(nouArrendadorForm.value),
        });
        const json = await res.json();
        if (!res.ok) {
            nouArrendadorErrors.value = json.errors ?? { general: json.message ?? 'Error desconegut' };
            return;
        }
        // Refrescar la pàgina per obtenir el nou arrendador
        router.reload({ only: ['arrendadors', 'lloguers'] });
        showNouArrendadorForm.value = false;
    } catch {
        nouArrendadorErrors.value = { general: 'Error de connexió' };
    } finally {
        nouArrendadorSaving.value = false;
    }
};

// ── Nou llogater (mini-formulari inline) ───────────────────────
const showNouLlogaterForm = ref(false);
const nouLlogaterSaving = ref(false);
const nouLlogaterErrors = ref<Record<string, string>>({});
const nouLlogaterForm = ref({
    tipus: 'persona' as 'persona' | 'empresa',
    persona_id: null as number | null,
    nom_rao_social: '',
    nif: '',
});

const openNouLlogaterForm = () => {
    nouLlogaterForm.value = { tipus: 'persona', persona_id: null, nom_rao_social: '', nif: '' };
    nouLlogaterErrors.value = {};
    showNouLlogaterForm.value = true;
};

const cancelNouLlogater = () => {
    showNouLlogaterForm.value = false;
    nouLlogaterErrors.value = {};
};

const submitNouLlogater = async () => {
    nouLlogaterSaving.value = true;
    nouLlogaterErrors.value = {};
    try {
        const body = nouLlogaterForm.value.tipus === 'persona'
            ? { tipus: 'persona', persona_id: nouLlogaterForm.value.persona_id }
            : { tipus: 'empresa', nom_rao_social: nouLlogaterForm.value.nom_rao_social, nif: nouLlogaterForm.value.nif };

        const res = await fetch('/llogaters', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (!res.ok) {
            nouLlogaterErrors.value = json.errors ?? { general: json.message ?? 'Error desconegut' };
            return;
        }
        // Afegir el nou llogater directament al formulari del contracte
        contracteForm.llogater_ids.push(json.id);
        router.reload({ only: ['llogaters'] });
        showNouLlogaterForm.value = false;
    } catch {
        nouLlogaterErrors.value = { general: 'Error de connexió' };
    } finally {
        nouLlogaterSaving.value = false;
    }
};

const deleteLlogaterEntitat = (llogater: LlogaterBasic) => {
    const nom = llogater.cognoms ? `${llogater.cognoms}, ${llogater.nom}` : llogater.nom;
    if (confirm(`Estàs segur que vols eliminar el llogater "${nom}"?`)) {
        removeLlogater(llogater.id);
        router.delete(route('llogaters.destroy', llogater.id), { preserveScroll: true });
    }
};

// ── Helpers ────────────────────────────────────────────────────
const formatCurrency = (value: string | null): string => {
    if (value === null) return '-';
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(parseFloat(value));
};
</script>

<template>
    <Head title="Lloguers" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Lloguers
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="flex gap-6 items-start">

                <!-- Main content -->
                <div class="flex-1 min-w-0 space-y-6">

                <!-- Lloguers table -->
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">Llistat de Lloguers</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Fes clic en una fila per gestionar el seu contracte
                                </p>
                            </div>
                            <button
                                @click="openCreateLloguerModal"
                                class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                            >
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Afegir Lloguer
                            </button>
                        </div>

                        <div v-if="lloguers.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Nom</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Acrònim</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Immoble</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Base (€/mes)</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Arrendador</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Contracte actiu</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="lloguer in lloguers"
                                        :key="lloguer.id"
                                        @click="selectLloguer(lloguer)"
                                        class="cursor-pointer transition-colors"
                                        :class="selectedLloguerId === lloguer.id
                                            ? 'bg-amber-50 dark:bg-amber-900/20'
                                            : 'hover:bg-gray-50 dark:hover:bg-gray-700'"
                                    >
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ lloguer.nom }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ lloguer.acronim || '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ lloguer.immoble?.adreca || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(lloguer.base_euros) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="lloguer.contracte_actiu?.arrendador">
                                                {{ lloguer.contracte_actiu.arrendador.arrendadorable?.nom ?? '—' }}
                                            </span>
                                            <span v-else class="italic text-gray-400 dark:text-gray-500">—</span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span v-if="lloguer.contracte_actiu" class="text-gray-900 dark:text-gray-100">
                                                {{ lloguer.contracte_actiu.data_inici }}
                                                → {{ lloguer.contracte_actiu.data_fi ?? 'indefinit' }}
                                            </span>
                                            <span v-else class="italic text-gray-400 dark:text-gray-500">Sense contracte</span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium" @click.stop>
                                            <button
                                                @click="openEditLloguerModal(lloguer)"
                                                class="mr-3 text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteLloguer(lloguer)"
                                                class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-12 text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No hi ha lloguers</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Comença afegint el primer lloguer.</p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateLloguerModal"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700"
                                >
                                    <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Afegir Lloguer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contracte panel (shown when a lloguer is selected) -->
                <div
                    v-if="selectedLloguer"
                    class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg border-l-4 border-amber-400"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Contracte — {{ selectedLloguer.nom }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ selectedLloguer.contracte_actiu ? 'Contracte actiu' : 'No hi ha contracte actiu' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <button
                                    v-if="selectedLloguer.contracte_actiu && !creantNouContracte"
                                    @click="deleteContracte"
                                    class="text-sm text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    Eliminar contracte
                                </button>
                                <button
                                    v-if="selectedLloguer.contracte_actiu && !creantNouContracte"
                                    @click="iniciarNouContracte"
                                    class="text-sm text-amber-700 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-200 font-medium"
                                >
                                    + Nou contracte
                                </button>
                                <button
                                    @click="selectedLloguerId = null"
                                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                >
                                    ✕ Tancar
                                </button>
                            </div>
                        </div>

                        <!-- Secció de tancament del contracte anterior (mode nou contracte) -->
                        <div
                            v-if="creantNouContracte"
                            class="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-600 dark:bg-amber-900/20"
                        >
                            <p class="mb-3 text-sm font-medium text-amber-800 dark:text-amber-200">
                                Per crear un nou contracte, primer cal tancar l'actual.
                                <span v-if="selectedLloguer.contracte_actiu?.data_fi">
                                    La data de finalització registrada és <strong>{{ selectedLloguer.contracte_actiu.data_fi }}</strong>. Confirma o modifica-la:
                                </span>
                                <span v-else>
                                    Introdueix la data de finalització del contracte actual:
                                </span>
                            </p>
                            <div class="flex items-end gap-4">
                                <div class="flex-1">
                                    <label for="data_fi_anterior" class="block text-sm font-medium text-amber-700 dark:text-amber-300">
                                        Data de finalització del contracte actual *
                                    </label>
                                    <input
                                        id="data_fi_anterior"
                                        v-model="dataFiContracteAntic"
                                        type="date"
                                        required
                                        class="mt-1 block w-full rounded-md border-amber-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-amber-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                                <button
                                    type="button"
                                    @click="cancellarNouContracte"
                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    Cancel·lar
                                </button>
                            </div>
                        </div>

                        <form @submit.prevent="submitContracte">
                            <input type="hidden" v-model="contracteForm.lloguer_id" />

                            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <!-- Data inici -->
                                <div>
                                    <label for="data_inici" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Data d'inici *
                                    </label>
                                    <input
                                        id="data_inici"
                                        v-model="contracteForm.data_inici"
                                        type="date"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="contracteForm.errors.data_inici" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.data_inici }}
                                    </p>
                                </div>

                                <!-- Data fi -->
                                <div>
                                    <label for="data_fi" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Data de finalització
                                    </label>
                                    <input
                                        id="data_fi"
                                        v-model="contracteForm.data_fi"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="contracteForm.errors.data_fi" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.data_fi }}
                                    </p>
                                </div>

                                <!-- Llogaters -->
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Llogaters signants
                                    </label>

                                    <!-- Selected tags -->
                                    <div v-if="selectedLlogaters.length > 0" class="mb-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="llogater in selectedLlogaters"
                                            :key="llogater.id"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                                        >
                                            {{ llogater.cognoms ? llogater.cognoms + ', ' + llogater.nom : llogater.nom }}
                                            <button
                                                type="button"
                                                @click="removeLlogater(llogater.id)"
                                                title="Treure del contracte"
                                                class="ml-1 rounded-full text-amber-600 hover:text-amber-900 dark:text-amber-300 dark:hover:text-amber-100 focus:outline-none"
                                            >✕</button>
                                            <button
                                                type="button"
                                                @click="deleteLlogaterEntitat(llogater)"
                                                title="Eliminar llogater"
                                                class="rounded-full text-red-400 hover:text-red-700 dark:text-red-400 dark:hover:text-red-200 focus:outline-none"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </span>
                                    </div>

                                    <!-- Dropdown to add + botó Nou -->
                                    <div class="flex gap-2">
                                        <select
                                            v-if="availableLlogaters.length > 0"
                                            @change="addLlogater"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        >
                                            <option value="">Afegir llogater…</option>
                                            <option v-for="llogater in availableLlogaters" :key="llogater.id" :value="llogater.id">
                                                {{ llogater.cognoms ? llogater.cognoms + ', ' + llogater.nom : llogater.nom }}
                                            </option>
                                        </select>
                                        <p v-else-if="!showNouLlogaterForm" class="flex-1 text-sm italic text-gray-400 self-center">
                                            {{ llogaters.length === 0 ? 'No hi ha llogaters.' : 'Tots els llogaters ja estan afegits.' }}
                                        </p>
                                        <button
                                            type="button"
                                            @click="openNouLlogaterForm"
                                            class="shrink-0 rounded-md border border-amber-500 px-3 py-1.5 text-sm font-medium text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20"
                                        >+ Nou</button>
                                    </div>

                                    <!-- Mini-formulari nou llogater -->
                                    <div v-if="showNouLlogaterForm" class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-600 dark:bg-amber-900/20">
                                        <h4 class="mb-3 text-sm font-medium text-amber-800 dark:text-amber-200">Nou llogater</h4>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                                                <select v-model="nouLlogaterForm.tipus" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                                    <option value="persona">Persona</option>
                                                    <option value="empresa">Empresa</option>
                                                </select>
                                            </div>
                                            <div v-if="nouLlogaterForm.tipus === 'persona'">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Persona</label>
                                                <select v-model="nouLlogaterForm.persona_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                                    <option :value="null">Selecciona…</option>
                                                    <option v-for="p in persones" :key="p.id" :value="p.id">{{ p.nom }}</option>
                                                </select>
                                                <p v-if="nouLlogaterErrors.persona_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ nouLlogaterErrors.persona_id }}</p>
                                            </div>
                                            <div v-else class="space-y-2">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Raó social</label>
                                                    <input v-model="nouLlogaterForm.nom_rao_social" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                                    <p v-if="nouLlogaterErrors.nom_rao_social" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ nouLlogaterErrors.nom_rao_social }}</p>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">NIF</label>
                                                    <input v-model="nouLlogaterForm.nif" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                                </div>
                                            </div>
                                            <p v-if="nouLlogaterErrors.general" class="text-sm text-red-600 dark:text-red-400">{{ nouLlogaterErrors.general }}</p>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" @click="cancelNouLlogater" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Cancel·lar</button>
                                                <button type="button" @click="submitNouLlogater" :disabled="nouLlogaterSaving" class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                                                    {{ nouLlogaterSaving ? 'Desant…' : 'Crear' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <p v-if="contracteForm.errors.llogater_ids" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ contracteForm.errors.llogater_ids }}
                                    </p>
                                </div>

                                <!-- Arrendador -->
                                <div class="sm:col-span-2">
                                    <label for="contracte_arrendador_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Arrendador (qui rep les rendes)</label>
                                    <div class="mt-1 flex gap-2">
                                        <select
                                            id="contracte_arrendador_id"
                                            v-model="contracteForm.arrendador_id"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        >
                                            <option :value="null">Sense arrendador</option>
                                            <option v-for="a in arrendadors" :key="a.id" :value="a.id">
                                                {{ a.arrendadorable?.nom ?? '—' }}
                                                ({{ a.arrendadorable_type === 'persona' ? 'Persona' : 'Comunitat de Béns' }})
                                            </option>
                                        </select>
                                        <button
                                            type="button"
                                            @click="openNouArrendadorForm"
                                            class="shrink-0 rounded-md border border-amber-500 px-3 py-1.5 text-sm font-medium text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20"
                                        >
                                            + Nou
                                        </button>
                                        <button
                                            v-if="contracteForm.arrendador_id"
                                            type="button"
                                            @click="deleteArrendador"
                                            class="shrink-0 rounded-md border border-red-300 px-2 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                                            title="Eliminar arrendador"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                    <p v-if="selectedLloguer?.propietaris.length" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Propietaris de l'immoble: {{ selectedLloguer.propietaris.map(p => p.nom).join(', ') }}
                                    </p>
                                    <p v-if="contracteForm.errors.arrendador_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ contracteForm.errors.arrendador_id }}</p>
                                </div>

                                <!-- Mini-formulari nou arrendador -->
                                <div v-if="showNouArrendadorForm" class="sm:col-span-2 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-600 dark:bg-amber-900/20">
                                    <h4 class="mb-3 text-sm font-medium text-amber-800 dark:text-amber-200">Nou arrendador</h4>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                                            <select
                                                v-model="nouArrendadorForm.arrendadorable_type"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            >
                                                <option value="persona">Persona</option>
                                                <option value="comunitat_bens">Comunitat de Béns</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ nouArrendadorForm.arrendadorable_type === 'persona' ? 'Persona' : 'Comunitat de Béns' }}
                                            </label>
                                            <select
                                                v-model="nouArrendadorForm.arrendadorable_id"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            >
                                                <option :value="null">Selecciona…</option>
                                                <template v-if="nouArrendadorForm.arrendadorable_type === 'persona'">
                                                    <option v-for="p in persones" :key="p.id" :value="p.id">{{ p.nom }}</option>
                                                </template>
                                                <template v-else>
                                                    <option v-for="c in comunitatsBens" :key="c.id" :value="c.id">{{ c.nom }}</option>
                                                </template>
                                            </select>
                                            <p v-if="nouArrendadorErrors.arrendadorable_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ nouArrendadorErrors.arrendadorable_id }}</p>
                                        </div>
                                        <p v-if="nouArrendadorErrors.general" class="text-sm text-red-600 dark:text-red-400">{{ nouArrendadorErrors.general }}</p>
                                        <div class="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                @click="cancelNouArrendador"
                                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                            >
                                                Cancel·lar
                                            </button>
                                            <button
                                                type="button"
                                                @click="submitNouArrendador"
                                                :disabled="nouArrendadorSaving"
                                                class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                                            >
                                                Crear arrendador
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="contracteForm.processing"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50"
                                >
                                    {{ creantNouContracte ? 'Crear nou contracte' : (selectedLloguer.contracte_actiu ? 'Actualitzar contracte' : 'Crear contracte') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Moviments panel -->
                <div
                    v-if="selectedLloguer"
                    class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Moviments — {{ selectedLloguer.compte_corrent?.nom ?? 'Compte corrent' }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ movimentsTotal }} moviments en total
                                </p>
                            </div>
                        </div>

                        <!-- Filtres -->
                        <div class="mb-4 flex flex-wrap items-center gap-4">
                            <div class="flex items-center gap-2">
                                <label class="text-sm text-gray-600 dark:text-gray-400">Any:</label>
                                <select
                                    v-model="movimentsFilterAny"
                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                >
                                    <option :value="null">Tots</option>
                                    <option v-for="a in movimentsAnys" :key="a" :value="a">{{ a }}</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="movimentsFilterClassificats"
                                    class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
                                />
                                Només classificats
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="movimentsFilterPendents"
                                    class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
                                />
                                Pendents de classificar
                            </label>
                            <div class="relative">
                                <input
                                    v-model="movimentsFilterCerca"
                                    type="search"
                                    placeholder="Cerca concepte, categoria, notes…"
                                    class="rounded-md border-gray-300 pl-8 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 w-60"
                                />
                                <svg class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 105 11a6 6 0 0012 0z"/>
                                </svg>
                            </div>
                            <div v-if="selectedLloguer" class="ml-auto flex items-center gap-2">
                                <button
                                    v-if="!selectedLloguer.es_habitatge"
                                    @click="showFacturesModal = true"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-blue-500 px-3 py-1.5 text-sm font-medium text-blue-600 shadow-sm hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 transition-colors"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Factures
                                </button>
                                <button
                                    v-if="!selectedLloguer.es_habitatge"
                                    @click="showRevisioIpcModal = true"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-purple-500 px-3 py-1.5 text-sm font-medium text-purple-600 shadow-sm hover:bg-purple-50 dark:text-purple-400 dark:hover:bg-purple-900/20 transition-colors"
                                >
                                    IPC
                                </button>
                                <button
                                    @click="openResum"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-amber-500 px-3 py-1.5 text-sm font-medium text-amber-600 shadow-sm hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    Resum
                                </button>
                                <button
                                    type="button"
                                    @click.stop="exportarResum(selectedLloguer, movimentsFilterAny ?? null)"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-amber-600 transition-colors"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    Exportar
                                </button>
                                <button
                                    v-if="!selectedLloguer.es_habitatge"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-green-700 transition-colors"
                                    @click.stop="exportarLlibreIva(selectedLloguer, movimentsFilterAny ?? new Date().getFullYear())"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Llibre IVA
                                </button>
                            </div>
                        </div>

                        <div v-if="movimentsLoading && moviments.length === 0" class="py-8 text-center text-sm text-gray-400">
                            Carregant…
                        </div>

                        <div v-else-if="moviments.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="w-8 px-3 py-3"></th>
                                        <th class="w-8 px-2 py-3" title="Exclou del lloguer">
                                            <svg class="h-4 w-4 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21" /></svg>
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Data</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Categoria</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Saldo</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Classificació</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="moviment in moviments"
                                        :key="moviment.id"
                                        class="transition-colors"
                                        :class="[
                                            classificacioAltresLloguer(moviment)
                                                ? 'opacity-50 pointer-events-none select-none bg-gray-100 dark:bg-gray-900/60'
                                                : moviment.exclou_lloguer
                                                    ? 'opacity-40'
                                                    : ingresNoQuadra(moviment)
                                                        ? 'bg-red-50 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:ring-red-800'
                                                        : classificacioThisLloguer(moviment)
                                                            ? 'bg-green-50 dark:bg-green-900/20'
                                                            : 'bg-amber-50 dark:bg-amber-900/10',
                                        ]"
                                    >
                                        <!-- Selecció múltiple -->
                                        <td class="px-3 py-3 text-center">
                                            <input
                                                v-if="!moviment.exclou_lloguer && !classificacioAltresLloguer(moviment)"
                                                type="checkbox"
                                                :checked="selectedMovimentIds.has(moviment.id)"
                                                @change="toggleSeleccio(moviment)"
                                                class="rounded border-gray-300 text-amber-500 focus:ring-amber-400 cursor-pointer"
                                            />
                                        </td>
                                        <!-- Exclou del lloguer -->
                                        <td class="px-2 py-3 text-center">
                                            <button
                                                v-if="!classificacioAltresLloguer(moviment)"
                                                type="button"
                                                @click.stop="toggleExclou(moviment)"
                                                :title="moviment.exclou_lloguer ? 'Inclou al lloguer' : 'Exclou del lloguer'"
                                                class="rounded p-0.5 transition-colors"
                                                :class="moviment.exclou_lloguer ? 'text-red-500 hover:text-red-700' : 'text-gray-300 hover:text-red-400'"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21" /></svg>
                                            </button>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {{ moviment.data_moviment }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 max-w-xs truncate">
                                            {{ moviment.concepte }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            {{ moviment.categoria_nom || '-' }}
                                        </td>
                                        <td
                                            class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium"
                                            :class="parseFloat(moviment.import) >= 0
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-red-600 dark:text-red-400'"
                                        >
                                            {{ formatCurrency(moviment.import) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                            {{ moviment.saldo_posterior ? formatCurrency(moviment.saldo_posterior) : '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <template v-if="moviment.factura">
                                                <button
                                                    @click.stop="showFacturesModal = true"
                                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                                    :class="parseFloat(moviment.factura.total) !== parseFloat(moviment.import)
                                                        ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 ring-2 ring-red-400'
                                                        : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'"
                                                >
                                                    {{ moviment.factura.numero_factura || 'Factura vinculada' }}
                                                </button>
                                            </template>
                                            <template v-else-if="classificacioThisLloguer(moviment)">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                                        :class="classificacioThisLloguer(moviment)?.tipus === 'despesa'
                                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                                            : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'"
                                                    >
                                                        {{ classificacioLabel(moviment) }}
                                                    </span>
                                                    <button @click.stop="openClassificacioModal(moviment)" class="text-xs text-amber-600 hover:text-amber-900 dark:text-amber-400">Editar</button>
                                                    <button @click.stop="deleteClassificacio(moviment)" class="text-xs text-red-500 hover:text-red-800 dark:text-red-400">✕</button>
                                                </div>
                                            </template>
                                            <template v-else-if="classificacioAltresLloguer(moviment)">
                                                <span class="inline-flex items-center rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400 italic">
                                                    Altre lloguer
                                                </span>
                                            </template>
                                            <template v-else-if="!moviment.exclou_lloguer">
                                                <button @click.stop="openClassificacioModal(moviment)" class="text-xs text-amber-600 hover:text-amber-900 dark:text-amber-400">
                                                    + Classificar
                                                </button>
                                            </template>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium">
                                            <button
                                                v-if="!moviment.exclou_lloguer"
                                                @click.stop="openMovimentEditModal(moviment)"
                                                class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            >
                                                Editar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-if="movimentsHasMore" class="mt-4 flex justify-center">
                                <button
                                    @click="loadMore"
                                    :disabled="movimentsLoading"
                                    class="rounded-md border border-amber-300 px-4 py-2 text-sm text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-600 dark:text-amber-400 dark:hover:bg-amber-900/20"
                                >
                                    {{ movimentsLoading ? 'Carregant…' : `Mostrar-ne més (${movimentsTotal - moviments.length} restants)` }}
                                </button>
                            </div>
                        </div>

                        <div v-else class="py-8 text-center text-sm italic text-gray-400">
                            No hi ha moviments per a aquest compte corrent.
                        </div>
                    </div>
                </div>

                </div><!-- end main content (flex-1) -->

                <!-- Sidebar: Accions fiscals -->
                <div class="w-52 shrink-0">
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800 sticky top-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900">
                                <svg class="h-4 w-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                                </svg>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Impostos</h3>
                        </div>
                        <div class="space-y-2 border-l-2 border-red-200 dark:border-red-800 pl-3">
                            <Link
                                :href="route('impostos.irpf')"
                                class="block text-sm text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-colors"
                            >
                                → IRPF Lloguers
                            </Link>
                            <Link
                                :href="route('impostos.iva')"
                                class="block text-sm text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-colors"
                            >
                                → IVA Lloguers
                            </Link>
                            <Link
                                :href="route('impostos.tipus-despesa-fiscal')"
                                class="block text-sm text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-colors"
                            >
                                → Configuració fiscal
                            </Link>
                        </div>
                    </div>
                </div><!-- end sidebar -->

                </div><!-- end flex -->
            </div>
        </div>

        <!-- Lloguer Modal -->
        <div
            v-if="showLloguerModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="closeLloguerModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submitLloguer">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3 class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                                {{ isEditingLloguer ? 'Editar Lloguer' : 'Nou Lloguer' }}
                            </h3>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="nom" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom *</label>
                                    <input
                                        id="nom"
                                        v-model="lloguerForm.nom"
                                        type="text"
                                        required
                                        maxlength="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.nom }}</p>
                                </div>

                                <div>
                                    <label for="acronim" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Acrònim</label>
                                    <input
                                        id="acronim"
                                        v-model="lloguerForm.acronim"
                                        type="text"
                                        maxlength="20"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.acronim" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.acronim }}</p>
                                </div>

                                <div>
                                    <label for="base_euros" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Base (€/mes)</label>
                                    <input
                                        id="base_euros"
                                        v-model="lloguerForm.base_euros"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="lloguerForm.errors.base_euros" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.base_euros }}</p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <input
                                        id="es_habitatge"
                                        v-model="lloguerForm.es_habitatge"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-amber-500 focus:ring-amber-400 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <label for="es_habitatge" class="text-sm font-medium text-gray-700 dark:text-gray-300">És habitatge</label>
                                </div>

                                <div v-if="!lloguerForm.es_habitatge" class="flex items-center gap-2">
                                    <input
                                        id="retencio_irpf"
                                        v-model="lloguerForm.retencio_irpf"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-amber-500 focus:ring-amber-400 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <label for="retencio_irpf" class="text-sm font-medium text-gray-700 dark:text-gray-300">Retencio IRPF</label>
                                </div>

                                <div v-if="!lloguerForm.es_habitatge">
                                    <label for="iva_percentatge" class="block text-sm font-medium text-gray-700 dark:text-gray-300">IVA %</label>
                                    <input
                                        id="iva_percentatge"
                                        v-model="lloguerForm.iva_percentatge"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div v-if="!lloguerForm.es_habitatge">
                                    <label for="irpf_percentatge" class="block text-sm font-medium text-gray-700 dark:text-gray-300">IRPF %</label>
                                    <input
                                        id="irpf_percentatge"
                                        v-model="lloguerForm.irpf_percentatge"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="immoble_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Immoble *</label>
                                    <select
                                        id="immoble_id"
                                        v-model="lloguerForm.immoble_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona un immoble</option>
                                        <option v-for="immoble in immobles" :key="immoble.id" :value="immoble.id">{{ immoble.adreca }}</option>
                                    </select>
                                    <p v-if="lloguerForm.errors.immoble_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.immoble_id }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="compte_corrent_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte corrent *</label>
                                    <select
                                        id="compte_corrent_id"
                                        v-model="lloguerForm.compte_corrent_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona un compte corrent</option>
                                        <option v-for="cc in comptesCorrents" :key="cc.id" :value="cc.id">{{ cc.nom }}</option>
                                    </select>
                                    <p v-if="lloguerForm.errors.compte_corrent_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ lloguerForm.errors.compte_corrent_id }}</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="proveidor_gestoria_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gestoria (proveïdor)</label>
                                    <select
                                        id="proveidor_gestoria_id"
                                        v-model="lloguerForm.proveidor_gestoria_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense gestoria</option>
                                        <option v-for="p in proveidors" :key="p.id" :value="p.id">{{ p.nom_rao_social }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="gestoria_percentatge" class="block text-sm font-medium text-gray-700 dark:text-gray-300">% Gestoria</label>
                                    <input
                                        id="gestoria_percentatge"
                                        v-model="lloguerForm.gestoria_percentatge"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="ruta_descarrega" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Ruta del llibre d'IVA <span class="font-normal text-gray-400">(només lloguers no habitatge)</span></label>
                                    <input
                                        id="ruta_descarrega"
                                        v-model="lloguerForm.ruta_descarrega"
                                        type="text"
                                        placeholder="/ruta/al/directori"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Directori on es desa el llibre d'IVA. Per a no habitatge, també s'usa com a destinació de l'exportació si no s'especifica la ruta d'exportació.</p>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="ruta_export" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Ruta d'exportació del resum</label>
                                    <input
                                        id="ruta_export"
                                        v-model="lloguerForm.ruta_export"
                                        type="text"
                                        placeholder="/ruta/al/directori"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Directori on es desa el fitxer XLSX del resum. Si és buit: per a no habitatge usa la ruta de l'IVA; altrament, usa Downloads.</p>
                                </div>

                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="lloguerForm.processing"
                                class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditingLloguer ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeLloguerModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Toast Llibre IVA -->
        <div
            v-if="llibreIvaMsg"
            class="fixed bottom-6 right-6 z-50 flex items-center gap-3 rounded-lg px-4 py-3 shadow-lg text-sm font-medium"
            :class="llibreIvaMsg.ok ? 'bg-green-600 text-white' : 'bg-red-600 text-white'"
        >
            {{ llibreIvaMsg.text }}
        </div>

        <!-- Edició Moviment Modal -->
        <div
            v-if="showMovimentEditModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeMovimentEditModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>
                <div class="inline-block transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submitMovimentEdit">
                        <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <h3 class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                                Editar Moviment
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data</label>
                                    <input
                                        v-model="movimentEditForm.data_moviment"
                                        type="date"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="movimentEditErrors.data_moviment" class="mt-1 text-sm text-red-600">{{ movimentEditErrors.data_moviment }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label>
                                    <input
                                        v-model="movimentEditForm.concepte"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="movimentEditErrors.concepte" class="mt-1 text-sm text-red-600">{{ movimentEditErrors.concepte }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (opcional)</label>
                                    <textarea
                                        v-model="movimentEditForm.notes"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Import (€)</label>
                                    <input
                                        v-model.number="movimentEditForm.import"
                                        type="number"
                                        step="0.01"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="movimentEditErrors.import" class="mt-1 text-sm text-red-600">{{ movimentEditErrors.import }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Saldo posterior (opcional)</label>
                                    <input
                                        v-model.number="movimentEditForm.saldo_posterior"
                                        type="number"
                                        step="0.01"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Categoria (opcional)</label>
                                    <CategoryTreeSelect
                                        :categories="movimentCategories"
                                        v-model="movimentEditForm.categoria_id"
                                        :allow-none="true"
                                        placeholder="Selecciona una categoria..."
                                    />
                                    <p v-if="movimentEditErrors.categoria_id" class="mt-1 text-sm text-red-600">{{ movimentEditErrors.categoria_id }}</p>
                                </div>
                                <p v-if="movimentEditErrors.error" class="text-sm text-red-600">{{ movimentEditErrors.error }}</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="movimentEditSaving"
                                class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ movimentEditSaving ? 'Desant…' : 'Actualitzar' }}
                            </button>
                            <button
                                type="button"
                                @click="closeMovimentEditModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-base font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Classificació Modal -->
        <div
            v-if="showClassificacioModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeClassificacioModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle">
                    <form @submit.prevent="submitClassificacio">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3 class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                                Classificar moviment
                            </h3>
                            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400 truncate">
                                {{ classificacioMoviment?.data_moviment }} — {{ classificacioMoviment?.concepte }}
                                <span class="font-medium" :class="parseFloat(classificacioMoviment?.import ?? '0') >= 0 ? 'text-green-600' : 'text-red-600'">
                                    {{ formatCurrency(classificacioMoviment?.import ?? null) }}
                                </span>
                            </p>

                            <p v-if="classificacioErrors.general" class="mb-4 text-sm text-red-600 dark:text-red-400">{{ classificacioErrors.general }}</p>

                            <!-- Tipus toggle -->
                            <div class="mb-6 flex rounded-md shadow-sm" role="group">
                                <button
                                    type="button"
                                    @click="classificacioTipus = 'despesa'"
                                    class="flex-1 rounded-l-md border px-4 py-2 text-sm font-medium transition-colors"
                                    :class="classificacioTipus === 'despesa'
                                        ? 'border-red-500 bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                >
                                    Despesa
                                </button>
                                <button
                                    type="button"
                                    @click="classificacioTipus = 'ingres'"
                                    class="flex-1 rounded-r-md border-y border-r px-4 py-2 text-sm font-medium transition-colors"
                                    :class="classificacioTipus === 'ingres'
                                        ? 'border-green-500 bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                >
                                    Ingrés
                                </button>
                            </div>

                            <!-- Despesa fields -->
                            <div v-if="classificacioTipus === 'despesa'" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Categoria *</label>
                                    <select
                                        v-model="classificacioDespesa.categoria"
                                        required
                                        @change="onCategoriaChange"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option value="">Selecciona una categoria</option>
                                        <option v-for="cat in categoriesDespesa" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                    </select>
                                    <p v-if="classificacioErrors['categoria']" class="mt-1 text-sm text-red-600">{{ classificacioErrors['categoria'] }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Proveïdor</label>
                                    <select
                                        v-model="classificacioDespesa.proveidor_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense proveïdor</option>
                                        <option v-for="p in proveidors" :key="p.id" :value="p.id">{{ p.nom_rao_social }}</option>
                                    </select>
                                </div>

                                <!-- Tipus despesa fiscal: només per lloguers no-habitatge -->
                                <div v-if="selectedLloguer && selectedLloguer.es_habitatge === false">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus de despesa fiscal</label>
                                    <select
                                        v-model="classificacioDespesa.tipus_despesa_fiscal_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense tipus fiscal</option>
                                        <option v-for="t in tipusDespesaFiscalOpcions" :key="t.id" :value="t.id">{{ t.codi }} - {{ t.descripcio }}</option>
                                    </select>
                                    <p v-if="classificacioErrors['tipus_despesa_fiscal_id']" class="mt-1 text-sm text-red-600">{{ classificacioErrors['tipus_despesa_fiscal_id'] }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Número factura</label>
                                    <input
                                        v-model="classificacioDespesa.numero_factura"
                                        type="text"
                                        maxlength="50"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label>
                                    <input
                                        v-model="classificacioDespesa.concepte"
                                        type="text"
                                        maxlength="255"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- % IVA: només per lloguers no-habitatge -->
                                <div v-if="selectedLloguer && selectedLloguer.es_habitatge === false">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">% IVA</label>
                                    <input
                                        v-model="classificacioDespesa.iva_percentatge"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="classificacioErrors['iva_percentatge']" class="mt-1 text-sm text-red-600">{{ classificacioErrors['iva_percentatge'] }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <textarea
                                        v-model="classificacioDespesa.notes"
                                        rows="2"
                                        maxlength="500"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    ></textarea>
                                </div>

                                <!-- Camps IVA: només per lloguers no-habitatge -->
                                <div v-if="selectedLloguer && selectedLloguer.es_habitatge === false" class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Base imposable IVA (€)</label>
                                        <input
                                            v-model="classificacioDespesa.base_imposable"
                                            type="number"
                                            step="0.01"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                        <p v-if="classificacioErrors['base_imposable']" class="mt-1 text-sm text-red-600">{{ classificacioErrors['base_imposable'] }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IVA suportat (€)</label>
                                        <input
                                            v-model="classificacioDespesa.iva_import"
                                            type="number"
                                            step="0.01"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                        <p v-if="classificacioErrors['iva_import']" class="mt-1 text-sm text-red-600">{{ classificacioErrors['iva_import'] }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Ingrés fields -->
                            <div v-else class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Base lloguer (€) *</label>
                                        <input
                                            v-model="classificacioIngres.base_lloguer"
                                            type="number"
                                            step="0.01"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <textarea
                                        v-model="classificacioIngres.notes"
                                        rows="2"
                                        maxlength="500"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    ></textarea>
                                </div>

                                <!-- Línies de despesa -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Despeses addicionals</label>
                                        <button type="button" @click="addLinia" class="text-xs text-amber-600 hover:text-amber-900 dark:text-amber-400">+ Afegir línia</button>
                                    </div>
                                    <div v-if="classificacioIngres.linies.length > 0" class="space-y-2">
                                        <div
                                            v-for="(linia, idx) in classificacioIngres.linies"
                                            :key="idx"
                                            class="grid grid-cols-12 gap-2 items-start rounded-md border border-gray-200 p-2 dark:border-gray-700"
                                        >
                                            <div class="col-span-2">
                                                <select
                                                    v-model="linia.tipus"
                                                    required
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-xs"
                                                >
                                                    <option value="">Categoria</option>
                                                    <option v-for="cat in categoriesIngresLinia" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                                </select>
                                            </div>
                                            <div class="col-span-4">
                                                <input
                                                    v-model="linia.descripcio"
                                                    type="text"
                                                    placeholder="Descripció"
                                                    maxlength="200"
                                                    required
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-xs"
                                                />
                                            </div>
                                            <div class="col-span-2">
                                                <input
                                                    v-model="linia.import"
                                                    type="number"
                                                    step="0.01"
                                                    placeholder="Import"
                                                    required
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-xs"
                                                />
                                            </div>
                                            <div class="col-span-3">
                                                <select
                                                    v-model="linia.proveidor_id"
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-xs"
                                                >
                                                    <option :value="null">Sense proveïdor</option>
                                                    <option v-for="p in proveidors" :key="p.id" :value="p.id">{{ p.nom_rao_social }}</option>
                                                </select>
                                            </div>
                                            <div class="col-span-1 flex justify-center pt-1">
                                                <button type="button" @click="removeLinia(idx)" class="text-red-500 hover:text-red-700 text-sm">✕</button>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="text-xs italic text-gray-400">Cap despesa addicional.</p>
                                </div>

                                <!-- Resum de reconciliació -->
                                <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/40">
                                    <div class="space-y-1">
                                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                            <span>Base lloguer</span>
                                            <span>{{ formatCurrency((classificacioIngres.base_lloguer ?? 0).toString()) }}</span>
                                        </div>
                                        <div
                                            v-for="(linia, idx) in classificacioIngres.linies.filter(l => (l.import ?? 0) !== 0)"
                                            :key="'res-' + idx"
                                            class="flex justify-between text-gray-600 dark:text-gray-400"
                                        >
                                            <span class="truncate max-w-[60%]">− {{ linia.descripcio || linia.tipus || `Línia ${idx + 1}` }}</span>
                                            <span class="text-red-600 dark:text-red-400">{{ formatCurrency((linia.import ?? 0).toString()) }}</span>
                                        </div>
                                        <div class="mt-1 border-t border-gray-300 pt-1 dark:border-gray-600 flex justify-between font-medium text-gray-800 dark:text-gray-200">
                                            <span>= Net calculat</span>
                                            <span>{{ formatCurrency(ingresNetCalculat.toString()) }}</span>
                                        </div>
                                        <div class="flex justify-between text-gray-500 dark:text-gray-400">
                                            <span>Import al banc</span>
                                            <span>{{ formatCurrency(classificacioMoviment?.import ?? '0') }}</span>
                                        </div>
                                        <div
                                            class="flex justify-between font-semibold border-t border-gray-300 pt-1 dark:border-gray-600"
                                            :class="ingresDiferencia === 0
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-amber-600 dark:text-amber-400'"
                                        >
                                            <span>Diferència</span>
                                            <span>{{ ingresDiferencia === 0 ? '✓ Quadra' : formatCurrency(ingresDiferencia.toString()) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="classificacioSaving"
                                class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ classificacioSaving ? 'Desant…' : 'Desar classificació' }}
                            </button>
                            <button
                                type="button"
                                @click="closeClassificacioModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Resum -->
        <div v-if="showResumModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center px-4 pt-4 pb-20 text-center sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity dark:bg-gray-900 dark:bg-opacity-75" @click="closeResumModal"></div>
                <div class="relative inline-block w-full max-w-5xl transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:align-middle">
                    <!-- Header -->
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Resum del lloguer
                        </h3>
                        <button @click="closeResumModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="flex -mb-px px-6" aria-label="Tabs">
                            <button
                                @click="resumTab = 'resum'"
                                :class="[
                                    'px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                                    resumTab === 'resum'
                                        ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'
                                ]"
                            >Resum</button>
                            <button
                                @click="resumTab = 'ingressos'"
                                :class="[
                                    'px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                                    resumTab === 'ingressos'
                                        ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'
                                ]"
                            >Ingressos</button>
                            <button
                                @click="resumTab = 'despeses'"
                                :class="[
                                    'px-4 py-3 text-sm font-medium border-b-2 transition-colors',
                                    resumTab === 'despeses'
                                        ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'
                                ]"
                            >Despeses</button>
                        </nav>
                    </div>

                    <!-- Content -->
                    <div class="max-h-[70vh] overflow-y-auto px-6 py-4">
                        <!-- Loading -->
                        <div v-if="resumLoading" class="py-12 text-center text-sm text-gray-400">
                            Carregant...
                        </div>

                        <template v-else-if="resumData">
                            <!-- Tab: Resum -->
                            <div v-if="resumTab === 'resum'" class="space-y-6">
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span class="font-medium text-gray-500 dark:text-gray-400">Lloguer</span>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ resumData.lloguer_nom }}</p>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-500 dark:text-gray-400">Immoble</span>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ resumData.immoble_adreca || '-' }}</p>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-500 dark:text-gray-400">Any</span>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ resumData.any ?? 'Tots' }}</p>
                                    </div>
                                </div>
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">Total ingressos bruts</td>
                                            <td class="px-4 py-3 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.total_base.toString()) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">Total despeses</td>
                                            <td class="px-4 py-3 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.total_despeses.toString()) }}</td>
                                        </tr>
                                        <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-bold">
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">Resultat net</td>
                                            <td class="px-4 py-3 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.resultat_net.toString()) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Tab: Ingressos -->
                            <div v-if="resumTab === 'ingressos'">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Data</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Base lloguer</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Despeses</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Net calculat</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import bancari</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Diferencia</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                            <tr
                                                v-for="(ing, idx) in resumData.ingressos"
                                                :key="idx"
                                                :class="ing.diferencia !== 0 ? 'bg-red-50 dark:bg-red-900/20' : ''"
                                            >
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ ing.data }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 max-w-[200px] truncate">{{ ing.concepte }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(ing.base.toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ ing.despeses !== null ? formatCurrency(ing.despeses.toString()) : '' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(ing.net_calculat.toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(ing.import_banc.toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono" :class="ing.diferencia !== 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-900 dark:text-gray-100'">{{ ing.diferencia !== 0 ? formatCurrency(ing.diferencia.toString()) : '' }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 max-w-[150px] truncate">{{ ing.notes }}</td>
                                            </tr>
                                            <!-- Totals -->
                                            <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-bold bg-gray-50 dark:bg-gray-700">
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100" colspan="2">TOTAL</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.total_base.toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ (() => { const t = resumData!.ingressos.reduce((s, i) => s + (i.despeses ?? 0), 0); return t !== 0 ? formatCurrency(t.toString()) : ''; })() }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.ingressos.reduce((s, i) => s + i.net_calculat, 0).toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.ingressos.reduce((s, i) => s + i.import_banc, 0).toString()) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ (() => { const t = resumData!.ingressos.reduce((s, i) => s + i.net_calculat, 0) - resumData!.ingressos.reduce((s, i) => s + i.import_banc, 0); return Math.abs(t) > 0.005 ? formatCurrency(t.toFixed(2)) : ''; })() }}</td>
                                                <td class="px-3 py-2"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="resumData.ingressos.length === 0" class="py-8 text-center text-sm text-gray-400">Cap ingres registrat.</p>
                            </div>

                            <!-- Tab: Despeses -->
                            <div v-if="resumTab === 'despeses'">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Data</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Categoria</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Concepte</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Proveidor</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">NIF/CIF</th>
                                                <th class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Import</th>
                                                <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                            <tr v-for="(desp, idx) in resumData.despeses" :key="idx">
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ desp.data }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ desp.categoria }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 max-w-[200px] truncate">{{ desp.concepte }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{{ desp.proveidor }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ desp.nif }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(desp.import.toString()) }}</td>
                                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 max-w-[150px] truncate">{{ desp.notes }}</td>
                                            </tr>
                                            <!-- Totals -->
                                            <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-bold bg-gray-50 dark:bg-gray-700">
                                                <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100" colspan="5">TOTAL</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right font-mono text-gray-900 dark:text-gray-100">{{ formatCurrency(resumData.total_despeses.toString()) }}</td>
                                                <td class="px-3 py-2"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="resumData.despeses.length === 0" class="py-8 text-center text-sm text-gray-400">Cap despesa registrada.</p>
                            </div>
                        </template>
                    </div>

                    <!-- Footer -->
                    <div class="border-t border-gray-200 px-6 py-3 dark:border-gray-700 flex justify-end">
                        <button
                            @click="closeResumModal"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            Tancar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Factures Modal -->
        <FacturesModal
            v-if="selectedLloguer && !selectedLloguer.es_habitatge"
            :lloguer="selectedLloguer"
            :show="showFacturesModal"
            @close="showFacturesModal = false"
        />

        <!-- Revisio IPC Modal -->
        <RevisioIpcModal
            v-if="selectedLloguer && !selectedLloguer.es_habitatge"
            :lloguer="selectedLloguer"
            :show="showRevisioIpcModal"
            @close="showRevisioIpcModal = false"
            @updated="router.reload()"
        />

        <!-- Barra flotant de classificació múltiple -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-4"
        >
            <div
                v-if="selectedMovimentIds.size > 0"
                class="fixed bottom-6 left-1/2 z-40 -translate-x-1/2 flex items-center gap-4 rounded-xl bg-gray-900 px-5 py-3 shadow-xl dark:bg-gray-700"
            >
                <span class="text-sm text-gray-300">
                    {{ selectedMovimentIds.size }} moviment{{ selectedMovimentIds.size > 1 ? 's' : '' }} seleccionat{{ selectedMovimentIds.size > 1 ? 's' : '' }}
                </span>
                <button
                    type="button"
                    @click="openBulkModal"
                    class="rounded-md bg-amber-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-600 transition-colors"
                >
                    Classificar
                </button>
                <button
                    type="button"
                    @click="openBulkEditModal"
                    class="rounded-md bg-indigo-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-600 transition-colors"
                >
                    Editar
                </button>
                <button
                    type="button"
                    @click="selectedMovimentIds = new Set()"
                    class="text-gray-400 hover:text-white transition-colors text-sm"
                >
                    ✕
                </button>
            </div>
        </Transition>

        <!-- Modal classificació múltiple -->
        <div
            v-if="showBulkModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeBulkModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle">
                    <form @submit.prevent="submitBulk">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3 class="mb-1 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                                Classificació múltiple
                            </h3>
                            <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                                {{ selectedMovimentIds.size }} moviments seleccionats. Només s'aplicaran els camps que empleneu; la resta es conservarà.
                            </p>

                            <p v-if="bulkErrors.general" class="mb-4 text-sm text-red-600 dark:text-red-400">{{ bulkErrors.general }}</p>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Categoria</label>
                                    <select
                                        v-model="bulkDespesa.categoria"
                                        @change="onBulkCategoriaChange"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option value="">— sense canvis —</option>
                                        <option v-for="cat in categoriesDespesa" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Proveïdor</label>
                                    <select
                                        v-model="bulkDespesa.proveidor_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">— sense canvis —</option>
                                        <option v-for="p in proveidors" :key="p.id" :value="p.id">{{ p.nom_rao_social }}</option>
                                    </select>
                                </div>

                                <div v-if="selectedLloguer && selectedLloguer.es_habitatge === false">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus de despesa fiscal</label>
                                    <select
                                        v-model="bulkDespesa.tipus_despesa_fiscal_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">— sense canvis —</option>
                                        <option v-for="t in tipusDespesaFiscalOpcions" :key="t.id" :value="t.id">{{ t.codi }} - {{ t.descripcio }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Número factura</label>
                                    <input
                                        v-model="bulkDespesa.numero_factura"
                                        type="text"
                                        maxlength="50"
                                        placeholder="— sense canvis —"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label>
                                    <input
                                        v-model="bulkDespesa.concepte"
                                        type="text"
                                        maxlength="255"
                                        placeholder="— sense canvis —"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <textarea
                                        v-model="bulkDespesa.notes"
                                        rows="2"
                                        maxlength="500"
                                        placeholder="— sense canvis —"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    ></textarea>
                                </div>

                                <div v-if="selectedLloguer && selectedLloguer.es_habitatge === false" class="grid grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">% IVA</label>
                                        <input v-model="bulkDespesa.iva_percentatge" type="number" step="0.01" min="0" max="100" placeholder="—"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Base imposable (€)</label>
                                        <input v-model="bulkDespesa.base_imposable" type="number" step="0.01" placeholder="—"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IVA suportat (€)</label>
                                        <input v-model="bulkDespesa.iva_import" type="number" step="0.01" placeholder="—"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="bulkSaving"
                                class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ bulkSaving ? 'Guardant…' : 'Aplicar' }}
                            </button>
                            <button
                                type="button"
                                @click="closeBulkModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal edició múltiple -->
        <BulkEditModal
            v-model:open="showBulkEditModal"
            :count="selectedMovimentIds.size"
            :categories="movimentCategories"
            :saving="bulkEditSaving"
            :error="bulkEditError"
            @submit="handleBulkEdit"
        />

    </AuthenticatedLayout>
</template>
