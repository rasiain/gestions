<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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
}

interface Immoble {
    id: number;
    referencia_cadastral: string;
    adreca: string;
    superficie_construida: number | null;
    superficie_parcela: number | null;
    us: string | null;
    valor_sol: number | null;
    valor_construccio: number | null;
    valor_cadastral: number | null;
    valor_adquisicio: number | null;
    referencia_administracio: string | null;
    administrador_id: number | null;
    administrador: Proveidor | null;
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
    superficie_construida: null as number | null,
    superficie_parcela: null as number | null,
    us: null as string | null,
    valor_sol: null as number | null,
    valor_construccio: null as number | null,
    valor_adquisicio: null as number | null,
    referencia_administracio: '',
    administrador_id: null as number | null,
    propietari_ids: [] as number[],
    propietari_data_inici: [] as string[],
    propietari_data_fi: [] as (string | null)[],
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
    showModal.value = true;
};

const openEditModal = (immoble: Immoble) => {
    isEditing.value = true;
    editingImmoble.value = immoble;
    form.referencia_cadastral = immoble.referencia_cadastral;
    form.adreca = immoble.adreca;
    form.superficie_construida = immoble.superficie_construida;
    form.superficie_parcela = immoble.superficie_parcela;
    form.us = immoble.us;
    form.valor_sol = immoble.valor_sol;
    form.valor_construccio = immoble.valor_construccio;
    form.valor_adquisicio = immoble.valor_adquisicio;
    form.referencia_administracio = immoble.referencia_administracio || '';
    form.administrador_id = immoble.administrador_id;
    form.propietari_ids = immoble.propietaris.map(p => p.id);
    form.propietari_data_inici = immoble.propietaris.map(p => p.pivot.data_inici);
    form.propietari_data_fi = immoble.propietaris.map(p => p.pivot.data_fi || '');
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    isEditing.value = false;
    editingImmoble.value = null;
};

const submit = () => {
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
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
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
                                            {{ immoble.administrador?.nom_rao_social || '-' }}
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

                                <!-- Referència Administració -->
                                <div>
                                    <label
                                        for="referencia_administracio"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Ref. Administració
                                    </label>
                                    <input
                                        id="referencia_administracio"
                                        v-model="form.referencia_administracio"
                                        type="text"
                                        maxlength="50"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                </div>

                                <!-- Administrador -->
                                <div>
                                    <label
                                        for="administrador_id"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Administrador
                                    </label>
                                    <select
                                        id="administrador_id"
                                        v-model="form.administrador_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense administrador</option>
                                        <option v-for="proveidor in proveidors" :key="proveidor.id" :value="proveidor.id">
                                            {{ proveidor.nom_rao_social }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.administrador_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.administrador_id }}
                                    </p>
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
