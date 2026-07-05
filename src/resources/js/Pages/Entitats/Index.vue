<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Entitat {
    id: number;
    nom: string;
    comptes_corrents_count: number;
}

const props = defineProps<{ entitats: Entitat[] }>();

const csrf = () => document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '';

const llista = ref<Entitat[]>(JSON.parse(JSON.stringify(props.entitats)));

const editingId = ref<number | null>(null);
const editingNom = ref('');
const showCreate = ref(false);
const newNom = ref('');
const error = ref<string | null>(null);
const saving = ref(false);

const startEdit = (e: Entitat) => {
    editingId.value = e.id;
    editingNom.value = e.nom;
    error.value = null;
};
const cancelEdit = () => { editingId.value = null; editingNom.value = ''; error.value = null; };

const saveEdit = async (e: Entitat) => {
    if (!editingNom.value.trim()) return;
    saving.value = true; error.value = null;
    try {
        const res = await fetch(`/entitats/${e.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ nom: editingNom.value.trim() }),
        });
        if (!res.ok) {
            const err = await res.json();
            error.value = Object.values(err.errors ?? {}).flat().join(' ') || err.message || 'Error.';
            return;
        }
        const saved: Entitat = await res.json();
        const idx = llista.value.findIndex(x => x.id === saved.id);
        if (idx !== -1) llista.value[idx] = { ...llista.value[idx], nom: saved.nom };
        llista.value.sort((a, b) => a.nom.localeCompare(b.nom, 'ca'));
        cancelEdit();
    } finally { saving.value = false; }
};

const saveCreate = async () => {
    if (!newNom.value.trim()) return;
    saving.value = true; error.value = null;
    try {
        const res = await fetch('/entitats', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ nom: newNom.value.trim() }),
        });
        if (!res.ok) {
            const err = await res.json();
            error.value = Object.values(err.errors ?? {}).flat().join(' ') || err.message || 'Error.';
            return;
        }
        const saved: Entitat = await res.json();
        llista.value.push({ ...saved, comptes_corrents_count: 0 });
        llista.value.sort((a, b) => a.nom.localeCompare(b.nom, 'ca'));
        newNom.value = '';
        showCreate.value = false;
    } finally { saving.value = false; }
};

const deleteEntitat = async (e: Entitat) => {
    if (!confirm(`Estàs segur que vols eliminar "${e.nom}"?`)) return;
    error.value = null;
    const res = await fetch(`/entitats/${e.id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf() },
    });
    if (!res.ok) {
        const err = await res.json();
        error.value = err.error || 'No s\'ha pogut eliminar.';
        return;
    }
    llista.value = llista.value.filter(x => x.id !== e.id);
};
</script>

<template>
    <Head title="Entitats bancàries" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Entitats bancàries</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">

                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium">Entitats bancàries</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Catàleg d'entitats financeres</p>
                            </div>
                            <button @click="showCreate = true; error = null" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Nova entitat
                            </button>
                        </div>

                        <p v-if="error" class="mb-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                        <!-- Formulari creació -->
                        <div v-if="showCreate" class="mb-4 flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3 dark:border-indigo-800 dark:bg-indigo-900/10">
                            <input v-model="newNom" type="text" placeholder="Nom de l'entitat" maxlength="200" autofocus
                                @keydown.enter="saveCreate" @keydown.escape="showCreate = false; newNom = ''"
                                class="flex-1 rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                            <button @click="saveCreate" :disabled="saving" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Crear</button>
                            <button @click="showCreate = false; newNom = ''" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Cancel·lar</button>
                        </div>

                        <!-- Llista -->
                        <div v-if="llista.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Nom</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Comptes</th>
                                        <th class="px-4 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr v-for="e in llista" :key="e.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-4 py-3">
                                            <div v-if="editingId === e.id" class="flex items-center gap-2">
                                                <input v-model="editingNom" type="text" maxlength="200"
                                                    @keydown.enter="saveEdit(e)" @keydown.escape="cancelEdit"
                                                    class="flex-1 rounded-md border-gray-300 bg-white text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:text-sm" />
                                                <button @click="saveEdit(e)" :disabled="saving" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50">Desar</button>
                                                <button @click="cancelEdit" class="text-sm text-gray-500">Cancel·lar</button>
                                            </div>
                                            <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ e.nom }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm text-gray-500 dark:text-gray-400">
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-700">{{ e.comptes_corrents_count }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm" v-if="editingId !== e.id">
                                            <button @click="startEdit(e)" class="mr-3 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</button>
                                            <button @click="deleteEntitat(e)" :disabled="e.comptes_corrents_count > 0" class="text-red-600 hover:text-red-900 dark:text-red-400 disabled:opacity-40 disabled:cursor-not-allowed" :title="e.comptes_corrents_count > 0 ? 'Té comptes associats' : ''">Eliminar</button>
                                        </td>
                                        <td v-else class="px-4 py-3"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="py-8 text-center text-sm text-gray-400">Cap entitat registrada.</p>

                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
