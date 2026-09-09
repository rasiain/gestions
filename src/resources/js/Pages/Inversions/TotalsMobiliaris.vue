<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, registerables } from 'chart.js';
import type { ChartData, ChartOptions } from 'chart.js';

ChartJS.register(...registerables);

/** Com es reparteix una posició: qui, i quant li toca. */
interface Part {
    titular_id: number | null;
    nom: string;
    part: number;
}

/** Un compte, un contracte de fons, de pla de pensions o de renda fixa. */
interface Posicio {
    clau: string;
    font: Font;
    nom: string;
    detall: string | null;
    /** Avui només «lloguer», per als comptes que en són. */
    etiqueta: string | null;
    valor: number;
    parts: Part[];
    /** Què valia a final de cada mes, per «AAAA-MM». */
    serie: Record<string, number>;
}

interface Titular {
    id: number | null;
    nom: string;
}

type Font = 'comptes' | 'fons' | 'pensions' | 'renda_fixa';

interface Props {
    posicions: Posicio[];
    titulars: Titular[];
    /** Tots els mesos amb dades, del més antic al d'ara. */
    mesos: string[];
}

const props = defineProps<Props>();

const FONTS: { clau: Font; titol: string; descripcio: string }[] = [
    { clau: 'comptes',    titol: 'Comptes bancaris', descripcio: 'Saldo d\'avui dels comptes corrents' },
    { clau: 'fons',       titol: "Fons d'inversió",  descripcio: 'Participacions per la darrera cotització' },
    { clau: 'pensions',   titol: 'Plans de pensions', descripcio: 'Participacions per la darrera cotització' },
    { clau: 'renda_fixa', titol: 'Renda fixa',       descripcio: 'Darrer valor patrimonial declarat' },
];

const clauTitular = (id: number | null) => id ?? 'sense';

const round2 = (n: number) => Math.round(n * 100) / 100;

// ── La tria ──────────────────────────────────────────────────────
// Tot marcat d'entrada: la pantalla obre ensenyant el patrimoni sencer, i des d'allà
// es va traient el que no interessa, que és com se sol mirar.
const titularsTriats = ref<Set<number | string>>(new Set(props.titulars.map(t => clauTitular(t.id))));
const posicionsTriades = ref<Set<string>>(new Set(props.posicions.map(p => p.clau)));

// Es reemplaça el conjunt sencer i no es muta: un Set mutat no torna a pintar la vista.
const alternaTitular = (id: number | null) => {
    const nou = new Set(titularsTriats.value);
    const clau = clauTitular(id);
    nou.has(clau) ? nou.delete(clau) : nou.add(clau);
    titularsTriats.value = nou;
};

const alternaPosicio = (clau: string) => {
    const nou = new Set(posicionsTriades.value);
    nou.has(clau) ? nou.delete(clau) : nou.add(clau);
    posicionsTriades.value = nou;
};

/** Les posicions que toquen algun dels titulars triats: les altres no es veuen ni compten. */
const posicionsVisibles = computed(() =>
    props.posicions.filter(p => p.parts.some(part => titularsTriats.value.has(clauTitular(part.titular_id)))),
);

const posicionsDe = (font: Font) => posicionsVisibles.value.filter(p => p.font === font);

/** Una font està sencera, a mitges o buida: el capçal de cada bloc ho ensenya. */
const estatFont = (font: Font): 'tot' | 'parcial' | 'res' => {
    const claus = posicionsDe(font).map(p => p.clau);
    const triades = claus.filter(c => posicionsTriades.value.has(c)).length;
    if (triades === 0) return 'res';
    return triades === claus.length ? 'tot' : 'parcial';
};

const alternaFont = (font: Font) => {
    const claus = posicionsDe(font).map(p => p.clau);
    const nou = new Set(posicionsTriades.value);
    if (estatFont(font) === 'tot') claus.forEach(c => nou.delete(c));
    else claus.forEach(c => nou.add(c));
    posicionsTriades.value = nou;
};

const totsElsTitulars = () => {
    titularsTriats.value = new Set(props.titulars.map(t => clauTitular(t.id)));
};

