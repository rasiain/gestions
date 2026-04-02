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
            <div class="mx-auto max-w-screen-2xl sm:px-6 lg:px-8">
                <div class="mb-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Accions Ràpides
                    </h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Selecciona una acció per començar
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <!-- Gestions bancàries Card -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
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
                                        d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                    />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Gestions bancàries
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestió de comptes i transaccions
                                </p>
                            </div>
                        </div>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-blue-200 dark:border-blue-800 pl-4">
                            <Link
                                :href="route('comptes-corrents.index')"
                                class="block text-sm text-gray-600 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors"
                            >
                                → Comptes Corrents
                            </Link>
                            <Link
                                :href="route('categories.index')"
                                class="block text-sm text-gray-600 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors"
                            >
                                → Categories
                            </Link>
                        </div>
                    </div>

                    <!-- Inversions Card (empty for now) -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                                <svg
                                    class="h-6 w-6 text-green-600 dark:text-green-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
                                    />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Inversions
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestió d'inversions bancàries
                                </p>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div class="mt-4 ml-16 pl-4">
                            <p class="text-sm italic text-gray-400 dark:text-gray-600">
                                Properament disponible
                            </p>
                        </div>
                    </div>

                    <!-- Lloguers Card -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900">
                                <svg
                                    class="h-6 w-6 text-amber-600 dark:text-amber-400"
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
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Lloguers
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Gestió d'immobles i llogaters
                                </p>
                            </div>
                        </div>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-amber-200 dark:border-amber-800 pl-4">
                            <Link
                                :href="route('lloguers.index')"
                                class="block text-sm text-gray-600 hover:text-amber-600 dark:text-gray-400 dark:hover:text-amber-400 transition-colors"
                            >
                                → Lloguers
                            </Link>
                            <Link
                                :href="route('immobles.index')"
                                class="block text-sm text-gray-600 hover:text-amber-600 dark:text-gray-400 dark:hover:text-amber-400 transition-colors"
                            >
                                → Immobles
                            </Link>
                            <Link
                                :href="route('llogaters.index')"
                                class="block text-sm text-gray-600 hover:text-amber-600 dark:text-gray-400 dark:hover:text-amber-400 transition-colors"
                            >
                                → Llogaters
                            </Link>
                        </div>
                    </div>

                    <!-- Impostos Card -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900">
                                <svg
                                    class="h-6 w-6 text-red-600 dark:text-red-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"
                                    />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Impostos
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Declaracions i càlculs fiscals
                                </p>
                            </div>
                        </div>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-red-200 dark:border-red-800 pl-4">
                            <Link
                                :href="route('impostos.irpf')"
                                class="block text-sm text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-colors"
                            >
                                → IRPF Lloguers
                            </Link>
                            <Link
                                :href="route('impostos.iva')"
                                class="block text-sm text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-colors"
                            >
                                → IVA i model 184 Lloguers
                            </Link>
                        </div>
                    </div>

                    <!-- Configuració Card -->
                    <div class="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div class="flex items-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900">
                                <svg
                                    class="h-6 w-6 text-purple-600 dark:text-purple-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                    />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Configuració
                                </h4>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    Paràmetres generals de l'aplicació
                                </p>
                            </div>
                        </div>

                        <!-- Sub-links -->
                        <div class="mt-4 ml-16 space-y-2 border-l-2 border-purple-200 dark:border-purple-800 pl-4">
                            <Link
                                :href="route('persones.index')"
                                class="block text-sm text-gray-600 hover:text-purple-600 dark:text-gray-400 dark:hover:text-purple-400 transition-colors"
                            >
                                → Persones
                            </Link>
                            <Link
                                :href="route('proveidors.index')"
                                class="block text-sm text-gray-600 hover:text-purple-600 dark:text-gray-400 dark:hover:text-purple-400 transition-colors"
                            >
                                → Proveïdors
                            </Link>
                            <Link
                                :href="route('comunitats-bens.index')"
                                class="block text-sm text-gray-600 hover:text-purple-600 dark:text-gray-400 dark:hover:text-purple-400 transition-colors"
                            >
                                → Comunitats de Béns
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
