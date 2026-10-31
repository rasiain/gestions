<script setup lang="ts">
/**
 * Les dades físiques de l'usuari, que són les entrades del càlcul de l'estat de forma.
 *
 * Cada registre porta les dades d'un dia, totes opcionals, i cada una val fins al registre
 * següent que la porti. La pulsació màxima, si no s'apunta, es dedueix de les sortides en bici:
 * al costat de la buida es mostra la que s'aplicarà.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import Info from '@/Components/Info.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { num, perAlServidor } from '@/formularis';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { Chart, ChartData, ChartOptions, Plugin } from 'chart.js';
import 'chartjs-adapter-date-fns';

ChartJS.register(...registerables);

interface FcDeduida {
    valor: number;
    /** `sortides`: la 5a més alta dels 365 dies anteriors; `tanaka`: 208 − 0,7 × edat. */
    origen: 'sortides' | 'tanaka' | 'registre';
    data?: string;
}

interface Registre {
    id: number;
    data: string;
    pes: number | null;
    fc_repos: number | null;
    fc_maxima: number | null;
    fc_maxima_deduida: FcDeduida | null;
    notes: string | null;
    origen: 'manual' | 'apple_salut';
}

interface Vigent {
    valor: number;
    data: string;
    /** Avui és abans del primer registre que la porta: s'hi aplica aquest. */
    anterior: boolean;
}

const props = defineProps<{
    registres: Registre[];
    /** L'alçada en cm, per a l'IMC. */
    perfil: { data_naixement: string | null; sexe: 'home' | 'dona' | null; alcada: number | null };
    avui: { pes: Vigent | null; fc_repos: Vigent | null; fc_maxima: FcDeduida | null };
}>();

