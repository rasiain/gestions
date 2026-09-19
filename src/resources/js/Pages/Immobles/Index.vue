<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { num } from '@/formularis';

interface Persona {
    id: number;
    nom: string;
    cognoms: string;
}

interface Proveidor {
    id: number;
    nom_rao_social: string;
    nif_cif: string | null;
}

interface PropietariPivot {
    data_inici: string;
    data_fi: string | null;
    /** Percentatge de titularitat d'aquell tram; null vol dir a parts iguals. */
    quota: number | string | null;
}

/** Un tram d'administració: qui administrava l'immoble, entre quines dates. */
interface Administracio {
    id?: number;
    proveidor_id: number | null;
    proveidor?: string | null;
    /** Com identifica l'immoble l'empresa. */
    referencia: string | null;
    /** Comissió sobre la renda cobrada, sense IVA. */
    percentatge: number | string | null;
    /** Buida: des de sempre. */
    data_inici: string | null;
    /** Buida mentre és vigent. */
    data_fi: string | null;
}

interface Immoble {
    id: number;
    referencia_cadastral: string;
    adreca: string;
    poblacio: string | null;
    superficie_construida: number | null;
    superficie_parcela: number | null;
    us: string | null;
    valor_sol: number | null;
    valor_construccio: number | null;
    valor_cadastral: number | null;
    valor_adquisicio: number | null;
    administracions: Administracio[];
    administracio_vigent: Administracio | null;
    propietaris: (Persona & { pivot: PropietariPivot })[];
    created_at: string;
    updated_at: string;
}

interface Props {
    immobles: Immoble[];
    persones: Persona[];
    proveidors: Proveidor[];
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingImmoble = ref<Immoble | null>(null);

const usOptions = [
    { value: 'residencial', label: 'Residencial' },
    { value: 'oficines', label: 'Oficines' },
    { value: 'magatzem_estacionament', label: 'Magatzem/Estacionament' },
    { value: 'agrari', label: 'Agrari' },
];

const form = useForm({
    referencia_cadastral: '',
    adreca: '',
    poblacio: '' as string,
    superficie_construida: null as number | null,
    superficie_parcela: null as number | null,
    us: null as string | null,
    valor_sol: null as number | null,
    valor_construccio: null as number | null,
    valor_adquisicio: null as number | null,
    administracions: [] as Administracio[],
    propietari_ids: [] as number[],
    propietari_data_inici: [] as string[],
    propietari_data_fi: [] as (string | null)[],
    propietari_quota: [] as (number | string | null)[],
});

interface PropietariLocal {
    persona_id: number;
    nom: string;
    data_inici: string;
    /** Buida mentre la titularitat és vigent. */
    data_fi: string | null;
    /** Percentatge d'aquell tram; buit vol dir a parts iguals amb els altres. */
    quota: number | string | null;
}

const localPropietaris = ref<PropietariLocal[]>([]);
const nouPropietariId = ref<number | null>(null);

// Una persona hi pot sortir més d'un cop, amb un tram i una quota diferents cada vegada
const personesDisponibles = computed(() => props.persones);

const addPropietari = () => {
    if (!nouPropietariId.value) return;
    const persona = props.persones.find(p => p.id === nouPropietariId.value);
    if (!persona) return;
    localPropietaris.value.push({
        persona_id: persona.id,
        nom: persona.nom + ' ' + persona.cognoms,
        // El tram nou comença on acaba l'últim d'aquella persona, si n'hi havia cap
        data_inici: new Date().toISOString().split('T')[0],
        data_fi: null,
        quota: null,
    });
    nouPropietariId.value = null;
};

const removePropietari = (index: number) => {
    localPropietaris.value.splice(index, 1);
};

/** Els trams vigents avui han de sumar 100: si no, el repartiment no vol dir res. */
const quotaVigent = computed(() => {
    const avui = new Date().toISOString().split('T')[0];
    const vigents = localPropietaris.value.filter(p => p.data_inici <= avui && (!p.data_fi || p.data_fi >= avui));
    const amb = vigents.filter(p => num(p.quota) !== null);

    if (!amb.length) return null;

    return {
        suma: Math.round(amb.reduce((s, p) => s + (num(p.quota) ?? 0), 0) * 100) / 100,
        incompleta: amb.length !== vigents.length,
    };
});

const localAdministracions = ref<Administracio[]>([]);

const addAdministracio = () => {
    const darrera = localAdministracions.value[localAdministracions.value.length - 1];
    localAdministracions.value.push({
        // Un tram nou sol ser una empresa nova; la comissió, en canvi, sovint es manté
        proveidor_id: null,
        referencia: null,
        percentatge: darrera?.percentatge ?? null,
        data_inici: new Date().toISOString().split('T')[0],
        data_fi: null,
    });
};

const removeAdministracio = (index: number) => {
    localAdministracions.value.splice(index, 1);
};

/** L'error de validació d'un tram, si n'hi ha cap. */
const errorAdministracions = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.administracions
        ?? Object.entries(errors).find(([k]) => k.startsWith('administracions.'))?.[1]
        ?? null;
});

