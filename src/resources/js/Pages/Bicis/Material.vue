<script setup lang="ts">
/**
 * El material esportiu que no és una bici: components, sabates i la resta. Ve de Garmin, però
 * s'hi poden afegir peces pròpies i corregir les que hi ha. Les bicis es porten a /bicis.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Peca {
    id: number;
    nom: string;
    model: string | null;
    tipus: string;
    estat: string;
    data_inici: string | null;
    data_fi: string | null;
    muntat_a_vehicle_id: number | null;
    muntat_a: string | null;
    origen: string;
    /** Una peça de Garmin corregida a mà: la importació ja no en toca les dades. */
    editat: boolean;
    sortides: number;
    km: number;
}

const props = defineProps<{
    peces: Peca[];
    bicis: { id: number; nom: string }[];
    tipus: string[];
}>();

const ETIQUETA_TIPUS: Record<string, string> = { component: 'Components', sabates: 'Sabates', altres: 'Altres' };
const ETIQUETA_TIPUS_SINGULAR: Record<string, string> = { component: 'Component', sabates: 'Sabates', altres: 'Altres' };

const mostraRetirades = ref(false);
const retirades = computed(() => props.peces.filter((p) => p.estat === 'retirat').length);

const perTipus = computed(() =>
    props.tipus
        .map((t) => [t, props.peces.filter((p) => p.tipus === t && (mostraRetirades.value || p.estat !== 'retirat'))] as const)
        .filter(([, llista]) => llista.length),
);

const nombre = (v: number) => v.toLocaleString('ca-ES');

// ---- Alta i edició ----
const showModal = ref(false);
const editant = ref<Peca | null>(null);

const form = useForm({
    nom: '',
    model: '',
    tipus: 'component',
    estat: 'actiu',
    data_inici: '' as string | null,
    data_fi: '' as string | null,
    muntat_a_vehicle_id: null as number | null,
});

function obreNova() {
    editant.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function obreEdicio(p: Peca) {
    editant.value = p;
    form.clearErrors();
    Object.assign(form, {
        nom: p.nom,
        model: p.model ?? '',
        tipus: p.tipus,
        estat: p.estat,
        data_inici: p.data_inici ?? '',
        data_fi: p.data_fi ?? '',
        muntat_a_vehicle_id: p.muntat_a_vehicle_id,
    });
    showModal.value = true;
}

function desa() {
    const opcions = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editant.value) {
        form.put(route('bicis.material.update', editant.value.id), opcions);
    } else {
        form.post(route('bicis.material.store'), opcions);
    }
}

// ---- Esborrar: només les pròpies sense sortides ----
const errorEsborrar = computed(() => (usePage().props.errors as Record<string, string>).esborrar ?? null);
const esborrable = (p: Peca) => p.origen === 'manual' && p.sortides === 0;

function esborra(p: Peca) {
    if (!confirm(`Vols esborrar «${p.nom}»?`)) return;
    router.delete(route('bicis.material.destroy', p.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Material esportiu" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('bicis.index')" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">← Bicis</Link>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Material esportiu</h2>
                </div>
                <button @click="obreNova"
                    class="inline-flex items-center rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-sky-700">
                    Afegeix una peça
                </button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-full space-y-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-0">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Components, sabates i la resta de material. Els km són els de les sortides on es va fer servir.
                    </p>
                    <label v-if="retirades" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input v-model="mostraRetirades" type="checkbox" class="rounded border-gray-300 text-sky-600 dark:border-gray-600 dark:bg-gray-700" />
                        Mostra les retirades ({{ retirades }})
                    </label>
                </div>

                <p v-if="errorEsborrar" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ errorEsborrar }}</p>

                <div v-for="[tipus, llista] in perTipus" :key="tipus" class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <h3 class="border-b border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">
                        {{ ETIQUETA_TIPUS[tipus] ?? tipus }}
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                                    <th class="px-4 py-2 font-medium">Peça</th>
                                    <th class="px-4 py-2 font-medium">Des de</th>
                                    <th class="px-4 py-2 font-medium">Fins a</th>
                                    <th class="px-4 py-2 font-medium">Muntat a</th>
                                    <th class="px-4 py-2 text-right font-medium">Sortides</th>
                                    <th class="px-4 py-2 text-right font-medium">Km</th>
                                    <th class="w-32 px-4 py-2 text-right font-medium">Accions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="p in llista" :key="p.id" :class="{ 'opacity-60': p.estat === 'retirat' }">
                                    <td class="px-4 py-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ p.nom }}</span>
                                        <span v-if="p.model && p.model !== p.nom" class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ p.model }}</span>
                                        <span v-if="p.estat === 'retirat'" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-400">retirada</span>
                                        <span v-if="p.origen === 'manual'" class="ml-2 rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">pròpia</span>
                                        <span v-else-if="p.editat" class="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                                            title="Corregida a mà: una importació nova de Garmin ja no en canviarà les dades">corregida</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 tabular-nums text-gray-600 dark:text-gray-400">{{ p.data_inici ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 tabular-nums text-gray-600 dark:text-gray-400">{{ p.data_fi ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ p.muntat_a ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ nombre(p.sortides) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ nombre(p.km) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right">
                                        <button @click="obreEdicio(p)" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">Edita</button>
                                        <button v-if="esborrable(p)" @click="esborra(p)" class="ml-3 text-sm text-red-600 hover:text-red-800 dark:text-red-400">Elimina</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="lg" @close="showModal = false">
            <div class="p-6">
                <h3 class="mb-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editant ? 'Edita la peça' : 'Nova peça' }}
                </h3>
                <p v-if="editant && editant.origen !== 'manual'" class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                    Ve de Garmin. Un cop desada, una importació nova ja no en canviarà el nom, el model, l'estat ni les dates.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom</label>
                        <input v-model="form.nom" type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.nom }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Model</label>
                        <input v-model="form.model" type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                        <select v-model="form.tipus"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option v-for="t in props.tipus" :key="t" :value="t">{{ ETIQUETA_TIPUS_SINGULAR[t] ?? t }}</option>
                        </select>
                        <p v-if="form.errors.tipus" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.tipus }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Estat</label>
                        <select v-model="form.estat"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="actiu">En ús</option>
                            <option value="retirat">Retirada</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Des de</label>
                        <input v-model="form.data_inici" type="date"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Fins a</label>
                        <input v-model="form.data_fi" type="date" :min="form.data_inici || undefined"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        <p v-if="form.errors.data_fi" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.data_fi }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Muntat a</label>
                        <select v-model="form.muntat_a_vehicle_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option :value="null">— cap bici —</option>
                            <option v-for="b in props.bicis" :key="b.id" :value="b.id">{{ b.nom }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">La bici on va muntada. Les sabates o el casc, que van amb tu, no en tenen.</p>
                        <p v-if="form.errors.muntat_a_vehicle_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.muntat_a_vehicle_id }}</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showModal = false" :disabled="form.processing"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Cancel·la
                    </button>
                    <button @click="desa"
                        class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