const capTitular = () => {
    titularsTriats.value = new Set();
};

// ── El moment ────────────────────────────────────────────────────────────
// Cada posició porta què valia a final de cada mes. D'aquí surten els talls: els mesos
// tal qual, o un tall per any (el darrer mes de cada any, que de l'any en curs és el d'ara).
type Granularitat = 'mes' | 'any';

interface Tall {
    /** Identifica el tall a la tria: «2026-03» o «2026». */
    clau: string;
    etiqueta: string;
    /** El mes de la sèrie que s'hi llegeix. */
    mes: string;
}

const mesActual = computed(() => props.mesos[props.mesos.length - 1] ?? '');

const granularitat = ref<Granularitat>('any');

const nomMes = (mes: string) => {
    const [any, m] = mes.split('-').map(Number);
    return new Date(any, m - 1, 1).toLocaleDateString('ca-ES', { month: 'short', year: 'numeric' });
};

/** L'últim dia del mes, que és quan es fa el tall. */
const dataTall = (mes: string) => {
    const [any, m] = mes.split('-').map(Number);
    return new Date(any, m, 0).toLocaleDateString('ca-ES');
};

/** Tots els talls possibles amb la granularitat d'ara, del més antic al més nou. */
const talls = computed<Tall[]>(() => {
    if (granularitat.value === 'mes') {
        return props.mesos.map(mes => ({ clau: mes, etiqueta: nomMes(mes), mes }));
    }

    // De cada any, el seu darrer mes: el 31 de desembre, i de l'any en curs, avui
    const perAny = new Map<string, string>();
    for (const mes of props.mesos) perAny.set(mes.slice(0, 4), mes);

    return [...perAny.entries()].map(([any, mes]) => ({ clau: any, etiqueta: any, mes }));
});

// Quants talls s'ensenyen d'entrada: tots els anys hi caben, però dues-centes barres d'un
// mes cadascuna no es llegeixen.
const TALLS_INICIALS: Record<Granularitat, number> = { mes: 24, any: 100 };

const clauDe = (i: number) => talls.value[i]?.clau ?? '';

const desDe = ref('');
const finsA = ref('');
const tallTriat = ref('');

/** El rang de d'entrada, i el mateix cada cop que es canvia de granularitat. */
const rangPerDefecte = () => {
    const n = talls.value.length;
    desDe.value = clauDe(Math.max(0, n - TALLS_INICIALS[granularitat.value]));
    finsA.value = clauDe(n - 1);
    tallTriat.value = clauDe(n - 1);
};

rangPerDefecte();
watch(granularitat, rangPerDefecte);

/** Els talls del rang triat: són les barres de la gràfica. */
const tallsDelRang = computed(() => {
    const inici = talls.value.findIndex(t => t.clau === desDe.value);
    const fi = talls.value.findIndex(t => t.clau === finsA.value);
    if (inici < 0 || fi < 0 || fi < inici) return [];
    return talls.value.slice(inici, fi + 1);
});

// El tall no pot quedar fora del rang: si se n'hi va, s'arrossega a l'extrem
watch(tallsDelRang, rang => {
    if (rang.length && !rang.some(t => t.clau === tallTriat.value)) {
        tallTriat.value = rang[rang.length - 1].clau;
    }
});

/** Tornar a avui vol dir també eixamplar el rang fins ara, si s'havia quedat enrere. */
const tornaAAra = () => {
    finsA.value = clauDe(talls.value.length - 1);
    tallTriat.value = finsA.value;
};

const tallActual = computed(() => talls.value.find(t => t.clau === tallTriat.value) ?? null);

const mesDelTall = computed(() => tallActual.value?.mes ?? mesActual.value);

/** El tall és el mes que corre: el que s'hi veu és l'estat d'avui, no el d'un mes tancat. */
const tallEsAra = computed(() => mesDelTall.value === mesActual.value);

/** Què valia una posició al tall que es mira. */
const valorAlMes = (posicio: Posicio, mes: string) => posicio.serie[mes] ?? 0;

