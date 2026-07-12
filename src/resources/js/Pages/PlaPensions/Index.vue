<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, watch, nextTick, onUnmounted, computed } from 'vue';
import { Chart, registerables } from 'chart.js';
import 'chartjs-adapter-date-fns';
Chart.register(...registerables);

interface ComptePlaPensions {
    id: number;
    nom: string;
    entitat: string;
    titulars: string;
}

interface Entitat {
    id: number;
    nom: string;
}

interface Aportacio {
    id: number;
    contracte_id: number;
    data: string;
    import: number;
    participacions: number;
    notes: string | null;
    rendibilitat: number | null;
}

interface ValorParticipacio {
    id: number;
    pla_id: number;
    data: string;
    valor_participacio: number;
}

interface DespesaPla {
    id: number;
    contracte_id: number;
    data: string;
    import: number;
    concepte: string | null;
}

interface ResumContracte {
    total_participacions: number;
    total_invertit: number;
    total_despeses: number;
    valor_contracte: number | null;
    rendibilitat_bruta: number | null;
    rendibilitat_neta: number | null;
}

interface Contracte {
    id: number;
    pla_id: number;
    compte_corrent_id: number;
    compte_nom: string;
    compte_referencia: string;
    compte_entitat: string;
    titulars: string;
    titular_ids: number[];
    data_inici: string;
    data_fi: string | null;
    notes: string | null;
    aportacions: Aportacio[];
    despeses: DespesaPla[];
    valor_contracte_num: number;
    resum: ResumContracte;
}

interface ResumPla {
    valor_participacio: number | null;
    data_valor_actual: string | null;
    total_valor: number;
    rendibilitat_bruta: number;
    rendibilitat_neta: number;
}

interface Pla {
    id: number;
    nom: string;
    valors: ValorParticipacio[];
    contractes: Contracte[];
    resum_pla: ResumPla;
}

interface Persona {
    id: number;
    nom: string;
    cognoms: string;
}

interface Props {
    plans: Pla[];
    comptesPlaPensions: ComptePlaPensions[];
    entitats: Entitat[];
    persones: Persona[];
}

const props = defineProps<Props>();

const plaList = ref<Pla[]>(JSON.parse(JSON.stringify(props.plans)));
watch(() => props.plans, v => { plaList.value = JSON.parse(JSON.stringify(v)); }, { deep: true });

const getPla = (id: number) => plaList.value.find(p => p.id === id)!;

// --- Expand ---
const expandedPlans = ref<Set<number>>(new Set());
const plaTab = ref<Record<number, 'valors' | 'contractes'>>({});
const expandedContractes = ref<Set<number>>(new Set());
const contracteTab = ref<Record<number, 'aportacions' | 'despeses'>>({});

const togglePla = (id: number) => {
    const s = new Set(expandedPlans.value);
    s.has(id) ? s.delete(id) : s.add(id);
    if (!plaTab.value[id]) plaTab.value[id] = 'contractes';
    expandedPlans.value = s;
};

const toggleContracte = (id: number) => {
    const s = new Set(expandedContractes.value);
    s.has(id) ? s.delete(id) : s.add(id);
    if (!contracteTab.value[id]) contracteTab.value[id] = 'aportacions';
    expandedContractes.value = s;
};

// --- Format ---
const formatEur = (v: number | null) =>
    v === null ? '—' : new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(v);

const formatNum = (v: number, dec = 4) =>
    new Intl.NumberFormat('ca-ES', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(v);

const classeRend = (v: number | null) =>
    v === null ? 'text-gray-400 dark:text-gray-500'
    : v >= 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold'
    : 'text-red-600 dark:text-red-400 font-semibold';

const formatPct = (v: number | null) =>
    v === null ? '—' : new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v) + ' %';

const csrf = () => document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '';

// === GRÀFIQUES ===
const personesAmbPP = computed(() => {
    const ids = new Set<number>();
    for (const p of plaList.value)
        for (const c of p.contractes)
            for (const id of c.titular_ids) ids.add(id);
    return props.persones.filter(p => ids.has(p.id));
});
const showGrafiques = ref(false);
const selectedPersonaId = ref<number | null>(null);
const rangeMode = ref<'individual' | 'global'>('individual');
const chartValorRef = ref<HTMLCanvasElement | null>(null);
const chartRendRefs: Record<number, HTMLCanvasElement | null> = {};
let chartValorInstance: Chart | null = null;
const chartRendInstances: Record<number, Chart> = {};

const CHART_COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444'];
const APORT_COLORS  = ['#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#84cc16', '#f97316', '#ec4899'];

