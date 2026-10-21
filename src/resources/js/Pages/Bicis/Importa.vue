<script setup lang="ts">
/**
 * Importa els FIT baixats de Garmin Connect («Export Original»): els ZIP o els .fit, d'un en un o
 * de cop. Completa les sortides sense recorregut i crea les que no hi són. Al costat, les sortides
 * que encara no en tenen, amb l'enllaç a Garmin Connect per baixar-ne l'original.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { xsrfToken } from '@/xsrf';

interface Resultat {
    fitxer: string;
    estat: 'afegit' | 'creada' | 'ja_hi_era' | 'cal_bici' | 'descartat' | 'error';
    missatge: string;
    sortida_id: number | null;
}

const props = defineProps<{
    bicis: { id: number; nom: string; actiu: boolean }[];
    senseRecorregut: { id: number; data: string; nom: string | null; bici: string | null; km: number | null; garmin: string | null }[];
}>();

const fitxers = ref<File[]>([]);
const bici = ref<number | null>(null);
const important = ref(false);
const error = ref<string | null>(null);
const resultats = ref<Resultat[] | null>(null);
const recalculant = ref(false);
const sobre = ref(false);

function afegeix(llista: FileList | null | undefined) {
    if (!llista) return;
    const nous = [...llista].filter((f) => /\.(fit|zip)$/i.test(f.name));
    const ignorats = llista.length - nous.length;
    error.value = ignorats ? `${ignorats} fitxers no són .fit ni .zip i no s'hi han afegit.` : null;
    // Sense repetits: el mateix nom no s'hi posa dos cops
    const noms = new Set(fitxers.value.map((f) => f.name));
    fitxers.value = [...fitxers.value, ...nous.filter((f) => !noms.has(f.name))];
}

function deixa(e: DragEvent) {
    sobre.value = false;
    afegeix(e.dataTransfer?.files);
}

async function importa() {
    // El botó no es deshabilita: si falta alguna cosa, es diu què
    if (!fitxers.value.length) {
        error.value = 'Tria primer algun fitxer .fit o .zip.';
        return;
    }
    important.value = true;
    error.value = null;

    const dades = new FormData();
    fitxers.value.forEach((f) => dades.append('fitxers[]', f));
    if (bici.value !== null) dades.append('vehicle_id', String(bici.value));

    const res = await fetch(route('bicis.importa.store'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: dades,
    });
    const json = await res.json().catch(() => ({}));
    important.value = false;

    if (!res.ok) {
        error.value = res.status === 419
            ? 'La sessió ha caducat. Recarrega la pàgina i torna-ho a provar.'
            : (Object.values(json.errors ?? {}).flat()[0] as string | undefined) ?? json.message ?? `No s'ha pogut importar (error ${res.status}).`;
        return;
    }

    resultats.value = json.resultats;
    recalculant.value = json.recalculant;
    fitxers.value = [];
    router.reload({ only: ['senseRecorregut'] });
}

const ESTATS: Record<Resultat['estat'], { etiqueta: string; classe: string }> = {
    afegit: { etiqueta: 'Recorregut afegit', classe: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    creada: { etiqueta: 'Sortida nova', classe: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' },
    ja_hi_era: { etiqueta: 'Ja hi era', classe: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
    cal_bici: { etiqueta: 'Cal la bici', classe: 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
    descartat: { etiqueta: 'Descartat', classe: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
    error: { etiqueta: 'Error', classe: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
};

const calBici = computed(() => (resultats.value ?? []).some((r) => r.estat === 'cal_bici'));
const nombre = (v: number | null, d = 1) => (v === null ? '—' : v.toLocaleString('ca-ES', { minimumFractionDigits: d, maximumFractionDigits: d }));
const mida = (bytes: number) => (bytes < 1048576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`);
</script>

<template>
    <Head title="Importa sortides" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('bicis.index')" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Importa sortides de Garmin</h2>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <!-- Pujar fitxers -->
                <div class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                            A Garmin Connect, obre l'activitat i tria ⚙ → <strong>Export Original</strong>. Deixa aquí el ZIP o el
                            <code>.fit</code> que baixa —d'un en un o molts de cop—, sense canviar-ne el nom: porta l'identificador
                            de l'activitat.
                        </p>

                        <label @dragover.prevent="sobre = true" @dragleave.prevent="sobre = false" @drop.prevent="deixa"
                            class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 text-center text-sm transition-colors"
                            :class="sobre ? 'border-sky-500 bg-sky-50 dark:bg-sky-900/20' : 'border-gray-300 text-gray-500 hover:border-sky-400 dark:border-gray-600 dark:text-gray-400'">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Arrossega aquí els fitxers</span>
                            <span class="text-xs">o clica per triar-los (.fit o .zip)</span>
                            <input type="file" multiple accept=".fit,.zip" class="hidden" @change="afegeix(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />
                        </label>

                        <ul v-if="fitxers.length" class="mt-3 max-h-40 space-y-0.5 overflow-y-auto text-sm">
                            <li v-for="(f, i) in fitxers" :key="f.name" class="flex items-center justify-between gap-2">
                                <span class="truncate text-gray-800 dark:text-gray-200">{{ f.name }} <span class="text-xs text-gray-400">{{ mida(f.size) }}</span></span>
                                <button type="button" @click="fitxers.splice(i, 1)" class="text-xs text-red-600 hover:text-red-800 dark:text-red-400">treu</button>
                            </li>
                        </ul>

                        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                            <label class="text-xs text-gray-500 dark:text-gray-400">
                                Bici per a les sortides que no hi són
                                <select v-model="bici" class="mt-1 block rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                    <option :value="null">— no les creïs —</option>
                                    <option v-for="b in bicis" :key="b.id" :value="b.id">{{ b.nom }}{{ b.actiu ? '' : ' (de baixa)' }}</option>
                                </select>
                            </label>
                            <button type="button" @click="importa"
                                class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-sky-700">
                                {{ important ? 'Important…' : `Importa${fitxers.length ? ` (${fitxers.length})` : ''}` }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            Les que ja hi són i no tenen recorregut el reben; les que ja en tenen no es toquen. Les noves es creen amb
                            el resum del dispositiu i amb els components muntats a la bici aquell dia.
                        </p>
                        <p v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                    </div>

                    <!-- Resultat -->
                    <div v-if="resultats" class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Resultat</h3>
                        <p v-if="!resultats.length" class="text-sm text-gray-500 dark:text-gray-400">Els fitxers no portaven cap activitat.</p>
                        <ul class="space-y-1 text-sm">
                            <li v-for="r in resultats" :key="r.fitxer + r.missatge" class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="ESTATS[r.estat].classe">{{ ESTATS[r.estat].etiqueta }}</span>
                                <span class="text-gray-800 dark:text-gray-200">{{ r.fitxer }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ r.missatge }}</span>
                                <Link v-if="r.sortida_id" :href="route('bicis.sortida', r.sortida_id)" class="text-sky-700 hover:underline dark:text-sky-400">obre</Link>
                            </li>
                        </ul>
                        <p v-if="calBici" class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                            Per importar les sortides noves, tria la bici i torna a pujar-ne els fitxers.
                        </p>
                        <p v-if="recalculant" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Les rutes s'estan tornant a calcular en segon pla: en un parell de minuts ja hi seran.
                        </p>
                    </div>
                </div>

                <!-- Les que encara no tenen recorregut -->
                <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Sortides sense recorregut <span class="font-normal text-gray-400">({{ senseRecorregut.length }})</span>
                    </h3>
                    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                        L'exportació de Garmin en va portar el resum però no els punts. Des de l'enllaç se'n pot baixar l'original.
                        De les més llargues a les més curtes.
                    </p>
                    <div class="max-h-[36rem] overflow-y-auto">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="s in senseRecorregut" :key="s.id">
                                    <td class="whitespace-nowrap py-1 pr-3 tabular-nums text-gray-600 dark:text-gray-400">{{ s.data }}</td>
                                    <td class="py-1 pr-3">
                                        <Link :href="route('bicis.sortida', s.id)" class="text-gray-900 hover:underline dark:text-gray-100">{{ s.nom ?? 'Sortida' }}</Link>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ s.bici }}</span>
                                    </td>
                                    <td class="whitespace-nowrap py-1 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(s.km) }} km</td>
                                    <td class="whitespace-nowrap py-1 text-right">
                                        <a v-if="s.garmin" :href="s.garmin" target="_blank" rel="noopener" class="text-xs text-sky-700 hover:underline dark:text-sky-400">Garmin Connect ↗</a>
                                    </td>
                                </tr>
                                <tr v-if="!senseRecorregut.length">
                                    <td class="py-4 text-center text-xs text-gray-400">Totes les sortides tenen recorregut.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
