<script setup lang="ts">
import { computed } from 'vue';

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
}

interface Props {
    category: Categoria;
    categories: Categoria[];
    expandedNodes: Set<number>;
    selectedId: number | string | null;
    level: number;
    disabledId?: number;
}

interface Emits {
    (e: 'toggle', value: number): void;
    (e: 'select', value: number): void;
}

const props = withDefaults(defineProps<Props>(), {
    level: 0,
    disabledId: undefined,
});

const emit = defineEmits<Emits>();

const hasChildren = computed(() => {
    return props.categories.some((cat: Categoria) => cat.categoria_pare_id === props.category.id);
});

const isExpanded = computed(() => {
    return props.expandedNodes.has(props.category.id);
});

const isSelected = computed(() => {
    return props.selectedId === props.category.id;
});

const isDisabled = computed(() => {
    return props.disabledId !== undefined && props.category.id === props.disabledId;
});

const children = computed(() => {
    return props.categories
        .filter((cat: Categoria) => cat.categoria_pare_id === props.category.id)
        .sort((a: Categoria, b: Categoria) => a.nom.localeCompare(b.nom));
});

const indentStyle = computed(() => {
    return `padding-left: ${props.level * 1.5 + 0.75}rem`;
});

const toggle = () => {
    if (hasChildren.value) {
        emit('toggle', props.category.id);
    }
};

const select = () => {
    if (!isDisabled.value) {
        emit('select', props.category.id);
    }
};
</script>

<template>
    <div>
        <div
            @click="select"
            :style="indentStyle"
            :class="[
                'py-2 pr-3 rounded-md transition-colors flex items-center gap-2',
                isDisabled
                    ? 'opacity-50 cursor-not-allowed text-gray-400 dark:text-gray-600'
                    : isSelected
                        ? 'bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 font-medium cursor-pointer'
                        : 'hover:bg-gray-100 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 cursor-pointer'
            ]"
        >
            <button
                v-if="hasChildren"
                @click.stop="toggle"
                class="w-5 h-5 flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            >
                <svg
                    class="w-4 h-4 transition-transform"
                    :class="{ 'rotate-90': isExpanded }"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <span v-else class="w-5"></span>
            <span class="flex-1">{{ category.nom }}</span>
        </div>

        <!-- Render children if expanded -->
        <template v-if="isExpanded && hasChildren">
            <CategoryNode
                v-for="child in children"
                :key="child.id"
                :category="child"
                :categories="categories"
                :expanded-nodes="expandedNodes"
                :selected-id="selectedId"
                :disabled-id="disabledId"
                :level="level + 1"
                @toggle="$emit('toggle', $event)"
                @select="$emit('select', $event)"
            />
        </template>
    </div>
</template>