function getSeriesData(personaId: number | null) {
    const allDates = [...new Set(
        plaList.value.flatMap(p => p.valors.map(v => v.data))
    )].sort();

    const aportacioByDate: Record<string, number> = {};
    const seriesTotal: { valor: number; invertit: number }[] = [];

    for (const date of allDates) {
        let totalValor = 0, totalInvertit = 0;
        for (const pla of plaList.value) {
            const vp = pla.valors.find(v => v.data <= date);
            const valorPart = vp?.valor_participacio ?? null;
            let fV = 0, fI = 0, hasInv = false;
            for (const contracte of pla.contractes) {
                let share = 1;
                if (personaId !== null) {
                    if (!contracte.titular_ids.includes(personaId)) continue;
                    share = contracte.titular_ids.length > 0 ? 1 / contracte.titular_ids.length : 1;
                }
                if (contracte.data_inici > date) continue;
                const aports = contracte.aportacions.filter(a => a.data <= date);
                if (!aports.length) continue;
                hasInv = true;
                fI += aports.reduce((s, a) => s + a.import, 0) * share;
                if (valorPart !== null) fV += aports.reduce((s, a) => s + a.participacions, 0) * valorPart * share;
            }
            if (hasInv && valorPart !== null) { totalValor += fV; totalInvertit += fI; }
        }
        seriesTotal.push({ valor: Math.round(totalValor * 100) / 100, invertit: Math.round(totalInvertit * 100) / 100 });
    }

    for (const pla of plaList.value) {
        for (const contracte of pla.contractes) {
            if (personaId !== null && !contracte.titular_ids.includes(personaId)) continue;
            const share = personaId !== null && contracte.titular_ids.length > 0 ? 1 / contracte.titular_ids.length : 1;
            for (const a of contracte.aportacions)
                aportacioByDate[a.data] = (aportacioByDate[a.data] ?? 0) + a.import * share;
        }
    }

    type PlaSeries = { plaId: number; plaIndex: number; plaNom: string; startDate: string; dates: string[]; totalData: (number | null)[]; aportacions: { label: string; data: (number | null)[] }[] };
    const plaSeries: PlaSeries[] = [];

    let globalEnd = '';
    for (const pla of plaList.value) {
        if (pla.valors.length > 0) {
            const latest = pla.valors[0].data;
            if (!globalEnd || latest > globalEnd) globalEnd = latest;
        }
    }

    let globalStart = '';
    let globalPlaDates: string[] = [];
    if (rangeMode.value === 'global') {
        for (const pla of plaList.value) {
            for (const contracte of pla.contractes) {
                if (personaId !== null && !contracte.titular_ids.includes(personaId)) continue;
                for (const a of contracte.aportacions)
                    if (!globalStart || a.data < globalStart) globalStart = a.data;
            }
        }
        if (globalStart && globalEnd) {
            globalPlaDates = [...new Set([
                ...plaList.value.flatMap(p => p.valors.map(v => v.data)),
                ...plaList.value.flatMap(p => p.contractes.flatMap(c =>
                    (personaId === null || c.titular_ids.includes(personaId))
                        ? c.aportacions.map(a => a.data) : []
                )),
            ])].sort().filter(d => d >= globalStart && d <= globalEnd);
        }
    }

    for (const pla of plaList.value) {
        const plaIndex = plaList.value.findIndex(p => p.id === pla.id);
        const allAports: Aportacio[] = [];
        let firstAportDate = '';
        for (const contracte of pla.contractes) {
            if (personaId !== null && !contracte.titular_ids.includes(personaId)) continue;
            for (const a of contracte.aportacions) {
                allAports.push(a);
                if (!firstAportDate || a.data < firstAportDate) firstAportDate = a.data;
            }
        }
        if (!allAports.length || !pla.valors.length || !globalEnd) continue;

        const plaDates = rangeMode.value === 'global'
            ? globalPlaDates
            : [...new Set([
                ...pla.valors.map(v => v.data),
                ...allAports.map(a => a.data),
              ])].sort().filter(d => d >= firstAportDate && d <= globalEnd);
        const startDate = rangeMode.value === 'global' ? globalStart : firstAportDate;
        if (!plaDates.length) continue;

        const totalData: (number | null)[] = [];
        const aportData = new Map<number, (number | null)[]>();
        for (const a of allAports) aportData.set(a.id, []);

        for (const date of plaDates) {
            const vp = pla.valors.find(v => v.data <= date);
            const valorPart = vp?.valor_participacio ?? null;
            let fV = 0, fI = 0, hasInv = false;
            for (const contracte of pla.contractes) {
                let share = 1;
                if (personaId !== null) {
                    if (!contracte.titular_ids.includes(personaId)) continue;
                    share = contracte.titular_ids.length > 0 ? 1 / contracte.titular_ids.length : 1;
                }
                if (contracte.data_inici > date) continue;
                const aports = contracte.aportacions.filter(a => a.data <= date);
                if (!aports.length) continue;
                hasInv = true;
                fI += aports.reduce((s, a) => s + a.import, 0) * share;
                if (valorPart !== null) fV += aports.reduce((s, a) => s + a.participacions, 0) * valorPart * share;
            }
            totalData.push(hasInv && valorPart !== null && fI > 0 ? Math.round((fV - fI) / fI * 10000) / 100 : null);
            for (const a of allAports) {
                const arr = aportData.get(a.id)!;
                if (date < a.data || valorPart === null) arr.push(null);
                else arr.push(Math.round((a.participacions * valorPart - a.import) / a.import * 10000) / 100);
            }
        }

        plaSeries.push({
            plaId: pla.id, plaIndex, plaNom: pla.nom, startDate, dates: plaDates, totalData,
            aportacions: allAports.map(a => {
                const [ay, am, ad] = a.data.split('-');
                return { label: `${ad}/${am}/${ay.slice(2)} · ${formatEur(a.import)}`, data: aportData.get(a.id)! };
            }),
        });
    }

    return { allDates, seriesTotal, aportacioByDate, plaSeries };
}

function buildCharts() {
    if (!showGrafiques.value) return;
    nextTick(() => {
        const { allDates, seriesTotal, aportacioByDate, plaSeries } = getSeriesData(selectedPersonaId.value);
        const aportacioDates = new Set(Object.keys(aportacioByDate));

        if (chartValorRef.value) {
            chartValorInstance?.destroy();
            chartValorInstance = new Chart(chartValorRef.value, {
                type: 'line',
                data: {
                    labels: allDates,
                    datasets: [
                        {
                            label: 'Capital invertit',
                            data: seriesTotal.map(s => s.invertit),
                            borderColor: '#9ca3af', backgroundColor: 'rgba(156,163,175,0.2)',
                            fill: true, tension: 0.3, pointRadius: 0, borderWidth: 1.5, order: 2,
                        } as any,
                        {
                            label: 'Valor cartera',
                            data: seriesTotal.map(s => s.valor),
                            borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.15)',
                            fill: true, tension: 0.3, borderWidth: 2, order: 1,
                            pointRadius: allDates.map(d => aportacioDates.has(d) ? 5 : 0),
                            pointBackgroundColor: allDates.map(d => aportacioDates.has(d) ? '#f59e0b' : '#10b981'),
                            pointBorderColor:     allDates.map(d => aportacioDates.has(d) ? '#f59e0b' : '#10b981'),
                        } as any,
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { callbacks: {
                            title: (items: any) => {
                                const d = allDates[items[0]?.dataIndex ?? -1];
                                if (!d) return '';
                                const [y, m, day] = d.split('-');
                                return `${day}/${m}/${y}`;
                            },
                            label: (ctx: any) => `${ctx.dataset.label}: ${formatEur(ctx.parsed.y)}`,
                            afterBody: (items: any) => {
                                const d = allDates[items[0]?.dataIndex ?? -1];
                                const ev = d != null ? aportacioByDate[d] : null;
                                return ev ? [`↑ Aportació: ${formatEur(ev)}`] : [];
                            },
                        }},
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: { tooltipFormat: 'dd/MM/yyyy', displayFormats: { day: 'dd/MM/yy', month: 'MM/yy', year: 'yyyy' } },
                            ticks: { maxTicksLimit: 14, maxRotation: 0 },
                        },
                        y: { ticks: { callback: (v: any) => typeof v === 'number' ? formatEur(v) : String(v) } },
                    },
                },
            } as any);
        }

        for (const ps of plaSeries) {
            const canvas = chartRendRefs[ps.plaId];
            if (!canvas) continue;
            chartRendInstances[ps.plaId]?.destroy();
            chartRendInstances[ps.plaId] = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: ps.dates,
                    datasets: [
                        ...ps.aportacions.map((ap, i) => ({
                            label: ap.label,
                            data: ap.data,
                            borderColor: APORT_COLORS[i % APORT_COLORS.length],
                            backgroundColor: 'transparent',
                            tension: 0.3, pointRadius: 0, borderWidth: 1.5,
                            borderDash: [4, 3], spanGaps: false,
                        } as any)),
                        {
                            label: 'Total',
                            data: ps.totalData,
                            borderColor: CHART_COLORS[ps.plaIndex % CHART_COLORS.length], backgroundColor: 'transparent',
                            tension: 0.3, pointRadius: 0, borderWidth: 2.5, spanGaps: false,
                        } as any,
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 16, padding: 10 } },
                        tooltip: { callbacks: {
                            title: (items: any) => {
                                const d = ps.dates[items[0]?.dataIndex ?? -1];
                                if (!d) return '';
                                const [y, m, day] = d.split('-');
                                return `${day}/${m}/${y}`;
                            },
                            label: (ctx: any) => `${ctx.dataset.label}: ${ctx.parsed.y !== null ? formatPct(ctx.parsed.y) : '—'}`,
                        }},
                    },
                    scales: {
                        x: {
                            type: 'time',
                            min: ps.startDate,
                            time: { tooltipFormat: 'dd/MM/yyyy', displayFormats: { day: 'dd/MM/yy', month: 'MM/yy', year: 'yyyy' } },
                            ticks: { maxTicksLimit: 12, maxRotation: 0 },
                        },
                        y: { ticks: { callback: (v: any) => typeof v === 'number' ? formatPct(v) : String(v) } },
                    },
                },
            } as any);
        }
    });
}

