<script setup lang="ts">
/**
 * L'estat de forma: la càrrega de cada sortida en bici (TRIMP d'Edwards) i, dia a dia, el fons, la
 * fatiga i la frescor que en surten. Tot arriba amb la pàgina i el rang es tria aquí.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import Info from '@/Components/Info.vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Bar, Line } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { Chart, ChartData, ChartOptions, Plugin } from 'chart.js';
import 'chartjs-adapter-date-fns';

ChartJS.register(...registerables);

interface Dia {
    data: string;
    carrega: number;
    fons: number;
    fatiga: number;
    /** La del matí: fons − fatiga del dia d'abans. */
    frescor: number;
}

interface Sortida {
    id: number;
    data: string;
    nom: string | null;
    bici: string | null;
    rodets: boolean;
    minuts: number | null;
    trimp: number | null;
    zones: Record<string, number> | null;
    /** `sortida`: la mateixa sortida la va superar de manera sostinguda, i s'hi ha fet servir aquesta. */
    fc_maxima: { valor: number; origen: 'registre' | 'sortides' | 'tanaka' | 'sortida' } | null;
    /** `punts`: pulsació de cada punt; `zones`: les zones de Garmin; `mitjana`: tota a la zona de la mitjana. */
    metode: 'punts' | 'zones' | 'mitjana' | null;
    parcial: boolean;
}

const props = defineProps<{
    corbes: Dia[];
    sortides: Sortida[];
    constants: { fons: number; fatiga: number };
}>();

