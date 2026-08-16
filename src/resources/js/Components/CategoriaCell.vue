<script setup lang="ts">
import { ref } from 'vue';

/**
 * Cel·la de categoria d'una taula de moviments: mostra el nom de la categoria i,
 * en fer-hi clic, el path complet (arrel > ... > fulla). Les classes de la cel·la
 * les posa la taula que l'utilitza.
 */
const props = defineProps<{
    nom?: string | null;
    fullPath?: string | null;
}>();

const expandit = ref(false);

const toggle = () => {
    if (props.fullPath) expandit.value = !expandit.value;
};
</script>

<template>
    <td :class="{ 'cursor-pointer select-none': !!fullPath }" @click="toggle">
        <span v-if="expandit && fullPath" class="text-xs text-gray-400 dark:text-gray-500">{{ fullPath }}</span>
        <span v-else>{{ nom || '-' }}</span>
    </td>
</template>
