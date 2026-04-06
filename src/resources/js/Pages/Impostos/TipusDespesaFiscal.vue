<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

interface CategoriaMapping {
    categoria: string;
    label: string;
    tipus_despesa_fiscal_id: number | null;
}

interface TipusDespesa {
    id: number;
    codi: string;
    descripcio: string;
}

interface Props {
    categoriesMapping: CategoriaMapping[];
    tipusDespesaOpcions: TipusDespesa[];
}

const props = defineProps<Props>();

const updateMapping = (categoria: string, tipusId: string) => {
    router.put(route('impostos.tipus-despesa-fiscal.update'), {
        categoria,
        tipus_despesa_fiscal_id: tipusId ? Number(tipusId) : null,
    }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Configuració fiscal" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Configuració fiscal
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-lg sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
                    <div class="p-6">
                        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                            Per a cada categoria de despesa de lloguer, tria el compte del PGC que li correspon.
                            Quan es classifiqui un moviment, el tipus fiscal s'omplirà automàticament.
                        </p>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                    <th class="pb-3 pr-6 font-medium text-gray-700 dark:text-gray-300 w-40">Categoria</th>
                                    <th class="pb-3 font-medium text-gray-700 dark:text-gray-300">Compte PGC associat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="row in categoriesMapping" :key="row.categoria">
                                    <td class="py-3 pr-6 font-medium text-gray-900 dark:text-gray-100">{{ row.label }}</td>
                                    <td class="py-3">
                                        <select
                                            :value="row.tipus_despesa_fiscal_id ?? ''"
                                            @change="updateMapping(row.categoria, ($event.target as HTMLSelectElement).value)"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        >
                                            <option value="">— sense associació —</option>
                                            <option
                                                v-for="t in tipusDespesaOpcions"
                                                :key="t.id"
                                                :value="t.id"
                                            >{{ t.codi }} – {{ t.descripcio }}</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
