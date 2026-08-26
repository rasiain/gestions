<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Vehicle {
    id: number;
    nom: string;
    tipus: string;
    marca: string | null;
    model: string | null;
    matricula: string | null;
    any_fabricacio: number | null;
    data_alta: string | null;
    data_baixa: string | null;
    notes: string | null;
    ordre: number;
    /** Fals quan té data de baixa: continua al catàleg però ja no és nostre. */
    actiu: boolean;
}

interface Props {
    vehicles: Vehicle[];
    /** Tipus que admet aquesta llista: cotxe i moto, o bici. */
    tipus: string[];
    titol: string;
    descripcio: string;
    /** Fals a les bicis: ni matrícula ni any de fabricació. */
    esMotor: boolean;
}

const props = defineProps<Props>();

const etiquetaTipus: Record<string, string> = {
    cotxe: 'Cotxe',
    moto: 'Moto',
    bici: 'Bicicleta',
    altres: 'Altres',
};

const colorTipus: Record<string, string> = {
    cotxe: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    moto: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    bici: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    altres: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

const mostraBaixes = ref(false);

const visibles = computed(() =>
    mostraBaixes.value ? props.vehicles : props.vehicles.filter((v) => v.actiu)
);

const donatsDeBaixa = computed(() => props.vehicles.filter((v) => !v.actiu).length);

// ---- Alta i edició ----
const showModal = ref(false);
const editant = ref<Vehicle | null>(null);

const form = useForm({
    nom: '',
    tipus: props.tipus[0],
    marca: '',
    model: '',
    matricula: '',
    any_fabricacio: null as number | null,
    data_alta: '' as string | null,
    data_baixa: '' as string | null,
    notes: '',
    ordre: 0,
});

function obreNou() {
    editant.value = null;
    form.reset();
    form.tipus = props.tipus[0];
    form.clearErrors();
    showModal.value = true;
}

function obreEdicio(vehicle: Vehicle) {
    editant.value = vehicle;
    form.clearErrors();
    form.nom = vehicle.nom;
    form.tipus = vehicle.tipus;
    form.marca = vehicle.marca ?? '';
    form.model = vehicle.model ?? '';
    form.matricula = vehicle.matricula ?? '';
    form.any_fabricacio = vehicle.any_fabricacio;
    form.data_alta = vehicle.data_alta ?? '';
    form.data_baixa = vehicle.data_baixa ?? '';
    form.notes = vehicle.notes ?? '';
    form.ordre = vehicle.ordre;
    showModal.value = true;
}

function desa() {
    const opcions = { onSuccess: () => (showModal.value = false) };

    if (editant.value) {
        form.put(route('vehicles.update', editant.value.id), opcions);
        return;
    }

    form.post(route('vehicles.store'), opcions);
}

function elimina(vehicle: Vehicle) {
    if (!confirm(`Vols eliminar «${vehicle.nom}»?`)) return;
    router.delete(route('vehicles.destroy', vehicle.id));
}
</script>

<template>
    <Head :title="props.titol" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {{ props.titol }}
                </h2>
                <button
                    @click="obreNou"
                    class="inline-flex items-center rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-sky-700"
                >
                    Afegeix-ne un
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-lg sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ props.descripcio }}</p>
                            <label v-if="donatsDeBaixa" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input v-model="mostraBaixes" type="checkbox"
                                    class="rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700" />
                                Mostra els {{ donatsDeBaixa }} donats de baixa
                            </label>
                        </div>

                        <div v-if="visibles.length" class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Vehicle</th>
                                        <th v-if="props.tipus.length > 1" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                        <th v-if="props.esMotor" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Matrícula</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Des de</th>
                                        <th class="w-28 px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr v-for="v in visibles" :key="v.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40" :class="{ 'opacity-60': !v.actiu }">
                                        <td class="px-4 py-3">
                                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ v.nom }}</span>
                                            <span v-if="v.marca || v.model" class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                                {{ [v.marca, v.model].filter(Boolean).join(' ') }}
                                                <span v-if="v.any_fabricacio">· {{ v.any_fabricacio }}</span>
                                            </span>
                                            <span v-if="!v.actiu" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                de baixa {{ v.data_baixa }}
                                            </span>
                                        </td>
                                        <td v-if="props.tipus.length > 1" class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="colorTipus[v.tipus] ?? colorTipus.altres">
                                                {{ etiquetaTipus[v.tipus] ?? v.tipus }}
                                            </span>
                                        </td>
                                        <td v-if="props.esMotor" class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ v.matricula ?? '—' }}</td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ v.data_alta ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <button @click="obreEdicio(v)" class="text-sm text-sky-600 hover:text-sky-800 dark:text-sky-400">Edita</button>
                                            <button @click="elimina(v)" class="ml-3 text-sm text-red-600 hover:text-red-800 dark:text-red-400">Elimina</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p v-else class="rounded-lg border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                            Encara no n'hi ha cap. Comença per afegir-ne un.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="lg" @close="showModal = false">
            <div class="p-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editant ? 'Edita el vehicle' : 'Nou vehicle' }}
                </h3>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom</label>
                            <input v-model="form.nom" type="text" placeholder="p. ex. Peugeot Partner"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.nom }}</p>
                        </div>
                        <div v-if="props.tipus.length > 1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus</label>
                            <select v-model="form.tipus"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option v-for="t in props.tipus" :key="t" :value="t">{{ etiquetaTipus[t] ?? t }}</option>
                            </select>
                            <p v-if="form.errors.tipus" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.tipus }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Marca</label>
                            <input v-model="form.marca" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Model</label>
                            <input v-model="form.model" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div v-if="props.esMotor">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Matrícula</label>
                            <input v-model="form.matricula" type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Any de fabricació</label>
                            <input v-model.number="form.any_fabricacio" type="number" min="1900" max="2100"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p v-if="form.errors.any_fabricacio" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.any_fabricacio }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data d'alta</label>
                            <input v-model="form.data_alta" type="date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Data de baixa</label>
                            <input v-model="form.data_baixa" type="date" :min="form.data_alta || undefined"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Deixa-ho buit mentre sigui teu.</p>
                            <p v-if="form.errors.data_baixa" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.data_baixa }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                        <textarea v-model="form.notes" rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button @click="showModal = false" :disabled="form.processing"
                        class="rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                        Cancel·la
                    </button>
                    <button @click="desa" :disabled="form.processing"
                        class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-40">
                        Desa
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
