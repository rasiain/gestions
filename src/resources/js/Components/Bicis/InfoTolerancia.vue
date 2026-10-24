<script setup lang="ts">
/** El botó ⓘ de la tolerància de les rutes: com s'agrupen les sortides i què vol dir cada nivell. */
import { onMounted, onUnmounted, ref } from 'vue';
import { NIVELLS } from '@/bicis/nivells';

defineProps<{ nivells: { clau: string; llindar: number }[] }>();

const obert = ref(false);
const arrel = ref<HTMLElement | null>(null);
const tanca = (e: MouseEvent) => { if (obert.value && arrel.value && !arrel.value.contains(e.target as Node)) obert.value = false; };
const escapa = (e: KeyboardEvent) => { if (e.key === 'Escape') obert.value = false; };
onMounted(() => { document.addEventListener('click', tanca); document.addEventListener('keydown', escapa); });
onUnmounted(() => { document.removeEventListener('click', tanca); document.removeEventListener('keydown', escapa); });
</script>

<template>
    <span ref="arrel" class="relative inline-block">
        <button type="button" @click="obert = !obert" :aria-expanded="obert" aria-label="Com s'agrupen les sortides"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 text-xs text-gray-500 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
            i
        </button>
        <div v-if="obert"
            class="absolute left-0 z-[1000] mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-4 text-xs text-gray-600 shadow-lg dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
            <p class="mb-2 font-semibold text-gray-800 dark:text-gray-100">Com s'agrupen les sortides</p>
            <p>
                Dues sortides són de la mateixa ruta si comparteixen prou recorregut. Es mira quina part de cada una
                passa a menys de ~50 m de l'altra, i val la de la que en comparteix menys: una sortida curta dins
                d'una de llarga no és la mateixa ruta.
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-4">
                <li>No compta el sentit ni el punt d'inici.</li>
                <li>Totes les sortides d'un grup compleixen el llindar entre elles, no només amb una.</li>
                <li>Els nivells van niats: cada grup és dins d'un del nivell següent.</li>
                <li>La representant és la més central del grup i, si n'hi ha diverses, la de millor gravació.</li>
            </ul>
            <dl class="mt-3 space-y-1.5 border-t border-gray-200 pt-2 dark:border-gray-700">
                <div v-for="n in nivells" :key="n.clau">
                    <dt class="font-medium text-gray-800 dark:text-gray-100">
                        {{ NIVELLS[n.clau]?.nom ?? n.clau }} <span class="font-normal text-gray-400">· comparteixen ≥ {{ Math.round(n.llindar * 100) }} %</span>
                    </dt>
                    <dd>{{ NIVELLS[n.clau]?.descripcio }}</dd>
                </div>
            </dl>
        </div>
    </span>
</template>