watch([showGrafiques, selectedPersonaId, rangeMode], buildCharts);
watch(plaList, () => { if (showGrafiques.value) buildCharts(); }, { deep: true });
onUnmounted(() => {
    chartValorInstance?.destroy();
    Object.values(chartRendInstances).forEach(c => c?.destroy());
});

// === PLANS CRUD (Inertia) ===
const showPlaModal = ref(false);
const isEditingPla = ref(false);
const editingPlaId = ref<number | null>(null);
const plaForm = useForm({ nom: '' });

const openCreatePla = () => {
    isEditingPla.value = false; editingPlaId.value = null;
    plaForm.reset(); showPlaModal.value = true;
};
const openEditPla = (p: Pla) => {
    isEditingPla.value = true; editingPlaId.value = p.id;
    plaForm.nom = p.nom; showPlaModal.value = true;
};
const closePlaModal = () => { showPlaModal.value = false; };
const submitPla = () => {
    if (isEditingPla.value && editingPlaId.value) {
        plaForm.put(route('plans-pensions.update', editingPlaId.value), { onSuccess: closePlaModal });
    } else {
        plaForm.post(route('plans-pensions.store'), { onSuccess: closePlaModal });
    }
};
const deletePla = (p: Pla) => {
    if (confirm(`Estàs segur que vols eliminar el pla "${p.nom}"?`))
        router.delete(route('plans-pensions.destroy', p.id));
};

// === CONTRACTES CRUD (fetch) ===
const showContracteModal = ref(false);
const isEditingContracte = ref(false);
const entitatNova = ref(false);
const editingContracteId = ref<number | null>(null);
const contracteError = ref<string | null>(null);
const contracteSaving = ref(false);
const compteNou = ref(false);
const contracteForm = ref({
    pla_id: 0, compte_corrent_id: '', compte_nou: false,
    compte_referencia: '', compte_nom: '', compte_entitat_id: null as number | null, compte_entitat_nova_nom: '',
    _compte_display: '',
    titular_ids: [] as number[],
    data_inici: '', data_fi: '', notes: '',
});

const openCreateContracte = (plaId: number) => {
    isEditingContracte.value = false; editingContracteId.value = null; compteNou.value = true; entitatNova.value = false;
    contracteForm.value = { pla_id: plaId, compte_corrent_id: '', compte_nou: true, compte_referencia: '', compte_nom: '', compte_entitat_id: null, compte_entitat_nova_nom: '', _compte_display: '', titular_ids: [], data_inici: new Date().toISOString().slice(0, 10), data_fi: '', notes: '' };
    contracteError.value = null; showContracteModal.value = true;
};
const openEditContracte = (c: Contracte) => {
    isEditingContracte.value = true; editingContracteId.value = c.id; compteNou.value = false; entitatNova.value = false;
    const suffix = c.compte_referencia ? ` ···· ${c.compte_referencia.slice(-4)}` : '';
    const display = [c.compte_nom + suffix, c.compte_entitat].filter(Boolean).join(' — ');
    contracteForm.value = { pla_id: c.pla_id, compte_corrent_id: String(c.compte_corrent_id), compte_nou: false, compte_referencia: '', compte_nom: c.compte_nom, compte_entitat_id: null, compte_entitat_nova_nom: '', _compte_display: display, titular_ids: [...(c.titular_ids ?? [])], data_inici: c.data_inici, data_fi: c.data_fi ?? '', notes: c.notes ?? '' };
    contracteError.value = null; showContracteModal.value = true;
};
const closeContracteModal = () => { showContracteModal.value = false; };

const submitContracte = async () => {
    contracteSaving.value = true; contracteError.value = null;
    try {
        const url = isEditingContracte.value
            ? `/plans-pensions/contractes/${editingContracteId.value}`
            : '/plans-pensions/contractes';
        const payload = { ...contracteForm.value, compte_nou: compteNou.value,
            compte_corrent_id: contracteForm.value.compte_corrent_id ? parseInt(contracteForm.value.compte_corrent_id) : null,
            data_fi: contracteForm.value.data_fi || null };
        const res = await fetch(url, {
            method: isEditingContracte.value ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(payload),
        });
        if (!res.ok) {
            const err = await res.json();
            contracteError.value = Object.values(err.errors ?? {}).flat().join(' ') || 'Error desconegut.';
            return;
        }
        const saved: Contracte = await res.json();
        const pla = getPla(saved.pla_id);
        if (isEditingContracte.value) {
            const idx = pla.contractes.findIndex(c => c.id === saved.id);
            if (idx !== -1) pla.contractes[idx] = saved;
        } else {
            pla.contractes.push(saved);
        }
        recalcPla(pla);
        closeContracteModal();
    } finally { contracteSaving.value = false; }
};