const nombre = (v: number, decimals = 0) =>
    v.toLocaleString('ca-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const formatData = (iso: string) => new Intl.DateTimeFormat('ca-ES', { dateStyle: 'medium' }).format(new Date(iso));
const ms = (iso: string) => new Date(iso + 'T00:00:00').getTime();

const ORIGEN_FC: Record<string, string> = {
    registre: 'apuntada a Dades físiques',
    sortides: 'deduïda de les sortides de l\'últim any',
    tanaka: 'per l\'edat (Tanaka)',
    sortida: 'la mateixa sortida la va superar',
};
const METODE: Record<string, { text: string; titol: string }> = {
    punts: { text: '', titol: 'Amb la pulsació de cada punt del recorregut' },
    zones: { text: 'zones Garmin', titol: 'Sense punts: amb el temps a cada zona que diu Garmin, que fa servir les seves zones' },
    mitjana: { text: 'mitjana', titol: 'Sense punts ni zones: tota la sortida a la zona de la pulsació mitjana' },
};

// ---- Rang ----
const anys = computed(() => [...new Set(props.corbes.map((d) => d.data.slice(0, 4)))].sort().reverse());
type Rang = '3m' | '12m' | 'tot' | string;
const rang = ref<Rang>('12m');
const RANGS: Array<[Rang, string]> = [['3m', '3 mesos'], ['12m', '12 mesos'], ['tot', 'Tot']];

const limits = computed<[string, string]>(() => {
    const darrer = props.corbes[props.corbes.length - 1]?.data ?? '';
    if (rang.value === 'tot') return [props.corbes[0]?.data ?? '', darrer];
    if (rang.value === '3m' || rang.value === '12m') {
        const d = new Date(darrer);
        d.setMonth(d.getMonth() - (rang.value === '3m' ? 3 : 12));
        return [d.toISOString().slice(0, 10), darrer];
    }
    return [`${rang.value}-01-01`, `${rang.value}-12-31`];
});
const dins = (data: string) => data >= limits.value[0] && data <= limits.value[1];
const dies = computed(() => props.corbes.filter((d) => dins(d.data)));
const sortidesRang = computed(() => props.sortides.filter((s) => dins(s.data)).reverse());

const avui = computed(() => props.corbes[props.corbes.length - 1]);
/** El fons més alt de tot el que hi ha: la referència amb què comparar-se. */
const maximFons = computed(() => props.corbes.reduce((m, d) => (d.fons > m.fons ? d : m), props.corbes[0] ?? { data: '', fons: 0 } as Dia));
/** El fons de fa quatre setmanes, per dir cap on va. */
const fonsFa4 = computed(() => props.corbes[props.corbes.length - 29]?.fons ?? null);

const resum = computed(() => {
    const s = sortidesRang.value;
    return {
        sortides: s.length,
        senseCarrega: s.filter((x) => x.trimp === null).length,
        aproximades: s.filter((x) => x.metode === 'zones' || x.metode === 'mitjana').length,
        parcials: s.filter((x) => x.parcial).length,
    };
});

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
const zero = computed(() => (fosc.value ? 'rgba(255,255,255,0.35)' : 'rgba(0,0,0,0.35)'));
/** Els dos colors de sèrie del projecte: validats en clar i en fosc i per a daltonisme. */
const BLAU = '#0284c7';
const AMBRE = '#d97706';

const linia = (label: string, color: string, valors: Array<{ x: number; y: number }>) => ({
    label,
    data: valors,
    borderColor: color,
    backgroundColor: color,
    borderWidth: 2,
    pointRadius: 0,
    pointHoverRadius: 4,
    pointHitRadius: 6,
    tension: 0,
});

const dadesFons = computed<ChartData<'line'>>(() => ({
    datasets: [
        linia(`Fons (${props.constants.fons} dies)`, BLAU, dies.value.map((d) => ({ x: ms(d.data), y: d.fons }))),
        linia(`Fatiga (${props.constants.fatiga} dies)`, AMBRE, dies.value.map((d) => ({ x: ms(d.data), y: d.fatiga }))),
    ],
}));

/**
 * La frescor en percentatge del fons, que és com es pot llegir amb independència de l'escala de la càrrega:
 * el TRIMP d'Edwards no té les mateixes unitats que el TSS de TrainingPeaks, i les xifres absolutes no
 * es poden comparar amb les seves. Trams orientatius, els de la «forma» d'intervals.icu.
 */
const TRAMS_FRESCOR = [
    { des: 20, fins: Infinity, nom: 'Transició', text: 'massa descans: el fons es perd', color: 'rgba(217,119,6,0.14)' },
    { des: 5, fins: 20, nom: 'Fresc', text: 'per a una prova o una sortida exigent', color: 'rgba(2,132,199,0.14)' },
    { des: -10, fins: 5, nom: 'Neutre', text: 'manteniment: ni es carrega ni es descansa', color: 'rgba(107,114,128,0.10)' },
    { des: -30, fins: -10, nom: 'Entrenament òptim', text: 'la càrrega fa pujar el fons', color: 'rgba(22,163,74,0.14)' },
    { des: -Infinity, fins: -30, nom: 'Risc', text: 'massa càrrega seguida: sobreentrenament i lesions', color: 'rgba(220,38,38,0.14)' },
];
/** Amb un fons molt baix, el percentatge es dispara i no vol dir res. */
const FONS_MINIM = 10;
const percentFrescor = (d: Dia) => (d.fons >= FONS_MINIM ? Math.round((d.frescor / d.fons) * 100) : null);
const tramFrescor = (pct: number | null) => (pct === null ? null : TRAMS_FRESCOR.find((t) => pct >= t.des && pct < t.fins) ?? null);

const tintaLinia = computed(() => (fosc.value ? '#e5e7eb' : '#374151'));
const dadesFrescor = computed<ChartData<'line'>>(() => ({
    datasets: [linia('Frescor', tintaLinia.value, dies.value.map((d) => ({ x: ms(d.data), y: percentFrescor(d) as number })))],
}));

/** Pinta els trams de la frescor de fons, darrere la línia. */
const tramsDeFons: Plugin<'line'> = {
    id: 'tramsFrescor',
    beforeDatasetsDraw(chart: Chart) {
        const { ctx, chartArea: a, scales: { y } } = chart;
        ctx.save();
        for (const t of TRAMS_FRESCOR) {
            const dalt = Math.max(a.top, y.getPixelForValue(Math.min(t.fins, y.max)));
            const baix = Math.min(a.bottom, y.getPixelForValue(Math.max(t.des, y.min)));
            if (baix <= dalt) continue;
            ctx.fillStyle = t.color;
            ctx.fillRect(a.left, dalt, a.right - a.left, baix - dalt);
        }
        ctx.restore();
    },
};

/** La càrrega per setmana (de dilluns a diumenge). */
const setmanes = computed(() => {
    const perSetmana = new Map<string, number>();
    for (const d of dies.value) {
        const dia = new Date(d.data + 'T00:00:00');
        dia.setDate(dia.getDate() - ((dia.getDay() + 6) % 7));
        const clau = `${dia.getFullYear()}-${String(dia.getMonth() + 1).padStart(2, '0')}-${String(dia.getDate()).padStart(2, '0')}`;
        perSetmana.set(clau, (perSetmana.get(clau) ?? 0) + d.carrega);
    }
    return [...perSetmana.entries()];
});
const dadesSetmanes = computed<ChartData<'bar', Array<{ x: number; y: number }>>>(() => ({
    datasets: [{
        label: 'Càrrega de la setmana',
        data: setmanes.value.map(([d, c]) => ({ x: ms(d), y: Math.round(c) })),
        backgroundColor: BLAU,
        borderRadius: 2,
        barPercentage: 0.9,
        categoryPercentage: 1,
    }],
}));

const eixTemps = computed(() => ({
    type: 'time' as const,
    time: { unit: (rang.value === '3m' ? 'week' : 'month') as 'week' | 'month', displayFormats: { week: 'dd/MM', month: 'MMM yy' } },
    ticks: { color: tinta.value, maxRotation: 0, autoSkip: true, maxTicksLimit: 13 },
    grid: { color: quadricula.value },
}));

/** Les mateixes opcions per a les tres: eix de temps, i a la frescor la línia del zero marcada. */
function opcions(llegenda: boolean, ambZero = false): ChartOptions<'line'> {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: llegenda, position: 'top', align: 'end', labels: { color: tinta.value, boxWidth: 12 } },
            tooltip: {
                callbacks: {
                    title: (items: any[]) => formatData(new Date(Number(items[0]?.parsed.x)).toISOString().slice(0, 10)),
                    label: (c: any) => `${c.dataset.label}: ${nombre(Number(c.parsed.y))}`,
                },
            },
        },
        scales: {
            x: eixTemps.value,
            y: {
                ticks: { color: tinta.value },
                border: { display: false },
                grid: { color: (c: { tick: { value: number } }) => (ambZero && c.tick.value === 0 ? zero.value : quadricula.value) },
            },
        },
    };
}
const opcionsFons = computed(() => opcions(true));
const opcionsFrescor = computed(() => {
    const o = opcions(false, true);
    Object.assign(o.scales!.y!, { suggestedMin: -45, suggestedMax: 35, ticks: { color: tinta.value, callback: (v: number | string) => `${v} %` } });
    (o.plugins!.tooltip!.callbacks as any).label = (c: any) => {
        const d = dies.value[c.dataIndex];
        return `Frescor: ${c.parsed.y} % del fons (${nombre(d.frescor)}) · ${tramFrescor(c.parsed.y)?.nom ?? ''}`;
    };
    return o;
});
const avuiPct = computed(() => (avui.value ? percentFrescor(avui.value) : null));
/** Fatiga ÷ fons: per sobre d'1,5 la càrrega dels últims dies és molt més alta del que s'està acostumat. */
const quocient = computed(() => (avui.value && avui.value.fons >= FONS_MINIM ? avui.value.fatiga / avui.value.fons : null));
const opcionsSetmanes = computed(() => {
    const o = opcions(false) as ChartOptions<'bar'>;
    (o.plugins!.tooltip!.callbacks as any).title = (items: any[]) => 'Setmana del ' + formatData(new Date(Number(items[0]?.parsed.x)).toISOString().slice(0, 10));
    return o;
});

