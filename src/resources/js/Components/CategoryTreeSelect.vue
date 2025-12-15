<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import CategoryNode from './CategoryNode.vue';

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
}

interface Props {
    categories: Categoria[];
    modelValue: number | null | 'none';
    allowNone?: boolean;
    disabledId?: number;
    placeholder?: string;
}

interface Emits {
    (e: 'update:modelValue', value: number | null | 'none'): void;
}

const props = withDefaults(defineProps<Props>(), {
    allowNone: false,
    disabledId: undefined,
    placeholder: 'Selecciona una categoria...',
});

const emit = defineEmits<Emits>();

const expandedNodes = ref<Set<number>>(new Set());

// Function to expand all parent nodes of a given category
const expandPathToCategory = (categoryId: number | null | 'none') => {
    if (categoryId === null || categoryId === 'none') {
        return;
    }

    const parentsToExpand: number[] = [];
    let current = props.categories.find(c => c.id === categoryId);

    // Traverse up the tree to find all parents
    while (current && current.categoria_pare_id !== null) {
        parentsToExpand.push(current.categoria_pare_id);
        current = props.categories.find(c => c.id === current!.categoria_pare_id);
    }

    // Add all parents to expanded nodes
    parentsToExpand.forEach(id => expandedNodes.value.add(id));
};

// Expand path when component mounts
onMounted(() => {
    expandPathToCategory(props.modelValue);
});

// Watch for changes in modelValue and expand the path
watch(() => props.modelValue, (newValue) => {
    expandPathToCategory(newValue);
}, { immediate: true });

// Build hierarchical tree structure
const categoryTree = computed(() => {
    const buildTree = (parentId: number | null): Categoria[] => {
        return props.categories
            .filter(cat => cat.categoria_pare_id === parentId)
            .sort((a, b) => a.nom.localeCompare(b.nom));
    };

    return buildTree(null);
});

const toggleNode = (categoryId: number) => {
    if (expandedNodes.value.has(categoryId)) {
        expandedNodes.value.delete(categoryId);
    } else {
        expandedNodes.value.add(categoryId);
    }
};

const selectCategory = (categoryId: number | null | 'none') => {
    emit('update:modelValue', categoryId);
};

const isSelected = (categoryId: number | null | 'none'): boolean => {
    return props.modelValue === categoryId;
};

// Get full path for a category
const getCategoryPath = (categoryId: number): string => {
    const path: string[] = [];
    let current = props.categories.find(c => c.id === categoryId);

    while (current) {
        path.unshift(current.nom);
        current = current.categoria_pare_id
            ? props.categories.find(c => c.id === current!.categoria_pare_id)
            : undefined;
    }

    return path.join(' > ');
};
</script>

<template>
    <div class="category-tree-select">
        <!-- Selected value display -->
        <div class="mb-2 text-sm text-gray-600 dark:text-gray-400">
            <span v-if="modelValue === null">{{ placeholder }}</span>
            <span v-else-if="modelValue === 'none'" class="text-orange-600 dark:text-orange-400">Sense categoria</span>
            <span v-else class="font-medium text-indigo-600 dark:text-indigo-400">
                {{ getCategoryPath(modelValue) }}
            </span>
        </div>

        <!-- Category tree -->
        <div class="border border-gray-300 dark:border-gray-600 rounded-md p-3 bg-white dark:bg-gray-700 max-h-96 overflow-y-auto">
            <!-- All categories option -->
            <div
                @click="selectCategory(null)"
                :class="[
                    'px-3 py-2 cursor-pointer rounded-md transition-colors mb-1',
                    isSelected(null)
                        ? 'bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 font-medium'
                        : 'hover:bg-gray-100 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300'
                ]"
            >
                Totes les categories
            </div>

            <!-- No category option -->
            <div
                v-if="allowNone"
                @click="selectCategory('none')"
                :class="[
                    'px-3 py-2 cursor-pointer rounded-md transition-colors mb-2',
                    isSelected('none')
                        ? 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300 font-medium'
                        : 'hover:bg-gray-100 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300'
                ]"
            >
                🚫 Sense categoria
            </div>

            <!-- Render tree recursively -->
            <template v-for="rootCategory in categoryTree" :key="rootCategory.id">
                <CategoryNode
                    :category="rootCategory"
                    :categories="categories"
                    :expanded-nodes="expandedNodes"
                    :selected-id="modelValue"
                    :disabled-id="disabledId"
                    :level="0"
                    @toggle="toggleNode"
                    @select="selectCategory"
                />
            </template>
        </div>
    </div>
</template>

