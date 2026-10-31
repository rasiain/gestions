<script setup lang="ts">
/**
 * Un botó ⓘ que obre una explicació al costat: què vol dir una xifra o una gràfica. El contingut va al
 * slot. Es tanca clicant fora o amb Esc, com el de la tolerància de les rutes.
 */
import { onMounted, onUnmounted, ref } from 'vue';

withDefaults(defineProps<{ titol: string; costat?: 'esquerra' | 'dreta'; amplada?: string }>(), { costat: 'esquerra', amplada: 'w-96' });

const obert = ref(false);
const arrel = ref<HTMLElement | null>(null);
const tanca = (e: MouseEvent) => { if (obert.value && arrel.value && !arrel.value.contains(e.target as Node)) obert.value = false; };
const escapa = (e: KeyboardEvent) => { if (e.key === 'Escape') obert.value = false; };
onMounted(() => { document.addEventListener('click', tanca); document.addEventListener('keydown', escapa); });
onUnmounted(() => { document.removeEventListener('click', tanca); document.removeEventListener('keydown', escapa); });
</script>

<template>
    <span ref="arrel" class="relative inline-block align-middle">
        <button type="button" @click.stop="obert = !obert" :aria-expanded="obert" :aria-label="titol"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 text-xs font-normal normal-case text-gray-500 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
            i
        </button>
        <div v-if="obert" :class="[costat === 'dreta' ? 'right-0' : 'left-0', amplada]"
            class="absolute z-[1000] mt-2 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-4 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-gray-600 shadow-lg dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
            <p class="mb-2 font-semibold text-gray-800 dark:text-gray-100">{{ titol }}</p>
            <div class="space-y-2"><slot /></div>
        </div>
    </span>
</template>