const valorCadastral = computed(() => {
    const sol = Number(form.valor_sol) || 0;
    const construccio = Number(form.valor_construccio) || 0;
    return sol + construccio;
});

const openCreateModal = () => {
    isEditing.value = false;
    editingImmoble.value = null;
    form.reset();
    localPropietaris.value = [];
    localAdministracions.value = [];
    nouPropietariId.value = null;
    showModal.value = true;
};

const openEditModal = (immoble: Immoble) => {
    isEditing.value = true;
    editingImmoble.value = immoble;
    form.referencia_cadastral = immoble.referencia_cadastral;
    form.adreca = immoble.adreca;
    form.poblacio = immoble.poblacio ?? '';
    form.superficie_construida = immoble.superficie_construida;
    form.superficie_parcela = immoble.superficie_parcela;
    form.us = immoble.us;
    form.valor_sol = immoble.valor_sol;
    form.valor_construccio = immoble.valor_construccio;
    form.valor_adquisicio = immoble.valor_adquisicio;
    localAdministracions.value = immoble.administracions.map(a => ({ ...a }));
    localPropietaris.value = immoble.propietaris.map(p => ({
        persona_id: p.id,
        nom: p.nom + ' ' + p.cognoms,
        data_inici: p.pivot.data_inici,
        data_fi: p.pivot.data_fi,
        quota: p.pivot.quota ?? null,
    }));
    nouPropietariId.value = null;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    localPropietaris.value = [];
    localAdministracions.value = [];
    isEditing.value = false;
    editingImmoble.value = null;
};