const nombre = (v: number, decimals = 0) =>
    v.toLocaleString('ca-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const formatData = (iso: string) => new Intl.DateTimeFormat('ca-ES', { dateStyle: 'medium' }).format(new Date(iso));
const ORIGEN_FC: Record<FcDeduida['origen'], string> = {
    registre: 'apuntada',
    sortides: 'de les sortides',
    tanaka: 'per l\'edat (Tanaka)',
};

const edat = computed(() => {
    if (!props.perfil.data_naixement) return null;
    const n = new Date(props.perfil.data_naixement);
    const a = new Date();
    return a.getFullYear() - n.getFullYear() - (a < new Date(a.getFullYear(), n.getMonth(), n.getDate()) ? 1 : 0);
});

// ---- Perfil ----
const perfilForm = useForm({
    data_naixement: props.perfil.data_naixement ?? '',
    sexe: props.perfil.sexe ?? '',
    alcada: props.perfil.alcada !== null ? String(props.perfil.alcada) : '',
});
const desaPerfil = () =>
    perfilForm
        .transform((d) => ({ data_naixement: d.data_naixement || null, sexe: d.sexe || null, alcada: num(d.alcada) }))
        .put(route('salut.perfil'), { preserveScroll: true });

// ---- Registres ----
const mostraModal = ref(false);
const editat = ref<Registre | null>(null);
const diaAvui = () => new Date().toISOString().slice(0, 10);
const form = useForm({ dia: '', pes: '', fc_repos: '', fc_maxima: '', notes: '' });
const errorLocal = ref<string | null>(null);

function obre(r: Registre | null) {
    editat.value = r;
    form.clearErrors();
    errorLocal.value = null;
    form.dia = r?.data ?? diaAvui();
    form.pes = r?.pes != null ? nombre(r.pes, 1) : '';
    form.fc_repos = r?.fc_repos != null ? String(r.fc_repos) : '';
    form.fc_maxima = r?.fc_maxima != null ? String(r.fc_maxima) : '';
    form.notes = r?.notes ?? '';
    mostraModal.value = true;
}

function desa() {
    // El botó no es deshabilita: si falta alguna cosa, es diu què
    const pes = num(form.pes);
    const fcRepos = num(form.fc_repos);
    const fcMaxima = num(form.fc_maxima);
    if (!form.dia) return (errorLocal.value = 'Falta la data.');
    if (form.pes.trim() && pes === null) return (errorLocal.value = 'El pes no és cap número.');
    if (pes === null && fcRepos === null && fcMaxima === null) {
        return (errorLocal.value = 'Escriu com a mínim una dada: el pes o alguna de les pulsacions.');
    }
    errorLocal.value = null;

    const enviament = form.transform((d) => perAlServidor({ ...d, pes, fc_repos: fcRepos, fc_maxima: fcMaxima, notes: d.notes || null }));
    const opcions = { preserveScroll: true, onSuccess: () => (mostraModal.value = false) };
    if (editat.value) enviament.put(route('salut.registres.update', editat.value.id), opcions);
    else enviament.post(route('salut.registres.store'), opcions);
}

function elimina(r: Registre) {
    if (!confirm(`Vols eliminar el registre del ${formatData(r.data)}?`)) return;
    router.delete(route('salut.registres.destroy', r.id), { preserveScroll: true });
}

// ---- Gràfiques ----
const fosc = ref(false);
let consultaFosc: MediaQueryList | null = null;
const anotaFosc = (e: MediaQueryListEvent | MediaQueryList) => (fosc.value = e.matches);
onMounted(() => {
    consultaFosc = window.matchMedia('(prefers-color-scheme: dark)');
    anotaFosc(consultaFosc);
    consultaFosc.addEventListener('change', anotaFosc);
});
onUnmounted(() => consultaFosc?.removeEventListener('change', anotaFosc));
const tinta = computed(() => (fosc.value ? '#9ca3af' : '#6b7280'));
const quadricula = computed(() => (fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'));
const SERIE = '#0284c7';

const cronologics = computed(() => [...props.registres].reverse());

/** Esglaonada: cada valor val fins al registre següent que el porta. */
const serie = (camp: 'pes' | 'fc_repos', etiqueta: string): ChartData<'line'> => ({
    datasets: [{
        label: etiqueta,
        data: cronologics.value.filter((r) => r[camp] !== null).map((r) => ({ x: new Date(r.data).getTime(), y: r[camp] as number })),
        borderColor: SERIE,
        backgroundColor: SERIE,
        borderWidth: 2,
        pointRadius: 2,
        pointHoverRadius: 5,
        pointHitRadius: 10,
        stepped: true,
    }],
});
const dadesPes = computed(() => serie('pes', 'Pes'));

// ---- IMC ----
/**
 * Els trams de l'OMS. L'IMC no distingeix el múscul del greix ni té en compte l'edat: és una referència
 * de població, no un diagnòstic.
 */
const TRAMS_IMC = [
    { des: 30, fins: Infinity, nom: 'Obesitat', color: 'rgba(220,38,38,0.13)' },
    { des: 25, fins: 30, nom: 'Sobrepès', color: 'rgba(217,119,6,0.14)' },
    { des: 18.5, fins: 25, nom: 'Normal', color: 'rgba(22,163,74,0.13)' },
    { des: -Infinity, fins: 18.5, nom: 'Baix pes', color: 'rgba(2,132,199,0.13)' },
];
const metres = computed(() => (props.perfil.alcada ? props.perfil.alcada / 100 : null));
const imc = (kg: number) => (metres.value ? kg / metres.value ** 2 : null);
const tramImc = (v: number | null) => (v === null ? null : TRAMS_IMC.find((t) => v >= t.des && v < t.fins) ?? null);
const imcAvui = computed(() => (props.avui.pes ? imc(props.avui.pes.valor) : null));
/** El pes que correspon a l'IMC normal (18,5–24,9) amb aquesta alçada. */
const pesNormal = computed(() => (metres.value ? [18.5 * metres.value ** 2, 24.9 * metres.value ** 2] : null));

const dadesImc = computed<ChartData<'line'>>(() => {
    const d = serie('pes', 'IMC');
    d.datasets[0].data = (d.datasets[0].data as Array<{ x: number; y: number }>).map((p) => ({ x: p.x, y: Math.round((imc(p.y) ?? 0) * 10) / 10 }));
    Object.assign(d.datasets[0], { borderColor: tintaLinia.value, backgroundColor: tintaLinia.value });
    return d;
});
const tintaLinia = computed(() => (fosc.value ? '#e5e7eb' : '#374151'));

/** Pinta els trams de l'IMC darrere la línia. */
const tramsDeFons: Plugin<'line'> = {
    id: 'tramsImc',
    beforeDatasetsDraw(chart: Chart) {
        const { ctx, chartArea: a, scales: { y } } = chart;
        ctx.save();
        for (const t of TRAMS_IMC) {
            const dalt = Math.max(a.top, y.getPixelForValue(Math.min(t.fins, y.max)));
            const baix = Math.min(a.bottom, y.getPixelForValue(Math.max(t.des, y.min)));
            if (baix <= dalt) continue;
            ctx.fillStyle = t.color;
            ctx.fillRect(a.left, dalt, a.right - a.left, baix - dalt);
        }
        ctx.restore();
    },
};
const dadesFc = computed(() => serie('fc_repos', 'Pulsació en repòs'));

const opcions = (unitat: string, decimals: number) => computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'nearest', axis: 'x', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                title: (items) => formatData(new Date(Number(items[0]?.parsed.x)).toISOString()),
                label: (c) => `${nombre(Number(c.parsed.y), decimals)} ${unitat}`,
            },
        },
    },
    scales: {
        x: {
            type: 'time',
            time: { displayFormats: { day: 'dd/MM/yy', month: 'MM/yy', year: 'yyyy' } },
            ticks: { color: tinta.value, maxTicksLimit: 8, maxRotation: 0 },
            grid: { color: quadricula.value },
        },
        y: { ticks: { color: tinta.value }, grid: { color: quadricula.value }, border: { display: false } },
    },
}));
const opcionsPes = opcions('kg', 1);
const opcionsImcBase = opcions('', 1);
const opcionsImc = computed<ChartOptions<'line'>>(() => {
    const o = opcionsImcBase.value;
    return {
        ...o,
        plugins: { ...o.plugins, tooltip: { callbacks: { ...o.plugins!.tooltip!.callbacks, label: (c: any) => `IMC ${nombre(Number(c.parsed.y), 1)} · ${tramImc(Number(c.parsed.y))?.nom ?? ''}` } } },
        scales: { ...o.scales, y: { ...o.scales!.y, suggestedMin: 18, suggestedMax: 31 } },
    };
});
const opcionsFc = opcions('ppm', 0);
</script>