const durada = (minuts: number) => `${Math.floor(minuts / 60)}:${String(Math.round(minuts % 60)).padStart(2, '0')}`;
</script>

<template>
    <Head title="Estat de forma" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Estat de forma</h2>
                <div class="inline-flex rounded-md border border-gray-300 text-sm dark:border-gray-600">
                    <button v-for="[clau, text] in [...RANGS, ...anys.map((a) => [a, a] as [string, string])]" :key="clau" type="button" @click="rang = clau"
                        class="px-3 py-1 first:rounded-l-md last:rounded-r-md"
                        :class="rang === clau ? 'bg-rose-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                        {{ text }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-xl space-y-6 sm:px-6 lg:px-8">
                <!-- Fixa: sense els altres esports el resultat queda esbiaixat -->
                <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    <strong>Només compten les sortides en bici.</strong> La natació, les caminades, el ioga i la resta no
                    hi són, i sense ells la càrrega i el fons surten més baixos del que són, i la frescor, més alta: una
                    setmana sense bici però amb altre esport hi surt com si s'hagués descansat.
                </p>

                <!-- Avui -->
                <div v-if="avui" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Fons
                            <Info titol="Fons">
                                <p>La càrrega mitjana de les últimes sis setmanes, que oblida a poc a poc: és la forma que s'ha anat construint. Sense entrenar, en sis setmanes en queda poc més d'un terç.</p>
                                <p><strong>No té cap valor òptim absolut</strong>: depèn de l'escala de la càrrega i de cadascú. El que importa és com evoluciona i comparar-lo amb tu mateix: el teu més alt des de 2024 és <strong>{{ nombre(maximFons.fons) }}</strong> ({{ formatData(maximFons.data) }}).</p>
                                <p>Per pujar-lo sense fer-se mal, que pugi de mica en mica: com a orientació, no més d'un 5–8 % per setmana de manera seguida.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(avui.fons) }}</div>
                        <div v-if="fonsFa4 !== null" class="text-xs text-gray-400 dark:text-gray-500">
                            {{ avui.fons >= fonsFa4 ? '+' : '−' }}{{ nombre(Math.abs(avui.fons - fonsFa4)) }} en quatre setmanes
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Fatiga
                            <Info titol="Fatiga">
                                <p>La càrrega mitjana de l'última setmana: el cansament de fa pocs dies. Puja de pressa amb una sortida dura i baixa de pressa amb uns dies de descans.</p>
                                <p>Es llegeix al costat del fons. Com a orientació, <strong>fatiga ÷ fons entre 0,8 i 1,3</strong> és una càrrega a l'altura del que s'està acostumat; per sobre d'1,5 durant dies, la càrrega recent és molt més alta que la de fons, que és quan més fàcil és fer-se mal. És una referència discutida i no una regla.</p>
                                <p v-if="quocient !== null">Avui: <strong>{{ nombre(quocient, 2) }}</strong>.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(avui.fatiga) }}</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">
                            <template v-if="quocient !== null">{{ nombre(quocient, 2) }} vegades el fons</template>
                            <template v-else>la càrrega de l'última setmana, aproximadament</template>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center gap-1.5 text-xs uppercase text-gray-500 dark:text-gray-400">
                            Frescor
                            <Info titol="Frescor" costat="dreta">
                                <p>Fons − fatiga: si hi hagués una prova demà, com s'hi arribaria. Es diu en <strong>percentatge del fons</strong>, perquè així es pot llegir sense dependre de l'escala de la càrrega.</p>
                                <p>Els trams de la gràfica són orientatius.</p>
                            </Info>
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ avuiPct !== null ? avuiPct + ' %' : nombre(avui.frescor) }}
                        </div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">
                            <template v-if="tramFrescor(avuiPct)">{{ tramFrescor(avuiPct)!.nom }}: {{ tramFrescor(avuiPct)!.text }}</template>
                            <template v-else>fons − fatiga</template>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Fons i fatiga <span class="font-normal text-gray-400">càrrega per dia</span>
                        <Info titol="Fons i fatiga">
                            <p>Totes dues són mitjanes de la càrrega de cada dia que obliden a poc a poc: el <strong>fons</strong> en unes sis setmanes (la forma que es va construint), la <strong>fatiga</strong> en una (el cansament dels últims dies). És el model de Banister (1975), en la forma que fan servir TrainingPeaks i Strava.</p>
                            <p>Com es llegeix:</p>
                            <ul class="list-disc space-y-1 pl-4">
                                <li><strong>Fatiga per sobre del fons</strong>: s'està carregant més del que s'està acostumat, i el fons puja. Bé, si no dura gaire.</li>
                                <li><strong>Fatiga per sota</strong>: es descansa o s'entrena poc, i el fons baixa.</li>
                                <li>El que convé és una pujada del fons a poc a poc, amb setmanes més dures i altres de més suaus, i no salts.</li>
                            </ul>
                            <p>Els valors no es poden comparar amb els de TrainingPeaks o Strava: la càrrega aquí és el TRIMP d'Edwards, que té una altra escala.</p>
                        </Info>
                    </h3>
                    <div class="mt-2 h-72"><Line :data="dadesFons" :options="opcionsFons" /></div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Frescor <span class="font-normal text-gray-400">% del fons</span>
                        <Info titol="Frescor: què vol dir cada tram">
                            <p>(Fons − fatiga) ÷ fons, el matí de cada dia. Positiva: més descansat que la forma que es porta. Negativa: s'arrossega cansament.</p>
                            <p>Trams orientatius, els que fa servir intervals.icu:</p>
                            <ul class="space-y-1">
                                <li v-for="t in TRAMS_FRESCOR" :key="t.nom" class="flex gap-2">
                                    <span class="mt-0.5 inline-block h-3 w-3 shrink-0 rounded-sm border border-gray-300 dark:border-gray-600" :style="{ backgroundColor: t.color }"></span>
                                    <span><strong>{{ t.nom }}</strong> ({{ t.des === -Infinity ? 'menys de ' + t.fins : t.fins === Infinity ? 'més de +' + t.des : (t.des > 0 ? '+' : '') + t.des + ' a ' + (t.fins > 0 ? '+' : '') + t.fins }} %): {{ t.text }}.</span>
                                </li>
                            </ul>
                            <p>L'<strong>òptim depèn del moment</strong>: entre −10 i −30 % mentre s'entrena per millorar, i entre +5 i +20 % abans d'una sortida important. Gaires setmanes per sobre de +20 % vol dir que el fons s'està perdent.</p>
                            <p>Recorda la nota de dalt: sense els altres esports, la frescor surt més alta del que és.</p>
                        </Info>
                    </h3>
                    <div class="mt-2 h-56"><Line :data="dadesFrescor" :options="opcionsFrescor" :plugins="[tramsDeFons]" /></div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <span v-for="t in TRAMS_FRESCOR" :key="t.nom" class="inline-flex items-center gap-1.5">
                            <span class="inline-block h-3 w-3 rounded-sm border border-gray-300 dark:border-gray-600" :style="{ backgroundColor: t.color }"></span>{{ t.nom }}
                        </span>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Càrrega per setmana <span class="font-normal text-gray-400">TRIMP</span>
                        <Info titol="Càrrega per setmana">
                            <p>La suma de la càrrega de les sortides de cada setmana, de dilluns a diumenge.</p>
                            <p>No hi ha cap xifra ideal; el que se sol recomanar és la <strong>regularitat</strong>: que una setmana no sigui gaire més del doble de la mitjana de les anteriors, que la càrrega pugi de mica en mica (com a orientació, un 10 % per setmana com a molt) i que cada tres o quatre setmanes n'hi hagi una de més suau per assimilar-ho.</p>
                            <p>Una setmana buida pot ser de descans o d'un altre esport, que aquí no surt.</p>
                        </Info>
                    </h3>
                    <div class="mt-2 h-48"><Bar :data="dadesSetmanes" :options="opcionsSetmanes" /></div>
                </div>

                <!-- Sortides -->
                <div class="rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4">
                        <h3 class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Càrrega de cada sortida
                            <Info titol="TRIMP: la càrrega d'una sortida">
                                <p>Minuts a cada zona de pulsació × el número de la zona (Edwards, 1993). Les zones són percentatges de la pulsació màxima: 50–60 % = 1, 60–70 % = 2, 70–80 % = 3, 80–90 % = 4, 90 % i més = 5.</p>
                                <p>Per fer-se'n una idea: una hora suau a la zona 2 són 120; una hora a la zona 3, 180; una hora forta a la zona 4, 240. Una sortida llarga de BTT passa fàcilment de 400.</p>
                                <p>No hi ha valors bons o dolents d'una sortida: la càrrega només diu quant ha costat, i el que en surt és el fons i la fatiga.</p>
                            </Info>
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ resum.sortides }} sortides<template v-if="resum.aproximades"> · {{ resum.aproximades }} aproximades</template><template v-if="resum.parcials"> · {{ resum.parcials }} parcials</template><template v-if="resum.senseCarrega"> · {{ resum.senseCarrega }} sense pulsació, que no compten</template>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="mt-2 min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <th class="px-4 py-2 font-medium">Data</th>
                                    <th class="px-4 py-2 font-medium">Sortida</th>
                                    <th class="px-4 py-2 font-medium">Bici</th>
                                    <th class="px-4 py-2 text-right font-medium">Temps</th>
                                    <th class="px-4 py-2 text-right font-medium" title="Minuts a cada zona de pulsació (1 a 5)">Zones (min)</th>
                                    <th class="px-4 py-2 text-right font-medium">FC màx.</th>
                                    <th class="px-4 py-2 text-right font-medium">TRIMP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                                <tr v-for="s in sortidesRang" :key="s.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="whitespace-nowrap px-4 py-1.5 text-gray-700 dark:text-gray-300">{{ formatData(s.data) }}</td>
                                    <td class="px-4 py-1.5">
                                        <Link :href="route('bicis.sortida', s.id)" class="text-gray-900 hover:underline dark:text-gray-100">{{ s.nom ?? 'Sortida' }}</Link>
                                        <span v-if="s.rodets" class="ml-1 text-xs text-gray-400">rodets</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-gray-500 dark:text-gray-400">{{ s.bici }}</td>
                                    <td class="px-4 py-1.5 text-right text-gray-700 dark:text-gray-300">{{ s.minuts !== null ? durada(s.minuts) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-1.5 text-right text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="s.zones">{{ [1, 2, 3, 4, 5].map((z) => Math.round(s.zones![z] ?? 0)).join(' · ') }}</template>
                                    </td>
                                    <td class="px-4 py-1.5 text-right text-gray-700 dark:text-gray-300" :title="s.fc_maxima ? ORIGEN_FC[s.fc_maxima.origen] : ''">
                                        {{ s.fc_maxima?.valor ?? '—' }}<span v-if="s.fc_maxima?.origen === 'sortida'" class="text-amber-600">*</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-right font-medium text-gray-900 dark:text-gray-100">
                                        <template v-if="s.trimp !== null">{{ nombre(s.trimp) }}</template>
                                        <span v-else class="font-normal text-gray-400" title="Sense pulsació: no hi ha manera de saber-ne la càrrega">—</span>
                                        <span v-if="s.metode && METODE[s.metode].text" class="ml-1 rounded bg-gray-100 px-1 text-xs font-normal text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                            :title="METODE[s.metode].titol">{{ METODE[s.metode].text }}</span>
                                        <span v-if="s.parcial" class="ml-1 rounded bg-amber-100 px-1 text-xs font-normal text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                            title="Més d'un 20 % del temps sense pulsació: la càrrega es queda curta">parcial</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                        La càrrega és el <strong>TRIMP d'Edwards</strong>: minuts a cada zona de pulsació × el número de la zona
                        (50–60 % de la màxima = 1 … 90 % i més = 5). La pulsació màxima és la de Dades físiques o, si no n'hi
                        ha, la deduïda de les sortides; amb <span class="text-amber-600">*</span>, la sortida la va superar
                        durant 30 s i s'hi ha fet servir aquella.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