/**
 * El valor a parts iguals entre els titulars, amb el residu per a l'últim.
 *
 * És el mateix repartiment que fa el servidor amb el valor d'avui, repetit aquí perquè la
 * pantalla el pugui refer a qualsevol tall sense tornar-hi.
 */
const reparteix = (valor: number, parts: Part[]): number[] => {
    const parcials: number[] = [];
    let repartit = 0;

    parts.forEach((_, i) => {
        const part = i === parts.length - 1 ? round2(valor - repartit) : round2(valor / parts.length);
        repartit += part;
        parcials.push(part);
    });

    return parcials;
};

/** El total dels titulars i les posicions marcats a un mes qualsevol, i d'on surt. */
const totalsAlMes = (mes: string) => {
    const perFont: Record<string, number> = {};
    let total = 0;

    for (const posicio of props.posicions) {
        if (!posicionsTriades.value.has(posicio.clau)) continue;

        const parts = reparteix(valorAlMes(posicio, mes), posicio.parts);

        posicio.parts.forEach((part, i) => {
            if (!titularsTriats.value.has(clauTitular(part.titular_id))) return;

            perFont[posicio.font] = (perFont[posicio.font] ?? 0) + parts[i];
            total += parts[i];
        });
    }

    return { perFont, total: round2(total) };
};

/** El recompte del capçal parla del que es veu, no del que hi ha desat a la tria. */
const posicionsVisiblesTriades = computed(
    () => posicionsVisibles.value.filter(p => posicionsTriades.value.has(p.clau)).length,
);

const fontsAmbPosicions = computed(() => FONTS.filter(f => posicionsDe(f.clau).length > 0));

/** Les fonts que aporten alguna cosa a la tria: són les columnes de la taula. */
const fontsVisibles = computed(() =>
    fontsAmbPosicions.value.filter(f => posicionsDe(f.clau).some(p => posicionsTriades.value.has(p.clau))),
);

// ── Els números ──────────────────────────────────────────────────
interface FilaTitular {
    clau: number | string;
    nom: string;
    /** El total de cada font, per la seva clau. */
    perFont: Record<string, number>;
    total: number;
    posicions: { nom: string; detall: string | null; etiqueta: string | null; font: Font; valor: number; titulars: number; part: number }[];
}

const files = computed<FilaTitular[]>(() => {
    const perTitular = new Map<number | string, FilaTitular>();

    for (const posicio of props.posicions) {
        if (!posicionsTriades.value.has(posicio.clau)) continue;

        // Al tall d'ara la sèrie diu el mateix que el valor calculat pel servidor
        const valor = valorAlMes(posicio, mesDelTall.value);
        const parts = reparteix(valor, posicio.parts);

        posicio.parts.forEach((part, i) => {
            const clau = clauTitular(part.titular_id);
            if (!titularsTriats.value.has(clau)) return;

            if (!perTitular.has(clau)) {
                perTitular.set(clau, { clau, nom: part.nom, perFont: {}, total: 0, posicions: [] });
            }

            const fila = perTitular.get(clau)!;
            fila.perFont[posicio.font] = (fila.perFont[posicio.font] ?? 0) + parts[i];
            fila.total += parts[i];
            fila.posicions.push({
                nom: posicio.nom,
                detall: posicio.detall,
                etiqueta: posicio.etiqueta,
                font: posicio.font,
                valor,
                titulars: posicio.parts.length,
                part: parts[i],
            });
        });
    }

    return [...perTitular.values()]
        .map(f => ({ ...f, total: round2(f.total), posicions: f.posicions.sort((a, b) => b.part - a.part) }))
        .sort((a, b) => b.total - a.total);
});

const totalGeneral = computed(() => round2(files.value.reduce((suma, f) => suma + f.total, 0)));

const totalFont = (font: Font) => round2(files.value.reduce((suma, f) => suma + (f.perFont[font] ?? 0), 0));

/** Quant pesa un titular sobre el total triat. Amb el total a zero no vol dir res. */
const percentatge = (valor: number) =>
    totalGeneral.value === 0 ? null : (valor / totalGeneral.value) * 100;

