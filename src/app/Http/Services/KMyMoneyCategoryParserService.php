<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Log;

class KMyMoneyCategoryParserService
{
    /**
     * Parse KMyMoney category export file
     *
     * @param string $content File content
     * @return array Parsed categories structure
     */
    public function parse(string $content): array
    {
        $lines = explode("\n", $content);
        $categories = [];
        $this->nameToFullPathMap = []; // Reset the mapping for each parse
        $i = 0;

        // Skip header line if exists
        if (isset($lines[0]) && str_starts_with($lines[0], '!Type:Cat')) {
            $i = 1;
        }

        while ($i < count($lines)) {
            // Skip empty lines
            if (empty(trim($lines[$i]))) {
                $i++;
                continue;
            }

            // Check if this is a category line (starts with N)
            if (!str_starts_with($lines[$i], 'N')) {
                $i++;
                continue;
            }

            // Extract category hierarchy (remove N prefix)
            $hierarchy = substr($lines[$i], 1);
            $i++;

            // Check for type line (I or E)
            if ($i >= count($lines) || !in_array(trim($lines[$i]), ['I', 'E'])) {
                Log::warning('KMyMoney parser: Expected type line (I/E) after category', [
                    'line' => $i,
                    'content' => $lines[$i] ?? 'EOF'
                ]);
                continue;
            }

            $type = trim($lines[$i]);
            $i++;

            // Check for end marker (^)
            if ($i >= count($lines) || trim($lines[$i]) !== '^') {
                Log::warning('KMyMoney parser: Expected end marker (^) after type', [
                    'line' => $i,
                    'content' => $lines[$i] ?? 'EOF'
                ]);
                continue;
            }

            $i++; // Move past the ^

            // Parse hierarchy and add to categories
            $this->addCategoryToTree($categories, $hierarchy, $type);
        }

        return $categories;
    }

    /**
     * Track category name to full path mapping
     * This is needed because KMyMoney uses the last segment as reference
     * e.g., "Sous:Sou Marta" can be referenced later as just "Sou Marta"
     */
    private array $nameToFullPathMap = [];

    /**
     * Add category to tree structure
     *
     * @param array &$categories Categories array (by reference)
     * @param string $hierarchy Category hierarchy (e.g., "Lloguer:Lloguer camps:JORDI FERRER")
     * @param string $type Type (I or E)
     */
    private function addCategoryToTree(array &$categories, string $hierarchy, string $type): void
    {
        $parts = explode(':', $hierarchy);
        $reconstructedParts = [];

        foreach ($parts as $index => $part) {
            $part = trim($part);

            if (empty($part)) {
                continue;
            }

            // If this is not the first part, check if the parent exists in our name map
            // This handles cases like "Sou Marta:Dietes" where "Sou Marta" was previously
            // defined as "Sous:Sou Marta"
            if ($index === 0 && isset($this->nameToFullPathMap[$part])) {
                // This part was previously seen as a full category path
                // Use the full path as the base
                $reconstructedParts = explode(':', $this->nameToFullPathMap[$part]);
            } else {
                // Add this part to the reconstructed path
                $reconstructedParts[] = $part;
            }

            // Normalize: uppercase and trim
            $normalizedName = mb_strtoupper($part, 'UTF-8');

            // Determine parent path for this category
            $parentPath = count($reconstructedParts) > 1
                ? implode(':', array_slice($reconstructedParts, 0, count($reconstructedParts) - 1))
                : null;

            // Create full path for this category using reconstructed parts
            $fullPath = implode(':', $reconstructedParts);

            // Determine the level based on the full reconstructed path
            $level = count($reconstructedParts) - 1;

            // Check if category already exists in tree
            if (!isset($categories[$fullPath])) {
                $categories[$fullPath] = [
                    'name' => $normalizedName,
                    'original_name' => $part,
                    'type' => $type, // I or E
                    'parent_path' => $parentPath,
                    'full_path' => $fullPath,
                    'level' => $level,
                ];

                // Map the category name (last segment) to its full path
                // This allows us to find "Sou Marta" even when referenced without "Sous:" prefix
                $this->nameToFullPathMap[$part] = $fullPath;
            }
        }
    }

    /**
     * Convert parsed categories to hierarchical structure for preview
     *
     * @param array $categories Flat categories array
     * @return array Hierarchical structure
     */
    public function toHierarchical(array $categories): array
    {
        $rootCategories = [];

        // Collect root categories
        foreach ($categories as $fullPath => $category) {
            if ($category['level'] === 0) {
                $rootCategories[] = $category;
            }
        }

        // Sort root categories alphabetically by name
        usort($rootCategories, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Build tree with sorted root categories
        $tree = [];
        foreach ($rootCategories as $category) {
            $tree[] = $this->buildCategoryNode($category, $categories);
        }

        return $tree;
    }

    /**
     * Build category node with children
     *
     * @param array $category Category data
     * @param array $allCategories All categories
     * @return array Category node with children
     */
    private function buildCategoryNode(array $category, array $allCategories): array
    {
        $node = [
            'name' => $category['name'],
            'type' => $category['type'],
            'level' => $category['level'],
            'children' => [],
        ];

        // Find children
        $children = [];
        foreach ($allCategories as $childCategory) {
            if ($childCategory['parent_path'] === $category['full_path']) {
                $children[] = $childCategory;
            }
        }

        // Sort children alphabetically by name
        usort($children, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Build child nodes recursively
        foreach ($children as $child) {
            $node['children'][] = $this->buildCategoryNode($child, $allCategories);
        }

        return $node;
    }
}