const deleteContracte = async (c: Contracte) => {
    if (!confirm('Estàs segur que vols eliminar aquest contracte?')) return;
    await fetch(`/plans-pensions/contractes/${c.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } });
    const pla = getPla(c.pla_id);
    pla.contractes = pla.contractes.filter(x => x.id !== c.id);
    recalcPla(pla);
};

// === VALORS CRUD (fetch) ===
const showValorModal = ref(false);
const isEditingValor = ref(false);
const editingValorId = ref<number | null>(null);
const valorError = ref<string | null>(null);
const valorSaving = ref(false);
const valorForm = ref({ pla_id: 0, data: '', valor_participacio: '' });

const openCreateValor = (plaId: number) => {
    isEditingValor.value = false; editingValorId.value = null;
    valorForm.value = { pla_id: plaId, data: new Date().toISOString().slice(0, 10), valor_participacio: '' };
    valorError.value = null; showValorModal.value = true;
};
const openEditValor = (v: ValorParticipacio) => {
    isEditingValor.value = true; editingValorId.value = v.id;
    valorForm.value = { pla_id: v.pla_id, data: v.data, valor_participacio: String(v.valor_participacio) };
    valorError.value = null; showValorModal.value = true;
};
const closeValorModal = () => { showValorModal.value = false; };

const submitValor = async () => {
    valorSaving.value = true; valorError.value = null;
    try {
        const url = isEditingValor.value ? `/plans-pensions/valors/${editingValorId.value}` : '/plans-pensions/valors';
        const res = await fetch(url, {
            method: isEditingValor.value ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ...valorForm.value, valor_participacio: parseFloat(valorForm.value.valor_participacio) }),
        });
        if (!res.ok) {
            const err = await res.json();
            valorError.value = Object.values(err.errors ?? {}).flat().join(' ') || 'Error desconegut.';
            return;
        }
        const saved: ValorParticipacio = await res.json();
        const pla = getPla(saved.pla_id);
        const idx = pla.valors.findIndex(v => v.id === saved.id);
        if (idx !== -1) pla.valors[idx] = saved; else pla.valors.unshift(saved);
        pla.valors.sort((a, b) => b.data.localeCompare(a.data));
        recalcPla(pla);
        closeValorModal();
    } finally { valorSaving.value = false; }
};

const deleteValor = async (v: ValorParticipacio) => {
    if (!confirm('Estàs segur que vols eliminar aquest valor?')) return;
    await fetch(`/plans-pensions/valors/${v.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } });
    const pla = getPla(v.pla_id);
    pla.valors = pla.valors.filter(x => x.id !== v.id);
    recalcPla(pla);
};

// === APORTACIONS CRUD (fetch) ===
const showAportacioModal = ref(false);
const isEditingAportacio = ref(false);
const editingAportacioId = ref<number | null>(null);
const aportacioError = ref<string | null>(null);
const aportacioSaving = ref(false);
const aportacioForm = ref({ contracte_id: 0, data: '', import: '', participacions: '', notes: '' });
const aportacioPlaId = ref(0);

const openCreateAportacio = (contracte: Contracte) => {
    isEditingAportacio.value = false; editingAportacioId.value = null;
    aportacioPlaId.value = contracte.pla_id;
    aportacioForm.value = { contracte_id: contracte.id, data: new Date().toISOString().slice(0, 10), import: '', participacions: '', notes: '' };
    aportacioError.value = null; showAportacioModal.value = true;
};
const openEditAportacio = (a: Aportacio, plaId: number) => {
    isEditingAportacio.value = true; editingAportacioId.value = a.id;
    aportacioPlaId.value = plaId;
    aportacioForm.value = { contracte_id: a.contracte_id, data: a.data, import: String(a.import), participacions: String(a.participacions), notes: a.notes ?? '' };
    aportacioError.value = null; showAportacioModal.value = true;
};
const closeAportacioModal = () => { showAportacioModal.value = false; };

const submitAportacio = async () => {
    aportacioSaving.value = true; aportacioError.value = null;
    try {
        const url = isEditingAportacio.value ? `/plans-pensions/aportacions/${editingAportacioId.value}` : '/plans-pensions/aportacions';
        const res = await fetch(url, {
            method: isEditingAportacio.value ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ...aportacioForm.value, import: parseFloat(aportacioForm.value.import), participacions: parseFloat(aportacioForm.value.participacions) }),
        });
        if (!res.ok) {
            const err = await res.json();
            aportacioError.value = Object.values(err.errors ?? {}).flat().join(' ') || 'Error desconegut.';
            return;
        }
        const saved: Aportacio = await res.json();
        const pla = getPla(aportacioPlaId.value);
        const contracte = pla.contractes.find(c => c.id === saved.contracte_id)!;
        if (isEditingAportacio.value) {
            const idx = contracte.aportacions.findIndex(a => a.id === saved.id);
            if (idx !== -1) contracte.aportacions[idx] = saved;
        } else {
            contracte.aportacions.push(saved);
            contracte.aportacions.sort((a, b) => a.data.localeCompare(b.data));
        }
        recalcPla(pla);
        closeAportacioModal();
    } finally { aportacioSaving.value = false; }
};

const deleteAportacio = async (a: Aportacio, plaId: number) => {
    if (!confirm('Estàs segur que vols eliminar aquesta aportació?')) return;
    await fetch(`/plans-pensions/aportacions/${a.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } });
    const pla = getPla(plaId);
    const contracte = pla.contractes.find(c => c.id === a.contracte_id)!;
    contracte.aportacions = contracte.aportacions.filter(x => x.id !== a.id);
    recalcPla(pla);
};

// === DESPESES CRUD (fetch) ===
const showDespesaModal = ref(false);
const isEditingDespesa = ref(false);
const editingDespesaId = ref<number | null>(null);
const despesaError = ref<string | null>(null);
const despesaSaving = ref(false);
const despesaForm = ref({ contracte_id: 0, data: '', import: '', concepte: '' });
const despesaPlaId = ref(0);

const openCreateDespesa = (contracte: Contracte) => {
    isEditingDespesa.value = false; editingDespesaId.value = null;
    despesaPlaId.value = contracte.pla_id;
    despesaForm.value = { contracte_id: contracte.id, data: new Date().toISOString().slice(0, 10), import: '', concepte: '' };
    despesaError.value = null; showDespesaModal.value = true;
};
const openEditDespesa = (d: DespesaPla, plaId: number) => {
    isEditingDespesa.value = true; editingDespesaId.value = d.id;
    despesaPlaId.value = plaId;
    despesaForm.value = { contracte_id: d.contracte_id, data: d.data, import: String(d.import), concepte: d.concepte ?? '' };
    despesaError.value = null; showDespesaModal.value = true;
};
const closeDespesaModal = () => { showDespesaModal.value = false; };

const submitDespesa = async () => {
    despesaSaving.value = true; despesaError.value = null;
    try {
        const url = isEditingDespesa.value ? `/plans-pensions/despeses/${editingDespesaId.value}` : '/plans-pensions/despeses';
        const res = await fetch(url, {
            method: isEditingDespesa.value ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ...despesaForm.value, import: parseFloat(despesaForm.value.import) }),
        });
        if (!res.ok) {
            const err = await res.json();
            despesaError.value = Object.values(err.errors ?? {}).flat().join(' ') || 'Error desconegut.';
            return;
        }
        const saved: DespesaPla = await res.json();
        const pla = getPla(despesaPlaId.value);
        const contracte = pla.contractes.find(c => c.id === saved.contracte_id)!;
        if (isEditingDespesa.value) {
            const idx = contracte.despeses.findIndex(d => d.id === saved.id);
            if (idx !== -1) contracte.despeses[idx] = saved;
        } else {
            contracte.despeses.unshift(saved);
            contracte.despeses.sort((a, b) => b.data.localeCompare(a.data));
        }
        recalcPla(pla);
        closeDespesaModal();
    } finally { despesaSaving.value = false; }
};

const deleteDespesa = async (d: DespesaPla, plaId: number) => {
    if (!confirm('Estàs segur que vols eliminar aquesta despesa?')) return;
    await fetch(`/plans-pensions/despeses/${d.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() } });
    const pla = getPla(plaId);
    const contracte = pla.contractes.find(c => c.id === d.contracte_id)!;
    contracte.despeses = contracte.despeses.filter(x => x.id !== d.id);
    recalcPla(pla);
};

