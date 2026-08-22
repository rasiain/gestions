<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Patro {
    id: number;
    etiqueta: string;
    patro: string;
    actiu: boolean;
    ordre: number;
}

interface Ajust {
    objecte: string | null;
    poblacio: string | null;
    companyia: string | null;
    tipus: string | null;
    inclou: boolean;
    ocult: boolean;
}

/** On és la categoria: el compte on s'ha importat, per poder-hi anar a veure els moviments. */
interface CompteDelCami {
    categoria_id: number;
    compte_corrent_id: number | null;
    compte: string | null;
    digits: string | null;
    moviments: number;
}

interface Cami {
    cami: string;
    /** Quantes categories comparteixen aquest camí (una per compte que l'hagi importat). */
    categories: number;
    moviments: number;
    comptes: CompteDelCami[];
    detectada: boolean;
    tipus: string | null;
    objecte: string | null;
    poblacio: string | null;
    companyia: string | null;
    ajust: Ajust | null;
}

interface Candidat {
    cami: string;
    categories: number;
}

interface Props {
    patrons: Patro[];
    camins: Cami[];
    /** Any dels moviments que es compten. Null = tots. */
    any: number | null;
    cerca: string;
    candidats: Candidat[];
}

const props = defineProps<Props>();

// ---- Patrons ----
const nou = useForm({ etiqueta: '', patro: '', actiu: true, ordre: 0 });

function afegeixPatro() {
    nou.post(route('impostos.assegurances.patrons.store'), {
        preserveScroll: true,
        onSuccess: () => nou.reset(),
    });
}

function desaPatro(p: Patro) {
    router.put(route('impostos.assegurances.patrons.update', p.id), {
        etiqueta: p.etiqueta,
        patro: p.patro,
        actiu: p.actiu,
        ordre: p.ordre,
    }, { preserveScroll: true });
}

function toggleActiu(p: Patro) {
    router.put(route('impostos.assegurances.patrons.update', p.id), {
        etiqueta: p.etiqueta,
        patro: p.patro,
        actiu: !p.actiu,
        ordre: p.ordre,
    }, { preserveScroll: true });
}

function eliminaPatro(p: Patro) {
    if (!confirm(`Eliminar el patró "${p.patro}" (${p.etiqueta})?`)) return;
    router.delete(route('impostos.assegurances.patrons.destroy', p.id), { preserveScroll: true });
}

// ---- Ajustos ----
function normalitza(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const filtre = ref('');
const nomesAmbAjust = ref(false);
/** Un camí sense cap moviment de l'any no és el que s'està classificant: per defecte, fora. */
const amagaSenseMoviments = ref(true);

const anyOpcions = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);

function canviarAny(event: Event) {
    const valor = (event.target as HTMLSelectElement).value;
    router.get(route('impostos.assegurances.config'), { any: valor }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['camins', 'any'],
    });
}

/** Els moviments d'aquesta categoria en aquest compte, i de l'any que es mira. */
function enllacMoviments(c: CompteDelCami) {
    return route('moviments.index', {
        compte_corrent_id: c.compte_corrent_id,
        categoria_id: c.categoria_id,
        ...(props.any !== null
            ? { data_inici: `${props.any}-01-01`, data_fi: `${props.any}-12-31` }
            : {}),
    });
}

const caminsFiltrats = computed(() => {
    const cerca = normalitza(filtre.value.trim());
    return props.camins.filter((c) => {
        if (amagaSenseMoviments.value && c.moviments === 0) return false;
        if (nomesAmbAjust.value && !c.ajust) return false;
        if (cerca && !normalitza(c.cami).includes(cerca)) return false;
        return true;
    });
});

/** Quants queden fora només per no tenir cap moviment de l'any. */
const amagatsSenseMoviments = computed(() =>
    amagaSenseMoviments.value ? props.camins.filter((c) => c.moviments === 0).length : 0
);

const ajustForm = useForm({
    cami: '',
    objecte: '' as string | null,
    poblacio: '' as string | null,
    companyia: '' as string | null,
    tipus: '' as string | null,
    inclou: false,
    ocult: false,
});

const showAjust = ref(false);
const camiEditat = ref<Cami | null>(null);
/** Quantes categories tocarà el desat: el mateix camí pot ser a diversos comptes. */
const categoriesAfectades = ref(0);

