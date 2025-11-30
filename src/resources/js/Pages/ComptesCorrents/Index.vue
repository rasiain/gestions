<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Titular {
    id: number;
    nom: string;
    cognoms: string;
}

interface CompteCorrent {
    id: number;
    compte_corrent: string;
    nom: string | null;
    entitat: string;
    bank_type: string | null;
    ordre: number;
    titulars: Titular[];
    created_at: string;
    updated_at: string;
}

interface Props {
    comptesCorrents: CompteCorrent[];
    titulars: Titular[];
}

const props = defineProps<Props>();

const showModal = ref(false);
const isEditing = ref(false);
const editingCompteCorrent = ref<CompteCorrent | null>(null);

const form = useForm({
    compte_corrent: '',
    nom: '',
    entitat: '',
    bank_type: null as string | null,
    ordre: 0,
    titular_ids: [] as number[],
});

const openCreateModal = () => {
    isEditing.value = false;
    editingCompteCorrent.value = null;
    form.reset();
    showModal.value = true;
};

const openEditModal = (compteCorrent: CompteCorrent) => {
    isEditing.value = true;
    editingCompteCorrent.value = compteCorrent;
    form.compte_corrent = compteCorrent.compte_corrent;
    form.nom = compteCorrent.nom || '';
    form.entitat = compteCorrent.entitat;
    form.bank_type = compteCorrent.bank_type;
    form.ordre = compteCorrent.ordre;
    form.titular_ids = compteCorrent.titulars.map(t => t.id);
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    form.reset();
    isEditing.value = false;
    editingCompteCorrent.value = null;
};

const submit = () => {
    if (isEditing.value && editingCompteCorrent.value) {
        form.put(route('comptes-corrents.update', editingCompteCorrent.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('comptes-corrents.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteCompteCorrent = (compteCorrent: CompteCorrent) => {
    if (confirm(`Estàs segur que vols eliminar el compte "${compteCorrent.compte_corrent}"?`)) {
        router.delete(route('comptes-corrents.destroy', compteCorrent.id));
    }
};

const getTitularsNames = (titulars: Titular[]): string => {
    if (titulars.length === 0) return 'Sense titulars';
    return titulars.map(t => `${t.nom} ${t.cognoms}`).join(', ');
};

const getBankTypeLabel = (bankType: string | null): string => {
    if (!bankType) return '-';
    const labels: Record<string, string> = {
        'caixa_enginyers': 'Caixa d\'Enginyers',
        'caixabank': 'CaixaBank',
        'kmymoney': 'KMyMoney'
    };
    return labels[bankType] || bankType;
};
</script>

<template>
    <Head title="Comptes Corrents" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Comptes Corrents
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Header with Add Button -->
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">
                                    Llistat de Comptes Corrents
                                </h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestiona els comptes corrents i els seus titulars
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
                                Afegir Compte Corrent
                            </button>
                        </div>

                        <!-- Table -->
                        <div v-if="comptesCorrents.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Compte Corrent
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Nom
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Entitat
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Tipus Banc
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Titulars
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300"
                                        >
                                            Ordre
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
                                        v-for="compte in comptesCorrents"
                                        :key="compte.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ compte.compte_corrent }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ compte.nom || '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            {{ compte.entitat }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span v-if="compte.bank_type" class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-900/20 px-2 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-300">
                                                {{ getBankTypeLabel(compte.bank_type) }}
                                            </span>
                                            <span v-else class="text-gray-500 dark:text-gray-400">-</span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ getTitularsNames(compte.titulars) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            {{ compte.ordre }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                            <button
                                                @click="openEditModal(compte)"
                                                class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                @click="deleteCompteCorrent(compte)"
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
                                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                                />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                No hi ha comptes corrents
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Comença afegint el primer compte corrent.
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
                                    Afegir Compte Corrent
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
                    class="inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:align-middle"
                >
                    <form @submit.prevent="submit">
                        <div class="bg-white px-4 pb-4 pt-5 dark:bg-gray-800 sm:p-6 sm:pb-4">
                            <h3
                                class="mb-4 text-lg font-medium leading-6 text-gray-900 dark:text-gray-100"
                                id="modal-title"
                            >
                                {{ isEditing ? 'Editar Compte Corrent' : 'Nou Compte Corrent' }}
                            </h3>

                            <div class="space-y-4">
                                <!-- Compte Corrent -->
                                <div>
                                    <label
                                        for="compte_corrent"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Compte Corrent (IBAN/CCC)
                                    </label>
                                    <input
                                        id="compte_corrent"
                                        v-model="form.compte_corrent"
                                        type="text"
                                        required
                                        maxlength="24"
                                        placeholder="ES1234567890123456789012"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.compte_corrent" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.compte_corrent }}
                                    </p>
                                </div>

                                <!-- Nom -->
                                <div>
                                    <label
                                        for="nom"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Nom (opcional)
                                    </label>
                                    <input
                                        id="nom"
                                        v-model="form.nom"
                                        type="text"
                                        maxlength="100"
                                        placeholder="P. ex: Compte personal, Negoci, etc."
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.nom" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.nom }}
                                    </p>
                                </div>

                                <!-- Entitat -->
                                <div>
                                    <label
                                        for="entitat"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Entitat Bancària
                                    </label>
                                    <input
                                        id="entitat"
                                        v-model="form.entitat"
                                        type="text"
                                        required
                                        maxlength="200"
                                        placeholder="CaixaBank, BBVA, etc."
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.entitat" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.entitat }}
                                    </p>
                                </div>

                                <!-- Tipus Banc -->
                                <div>
                                    <label
                                        for="bank_type"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Tipus de Banc (per importació de moviments)
                                    </label>
                                    <select
                                        id="bank_type"
                                        v-model="form.bank_type"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    >
                                        <option :value="null">Sense tipus</option>
                                        <option value="caixa_enginyers">Caixa d'Enginyers</option>
                                        <option value="caixabank">CaixaBank</option>
                                        <option value="kmymoney">KMyMoney</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Necessari per poder importar moviments bancaris
                                    </p>
                                    <p v-if="form.errors.bank_type" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.bank_type }}
                                    </p>
                                </div>

                                <!-- Ordre -->
                                <div>
                                    <label
                                        for="ordre"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                    >
                                        Ordre
                                    </label>
                                    <input
                                        id="ordre"
                                        v-model.number="form.ordre"
                                        type="number"
                                        min="0"
                                        max="255"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm"
                                    />
                                    <p v-if="form.errors.ordre" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.ordre }}
                                    </p>
                                </div>

                                <!-- Titulars -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Titulars
                                    </label>
                                    <div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-md border border-gray-300 p-3 dark:border-gray-600">
                                        <div
                                            v-for="titular in titulars"
                                            :key="titular.id"
                                            class="flex items-center"
                                        >
                                            <input
                                                :id="`titular-${titular.id}`"
                                                v-model="form.titular_ids"
                                                :value="titular.id"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <label
                                                :for="`titular-${titular.id}`"
                                                class="ml-2 text-sm text-gray-700 dark:text-gray-300"
                                            >
                                                {{ titular.nom }} {{ titular.cognoms }}
                                            </label>
                                        </div>
                                        <div v-if="titulars.length === 0" class="text-center text-sm text-gray-500 dark:text-gray-400">
                                            No hi ha titulars disponibles
                                        </div>
                                    </div>
                                    <p v-if="form.errors.titular_ids" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.titular_ids }}
                                    </p>
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