// === Recàlcul local ===
const recalcPla = (pla: Pla) => {
    const valorActual = pla.valors.length > 0 ? pla.valors[0] : null;
    const valorPart = valorActual ? valorActual.valor_participacio : null;

    pla.contractes.forEach(c => {
        const totalPart = c.aportacions.reduce((s, a) => s + a.participacions, 0);
        const totalInvertit = c.aportacions.reduce((s, a) => s + a.import, 0);
        const totalDespeses = c.despeses.reduce((s, d) => s + d.import, 0);
        const valorC = valorPart !== null ? Math.round(totalPart * valorPart * 100) / 100 : null;
        const rendB = valorC !== null ? Math.round((valorC - totalInvertit) * 100) / 100 : null;
        const rendN = rendB !== null ? Math.round((rendB - totalDespeses) * 100) / 100 : null;
        c.valor_contracte_num = valorC ?? 0;
        c.resum = { total_participacions: totalPart, total_invertit: totalInvertit, total_despeses: totalDespeses, valor_contracte: valorC, rendibilitat_bruta: rendB, rendibilitat_neta: rendN };
        c.aportacions.forEach(a => {
            a.rendibilitat = valorPart !== null ? Math.round((a.participacions * valorPart - a.import) * 100) / 100 : null;
        });
    });

    const totalValor = pla.contractes.reduce((s, c) => s + c.valor_contracte_num, 0);
    const totalRendB = pla.contractes.reduce((s, c) => s + (c.resum.rendibilitat_bruta ?? 0), 0);
    const totalRendN = pla.contractes.reduce((s, c) => s + (c.resum.rendibilitat_neta ?? 0), 0);
    pla.resum_pla = { valor_participacio: valorPart, data_valor_actual: valorActual?.data ?? null, total_valor: Math.round(totalValor * 100) / 100, rendibilitat_bruta: Math.round(totalRendB * 100) / 100, rendibilitat_neta: Math.round(totalRendN * 100) / 100 };
};
</script>

