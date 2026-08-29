<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Persona {
    id: number;
    nom: string;
}

interface CompteDiposit {
    compte_corrent_id: number;
    nom: string;
    digits: string;
    entitat: string;
    /** Saldo del compte sencer el 31 de desembre. */
    saldo: number;
    /** Saldo mitjà del quart trimestre, ponderat pels dies. */
    saldo_mig: number;
    /** El més gran dels dos: el que es declara. */
    valor: number;
    criteri: 'saldo' | 'mitjana';
    titulars: number;
    /** El que en pertoca a la persona, sobre el valor declarat. */
    part: number;
    altres_titulars: string[];
}

interface Apartat {
    clau: string;
    titol: string;
    comptes: CompteDiposit[];
    total: number;
}

interface Declaracio {
    persona: { id: number; nom: string; nif: string | null };
    any: number;
    data: string;
    bens: { apartats: Apartat[]; total: number };
    deutes: { apartats: Apartat[]; total: number };
    total: number;
}

interface Props {
    persones: Persona[];
    personaId: number | null;
    any: number;
    /** L'exercici en curs no es pot declarar: encara no ha arribat al 31 de desembre. */
    anyMaxim: number;
    declaracio: Declaracio | null;
}

const props = defineProps<Props>();

function formatEur(value: number): string {
    return new Intl.NumberFormat('ca-ES', { style: 'currency', currency: 'EUR' }).format(value);
}

function formatData(iso: string): string {
    return new Intl.DateTimeFormat('ca-ES', { dateStyle: 'long' }).format(new Date(iso));
}

function recarrega(camp: 'persona_id' | 'any', valor: string) {
    router.get(route('impostos.patrimoni'), {
        persona_id: camp === 'persona_id' ? valor : props.personaId,
        any: camp === 'any' ? valor : props.any,
    }, { preserveState: true, preserveScroll: true });
}

const anyOpcions = Array.from({ length: 6 }, (_, i) => props.anyMaxim - i);

const capCompte = computed(() =>
    (props.declaracio?.bens.apartats[0]?.comptes.length ?? 0) === 0
);
</script>

