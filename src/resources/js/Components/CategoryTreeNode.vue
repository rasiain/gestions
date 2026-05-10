<script setup lang="ts">
import { computed } from 'vue';

interface Categoria {
    id: number;
    compte_corrent_id: number;
    nom: string;
    categoria_pare_id: number | null;
    ordre: number;
    fills?: Categoria[];
    created_at: string;
    updated_at: string;
}

interface Props {
    categoria: Categoria;
    level: number;
    isExpanded: boolean;
    expandedCategories: Set<number>;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    toggle: [categoriaId: number];
    createChild: [parentId: number];
    edit: [categoria: Categoria];
    delete: [categoriaId: number];
    calcularTotals: [categoria: Categoria];
    veurMoviments: [categoria: Categoria];
}>();

const hasChildren = computed(() => {
    return props.categoria.fills && props.categoria.fills.length > 0;
});

const indentStyle = computed(() => {
    return {
        paddingLeft: `${props.level * 1.5}rem`
    };
});
</script>

<template>
    <div class="category-tree-node">
        <!-- Category Row -->
        <div
            class="flex items-center justify-between border-b border-gray-200 py-3 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50"
            :style="indentStyle"
        >
            <div class="flex items-center gap-2">
                <!-- Expand/Collapse Button -->
                <button
                    v-if="hasChildren"
                    @click="emit('toggle', categoria.id)"
                    class="flex h-6 w-6 items-center justify-center rounded hover:bg-gray-200 dark:hover:bg-gray-600"
                    type="button"
                >
                    <svg
                        class="h-4 w-4 text-gray-600 dark:text-gray-400 transition-transform"
                        :class="{ 'rotate-90': isExpanded }"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 5l7 7-7 7"
                        />
                    </svg>
                </button>
                <div v-else class="w-6"></div>

                <!-- Category Name -->
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ categoria.nom }}
                </span>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2">
                <!-- Moviments Button -->
                <button
                    @click="emit('veurMoviments', categoria)"
                    class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    type="button"
                    title="Veure moviments"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                </button>

                <!-- Totals Button -->
                <button
                    @click="emit('calcularTotals', categoria)"
                    class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    type="button"
                    title="Calcular totals"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </button>

                <!-- Add Child Category Button -->
                <button
                    @click="emit('createChild', categoria.id)"
                    class="rounded-md bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700"
                    type="button"
                >
                    Afegir subcategoria
                </button>

                <!-- Edit Button -->
                <button
                    @click="emit('edit', categoria)"
                    class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                    type="button"
                >
                    Editar
                </button>

                <!-- Delete Button -->
                <button
                    @click="emit('delete', categoria.id)"
                    class="rounded-md bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700"
                    type="button"
                >
                    Eliminar
                </button>
            </div>
        </div>

        <!-- Children (Recursive) -->
        <template v-if="hasChildren && isExpanded">
            <CategoryTreeNode
                v-for="child in categoria.fills"
                :key="child.id"
                :categoria="child"
                :level="level + 1"
                :is-expanded="expandedCategories.has(child.id)"
                :expanded-categories="expandedCategories"
                @toggle="emit('toggle', $event)"
                @create-child="emit('createChild', $event)"
                @edit="emit('edit', $event)"
                @delete="emit('delete', $event)"
                @calcular-totals="emit('calcularTotals', $event)"
                @veur-moviments="emit('veurMoviments', $event)"
            />
        </template>
    </div>
</template>