function obreAjust(c: Cami) {
    camiEditat.value = c;
    categoriesAfectades.value = c.categories;
    ajustForm.clearErrors();
    ajustForm.cami = c.cami;
    ajustForm.objecte = c.ajust?.objecte ?? '';
    ajustForm.poblacio = c.ajust?.poblacio ?? '';
    ajustForm.companyia = c.ajust?.companyia ?? '';
    ajustForm.tipus = c.ajust?.tipus ?? '';
    ajustForm.inclou = c.ajust?.inclou ?? false;
    ajustForm.ocult = c.ajust?.ocult ?? false;
    showAjust.value = true;
}

function obreInclusio(c: Candidat) {
    camiEditat.value = null;
    categoriesAfectades.value = c.categories;
    ajustForm.clearErrors();
    ajustForm.cami = c.cami;
    ajustForm.objecte = '';
    ajustForm.poblacio = '';
    ajustForm.companyia = '';
    ajustForm.tipus = '';
    ajustForm.inclou = true;
    ajustForm.ocult = false;
    showAjust.value = true;
}

function desaAjust() {
    ajustForm.put(route('impostos.assegurances.ajustos.update'), {
        preserveScroll: true,
        onSuccess: () => (showAjust.value = false),
    });
}

function treuAjust() {
    if (!confirm("Vols treure l'ajust d'aquest camí?")) return;
    ajustForm.objecte = '';
    ajustForm.poblacio = '';
    ajustForm.companyia = '';
    ajustForm.tipus = '';
    ajustForm.inclou = false;
    ajustForm.ocult = false;
    desaAjust();
}

// ---- Cerca de camins per incloure (al servidor: l'arbre té milers de categories) ----
const cerca = ref(props.cerca);
let temporitzador: ReturnType<typeof setTimeout> | undefined;

function cerca_amb_pausa() {
    clearTimeout(temporitzador);
    temporitzador = setTimeout(() => {
        router.get(route('impostos.assegurances.config'), { cerca: cerca.value }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['candidats', 'cerca'],
        });
    }, 350);
}
</script>