const titolFont = (font: Font) => FONTS.find(f => f.clau === font)?.titol ?? font;

const formatEur = (n: number) =>
    new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(n);

const formatPercent = (n: number | null) =>
    n === null ? '—' : new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(n) + ' %';

// ── La gràfica ───────────────────────────────────────────────────────────
/**
 * Una barra per tall del rang, amb el total dels titulars marcats.
 *
 * És una sola sèrie a posta: qui vulgui veure què és de cadascú, que marqui un titular sol.
 */
const evolucio = computed(() =>
    tallsDelRang.value.map(t => {
        const { perFont, total } = totalsAlMes(t.mes);

        return { tall: t, perFont, total };
    }),
);

/**
 * El mateix, amb el que ha canviat respecte del període anterior.
 *
 * La variació és sempre contra la fila d'abans de la gràfica: si es miren anys, l'any
 * passat; si es miren mesos, el mes passat. El primer període no en té cap.
 */
const evolucioDetall = computed(() =>
    evolucio.value.map((e, i) => {
        const anterior = i > 0 ? evolucio.value[i - 1].total : null;

        return {
            ...e,
            variacio: anterior === null ? null : round2(e.total - anterior),
            percent: anterior === null || anterior === 0 ? null : ((e.total - anterior) / Math.abs(anterior)) * 100,
        };
    }),
);

/** La taula va del més recent al més antic, com la resta de taules; la gràfica, endavant. */
const evolucioTaula = computed(() => [...evolucioDetall.value].reverse());

const primerTotal = computed(() => evolucio.value[0]?.total ?? 0);
const ultimTotal  = computed(() => evolucio.value[evolucio.value.length - 1]?.total ?? 0);
const variacio    = computed(() => round2(ultimTotal.value - primerTotal.value));

/** Quant ha crescut al llarg del rang. Partint de zero no hi ha percentatge que valgui. */
const variacioPercent = computed(() =>
    primerTotal.value === 0 ? null : (variacio.value / Math.abs(primerTotal.value)) * 100,
);

/**
 * Els dos colors de sèrie, els mateixos que a les gràfiques dels vehicles: passen les
 * comprovacions de contrast i de daltonisme sobre fons clar i fosc.
 */
const SERIE_BLAU  = '#0284c7';
const SERIE_AMBRE = '#d97706';

/** Tailwind va per `prefers-color-scheme`: aquí també, que chart.js pinta en canvas. */
const fosc = ref(false);
let consultaFosc: MediaQueryList | null = null;

const anotaFosc = (e: MediaQueryListEvent | MediaQueryList) => {
    fosc.value = e.matches;
};

onMounted(() => {
    consultaFosc = window.matchMedia('(prefers-color-scheme: dark)');
    anotaFosc(consultaFosc);
    consultaFosc.addEventListener('change', anotaFosc);
});

onUnmounted(() => consultaFosc?.removeEventListener('change', anotaFosc));

const tinta      = computed(() => (fosc.value ? '#9ca3af' : '#6b7280'));
const quadricula = computed(() => (fosc.value ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'));

/** Milers i milions, que a l'eix no hi caben els cèntims. */
const formatEurCurt = (n: number) => {
    if (Math.abs(n) >= 1_000_000) return (n / 1_000_000).toFixed(1).replace('.', ',') + ' M€';
    if (Math.abs(n) >= 1_000) return Math.round(n / 1_000) + ' k€';
    return Math.round(n) + ' €';
};

const dadesEvolucio = computed<ChartData<'bar'>>(() => ({
    labels: evolucio.value.map(e => e.tall.etiqueta),
    datasets: [
        {
            label: 'Total',
            data: evolucio.value.map(e => e.total),
            // La barra del tall que mira la taula va en ambre: així es veu d'on surten els números
            backgroundColor: evolucio.value.map(e => (e.tall.clau === tallTriat.value ? SERIE_AMBRE : SERIE_BLAU)),
            borderWidth: 0,
            maxBarThickness: 48,
        },
    ],
}));

const opcionsEvolucio = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    // Clicar una barra és triar el moment que desglossa la taula de sota
    onClick: (_e, elements) => {
        const barra = elements[0];
        if (barra) tallTriat.value = evolucio.value[barra.index]?.tall.clau ?? tallTriat.value;
    },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => formatEur(ctx.parsed.y ?? 0),
            },
        },
    },
    scales: {
        x: {
            ticks: { color: tinta.value, maxRotation: 0, autoSkipPadding: 12 },
            grid: { display: false },
        },
        y: {
            beginAtZero: true,
            ticks: { color: tinta.value, callback: (v) => formatEurCurt(Number(v)) },
            grid: { color: quadricula.value },
        },
    },
}));

