<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { grups } from '@/navegacio';
</script>

<template>
    <Head title="Tauler" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
            >
                Tauler
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-full sm:px-6 lg:px-8">
                <div class="mb-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Accions Ràpides
                    </h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Selecciona una acció per començar
                    </p>
                </div>

                <!-- Les targetes i la barra lateral surten de @/navegacio -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div
                        v-for="grup in grups"
                        :key="grup.clau"
                        class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800"
                    >
                        <div class="flex items-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg" :class="grup.color.fons">
                                <svg class="h-6 w-6" :class="grup.color.icona" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="grup.icona" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    {{ grup.titol }}
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ grup.descripcio }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 ml-16 space-y-2 border-l-2 pl-4" :class="grup.color.vora">
                            <Link
                                v-for="accio in grup.accions"
                                :key="accio.ruta"
                                :href="route(accio.ruta)"
                                class="block text-sm text-gray-600 transition-colors dark:text-gray-400"
                                :class="grup.color.enllac"
                            >
                                → {{ accio.titol }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
