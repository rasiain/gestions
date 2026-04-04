<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface PersonaBasic {
    id: number;
    nom: string; // "Cognoms, Nom" format
}

interface PersonaLinked {
    id: number;
    nom: string; // "Nom Cognoms" format
    cognoms: string;
    nif: string | null;
}

interface Llogater {
    id: number;
    tipus: 'persona' | 'empresa';
    persona_id: number | null;
    persona: PersonaLinked | null;
    nom_rao_social: string | null;
    nif: string | null;
    adreca: string | null;
    codi_postal: string | null;
    poblacio: string | null;
}

interface Props {
    llogaters: Llogater[];
    persones: PersonaBasic[];
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingLlogater = ref<Llogater | null>(null);

const form = useForm({
    tipus: 'persona' as 'persona' | 'empresa',
    persona_id: null as number | null,
    nom_rao_social: '',
    nif: '',
    adreca: '',
    codi_postal: '',
    poblacio: '',
});

const openCreateModal = () => {
    isEditing.value = false;
    editingLlogater.value = null;
    form.reset();
    showModal.value = true;
};

const openEditModal = (llogater: Llogater) => {
    isEditing.value = true;
    editingLlogater.value = llogater;
    form.tipus = llogater.tipus;
    form.persona_id = llogater.persona_id;
    form.nom_rao_social = llogater.nom_rao_social || '';
    form.nif = llogater.nif || '';
    form.adreca = llogater.adreca || '';
    form.codi_postal = llogater.codi_postal || '';
    form.poblacio = llogater.poblacio || '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    isEditing.value = false;
    editingLlogater.value = null;
};

const submit = () => {
    if (isEditing.value && editingLlogater.value) {
        form.put(route('llogaters.update', editingLlogater.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('llogaters.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteLlogater = (llogater: Llogater) => {
    const nom = llogater.tipus === 'persona'
        ? (llogater.persona?.nom ?? '?')
        : (llogater.nom_rao_social ?? '?');
    if (confirm(`Estàs segur que vols eliminar el llogater "${nom}"?`)) {
        router.delete(route('llogaters.destroy', llogater.id));
    }
};

const nomDisplay = (llogater: Llogater): string => {
    if (llogater.tipus === 'persona') return llogater.persona?.nom ?? '—';
    return llogater.nom_rao_social ?? '—';
};

const nifDisplay = (llogater: Llogater): string => {
    if (llogater.tipus === 'persona') return llogater.persona?.nif ?? '—';
    return llogater.nif ?? '—';
};

const adrecaDisplay = (llogater: Llogater): string => {
    if (llogater.tipus !== 'empresa') return '';
    const parts = [llogater.adreca, llogater.codi_postal, llogater.poblacio].filter(Boolean);
    return parts.join(', ');
};
</script>

<template>
    <Head title="Llogaters" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Llogaters
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Header -->
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">Llistat de Llogaters</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestiona les persones i empreses que llogen els immobles
                                </p>
                            </div>
                            <button
                                @click="openCreateModal"
                                class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                            >
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Afegir Llogater
                            </button>
                        </div>

                        <!-- Table -->
                        <div v-if="llogaters.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Tipus</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Nom / Raó social</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">NIF / DNI</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Adreça</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Accions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr
                                        v-for="llogater in llogaters"
                                        :key="llogater.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="llogater.tipus === 'persona'
                                                    ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
                                                    : 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'"
                                            >
                                                {{ llogater.tipus === 'persona' ? 'Persona' : 'Empresa' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ nomDisplay(llogater) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ nifDisplay(llogater) }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ adrecaDisplay(llogater) || '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                            <button
                                                @click="openEditModal(llogater)"
                                                class="mr-3 text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteLlogater(llogater)"
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
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No hi ha llogaters</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Comença afegint el primer llogater.</p>
                            <div class="mt-6">
                                <button
                                    @click="openCreateModal"
                                    class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                                >
                                    <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Afegir Llogater
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
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="closeModal"></div>
                <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">&#8203;</span>

                <div class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3 class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100" id="modal-title">
                                {{ isEditing ? 'Editar Llogater' : 'Nou Llogater' }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Tipus -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipus *</label>
                                    <div class="mt-1 flex gap-4">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" v-model="form.tipus" value="persona" class="text-amber-600 focus:ring-amber-500" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Persona</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" v-model="form.tipus" value="empresa" class="text-amber-600 focus:ring-amber-500" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Empresa</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Persona: selecció de persona -->
                                <template v-if="form.tipus === 'persona'">
                                    <div>
                                        <label for="persona_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Persona *</label>
                                        <select
                                            id="persona_id"
                                            v-model="form.persona_id"
                                            required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        >
                                            <option :value="null">— Selecciona una persona —</option>
                                            <option v-for="p in persones" :key="p.id" :value="p.id">{{ p.nom }}</option>
                                        </select>
                                        <p v-if="form.errors.persona_id" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.persona_id }}</p>
                                    </div>
                                </template>

                                <!-- Empresa: camps propis -->
                                <template v-else>
                                    <div>
                                        <label for="nom_rao_social" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Raó social *</label>
                                        <input
                                            id="nom_rao_social"
                                            v-model="form.nom_rao_social"
                                            type="text"
                                            required
                                            maxlength="150"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                        <p v-if="form.errors.nom_rao_social" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.nom_rao_social }}</p>
                                    </div>
                                    <div>
                                        <label for="nif" class="block text-sm font-medium text-gray-700 dark:text-gray-300">NIF/CIF</label>
                                        <input
                                            id="nif"
                                            v-model="form.nif"
                                            type="text"
                                            maxlength="20"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label for="adreca" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Adreça</label>
                                        <input
                                            id="adreca"
                                            v-model="form.adreca"
                                            type="text"
                                            maxlength="200"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="codi_postal" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Codi postal</label>
                                            <input
                                                id="codi_postal"
                                                v-model="form.codi_postal"
                                                type="text"
                                                maxlength="10"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                        </div>
                                        <div>
                                            <label for="poblacio" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Població</label>
                                            <input
                                                id="poblacio"
                                                v-model="form.poblacio"
                                                type="text"
                                                maxlength="100"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                            />
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-4 py-3 dark:bg-gray-700 sm:flex sm:flex-row-reverse sm:px-6">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                            >
                                {{ isEditing ? 'Actualitzar' : 'Crear' }}
                            </button>
                            <button
                                type="button"
                                @click="closeModal"
                                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
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