<template>
    <Head title="Patrimoni" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Patrimoni — exercici {{ props.any }}
                </h2>
                <div class="flex items-center gap-3">
                    <select
                        :value="props.personaId ?? ''"
                        @change="recarrega('persona_id', ($event.target as HTMLSelectElement).value)"
                        class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="p in props.persones" :key="p.id" :value="p.id">{{ p.nom }}</option>
                    </select>
                    <select
                        :value="props.any"
                        @change="recarrega('any', ($event.target as HTMLSelectElement).value)"
                        class="rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option v-for="a in anyOpcions" :key="a" :value="a">{{ a }}</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-screen-lg space-y-6 sm:px-6 lg:px-8">
                <p v-if="!props.declaracio" class="text-sm text-gray-500 dark:text-gray-400">
                    No hi ha cap persona donada d'alta.
                </p>

                <template v-else>
                    <div class="rounded-lg bg-white px-6 py-4 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ props.declaracio.persona.nom }}</span>
                            <span v-if="props.declaracio.persona.nif" class="ml-2 font-mono text-xs text-gray-400 dark:text-gray-500">
                                {{ props.declaracio.persona.nif }}
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            El patrimoni es valora el darrer dia de l'any: els imports són els del
                            <strong>{{ formatData(props.declaracio.data) }}</strong>, no els d'avui. L'exercici en curs no
                            s'ofereix perquè encara no hi ha arribat.
                        </p>
                    </div>

                    <!-- 1. Béns i drets -->
                    <section class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div class="flex items-center justify-between border-l-4 border-red-400 bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">1. Béns i drets</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(props.declaracio.bens.total) }}</span>
                            </span>
                        </div>

                        <div v-for="apartat in props.declaracio.bens.apartats" :key="apartat.clau" class="p-4">
                            <h4 class="mb-3 flex gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-gray-200 text-xs font-bold dark:bg-gray-600">
                                    {{ apartat.clau }}
                                </span>
                                <span>{{ apartat.titol }}</span>
                            </h4>

                            <table v-if="apartat.comptes.length" class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th class="pb-2 font-medium">Compte</th>
                                        <th class="w-32 pb-2 text-right font-medium">Saldo a 31/12</th>
                                        <th class="w-32 pb-2 text-right font-medium">Mitjana 4t trim.</th>
                                        <th class="w-32 pb-2 text-right font-medium">Valor</th>
                                        <th class="w-20 pb-2 text-right font-medium">Titulars</th>
                                        <th class="w-32 pb-2 text-right font-medium">Li pertoca</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="c in apartat.comptes" :key="c.compte_corrent_id">
                                        <td class="py-2">
                                            <span class="text-gray-800 dark:text-gray-200">{{ c.nom }}</span>
                                            <span class="ml-2 font-mono text-xs tracking-widest text-gray-400 dark:text-gray-500">···· {{ c.digits }}</span>
                                            <span v-if="c.altres_titulars.length" class="block text-xs text-gray-400 dark:text-gray-500">
                                                amb {{ c.altres_titulars.join(', ') }}
                                            </span>
                                        </td>
                                        <!-- El que guanya es marca: la llei demana el més gran dels dos -->
                                        <td class="whitespace-nowrap py-2 text-right tabular-nums"
                                            :class="c.criteri === 'saldo' ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500'">
                                            {{ formatEur(c.saldo) }}
                                        </td>
                                        <td class="whitespace-nowrap py-2 text-right tabular-nums"
                                            :class="c.criteri === 'mitjana' ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500'">
                                            {{ formatEur(c.saldo_mig) }}
                                        </td>
                                        <td class="whitespace-nowrap py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ formatEur(c.valor) }}</td>
                                        <td class="whitespace-nowrap py-2 text-right text-gray-500 dark:text-gray-400">{{ c.titulars }}</td>
                                        <td class="whitespace-nowrap py-2 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(c.part) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t-2 border-gray-200 dark:border-gray-600">
                                    <tr class="font-semibold">
                                        <td colspan="5" class="py-2 text-gray-900 dark:text-gray-100">Total de l'apartat {{ apartat.clau }}</td>
                                        <td class="py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ formatEur(apartat.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>

                            <p v-else class="text-sm text-gray-400 dark:text-gray-500">
                                Aquesta persona no consta com a titular de cap compte corrent.
                            </p>
                        </div>

                        <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            Es declara <strong>el més gran</strong> entre el saldo del 31 de desembre i la mitjana del
                            quart trimestre, que es calcula ponderant cada saldo pels dies que dura. La llei permet
                            descomptar de la mitjana el que s'hagi fet servir per comprar altres béns o cancel·lar
                            deutes: això no ho fa l'aplicació, ho has de mirar tu.
                            <br />
                            Hi entren els comptes corrents, personals i de lloguer. Els fons i els plans de pensions no
                            són dipòsits i tenen el seu propi apartat, que encara no s'ha fet. El valor es reparteix a
                            parts iguals entre els titulars del compte.
                        </p>
                    </section>

                    <!-- 2. Deutes deduïbles -->
                    <section class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div class="flex items-center justify-between border-l-4 border-gray-300 bg-gray-50 px-4 py-3 dark:border-gray-600 dark:bg-gray-700/50">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">2. Deutes deduïbles</h3>
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ formatEur(props.declaracio.deutes.total) }}</span>
                        </div>
                        <p class="p-4 text-sm text-gray-400 dark:text-gray-500">
                            Encara no s'hi ha posat cap apartat.
                        </p>
                    </section>

                    <!-- Total -->
                    <div class="flex items-center justify-between rounded-lg bg-white px-6 py-4 shadow-sm dark:bg-gray-800">
                        <span class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Patrimoni net
                        </span>
                        <span class="text-xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ formatEur(props.declaracio.total) }}
                        </span>
                    </div>

                    <p v-if="capCompte" class="text-xs text-gray-400 dark:text-gray-500">
                        Amb els apartats que hi ha avui, aquesta persona no suma res.
                    </p>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
