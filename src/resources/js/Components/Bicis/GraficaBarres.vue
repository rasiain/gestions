<script setup lang="ts">
/**
 * Un bloc de gràfica de barres de la fitxa de la bici: títol amb la unitat, controls a la dreta
 * (selector d'any, fletxes…), la gràfica i un peu. Tots els blocs de la pestanya Gràfiques en són un.
 */
import { Bar } from 'vue-chartjs';
import type { ChartData, ChartOptions } from 'chart.js';

withDefaults(defineProps<{
    titol: string;
    unitat: string;
    data: ChartData<'bar'>;
    options: ChartOptions<'bar'>;
    /** Classe d'alçada de la gràfica. */
    alcada?: string;
}>(), { alcada: 'h-64' });
</script>

<template>
    <div class="min-w-0">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ titol }} <span class="ml-1 font-normal text-gray-400 dark:text-gray-500">{{ unitat }}</span>
            </h4>
            <div v-if="$slots.controls" class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <slot name="controls" />
            </div>
        </div>
        <div :class="alcada"><Bar :data="data" :options="options" /></div>
        <div v-if="$slots.peu" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            <slot name="peu" />
        </div>
    </div>
</template>
