<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Proveidor {
    id: number;
    nom_rao_social: string;
    nif_cif: string | null;
    adreca: string | null;
    correu_electronic: string | null;
    telefons: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    proveidors: Proveidor[];
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingProveidor = ref<Proveidor | null>(null);

const form = useForm({
    nom_rao_social: '',
    nif_cif: '',
    adreca: '',
    correu_electronic: '',
    telefons: '',
});

const openCreateModal = () => {
    isEditing.value = false;
    editingProveidor.value = null;
    form.reset();
    showModal.value = true;
};

const openEditModal = (proveidor: Proveidor) => {
    isEditing.value = true;
    editingProveidor.value = proveidor;
    form.nom_rao_social = proveidor.nom_rao_social;
    form.nif_cif = proveidor.nif_cif || '';
    form.adreca = proveidor.adreca || '';
    form.correu_electronic = proveidor.correu_electronic || '';
    form.telefons = proveidor.telefons || '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (isEditing.value && editingProveidor.value) {
        form.put(route('proveidors.update', editingProveidor.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('proveidors.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

const deleteProveidor = (proveidor: Proveidor) => {
    if (confirm(`Esteu segur que voleu eliminar el proveïdor "${proveidor.nom_rao_social}"?`)) {
        router.delete(route('proveidors.destroy', proveidor.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Proveïdors" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Proveïdors
                </h2>
                <button
                    @click="openCreateModal"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                >
                    Afegir Proveïdor
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Nom o Raó Social
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            NIF/CIF
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Adreça
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Correu Electrònic
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Telèfons
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Accions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr v-for="proveidor in proveidors" :key="proveidor.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ proveidor.nom_rao_social }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ proveidor.nif_cif || '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ proveidor.adreca || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ proveidor.correu_electronic || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ proveidor.telefons || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                            <button
                                                @click="openEditModal(proveidor)"
                                                class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteProveidor(proveidor)"
                                                class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="proveidors.length === 0">
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                            No hi ha proveïdors registrats
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="closeModal"></div>

                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mt-3 w-full text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100" id="modal-title">
                                        {{ isEditing ? 'Editar Proveïdor' : 'Afegir Proveïdor' }}
                                    </h3>
                                    <div class="mt-4 space-y-4">
                                        <div>
                                            <label for="nom_rao_social" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Nom o Raó Social <span class="text-red-500">*</span>
                                            </label>
                                            <input
                                                id="nom_rao_social"
                                                v-model="form.nom_rao_social"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                                required
                                            />
                                            <div v-if="form.errors.nom_rao_social" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.nom_rao_social }}
                                            </div>
                                        </div>

                                        <div>
                                            <label for="nif_cif" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                NIF/CIF
                                            </label>
                                            <input
                                                id="nif_cif"
                                                v-model="form.nif_cif"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                            <div v-if="form.errors.nif_cif" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.nif_cif }}
                                            </div>
                                        </div>

                                        <div>
                                            <label for="adreca" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Adreça
                                            </label>
                                            <input
                                                id="adreca"
                                                v-model="form.adreca"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                            <div v-if="form.errors.adreca" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.adreca }}
                                            </div>
                                        </div>

                                        <div>
                                            <label for="correu_electronic" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Correu Electrònic
                                            </label>
                                            <input
                                                id="correu_electronic"
                                                v-model="form.correu_electronic"
                                                type="email"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                            <div v-if="form.errors.correu_electronic" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.correu_electronic }}
                                            </div>
                                        </div>

                                        <div>
                                            <label for="telefons" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Telèfons
                                            </label>
                                            <input
                                                id="telefons"
                                                v-model="form.telefons"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                            <div v-if="form.errors.telefons" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.telefons }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 dark:bg-indigo-500 dark:hover:bg-indigo-600 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
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