// El detall d'un titular: de quina posició ve cada part
const titularObert = ref<number | string | null>(null);

const obreDetall = (fila: FilaTitular) => {
    titularObert.value = titularObert.value === fila.clau ? null : fila.clau;
};
</script>

<template>
    <Head title="Totals mobiliaris" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Totals mobiliaris per titular</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full space-y-6 sm:px-6 lg:px-8">

                <!-- Què se suma -->
                <div class="rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Què se suma</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Cada posició es reparteix a parts iguals entre els titulars del seu compte. Treu la marca
                            del que no vulguis comptar: els números de sota es refan a l'instant.
                        </p>
                    </div>

                    <!-- Titulars -->
                    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <div class="mb-2 flex items-center gap-3">
                            <span class="text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Titulars</span>
                            <button @click="totsElsTitulars" class="text-xs text-green-700 hover:underline dark:text-green-400">Tots</button>
                            <button @click="capTitular" class="text-xs text-gray-500 hover:underline dark:text-gray-400">Cap</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="t in props.titulars"
                                :key="clauTitular(t.id)"
                                type="button"
                                @click="alternaTitular(t.id)"
                                :class="[
                                    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors',
                                    titularsTriats.has(clauTitular(t.id))
                                        ? 'border-green-600 bg-green-50 font-medium text-green-800 dark:border-green-500 dark:bg-green-900/30 dark:text-green-200'
                                        : 'border-gray-300 bg-white text-gray-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400',
                                ]"
                            >
                                <span class="w-3 text-center">{{ titularsTriats.has(clauTitular(t.id)) ? '✓' : '' }}</span>
                                {{ t.nom }}
                            </button>
                        </div>
                    </div>

                    <!-- Posicions, per font -->
                    <div class="grid gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="f in fontsAmbPosicions" :key="f.clau" class="bg-white p-4 dark:bg-gray-800">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500 dark:border-gray-600"
                                    :checked="estatFont(f.clau) === 'tot'"
                                    :indeterminate="estatFont(f.clau) === 'parcial'"
                                    @change="alternaFont(f.clau)"
                                />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ f.titol }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ f.descripcio }}</span>
                                </span>
                            </label>

                            <div class="mt-3 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-700">
                                <label
                                    v-for="p in posicionsDe(f.clau)"
                                    :key="p.clau"
                                    class="flex cursor-pointer items-baseline justify-between gap-2 text-xs"
                                >
                                    <span class="flex min-w-0 items-baseline gap-1.5">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-green-600 focus:ring-green-500 dark:border-gray-600"
                                            :checked="posicionsTriades.has(p.clau)"
                                            @change="alternaPosicio(p.clau)"
                                        />
                                        <span class="truncate" :class="posicionsTriades.has(p.clau) ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-500'">
                                            {{ p.nom }}
                                            <span v-if="p.etiqueta" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ p.etiqueta }}</span>
                                            <span v-if="p.detall" class="ml-1 text-gray-400 dark:text-gray-500">· {{ p.detall }}</span>
                                        </span>
                                    </span>
                                    <span class="shrink-0 tabular-nums" :class="posicionsTriades.has(p.clau) ? 'text-gray-600 dark:text-gray-400' : 'text-gray-300 dark:text-gray-600'">
                                        {{ formatEur(valorAlMes(p, mesDelTall)) }}
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- L'evolució -->
                <div class="rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Evolució del total</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                El que sumaven els titulars marcats al final de cada període. Clica una barra per veure
                                el desglossament d'aquell moment a la taula de sota.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Període</span>
                                <div class="inline-flex overflow-hidden rounded-md border border-gray-300 dark:border-gray-600">
                                    <button
                                        v-for="g in (['any', 'mes'] as const)"
                                        :key="g"
                                        type="button"
                                        @click="granularitat = g"
                                        :class="[
                                            'px-3 py-1.5 text-sm transition-colors',
                                            granularitat === g
                                                ? 'bg-green-600 font-medium text-white'
                                                : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
                                        ]"
                                    >{{ g === 'any' ? 'Anys' : 'Mesos' }}</button>
                                </div>
                            </div>

                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Des de</span>
                                <select v-model="desDe" class="rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                    <option v-for="t in talls" :key="t.clau" :value="t.clau">{{ t.etiqueta }}</option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Fins a</span>
                                <select v-model="finsA" class="rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                    <option v-for="t in talls" :key="t.clau" :value="t.clau">{{ t.etiqueta }}</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <div v-if="evolucio.length" class="px-6 py-4">
                        <div class="h-72">
                            <Bar :data="dadesEvolucio" :options="opcionsEvolucio" />
                        </div>

                        <div class="mt-4 flex flex-wrap items-baseline gap-x-8 gap-y-2 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ evolucio[0].tall.etiqueta }}:
                                <span class="font-medium tabular-nums text-gray-800 dark:text-gray-200">{{ formatEur(primerTotal) }}</span>
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ evolucio[evolucio.length - 1].tall.etiqueta }}:
                                <span class="font-medium tabular-nums text-gray-800 dark:text-gray-200">{{ formatEur(ultimTotal) }}</span>
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                Variació:
                                <span
                                    class="font-semibold tabular-nums"
                                    :class="variacio >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                                >
                                    {{ variacio >= 0 ? '+' : '' }}{{ formatEur(variacio) }}
                                    <template v-if="variacioPercent !== null">
                                        ({{ variacio >= 0 ? '+' : '' }}{{ formatPercent(variacioPercent) }})
                                    </template>
                                </span>
                            </span>
                        </div>

                        <!-- Els números de cada barra -->
                        <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <div class="mb-2 flex items-baseline justify-between gap-2">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Detall per període</h4>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    La variació és contra el període anterior de la llista
                                </span>
                            </div>

                            <div class="max-h-96 overflow-auto pr-4">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 bg-white dark:bg-gray-800">
                                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                            <th class="pb-2 pr-3 font-medium">Període</th>
                                            <th v-for="f in fontsVisibles" :key="f.clau" class="w-44 whitespace-nowrap px-3 pb-2 text-right font-medium">{{ f.titol }}</th>
                                            <th class="w-44 whitespace-nowrap px-3 pb-2 text-right font-medium">Total</th>
                                            <th class="w-44 whitespace-nowrap px-3 pb-2 text-right font-medium">Variació</th>
                                            <th class="w-28 whitespace-nowrap pb-2 pl-3 text-right font-medium">%</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr
                                            v-for="e in evolucioTaula"
                                            :key="e.tall.clau"
                                            @click="tallTriat = e.tall.clau"
                                            class="cursor-pointer"
                                            :class="e.tall.clau === tallTriat
                                                ? 'bg-amber-50 dark:bg-amber-900/20'
                                                : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'"
                                        >
                                            <td class="whitespace-nowrap py-1.5 pr-3 font-medium text-gray-900 dark:text-gray-100">
                                                {{ e.tall.etiqueta }}
                                                <span v-if="e.tall.mes === mesActual" class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">(avui)</span>
                                            </td>
                                            <td v-for="f in fontsVisibles" :key="f.clau" class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                                {{ e.perFont[f.clau] ? formatEur(round2(e.perFont[f.clau])) : '—' }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(e.total) }}</td>
                                            <td
                                                class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums"
                                                :class="e.variacio === null ? 'text-gray-300 dark:text-gray-600'
                                                    : e.variacio >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                                            >
                                                {{ e.variacio === null ? '—' : (e.variacio > 0 ? '+' : '') + formatEur(e.variacio) }}
                                            </td>
                                            <td
                                                class="whitespace-nowrap py-1.5 pl-3 text-right tabular-nums"
                                                :class="e.percent === null ? 'text-gray-300 dark:text-gray-600'
                                                    : e.percent >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                                            >
                                                {{ e.percent === null ? '—' : (e.percent > 0 ? '+' : '') + formatPercent(e.percent) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <p v-else class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        El rang triat no té cap període. Comprova que «Des de» va abans de «Fins a».
                    </p>
                </div>

                <!-- El total -->
                <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Total per titular
                            <span class="ml-1 font-normal text-gray-500 dark:text-gray-400">
                                <template v-if="tallEsAra">avui</template>
                                <template v-else>a {{ dataTall(mesDelTall) }}</template>
                            </span>
                            <button
                                v-if="!tallEsAra"
                                type="button"
                                @click="tornaAAra"
                                class="ml-2 text-xs font-normal text-green-700 hover:underline dark:text-green-400"
                            >torna a avui</button>
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ posicionsVisiblesTriades }} de {{ posicionsVisibles.length }} posicions,
                            {{ titularsTriats.size }} de {{ props.titulars.length }} titulars
                        </p>
                    </div>

                    <div v-if="files.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <th class="pb-2 font-medium">Titular</th>
                                    <th v-for="f in fontsVisibles" :key="f.clau" class="w-40 pb-2 text-right font-medium">{{ f.titol }}</th>
                                    <th class="w-40 pb-2 text-right font-medium">Total</th>
                                    <th class="w-20 pb-2 text-right font-medium">%</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template v-for="fila in files" :key="fila.clau">
                                    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40" @click="obreDetall(fila)">
                                        <td class="py-2 font-medium text-gray-900 dark:text-gray-100">
                                            <span class="mr-1 inline-block w-3 text-gray-400">{{ titularObert === fila.clau ? '▾' : '▸' }}</span>
                                            {{ fila.nom }}
                                        </td>
                                        <td v-for="f in fontsVisibles" :key="f.clau" class="py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                            {{ fila.perFont[f.clau] ? formatEur(round2(fila.perFont[f.clau])) : '—' }}
                                        </td>
                                        <td class="py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(fila.total) }}</td>
                                        <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ formatPercent(percentatge(fila.total)) }}</td>
                                    </tr>
                                    <!-- El detall: de quina posició ve cada part -->
                                    <tr v-if="titularObert === fila.clau" class="bg-gray-50 dark:bg-gray-700/30">
                                        <td :colspan="fontsVisibles.length + 3" class="px-3 py-2">
                                            <div v-for="(p, i) in fila.posicions" :key="i" class="flex items-baseline justify-between gap-4 py-0.5 text-xs">
                                                <span class="text-gray-600 dark:text-gray-400">
                                                    <span class="mr-1 text-gray-400 dark:text-gray-500">{{ titolFont(p.font) }} ·</span>
                                                    {{ p.nom }}
                                                    <span v-if="p.detall" class="ml-1 text-gray-400 dark:text-gray-500">({{ p.detall }})</span>
                                                    <span v-if="p.etiqueta" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ p.etiqueta }}</span>
                                                    <span v-if="p.titulars > 1" class="ml-1 text-gray-400 dark:text-gray-500">
                                                        {{ formatEur(p.valor) }} entre {{ p.titulars }}
                                                    </span>
                                                </span>
                                                <span class="shrink-0 tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(p.part) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="border-t-2 border-gray-200 dark:border-gray-600">
                                <tr class="font-bold">
                                    <td class="py-2 text-gray-900 dark:text-gray-100">Total</td>
                                    <td v-for="f in fontsVisibles" :key="f.clau" class="py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">
                                        {{ formatEur(totalFont(f.clau)) }}
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(totalGeneral) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        La tria d'ara no suma res. Marca algun titular i alguna posició.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
