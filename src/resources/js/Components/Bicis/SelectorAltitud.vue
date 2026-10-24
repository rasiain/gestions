<script setup lang="ts">
/**
 * Amb quina altitud es compta el desnivell d'una llista de sortides (fitxa de la bici, rutes). «Per
 * sortida» és la de cada sortida —la que s'hi ha desat o, si no, la de per defecte—; la resta l'aplica
 * a totes, amb la millor font que les cobreixi. Vegeu AltitudTerrenyService::desnivellsTriats().
 */
const model = defineModel<string | null>({ required: true });

const OPCIONS: [string | null, string, string][] = [
    [null, 'Per sortida', "L'altitud de cada sortida: la que s'hi ha desat o, si no, la de per defecte."],
    ['dispositiu', 'Dispositiu', 'El desnivell de Garmin Connect, de l\'altímetre del dispositiu.'],
    ['combinat', 'Combinat', "El nivell del model del terreny i el detall de l'altímetre on és de fiar."],
    ['terreny', 'Terreny', "L'altitud del model del terreny (ICGC o IGN)."],
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-gray-500 dark:text-gray-400">Desnivell</span>
        <div class="inline-flex rounded-md border border-gray-300 text-xs dark:border-gray-600">
            <button v-for="[valor, etiqueta, ajuda] in OPCIONS" :key="etiqueta" type="button" @click="model = valor" :title="ajuda"
                class="px-2.5 py-1 first:rounded-l-md last:rounded-r-md"
                :class="model === valor ? 'bg-sky-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'">
                {{ etiqueta }}
            </button>
        </div>
    </div>
</template>