<template>
    <Head title="Dades físiques" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Dades físiques</h2>
                <button @click="obre(null)"
                    class="inline-flex items-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-rose-700">
                    Nou registre
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-xl space-y-6 sm:px-6 lg:px-8">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Les dades que necessita el càlcul de l'estat de forma. Cada una val des de la seva data fins al
                    registre següent que la porti: un registre pot portar només el pes, o només les pulsacions.
                </p>

                <!-- Vigents avui -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Pes
                            <Info titol="Pes">
                                <p>Al càlcul de l'estat de forma només hi entrarà a l'índex d'eficiència en pujada: amb menys quilos es puja més ràpid sense estar més en forma.</p>
                                <p v-if="pesNormal">Amb {{ perfil.alcada }} cm, el pes d'un IMC normal (18,5–24,9) és <strong>entre {{ nombre(pesNormal[0], 1) }} i {{ nombre(pesNormal[1], 1) }} kg</strong>. Vegeu l'IMC per a les limitacions d'aquesta xifra.</p>
                                <p>Les pesades varien un o dos quilos d'un dia a l'altre (aigua, digestió): per veure'n l'evolució val més la mediana d'un mes que una pesada sola.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ avui.pes ? nombre(avui.pes.valor, 1) + ' kg' : '—' }}
                        </div>
                        <div v-if="avui.pes" class="text-xs text-gray-400 dark:text-gray-500">des del {{ formatData(avui.pes.data) }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            IMC
                            <Info titol="Índex de massa corporal">
                                <p>Pes (kg) ÷ alçada (m)². Trams de l'OMS per a adults: <strong>menys de 18,5</strong> baix pes, <strong>18,5–24,9</strong> normal, <strong>25–29,9</strong> sobrepès, <strong>30 o més</strong> obesitat.</p>
                                <p v-if="pesNormal">Amb {{ perfil.alcada }} cm, l'IMC normal correspon a <strong>{{ nombre(pesNormal[0], 1) }}–{{ nombre(pesNormal[1], 1) }} kg</strong>.</p>
                                <p>És una referència de població, no un diagnòstic:</p>
                                <ul class="list-disc space-y-1 pl-4">
                                    <li>No distingeix el múscul del greix: algú amb molta musculatura (cames de ciclista) pot sortir amb sobrepès sense tenir-ne.</li>
                                    <li>Amb l'edat, diversos estudis troben el risc més baix una mica més amunt: en majors de 65 anys, cap a 23–28.</li>
                                    <li>Diu més el perímetre de la cintura o el percentatge de greix (la Tanita el mesura) que l'IMC sol.</li>
                                </ul>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ imcAvui !== null ? nombre(imcAvui, 1) : '—' }}
                        </div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">
                            <template v-if="imcAvui !== null">{{ tramImc(imcAvui)?.nom }}<template v-if="pesNormal"> · normal: {{ nombre(pesNormal[0], 0) }}–{{ nombre(pesNormal[1], 0) }} kg</template></template>
                            <template v-else-if="!perfil.alcada">cal l'alçada al perfil</template>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Pulsació en repòs
                            <Info titol="Pulsació en repòs">
                                <p>La més baixa del dia, en repòs (el rellotge la pren sobretot de nit). En adults, entre 60 i 100 és el normal; en persones que entrenen resistència, entre 40 i 60, i baixa a mesura que es guanya forma aeròbica.</p>
                                <p>Més que el valor, compta <strong>el canvi respecte del que és habitual</strong> en tu: 5 o més pulsacions per sobre durant uns quants dies sol voler dir fatiga acumulada, poc descans o que s'està covant alguna cosa.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ avui.fc_repos ? avui.fc_repos.valor + ' ppm' : '—' }}
                        </div>
                        <div v-if="avui.fc_repos" class="text-xs text-gray-400 dark:text-gray-500">des del {{ formatData(avui.fc_repos.data) }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Pulsació màxima
                            <Info titol="Pulsació màxima" costat="dreta">
                                <p>La més alta que el cor pot arribar a fer. No millora entrenant: és de cadascú i baixa a poc a poc amb l'edat. No n'hi ha de bones o de dolentes.</p>
                                <p>Serveix per definir les zones de pulsació del càlcul de la càrrega. Si no s'apunta, es pren la 5a més alta de les sortides de l'últim any (la més alta sola acostuma a ser una punxa del sensor), que és molt més fiable que la fórmula per edat: 208 − 0,7 × edat et dona {{ edat !== null ? Math.round(208 - 0.7 * edat) : '—' }}.</p>
                                <p>Si mai es mesura en una prova d'esforç, val la pena apuntar-la.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ avui.fc_maxima ? avui.fc_maxima.valor + ' ppm' : '—' }}
                        </div>
                        <div v-if="avui.fc_maxima" class="text-xs text-gray-400 dark:text-gray-500">
                            {{ ORIGEN_FC[avui.fc_maxima.origen] }}<template v-if="avui.fc_maxima.origen === 'sortides'">: la 5a més alta de l'últim any</template>
                        </div>
                    </div>
                </div>

                <!-- Perfil -->
                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Perfil</h3>
                    <div class="mt-3 flex flex-wrap items-end gap-4">
                        <label class="block text-sm text-gray-600 dark:text-gray-400">
                            Data de naixement
                            <input v-model="perfilForm.data_naixement" type="date"
                                class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <label class="block text-sm text-gray-600 dark:text-gray-400">
                            Sexe
                            <select v-model="perfilForm.sexe"
                                class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="">—</option>
                                <option value="home">Home</option>
                                <option value="dona">Dona</option>
                            </select>
                        </label>
                        <label class="block text-sm text-gray-600 dark:text-gray-400">
                            Alçada (cm)
                            <input v-model="perfilForm.alcada" type="text" inputmode="numeric" placeholder="181"
                                class="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <button type="button" @click="desaPerfil"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            Desa
                        </button>
                        <span v-if="edat !== null" class="pb-2 text-sm text-gray-500 dark:text-gray-400">{{ edat }} anys</span>
                        <span v-if="perfilForm.recentlySuccessful" class="pb-2 text-sm text-green-600 dark:text-green-400">Desat.</span>
                    </div>
                    <p v-for="(e, camp) in perfilForm.errors" :key="camp" class="mt-2 text-sm text-red-600">{{ e }}</p>
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                        L'edat només serveix quan no hi ha prou sortides per deduir la pulsació màxima; el sexe, per a una
                        variant del càlcul de la càrrega; l'alçada, per a l'IMC.
                    </p>
                </div>

                <!-- Gràfiques -->
                <div v-if="registres.length" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Pes <span class="font-normal text-gray-400">kg</span></h3>
                        <div class="mt-2 h-48"><Line :data="dadesPes" :options="opcionsPes" /></div>
                    </div>
                    <div v-if="perfil.alcada" class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                            IMC <span class="font-normal text-gray-400">amb {{ perfil.alcada }} cm</span>
                            <Info titol="Evolució de l'IMC">
                                <p>El mateix que el pes, dividit per l'alçada al quadrat, amb els trams de l'OMS de fons. El desitjable per a un adult és el tram <strong>normal (18,5–24,9)</strong><template v-if="pesNormal">: {{ nombre(pesNormal[0], 1) }}–{{ nombre(pesNormal[1], 1) }} kg</template>.</p>
                                <p>Les limitacions són les de la xifra d'avui: no separa múscul de greix, i amb l'edat el risc més baix se situa una mica més amunt.</p>
                            </Info>
                        </h3>
                        <div class="mt-2 h-48"><Line :data="dadesImc" :options="opcionsImc" :plugins="[tramsDeFons]" /></div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span v-for="t in [...TRAMS_IMC].reverse()" :key="t.nom" class="inline-flex items-center gap-1.5">
                                <span class="inline-block h-3 w-3 rounded-sm border border-gray-300 dark:border-gray-600" :style="{ backgroundColor: t.color }"></span>{{ t.nom }}
                            </span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Pulsació en repòs <span class="font-normal text-gray-400">ppm</span></h3>
                        <div class="mt-2 h-48"><Line :data="dadesFc" :options="opcionsFc" /></div>
                    </div>
                </div>

                <!-- Registres -->
                <div class="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="px-4 py-2 font-medium">Data</th>
                                <th class="px-4 py-2 text-right font-medium">Pes (kg)</th>
                                <th class="px-4 py-2 text-right font-medium">FC repòs</th>
                                <th class="px-4 py-2 text-right font-medium">FC màxima</th>
                                <th class="px-4 py-2 font-medium">Notes</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-if="!registres.length">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">Encara no hi ha cap registre.</td>
                            </tr>
                            <tr v-for="r in registres" :key="r.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="whitespace-nowrap px-4 py-2 text-gray-900 dark:text-gray-100">
                                    {{ formatData(r.data) }}
                                    <span v-if="r.origen === 'apple_salut'" class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                        title="Mediana del mes, de la càrrega inicial des d'Apple Salut">Apple Salut</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ r.pes !== null ? nombre(r.pes, 1) : '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ r.fc_repos ?? '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    <span v-if="r.fc_maxima !== null" class="text-gray-700 dark:text-gray-300">{{ r.fc_maxima }}</span>
                                    <span v-else-if="r.fc_maxima_deduida" class="text-gray-400 dark:text-gray-500"
                                        :title="'Deduïda ' + ORIGEN_FC[r.fc_maxima_deduida.origen] + ': és la que s\'aplicarà si no se n\'apunta cap'">
                                        {{ r.fc_maxima_deduida.valor }}
                                    </span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ r.notes }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <button @click="obre(r)" class="text-rose-700 hover:underline dark:text-rose-400">Edita</button>
                                    <button @click="elimina(r)" class="ml-3 text-red-600 hover:underline dark:text-red-400">Elimina</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    En gris, la pulsació màxima deduïda de les sortides en bici (la 5a més alta dels 365 dies anteriors):
                    s'aplica mentre no se n'apunti cap. Els registres d'Apple Salut són la mediana de cada mes; si se
                    n'edita un, passa a ser manual i una nova càrrega ja no el toca.
                </p>
            </div>
        </div>

        <Modal :show="mostraModal" max-width="md" @close="mostraModal = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editat ? 'Edita el registre' : 'Nou registre' }}
                </h3>
                <div class="space-y-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Data
                        <input v-model="form.dia" type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </label>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Pes (kg)
                            <input v-model="form.pes" type="text" inputmode="decimal" placeholder="84,5"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            FC repòs
                            <input v-model="form.fc_repos" type="text" inputmode="numeric" placeholder="58"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            FC màxima
                            <input v-model="form.fc_maxima" type="text" inputmode="numeric" placeholder="deduïda"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Totes opcionals, però com a mínim una. La pulsació màxima, només si la saps d'una prova: si no, es
                        dedueix de les sortides.
                    </p>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Notes
                        <textarea v-model="form.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </label>
                    <p v-if="errorLocal" class="text-sm text-red-600">{{ errorLocal }}</p>
                    <p v-for="(e, camp) in form.errors" :key="camp" class="text-sm text-red-600">{{ e }}</p>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="mostraModal = false"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                        Cancel·la
                    </button>
                    <button type="button" @click="desa"
                        class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-rose-700">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