<template>
    <Head title="Configuració Assegurances" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Configuració Assegurances — detecció i ajustos
                </h2>
                <Link :href="route('impostos.assegurances')" class="text-sm text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400">
                    ← Tornar a Assegurances
                </Link>
            </div>
        </template>

        <div class="space-y-8 py-12">
            <!-- Patrons -->
            <div class="mx-auto max-w-screen-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">Patrons de detecció</h3>
                        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                            Un moviment és d'una assegurança si <strong>algun node del camí</strong> de la seva categoria comença
                            una paraula amb un d'aquests patrons — no només la fulla, que sovint és la companyia
                            (<span class="font-mono text-xs">… &gt; ASSEGURANÇA &gt; SEGURCAIXA</span>). Val el node coincident
                            <strong>més alt</strong>, i la seva etiqueta és el nom de la fila. Entre dos patrons que encaixen amb
                            el mateix node, guanya el de <strong>menor ordre</strong>. La comparació ignora accents i majúscules.
                        </p>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300">Etiqueta (pòlissa visible)</th>
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300">Patró (inici de paraula)</th>
                                    <th class="w-20 pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300">Ordre</th>
                                    <th class="w-20 pb-3 pr-4 text-center font-medium text-gray-700 dark:text-gray-300">Actiu</th>
                                    <th class="w-32 pb-3 text-right font-medium text-gray-700 dark:text-gray-300">Accions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="p in props.patrons" :key="p.id">
                                    <td class="py-2 pr-4">
                                        <input v-model="p.etiqueta" @blur="desaPatro(p)"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model="p.patro" @blur="desaPatro(p)"
                                            class="block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model.number="p.ordre" @blur="desaPatro(p)" type="number" min="0"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4 text-center">
                                        <button @click="toggleActiu(p)"
                                            :class="p.actiu ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium">
                                            {{ p.actiu ? 'Sí' : 'No' }}
                                        </button>
                                    </td>
                                    <td class="py-2 text-right">
                                        <button @click="eliminaPatro(p)" class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">
                                            Eliminar
                                        </button>
                                    </td>
                                </tr>

                                <tr class="bg-gray-50 dark:bg-gray-700/40">
                                    <td class="py-2 pr-4">
                                        <input v-model="nou.etiqueta" placeholder="ex. Vehicle"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model="nou.patro" placeholder="ex. ASSEGURANÇA COTXE" @keyup.enter="afegeixPatro"
                                            class="block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model.number="nou.ordre" type="number" min="0"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4"></td>
                                    <td class="py-2 text-right">
                                        <button @click="afegeixPatro" :disabled="nou.processing || !nou.etiqueta || !nou.patro"
                                            class="rounded-md bg-red-600 px-3 py-1 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                                            Afegeix
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="nou.errors.etiqueta || nou.errors.patro" class="mt-2 text-sm text-red-600 dark:text-red-400">
                            {{ nou.errors.etiqueta || nou.errors.patro }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Ajustos -->
            <div class="mx-auto max-w-screen-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Ajustos manuals
                            <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">{{ props.camins.length }} camins</span>
                        </h3>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                            Aquí hi ha el que el detector ha resolt de cada camí de categoria. Si s'equivoca —el municipi que
                            l'arbre no diu, dues categories que són el mateix grup, una que no és cap pòlissa— es corregeix
                            aquí. L'ajust <strong>mana sobre l'arbre</strong> i s'aplica a totes les categories del mateix camí:
                            el mateix camí existeix a cada compte que l'hagi importat i vol dir el mateix a tots. Sota cada camí
                            hi ha els <strong>comptes</strong> on és, amb els moviments de l'any que es mira; clicant-hi s'obre la
                            llista de moviments d'aquella categoria en aquell compte, per comprovar-los.
                        </p>

                        <div class="mb-4 flex flex-wrap items-center gap-3">
                            <input v-model="filtre" type="search" placeholder="Filtra pel camí…"
                                class="w-72 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input v-model="amagaSenseMoviments" type="checkbox"
                                    class="rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700" />
                                Amaga els que no tenen cap moviment
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input v-model="nomesAmbAjust" type="checkbox"
                                    class="rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700" />
                                Només els que tenen ajust
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                Moviments de
                                <select :value="props.any === null ? 'tots' : String(props.any)" @change="canviarAny"
                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <option v-for="a in anyOpcions" :key="a" :value="String(a)">{{ a }}</option>
                                    <option value="tots">tots els anys</option>
                                </select>
                            </label>
                            <span class="ml-auto text-sm text-gray-500 dark:text-gray-400">
                                {{ caminsFiltrats.length }} camins
                                <span v-if="amagatsSenseMoviments" class="text-gray-400 dark:text-gray-500">
                                    · {{ amagatsSenseMoviments }} amagats sense moviments
                                </span>
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Camí de la categoria i comptes on és</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Mov. {{ props.any ?? '(tots)' }}
                                        </th>
                                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Pòlissa</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Objecte</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Població</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Companyia</th>
                                        <th class="w-24 px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Ajust</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="c in caminsFiltrats" :key="c.cami" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <td class="px-3 py-2">
                                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ c.cami }}</span>
                                            <span v-if="c.categories > 1" class="ml-2 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                                :title="c.categories + ' categories amb aquest camí, una per compte'">
                                                ×{{ c.categories }}
                                            </span>
                                            <span v-if="!c.detectada" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                                no detectada
                                            </span>
                                            <span v-if="c.ajust?.ocult" class="ml-2 rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-100 dark:bg-gray-900">
                                                oculta
                                            </span>
                                            <span v-if="c.ajust?.inclou" class="ml-2 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                                inclosa a mà
                                            </span>

                                            <!-- On és la categoria: cada compte porta als seus moviments -->
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <a v-for="q in c.comptes" :key="q.categoria_id" :href="enllacMoviments(q)"
                                                    :title="'Veure els moviments d\'aquesta categoria a ' + (q.compte ?? 'aquest compte')"
                                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs transition-colors"
                                                    :class="q.moviments > 0
                                                        ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300 dark:hover:bg-blue-900/70'
                                                        : 'bg-gray-50 text-gray-400 hover:bg-gray-100 dark:bg-gray-700/50 dark:text-gray-500 dark:hover:bg-gray-700'">
                                                    {{ q.compte ?? 'sense compte' }}
                                                    <span v-if="q.digits" class="font-mono tracking-widest opacity-60">····{{ q.digits }}</span>
                                                    <span class="font-semibold">{{ q.moviments }}</span>
                                                </a>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-gray-500 dark:text-gray-400">{{ c.moviments }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300">{{ c.tipus ?? '—' }}</td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ c.objecte ?? '—' }}</td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ c.poblacio ?? '—' }}</td>
                                        <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ c.companyia ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <button @click="obreAjust(c)"
                                                :class="c.ajust
                                                    ? 'text-red-600 hover:text-red-800 dark:text-red-400'
                                                    : 'text-gray-400 hover:text-red-600 dark:text-gray-500 dark:hover:text-red-400'"
                                                class="text-sm">
                                                {{ c.ajust ? 'Edita' : 'Ajusta' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="caminsFiltrats.length === 0">
                                        <td colspan="7" class="px-3 py-6 text-center text-sm text-gray-400 dark:text-gray-500">Cap camí amb aquest filtre.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inclusió manual -->
            <div class="mx-auto max-w-screen-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">Inclou una pòlissa que no es detecta</h3>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                            Hi ha assegurances que cap patró raonable no pot enganxar pel nom, com
                            <span class="font-mono text-xs">MUTUALITAT DELS ENGINYERS</span>. Cerca-hi el camí de la categoria i
                            inclou-la a mà: passarà a ser el node de la pòlissa, i el que hi pengi a sota en serà la companyia.
                        </p>

                        <input v-model="cerca" @input="cerca_amb_pausa" type="search" placeholder="Cerca al camí (mínim 3 lletres)…"
                            class="w-96 rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />

                        <ul v-if="props.candidats.length" class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
                            <li v-for="c in props.candidats" :key="c.cami" class="flex items-center justify-between gap-4 py-2">
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                    {{ c.cami }}
                                    <span v-if="c.categories > 1" class="ml-2 rounded-full bg-gray-100 px-1.5 py-0.5 text-gray-500 dark:bg-gray-700 dark:text-gray-400">×{{ c.categories }}</span>
                                </span>
                                <button @click="obreInclusio(c)" class="shrink-0 text-sm text-red-600 hover:text-red-800 dark:text-red-400">
                                    Inclou-la
                                </button>
                            </li>
                        </ul>
                        <p v-else-if="cerca.length >= 3" class="mt-4 text-sm text-gray-400 dark:text-gray-500">
                            Cap camí sense detectar que encaixi amb «{{ cerca }}».
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal d'ajust -->
        <Modal :show="showAjust" max-width="lg" @close="showAjust = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Ajust manual</h3>
                <p class="mb-1 font-mono text-xs text-gray-500 dark:text-gray-400">{{ ajustForm.cami }}</p>
                <p class="mb-4 text-xs text-gray-400 dark:text-gray-500">
                    S'aplicarà a {{ categoriesAfectades }}
                    {{ categoriesAfectades === 1 ? 'categoria' : 'categories' }} amb aquest camí.
                </p>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Objecte assegurat</label>
                            <input v-model="ajustForm.objecte" type="text" :placeholder="camiEditat?.objecte ?? 'el que digui l\'arbre'"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">És el nom del grup. Dos camins amb el mateix objecte i municipi es fonen en un.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Població</label>
                            <input v-model="ajustForm.poblacio" type="text" :placeholder="camiEditat?.poblacio ?? 'el que digui l\'arbre'"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">El municipi, no el nucli: és qui agrupa.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Companyia</label>
                            <input v-model="ajustForm.companyia" type="text" :placeholder="camiEditat?.companyia ?? 'la fulla de sota el node'"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Si el nom és mal escrit a l'arbre, val més reanomenar la categoria.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pòlissa (etiqueta de la fila)</label>
                            <input v-model="ajustForm.tipus" type="text" :placeholder="camiEditat?.tipus ?? 'la del patró'"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                    </div>

                    <label class="flex items-start gap-2">
                        <input v-model="ajustForm.inclou" type="checkbox" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            És una assegurança encara que cap patró no l'enganxi
                            <span class="block text-xs text-gray-400 dark:text-gray-500">Aquest camí passa a ser el node de la pòlissa.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2">
                        <input v-model="ajustForm.ocult" type="checkbox" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            No és cap pòlissa
                            <span class="block text-xs text-gray-400 dark:text-gray-500">Surt de la vista per pòlissa; els moviments continuen a la llista.</span>
                        </span>
                    </label>

                    <p v-if="ajustForm.errors.cami" class="text-sm text-red-600 dark:text-red-400">{{ ajustForm.errors.cami }}</p>
                </div>

                <div class="mt-6 flex justify-between gap-2">
                    <button v-if="camiEditat?.ajust" @click="treuAjust" :disabled="ajustForm.processing"
                        class="rounded-md px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                        Treu l'ajust
                    </button>
                    <div class="ml-auto flex gap-2">
                        <button @click="showAjust = false" :disabled="ajustForm.processing"
                            class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                            Cancel·la
                        </button>
                        <button @click="desaAjust" :disabled="ajustForm.processing"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                            Desa
                        </button>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
