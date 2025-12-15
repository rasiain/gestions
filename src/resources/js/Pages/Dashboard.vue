<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
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
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Accions Ràpides
                    </h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Selecciona una acció per començar
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <!-- Comptes Corrents Card with sub-links -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <Link
                            :href="route('comptes-corrents.index')"
                            class="group block"
                        >
                            <div class="flex items-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                                    <svg
                                        class="h-6 w-6 text-blue-600 dark:text-blue-400"
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
                                </div>
                                <div class="ml-4">
                                    <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Comptes Corrents
                                    </h4>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        Gestionar comptes bancaris
                                    </p>
                                </div>
                            </div>
                        </Link>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-blue-200 dark:border-blue-800 pl-4">
                            <Link
                                :href="route('titulars.index')"
                                class="block text-sm text-gray-600 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors"
                            >
                                → Gestionar Titulars
                            </Link>
                            <Link
                                :href="route('maintenance.movements.import')"
                                class="block text-sm text-gray-600 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors"
                            >
                                → Importar Moviments
                            </Link>
                        </div>
                    </div>

                    <!-- Moviments Card with sub-links -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <Link
                            :href="route('moviments.index')"
                            class="group block"
                        >
                            <div class="flex items-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-yellow-100 dark:bg-yellow-900">
                                    <svg
                                        class="h-6 w-6 text-yellow-600 dark:text-yellow-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                        />
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Moviments
                                    </h4>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        Visualitzar i editar transaccions
                                    </p>
                                </div>
                            </div>
                        </Link>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-yellow-200 dark:border-yellow-800 pl-4">
                            <Link
                                :href="route('categories.index')"
                                class="block text-sm text-gray-600 hover:text-yellow-600 dark:text-gray-400 dark:hover:text-yellow-400 transition-colors"
                            >
                                → Gestionar Categories
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
