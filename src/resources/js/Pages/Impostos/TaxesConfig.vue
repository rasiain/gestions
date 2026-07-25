<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Patro {
    id: number;
    etiqueta: string;
    patro: string;
    actiu: boolean;
    ordre: number;
}

interface Props {
    patrons: Patro[];
}

defineProps<Props>();

// Formulari per afegir un patró nou.
const nou = useForm({ etiqueta: '', patro: '', actiu: true, ordre: 0 });

function afegeix() {
    nou.post(route('impostos.taxes.patrons.store'), {
        preserveScroll: true,
        onSuccess: () => nou.reset(),
    });
}

// Edició inline: es desa on canvia.
const editant = ref<Record<number, Patro>>({});

function desa(p: Patro) {
    router.put(route('impostos.taxes.patrons.update', p.id), {
        etiqueta: p.etiqueta,
        patro: p.patro,
        actiu: p.actiu,
        ordre: p.ordre,
    }, { preserveScroll: true });
}

function toggleActiu(p: Patro) {
    router.put(route('impostos.taxes.patrons.update', p.id), {
        etiqueta: p.etiqueta,
        patro: p.patro,
        actiu: !p.actiu,
        ordre: p.ordre,
    }, { preserveScroll: true });
}

function elimina(p: Patro) {
    if (!confirm(`Eliminar el patró "${p.patro}" (${p.etiqueta})?`)) return;
    router.delete(route('impostos.taxes.patrons.destroy', p.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuració Taxes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Configuració Taxes — patrons de detecció
                </h2>
                <Link :href="route('impostos.taxes')" class="text-sm text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400">
                    ← Tornar a Taxes
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-lg sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                            Un moviment es considera una taxa (impost municipal) si el <strong>nom de la seva categoria bancària conté</strong>
                            algun d'aquests patrons. La comparació ignora accents i majúscules/minúscules. Si un nom coincideix amb
                            més d'un patró actiu, guanya el de <strong>menor ordre</strong>.
                        </p>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300">Etiqueta (tipus visible)</th>
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300">Patró (text a cercar)</th>
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300 w-20">Ordre</th>
                                    <th class="pb-3 pr-4 font-medium text-gray-700 dark:text-gray-300 w-20 text-center">Actiu</th>
                                    <th class="pb-3 font-medium text-gray-700 dark:text-gray-300 w-32 text-right">Accions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="p in patrons" :key="p.id">
                                    <td class="py-2 pr-4">
                                        <input v-model="p.etiqueta" @blur="desa(p)"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model="p.patro" @blur="desa(p)"
                                            class="block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model.number="p.ordre" @blur="desa(p)" type="number" min="0"
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
                                        <button @click="elimina(p)" class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">
                                            Eliminar
                                        </button>
                                    </td>
                                </tr>

                                <!-- Fila afegir -->
                                <tr class="bg-gray-50 dark:bg-gray-700/40">
                                    <td class="py-2 pr-4">
                                        <input v-model="nou.etiqueta" placeholder="ex. IBI"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model="nou.patro" placeholder="ex. IBI" @keyup.enter="afegeix"
                                            class="block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input v-model.number="nou.ordre" type="number" min="0"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    </td>
                                    <td class="py-2 pr-4"></td>
                                    <td class="py-2 text-right">
                                        <button @click="afegeix" :disabled="nou.processing || !nou.etiqueta || !nou.patro"
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
        </div>
    </AuthenticatedLayout>
</template>