const submit = () => {
    form.propietari_ids = localPropietaris.value.map(p => p.persona_id);
    form.propietari_data_inici = localPropietaris.value.map(p => p.data_inici);
    // La data de fi que hi hagi: enviar-hi null sempre esborrava les titularitats tancades
    form.propietari_data_fi = localPropietaris.value.map(p => p.data_fi || null);
    form.propietari_quota = localPropietaris.value.map(p => num(p.quota));
    form.administracions = localAdministracions.value.map(a => ({
        proveidor_id: a.proveidor_id,
        referencia: a.referencia?.trim() || null,
        percentatge: num(a.percentatge),
        data_inici: a.data_inici || null,
        data_fi: a.data_fi || null,
    }));
    if (isEditing.value && editingImmoble.value) {
        form.put(route('immobles.update', editingImmoble.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('immobles.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteImmoble = (immoble: Immoble) => {
    if (confirm(`Estàs segur que vols eliminar l'immoble "${immoble.adreca}"?`)) {
        router.delete(route('immobles.destroy', immoble.id));
    }
};

const formatCurrency = (value: number | null): string => {
    if (value === null) return '-';
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
};

const formatNumber = (value: number | null, suffix: string = ''): string => {
    if (value === null) return '-';
    return `${value.toFixed(2)}${suffix}`;
};
</script>

<template>
    <Head title="Immobles" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Immobles
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Header with Add Button -->
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Llistat d'Immobles
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestiona els immobles i els seus propietaris
                                </p>
                            </div>
                            <button
                                @click="openCreateModal"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                <svg
                                    class="-ml-1 mr-2 h-5 w-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                    />
                                </svg>
                                Afegir Immoble
                            </button>
                        </div>

                        <!-- Table -->
                        <div v-if="immobles.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Referència Cadastral
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Adreça
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Ús
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Valor Cadastral
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Administrador
                                        </th>
                                        <th
                                            class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Accions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="immoble in immobles"
                                        :key="immoble.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ immoble.referencia_cadastral }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ immoble.adreca }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ usOptions.find(o => o.value === immoble.us)?.label || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(immoble.valor_cadastral) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            <template v-if="immoble.administracio_vigent">
                                                {{ immoble.administracio_vigent.proveidor }}
                                                <span v-if="immoble.administracio_vigent.referencia" class="block text-xs text-gray-500 dark:text-gray-400">
                                                    Ref. {{ immoble.administracio_vigent.referencia }}
                                                </span>
                                            </template>
                                            <template v-else>-</template>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                            <button
                                                @click="openEditModal(immoble)"
                                                class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteImmoble(immoble)"
                                                class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="py-12 text-center">
                            <svg
                                class="mx-auto h-12 w-12 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                No hi ha immobles
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Comença afegint el primer immoble.
                            </p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateModal"
                                    class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <svg
                                        class="-ml-1 mr-2 h-5 w-5"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                        />
                                    </svg>
                                    Afegir Immoble
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div
                    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    aria-hidden="true"
                    @click="closeModal"
                ></div>

                <!-- Center modal -->
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div
                    class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-2xl sm:align-middle"
                >
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3
                                class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100"
                                id="modal-title"
                            >
                                {{ isEditing ? 'Editar Immoble' : 'Nou Immoble' }}
                            </h3>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <!-- Referència Cadastral -->
                                <div class="sm:col-span-2">
                                    <label
                                        for="referencia_cadastral"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Referència Cadastral *
                                    </label>
                                    <input
                                        id="referencia_cadastral"
                                        v-model="form.referencia_cadastral"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.referencia_cadastral" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.referencia_cadastral }}
                                    </p>
                                </div>

                                <!-- Adreça -->
                                <div class="sm:col-span-2">
                                    <label
                                        for="adreca"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Adreça *
                                    </label>
                                    <input
                                        id="adreca"
                                        v-model="form.adreca"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.adreca" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.adreca }}
                                    </p>
                                </div>

                                <!-- Població -->
                                <div class="sm:col-span-2">
                                    <label
                                        for="poblacio"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Població
                                    </label>
                                    <input
                                        id="poblacio"
                                        v-model="form.poblacio"
                                        type="text"
                                        placeholder="Girona, Salt, Platja d'Aro…"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Serveix per agrupar els immobles a la vista de taxes.
                                    </p>
                                    <p v-if="form.errors.poblacio" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.poblacio }}
                                    </p>
                                </div>

                                <!-- Superfície Construïda -->
                                <div>
                                    <label
                                        for="superficie_construida"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Superfície Construïda (m²)
                                    </label>
                                    <input
                                        id="superficie_construida"
                                        v-model="form.superficie_construida"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- Superfície Parcel·la -->
                                <div>
                                    <label
                                        for="superficie_parcela"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Superfície Parcel·la (m²)
                                    </label>
                                    <input
                                        id="superficie_parcela"
                                        v-model="form.superficie_parcela"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- Ús -->
                                <div>
                                    <label
                                        for="us"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Ús
                                    </label>
                                    <select
                                        id="us"
                                        v-model="form.us"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona un ús</option>
                                        <option v-for="option in usOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Valor Sòl -->
                                <div>
                                    <label
                                        for="valor_sol"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Valor Sòl (€)
                                    </label>
                                    <input
                                        id="valor_sol"
                                        v-model="form.valor_sol"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- Valor Construcció -->
                                <div>
                                    <label
                                        for="valor_construccio"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Valor Construcció (€)
                                    </label>
                                    <input
                                        id="valor_construccio"
                                        v-model="form.valor_construccio"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- Valor Cadastral (calculat) -->
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Valor Cadastral (calculat)
                                    </label>
                                    <div class="mt-1 block w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300">
                                        {{ formatCurrency(valorCadastral) }}
                                    </div>
                                </div>

                                <!-- Valor Adquisició -->
                                <div>
                                    <label
                                        for="valor_adquisicio"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Valor Adquisició (€)
                                    </label>
                                    <input
                                        id="valor_adquisicio"
                                        v-model="form.valor_adquisicio"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>
                            </div>

                            <!-- Propietaris -->
                            <div class="sm:col-span-2 mt-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Propietaris
                                </label>

                                <!-- Llista de propietaris actuals -->
                                <p v-if="quotaVigent && (quotaVigent.suma !== 100 || quotaVigent.incompleta)"
                                    class="mb-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                                    <template v-if="quotaVigent.incompleta">
                                        Hi ha titulars vigents amb quota i altres sense: o les poses totes, o cap.
                                    </template>
                                    <template v-else>
                                        Les quotes vigents sumen {{ quotaVigent.suma }} % i haurien de sumar 100.
                                    </template>
                                </p>

                                <ul v-if="localPropietaris.length" class="mb-3 divide-y divide-gray-100 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-600">
                                    <li
                                        v-for="(p, i) in localPropietaris"
                                        :key="i"
                                        class="flex items-center justify-between px-3 py-2 text-sm text-gray-800 dark:text-gray-200"
                                    >
                                        <span class="min-w-0 flex-1 truncate">{{ p.nom }}</span>
                                        <label class="ml-3 flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            <input
                                                v-model="p.quota"
                                                type="text"
                                                inputmode="decimal"
                                                placeholder="a parts iguals"
                                                title="Percentatge de titularitat en aquest tram"
                                                class="w-28 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                            %
                                        </label>
                                        <!-- La data d'adquisició de la quota, no la d'alta del
                                             registre: el model 184 hi filtra per exercici. -->
                                        <label class="ml-3 flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            des de
                                            <input
                                                v-model="p.data_inici"
                                                type="date"
                                                class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                        </label>
                                        <label class="ml-2 flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            fins a
                                            <input
                                                v-model="p.data_fi"
                                                type="date"
                                                :min="p.data_inici"
                                                title="Deixa-ho buit mentre la titularitat sigui vigent"
                                                class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            @click="removePropietari(i)"
                                            class="ml-4 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                            title="Eliminar propietari"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </li>
                                </ul>
                                <p v-else class="mb-3 text-xs text-gray-400 dark:text-gray-500">Sense propietaris definits.</p>

                                <!-- Afegir propietari -->
                                <div class="flex gap-2">
                                    <select
                                        v-model="nouPropietariId"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Selecciona una persona…</option>
                                        <option v-for="p in personesDisponibles" :key="p.id" :value="p.id">
                                            {{ p.cognoms }}, {{ p.nom }}
                                        </option>
                                    </select>
                                    <button
                                        type="button"
                                        @click="addPropietari"
                                        :disabled="!nouPropietariId"
                                        class="shrink-0 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                    >
                                        Afegir
                                    </button>
                                </div>
                            </div>

                            <!-- Administració -->
                            <div class="sm:col-span-2 mt-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Administració
                                </label>
                                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                    L'empresa que l'administra, com l'identifica i la comissió que cobra. Quan canvia, tanca el tram anterior amb una data de fi i afegeix-ne un de nou: la comissió de cada cobrament es pren del tram del seu dia.
                                </p>

                                <p v-if="errorAdministracions" class="mb-2 text-sm text-red-600 dark:text-red-400">
                                    {{ errorAdministracions }}
                                </p>

                                <ul v-if="localAdministracions.length" class="mb-3 divide-y divide-gray-100 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-600">
                                    <li
                                        v-for="(a, i) in localAdministracions"
                                        :key="i"
                                        class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2 text-sm text-gray-800 dark:text-gray-200"
                                    >
                                        <select
                                            v-model="a.proveidor_id"
                                            class="min-w-0 flex-1 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        >
                                            <option :value="null">Selecciona l'empresa…</option>
                                            <option v-for="proveidor in proveidors" :key="proveidor.id" :value="proveidor.id">
                                                {{ proveidor.nom_rao_social }}
                                            </option>
                                        </select>
                                        <label class="flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            ref.
                                            <input
                                                v-model="a.referencia"
                                                type="text"
                                                maxlength="50"
                                                title="Identificador que l'administradora fa servir per a aquest immoble"
                                                class="w-24 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                        </label>
                                        <label class="flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            <input
                                                v-model="a.percentatge"
                                                type="text"
                                                inputmode="decimal"
                                                title="Comissió sobre la renda, sense IVA"
                                                class="w-14 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                            %
                                        </label>
                                        <label class="flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            des de
                                            <input
                                                v-model="a.data_inici"
                                                type="date"
                                                title="Deixa-ho buit si no se sap: vol dir des de sempre"
                                                class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                        </label>
                                        <label class="flex shrink-0 items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                            fins a
                                            <input
                                                v-model="a.data_fi"
                                                type="date"
                                                :min="a.data_inici ?? undefined"
                                                title="Deixa-ho buit mentre sigui vigent"
                                                class="rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            @click="removeAdministracio(i)"
                                            class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                            title="Eliminar tram"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </li>
                                </ul>
                                <p v-else class="mb-3 text-xs text-gray-400 dark:text-gray-500">Sense administradora.</p>

                                <button
                                    type="button"
                                    @click="addAdministracio"
                                    class="rounded-md border border-indigo-600 px-3 py-1.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50 dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-900/20"
                                >
                                    Afegir tram
                                </button>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
                            >
                                Cancel·lar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