<template>
    <Head title="Plans de Pensions" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Plans de Pensions</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">

                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">Plans de Pensions</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Catàleg de plans amb els seus contractes, aportacions i rendibilitat</p>
                            </div>
                            <button @click="openCreatePla" class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Nou Pla
                            </button>
                        </div>

                        <!-- Gràfiques -->
                        <div class="mb-6 overflow-hidden rounded-lg border-2 border-gray-300 dark:border-gray-500">
                            <div class="flex cursor-pointer select-none items-center justify-between p-4 hover:bg-gray-50 dark:hover:bg-gray-700/30" @click="showGrafiques = !showGrafiques">
                                <div class="flex items-center gap-2">
                                    <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-90': showGrafiques }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">Evolució de la cartera</span>
                                </div>
                                <div v-if="showGrafiques" class="flex items-center gap-3" @click.stop>
                                    <label class="text-sm text-gray-500 dark:text-gray-400">Titular:</label>
                                    <select v-model="selectedPersonaId" style="color-scheme: auto" class="rounded-md border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        <option :value="null">Tots</option>
                                        <option v-for="p in personesAmbPP" :key="p.id" :value="p.id">{{ p.nom }} {{ p.cognoms }}</option>
                                    </select>
                                    <div class="flex overflow-hidden rounded-md border border-gray-300 text-xs dark:border-gray-600">
                                        <button @click="rangeMode = 'individual'"
                                            class="px-3 py-1.5 transition-colors"
                                            :class="rangeMode === 'individual' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'">
                                            Per pla
                                        </button>
                                        <button @click="rangeMode = 'global'"
                                            class="border-l border-gray-300 px-3 py-1.5 transition-colors dark:border-gray-600"
                                            :class="rangeMode === 'global' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'">
                                            Global
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="showGrafiques" class="space-y-8 border-t border-gray-200 p-6 dark:border-gray-700">
                                <div>
                                    <h4 class="mb-1 text-sm font-medium text-gray-700 dark:text-gray-300">Valor de cartera i capital invertit</h4>
                                    <p class="mb-3 text-xs text-gray-400">Els punts taronges marquen les aportacions.</p>
                                    <div class="relative rounded-md bg-white" style="height:300px">
                                        <canvas ref="chartValorRef"></canvas>
                                    </div>
                                </div>
                                <div v-for="p in plaList" :key="p.id" class="border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <h4 class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Rendibilitat per aportació — <span class="font-semibold">{{ p.nom }}</span>
                                    </h4>
                                    <div class="relative rounded-md bg-white" style="height:280px">
                                        <canvas :ref="el => { chartRendRefs[p.id] = el as HTMLCanvasElement | null }"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="plaList.length > 0" class="overflow-hidden rounded-lg border-2 border-gray-300 dark:border-gray-500">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Pla</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Contractes</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Valor total</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Rdbt. bruta</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Rdbt. neta</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Val./part.</th>
                                        <th class="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="p in plaList" :key="p.id">

                                        <!-- Fila resum pla -->
                                        <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                            :class="expandedPlans.has(p.id) ? '' : 'border-b-2 border-gray-300 dark:border-gray-500'"
                                            @click="togglePla(p.id)">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-90': expandedPlans.has(p.id) }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ p.nom }}</span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ p.contractes.length }}</td>
                                            <td class="px-4 py-3 text-right font-medium">{{ formatEur(p.resum_pla.total_valor) }}</td>
                                            <td class="px-4 py-3 text-right" :class="classeRend(p.resum_pla.rendibilitat_bruta)">{{ formatEur(p.resum_pla.rendibilitat_bruta) }}</td>
                                            <td class="px-4 py-3 text-right" :class="classeRend(p.resum_pla.rendibilitat_neta)">{{ formatEur(p.resum_pla.rendibilitat_neta) }}</td>
                                            <td class="px-4 py-3 text-right">
                                                <template v-if="p.resum_pla.valor_participacio !== null">
                                                    <div class="font-mono">{{ formatNum(p.resum_pla.valor_participacio, 4) }}</div>
                                                    <div v-if="p.resum_pla.data_valor_actual" class="text-xs text-gray-400">{{ p.resum_pla.data_valor_actual }}</div>
                                                </template>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                            <td class="px-4 py-3 text-right" @click.stop>
                                                <div class="flex items-center justify-end gap-3">
                                                    <button @click="openEditPla(p)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                                    <button @click="deletePla(p)" class="text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Contingut expandit -->
                                        <tr v-if="expandedPlans.has(p.id)" class="border-b-2 border-gray-300 dark:border-gray-500">
                                            <td colspan="7" class="p-0">
                                                <div class="border-t border-gray-300 bg-gray-50 dark:border-gray-500 dark:bg-gray-900/60">

                                    <!-- Tabs pla -->
                                    <div class="flex items-center justify-between border-b border-gray-200 px-4 dark:border-gray-700">
                                        <nav class="-mb-px flex space-x-6">
                                            <button v-for="tab in (['contractes', 'valors'] as const)" :key="tab"
                                                @click="plaTab[p.id] = tab"
                                                class="border-b-2 py-3 text-sm font-medium transition-colors"
                                                :class="plaTab[p.id] === tab ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                            >
                                                {{ tab === 'contractes' ? 'Contractes' : 'Valors participació' }}
                                                <span class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs" :class="plaTab[p.id] === tab ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'">
                                                    {{ tab === 'contractes' ? p.contractes.length : p.valors.length }}
                                                </span>
                                            </button>
                                        </nav>
                                        <button v-if="plaTab[p.id] === 'contractes'" @click.stop="openCreateContracte(p.id)" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">+ Nou contracte</button>
                                        <button v-if="plaTab[p.id] === 'valors'" @click.stop="openCreateValor(p.id)" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">+ Nou valor</button>
                                    </div>

                                    <!-- Tab: Valors -->
                                    <div v-if="plaTab[p.id] === 'valors'" class="p-4">
                                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">Si ja existeix un valor per la mateixa data, s'actualitzarà automàticament.</p>
                                        <div v-if="p.valors.length > 0" class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-500">
                                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                                <thead class="bg-gray-50 dark:bg-gray-700">
                                                    <tr>
                                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Data</th>
                                                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Valor / participació</th>
                                                        <th class="px-4 py-2"></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                                    <tr v-for="(v, idx) in p.valors" :key="v.id" class="hover:bg-gray-50 dark:hover:bg-gray-700" :class="{ 'bg-emerald-50 dark:bg-emerald-900/10': idx === 0 }">
                                                        <td class="px-4 py-2 text-gray-900 dark:text-gray-100">{{ v.data }}<span v-if="idx === 0" class="ml-2 text-xs font-medium text-emerald-600 dark:text-emerald-400">actual</span></td>
                                                        <td class="px-4 py-2 text-right font-mono">{{ formatNum(v.valor_participacio, 4) }}</td>
                                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                                            <button @click.stop="openEditValor(v)" class="mr-2 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                                            <button @click.stop="deleteValor(v)" class="text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p v-else class="text-sm text-gray-400">Cap valor registrat.</p>
                                    </div>

                                    <!-- Tab: Contractes -->
                                    <div v-if="plaTab[p.id] === 'contractes'" class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <p v-if="p.contractes.length === 0" class="p-4 text-sm text-gray-400">Cap contracte registrat.</p>

                                        <div v-for="c in p.contractes" :key="c.id">
                                            <!-- Capçalera contracte -->
                                            <div class="flex cursor-pointer select-none items-center justify-between bg-gray-50 px-4 py-3 dark:bg-gray-900/20" @click="toggleContracte(c.id)">
                                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                                    <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-90': expandedContractes.has(c.id) }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ c.compte_nom }} <span class="font-normal text-gray-400">— {{ c.compte_entitat }}</span></div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ c.titulars || 'Sense titulars' }} · des de {{ c.data_inici }}{{ c.data_fi ? ` fins ${c.data_fi}` : '' }}</div>
                                                    </div>
                                                </div>
                                                <div class="mr-4 hidden shrink-0 items-center gap-6 sm:flex">
                                                    <div class="text-right">
                                                        <div class="text-xs text-gray-400">Valor</div>
                                                        <div class="text-sm font-medium">{{ formatEur(c.resum.valor_contracte) }}</div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="text-xs text-gray-400">Rdbt. bruta</div>
                                                        <div class="text-sm" :class="classeRend(c.resum.rendibilitat_bruta)">{{ formatEur(c.resum.rendibilitat_bruta) }}</div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="text-xs text-gray-400">Rdbt. neta</div>
                                                        <div class="text-sm" :class="classeRend(c.resum.rendibilitat_neta)">{{ formatEur(c.resum.rendibilitat_neta) }}</div>
                                                    </div>
                                                </div>
                                                <div class="flex shrink-0 items-center gap-3" @click.stop>
                                                    <button @click="openEditContracte(c)" class="text-xs text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                                    <button @click="deleteContracte(c)" class="text-xs text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                                </div>
                                            </div>

                                            <!-- Detall contracte -->
                                            <div v-if="expandedContractes.has(c.id)" class="border-t border-gray-100 dark:border-gray-700">
                                                <div class="grid grid-cols-2 gap-x-8 gap-y-1 bg-emerald-50 px-6 py-3 text-sm dark:bg-emerald-900/10 sm:grid-cols-4">
                                                    <div class="flex justify-between gap-2"><span class="text-gray-500">Participacions:</span><span class="font-mono font-medium">{{ formatNum(c.resum.total_participacions, 4) }}</span></div>
                                                    <div class="flex justify-between gap-2"><span class="text-gray-500">Total invertit:</span><span class="font-medium">{{ formatEur(c.resum.total_invertit) }}</span></div>
                                                    <div class="flex justify-between gap-2"><span class="text-gray-500">Total despeses:</span><span class="font-medium">{{ formatEur(c.resum.total_despeses) }}</span></div>
                                                    <div class="flex justify-between gap-2"><span class="text-gray-500">Valor contracte:</span><span class="font-medium">{{ formatEur(c.resum.valor_contracte) }}</span></div>
                                                </div>

                                                <div class="flex items-center justify-between border-b border-gray-100 px-6 dark:border-gray-700">
                                                    <nav class="-mb-px flex space-x-6">
                                                        <button v-for="tab in (['aportacions', 'despeses'] as const)" :key="tab"
                                                            @click="contracteTab[c.id] = tab"
                                                            class="border-b-2 py-2.5 text-sm font-medium transition-colors"
                                                            :class="contracteTab[c.id] === tab ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                                        >
                                                            {{ tab === 'aportacions' ? 'Aportacions' : 'Despeses' }}
                                                            <span class="ml-1 rounded-full px-1.5 py-0.5 text-xs" :class="contracteTab[c.id] === tab ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'">{{ tab === 'aportacions' ? c.aportacions.length : c.despeses.length }}</span>
                                                        </button>
                                                    </nav>
                                                    <button v-if="contracteTab[c.id] === 'aportacions'" @click.stop="openCreateAportacio(c)" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700">+ Nova aportació</button>
                                                    <button v-if="contracteTab[c.id] === 'despeses'" @click.stop="openCreateDespesa(c)" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700">+ Nova despesa</button>
                                                </div>

                                                <!-- Tab: Aportacions -->
                                                <div v-if="contracteTab[c.id] === 'aportacions'" class="px-6 py-3">
                                                    <div v-if="c.aportacions.length > 0" class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-500">
                                                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                                            <thead class="bg-gray-50 dark:bg-gray-700">
                                                                <tr>
                                                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Data</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Import</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Participacions</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Preu/part.</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Rdbt. €</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Rdbt. %</th>
                                                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Notes</th>
                                                                    <th class="px-4 py-2"></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                                                <tr v-for="a in c.aportacions" :key="a.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                                    <td class="px-4 py-2">{{ a.data }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono">{{ formatEur(a.import) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono">{{ formatNum(a.participacions, 4) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono text-gray-500 dark:text-gray-400">{{ formatNum(a.participacions > 0 ? a.import / a.participacions : 0, 4) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono" :class="classeRend(a.rendibilitat)">{{ formatEur(a.rendibilitat) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono" :class="classeRend(a.rendibilitat)">{{ formatPct(a.rendibilitat !== null && a.import > 0 ? a.rendibilitat / a.import * 100 : null) }}</td>
                                                                    <td class="px-4 py-2 text-gray-500">{{ a.notes ?? '' }}</td>
                                                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                                                        <button @click.stop="openEditAportacio(a, p.id)" class="mr-2 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                                                        <button @click.stop="deleteAportacio(a, p.id)" class="text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                            <tfoot class="bg-gray-50 dark:bg-gray-700">
                                                                <tr>
                                                                    <td class="px-4 py-2 text-xs font-semibold uppercase text-gray-500">Total</td>
                                                                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ formatEur(c.resum.total_invertit) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ formatNum(c.resum.total_participacions, 4) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono text-gray-500 dark:text-gray-400">{{ formatNum(c.resum.total_participacions > 0 ? c.resum.total_invertit / c.resum.total_participacions : 0, 4) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono font-semibold" :class="classeRend(c.resum.rendibilitat_bruta)">{{ formatEur(c.resum.rendibilitat_bruta) }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono font-semibold" :class="classeRend(c.resum.rendibilitat_bruta)">{{ formatPct(c.resum.rendibilitat_bruta !== null && c.resum.total_invertit > 0 ? c.resum.rendibilitat_bruta / c.resum.total_invertit * 100 : null) }}</td>
                                                                    <td colspan="2"></td>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                    <p v-else class="text-sm text-gray-400">Cap aportació registrada.</p>
                                                </div>

                                                <!-- Tab: Despeses -->
                                                <div v-if="contracteTab[c.id] === 'despeses'" class="px-6 py-3">
                                                    <div v-if="c.despeses.length > 0" class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-500">
                                                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                                            <thead class="bg-gray-50 dark:bg-gray-700">
                                                                <tr>
                                                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Data</th>
                                                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Import</th>
                                                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-300">Concepte</th>
                                                                    <th class="px-4 py-2"></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                                                <tr v-for="d in c.despeses" :key="d.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                                    <td class="px-4 py-2">{{ d.data }}</td>
                                                                    <td class="px-4 py-2 text-right font-mono">{{ formatEur(d.import) }}</td>
                                                                    <td class="px-4 py-2 text-gray-500">{{ d.concepte ?? '' }}</td>
                                                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                                                        <button @click.stop="openEditDespesa(d, p.id)" class="mr-2 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                                                        <button @click.stop="deleteDespesa(d, p.id)" class="text-red-600 hover:text-red-900 dark:text-red-400">Eliminar</button>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                            <tfoot class="bg-gray-50 dark:bg-gray-700">
                                                                <tr>
                                                                    <td class="px-4 py-2 text-xs font-semibold uppercase text-gray-500">Total</td>
                                                                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ formatEur(c.resum.total_despeses) }}</td>
                                                                    <td colspan="2"></td>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                    <p v-else class="text-sm text-gray-400">Cap despesa registrada.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-12 text-center">
                            <p class="text-sm text-gray-500 dark:text-gray-400">No hi ha plans de pensions registrats.</p>
                            <button @click="openCreatePla" class="mt-4 inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">+ Nou Pla</button>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL: PLA -->
        <div v-if="showPlaModal" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="closePlaModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                <div class="inline-block w-full transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl dark:bg-gray-800 sm:my-8 sm:max-w-md sm:align-middle">
                    <form @submit.prevent="submitPla">
                        <div class="px-6 py-5">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">{{ isEditingPla ? 'Editar pla' : 'Nou pla de pensions' }}</h3>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom del pla *</label>
                                <input v-model="plaForm.nom" type="text" required maxlength="200" placeholder="p.ex. Pla de Pensions Indexat" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                <p v-if="plaForm.errors.nom" class="mt-1 text-sm text-red-600">{{ plaForm.errors.nom }}</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="plaForm.processing" class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-4 py-2 text-base font-medium text-white hover:bg-emerald-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">{{ isEditingPla ? 'Actualitzar' : 'Crear' }}</button>
                            <button type="button" @click="closePlaModal" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm">Cancel·lar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL: CONTRACTE -->
        <div v-if="showContracteModal" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="closeContracteModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                <div class="inline-block w-full transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl dark:bg-gray-800 sm:my-8 sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submitContracte">
                        <div class="px-6 py-5">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">{{ isEditingContracte ? 'Editar contracte' : 'Nou contracte' }}</h3>
                            <p v-if="contracteError" class="mb-3 text-sm text-red-600">{{ contracteError }}</p>
                            <div class="space-y-4">
                                <div v-if="isEditingContracte">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte bancari</label>
                                    <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-300">{{ contracteForm._compte_display || '—' }}</p>
                                </div>
                                <div v-if="isEditingContracte">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom descriptiu</label>
                                    <input v-model="contracteForm.compte_nom" type="text" maxlength="100" placeholder="p.ex. Pla Pensions - Joan" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                                <div v-if="!isEditingContracte">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compte bancari</label>
                                    <div class="mt-1 flex gap-3">
                                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="radio" v-model="compteNou" :value="true" class="text-emerald-600" /> Compte nou</label>
                                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="radio" v-model="compteNou" :value="false" class="text-emerald-600" /> Compte existent</label>
                                    </div>
                                    <div v-if="!compteNou" class="mt-2">
                                        <select v-model="contracteForm.compte_corrent_id" class="block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                            <option value="">— Selecciona un compte —</option>
                                            <option v-for="c in props.comptesPlaPensions" :key="c.id" :value="c.id">{{ c.nom }} — {{ c.entitat }}{{ c.titulars ? ` (${c.titulars})` : '' }}</option>
                                        </select>
                                    </div>
                                    <div v-else class="mt-2 space-y-3 rounded-md border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-900/10">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">Referència / número *</label>
                                            <input v-model="contracteForm.compte_referencia" type="text" maxlength="24" placeholder="p.ex. PP-2024-001" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">Nom descriptiu</label>
                                            <input v-model="contracteForm.compte_nom" type="text" maxlength="100" placeholder="p.ex. Pla Pensions - Joan" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                        </div>
                                        <div>
                                            <div class="flex items-center justify-between">
                                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">Entitat *</label>
                                                <button type="button" @click="entitatNova = !entitatNova; contracteForm.compte_entitat_id = null; contracteForm.compte_entitat_nova_nom = ''" class="text-xs text-emerald-700 hover:text-emerald-900 dark:text-emerald-400">
                                                    {{ entitatNova ? '← Seleccionar existent' : '+ Entitat nova' }}
                                                </button>
                                            </div>
                                            <div v-if="!entitatNova" class="mt-1">
                                                <select v-model="contracteForm.compte_entitat_id" class="block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm">
                                                    <option :value="null">— Selecciona una entitat —</option>
                                                    <option v-for="e in props.entitats" :key="e.id" :value="e.id">{{ e.nom }}</option>
                                                </select>
                                            </div>
                                            <div v-else class="mt-1">
                                                <input v-model="contracteForm.compte_entitat_nova_nom" type="text" maxlength="200" placeholder="p.ex. Caixa Enginyers" class="block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Titulars</label>
                                    <div v-if="props.persones.length > 0" class="mt-2 max-h-36 space-y-1.5 overflow-y-auto rounded-md border border-gray-300 p-3 dark:border-gray-600">
                                        <label v-for="person in props.persones" :key="person.id" class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                            <input type="checkbox" v-model="contracteForm.titular_ids" :value="person.id" class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700" />
                                            {{ person.nom }} {{ person.cognoms }}
                                        </label>
                                    </div>
                                    <p v-else class="mt-1 text-sm text-gray-400">Cap persona disponible.</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data d'inici *</label>
                                    <input v-model="contracteForm.data_inici" type="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data de fi (opcional)</label>
                                    <input v-model="contracteForm.data_fi" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <input v-model="contracteForm.notes" type="text" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="contracteSaving" class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-4 py-2 text-base font-medium text-white hover:bg-emerald-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">{{ isEditingContracte ? 'Actualitzar' : 'Crear' }}</button>
                            <button type="button" @click="closeContracteModal" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm">Cancel·lar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL: VALOR -->
        <div v-if="showValorModal" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="closeValorModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                <div class="inline-block w-full transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl dark:bg-gray-800 sm:my-8 sm:max-w-sm sm:align-middle">
                    <form @submit.prevent="submitValor">
                        <div class="px-6 py-5">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">{{ isEditingValor ? 'Editar valor' : 'Nou valor de participació' }}</h3>
                            <p v-if="valorError" class="mb-3 text-sm text-red-600">{{ valorError }}</p>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data *</label>
                                    <input v-model="valorForm.data" type="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Valor per participació (€) *</label>
                                    <input v-model="valorForm.valor_participacio" type="number" step="any" min="0.000001" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="valorSaving" class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-4 py-2 text-base font-medium text-white hover:bg-emerald-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">{{ isEditingValor ? 'Actualitzar' : 'Afegir' }}</button>
                            <button type="button" @click="closeValorModal" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm">Cancel·lar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL: APORTACIÓ -->
        <div v-if="showAportacioModal" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="closeAportacioModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                <div class="inline-block w-full transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl dark:bg-gray-800 sm:my-8 sm:max-w-md sm:align-middle">
                    <form @submit.prevent="submitAportacio">
                        <div class="px-6 py-5">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">{{ isEditingAportacio ? 'Editar aportació' : 'Nova aportació' }}</h3>
                            <p v-if="aportacioError" class="mb-3 text-sm text-red-600">{{ aportacioError }}</p>
                            <div class="space-y-4">
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data *</label><input v-model="aportacioForm.data" type="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Import invertit (€) *</label><input v-model="aportacioForm.import" type="number" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Participacions adquirides *</label><input v-model="aportacioForm.participacions" type="number" step="any" min="0.000001" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label><input v-model="aportacioForm.notes" type="text" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="aportacioSaving" class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-4 py-2 text-base font-medium text-white hover:bg-emerald-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">{{ isEditingAportacio ? 'Actualitzar' : 'Afegir' }}</button>
                            <button type="button" @click="closeAportacioModal" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm">Cancel·lar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL: DESPESA -->
        <div v-if="showDespesaModal" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="closeDespesaModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
                <div class="inline-block w-full transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl dark:bg-gray-800 sm:my-8 sm:max-w-md sm:align-middle">
                    <form @submit.prevent="submitDespesa">
                        <div class="px-6 py-5">
                            <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-gray-100">{{ isEditingDespesa ? 'Editar despesa' : 'Nova despesa' }}</h3>
                            <p v-if="despesaError" class="mb-3 text-sm text-red-600">{{ despesaError }}</p>
                            <div class="space-y-4">
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data *</label><input v-model="despesaForm.data" type="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Import (€) *</label><input v-model="despesaForm.import" type="number" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Concepte</label><input v-model="despesaForm.concepte" type="text" maxlength="300" placeholder="p.ex. Comissió de gestió" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" /></div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="submit" :disabled="despesaSaving" class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-4 py-2 text-base font-medium text-white hover:bg-emerald-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm">{{ isEditingDespesa ? 'Actualitzar' : 'Afegir' }}</button>
                            <button type="button" @click="closeDespesaModal" class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm">Cancel·lar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
