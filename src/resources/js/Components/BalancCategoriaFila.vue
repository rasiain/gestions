<script setup lang="ts">
interface BalancCategoria {
    id: number;
    nom: string;
    ingressos: number;
    despeses: number;
    net: number;
    fills: BalancCategoria[];
}

const props = defineProps<{
    cat: BalancCategoria;
    nivell: number;
    formatEur: (val: number) => string;
}>();

const netClass = (val: number) =>
    val >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600 dark:text-orange-400';
</script>

<template>
    <tr :class="nivell === 0 ? 'font-medium bg-gray-50 dark:bg-gray-700/50' : ''">
        <td
            class="px-4 py-2 text-gray-900 dark:text-gray-100"
            :style="{ paddingLeft: `${16 + nivell * 20}px` }"
        >{{ cat.nom }}</td>
        <td class="px-4 py-2 text-right text-green-700 dark:text-green-300">
            {{ cat.ingressos !== 0 ? formatEur(cat.ingressos) : '-' }}
        </td>
        <td class="px-4 py-2 text-right text-red-700 dark:text-red-300">
            {{ cat.despeses !== 0 ? formatEur(cat.despeses) : '-' }}
        </td>
        <td class="px-4 py-2 text-right font-medium" :class="netClass(cat.net)">
            {{ cat.net !== 0 ? formatEur(cat.net) : '-' }}
        </td>
    </tr>
    <template v-for="fill in cat.fills" :key="fill.id">
        <BalancCategoriaFila :cat="fill" :nivell="nivell + 1" :format-eur="formatEur" />
    </template>
</template>
