<?php

namespace App\Http\Controllers;

use App\Http\Services\Categories\CategoryDeletionService;
use App\Http\Services\Categories\CategoryImportService;
use App\Http\Services\DataProcess\FileParserService;
use App\Http\Services\Categories\KMyMoneyCategoryParserService;
use App\Models\CompteCorrent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class CategoryImportController extends Controller
{
    public function __construct(
        private KMyMoneyCategoryParserService $categoryParser,
        private CategoryImportService $categoryImporter,
        private CategoryDeletionService $categoryDeletion,
        private FileParserService $fileParser
    ) {
    }

    /**
     * Show the category import page
     */
    public function index(): Response
    {
        $comptesCorrents = CompteCorrent::orderBy('ordre')->orderBy('entitat')->get();

        return Inertia::render('Maintenance/CategoryImport', [
            'comptesCorrents' => $comptesCorrents,
        ]);
    }

    /**
     * Parse uploaded KMyMoney category file
     */
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:txt,csv,qif|max:10240', // 10MB max
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
        ]);

        try {
            $file = $request->file('file');
            $compteCorrentId = $request->input('compte_corrent_id');

            // Read file content
            $content = file_get_contents($file->getRealPath());

            // Parse KMyMoney format
            $parsedCategories = $this->categoryParser->parse($content);

            if (empty($parsedCategories)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No s\'han trobat categories al fitxer',
                ], 400);
            }

            // Separate categories by type
            $categoriesI = array_filter($parsedCategories, fn($cat) => $cat['type'] === 'I');
            $categoriesE = array_filter($parsedCategories, fn($cat) => $cat['type'] === 'E');

            // Validate both types
            $validationI = $this->categoryImporter->validate($categoriesI, $compteCorrentId, 'I');
            $validationE = $this->categoryImporter->validate($categoriesE, $compteCorrentId, 'E');

            // Convert to hierarchical structure for preview
            $hierarchicalI = $this->categoryParser->toHierarchical($categoriesI);
            $hierarchicalE = $this->categoryParser->toHierarchical($categoriesE);

            // Merge validation results
            $validation = [
                'valid' => $validationI['valid'] && $validationE['valid'],
                'errors' => array_merge($validationI['errors'], $validationE['errors']),
                'warnings' => array_merge($validationI['warnings'], $validationE['warnings']),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Fitxer analitzat correctament',
                'data' => [
                    'total_categories' => count($parsedCategories),
                    'total_ingressos' => count($categoriesI),
                    'total_despeses' => count($categoriesE),
                    'categories_ingressos' => $hierarchicalI,
                    'categories_despeses' => $hierarchicalE,
                    'validation' => $validation,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error parsing KMyMoney category file', [
                'error' => $e->getMessage(),
                'file' => $request->file('file')?->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    /**
     * Import categories to database
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:txt,csv,qif|max:10240',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
        ]);

        try {
            $file = $request->file('file');
            $compteCorrentId = $request->input('compte_corrent_id');

            // Read and parse file
            $content = file_get_contents($file->getRealPath());
            $parsedCategories = $this->categoryParser->parse($content);

            if (empty($parsedCategories)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hi ha categories per importar',
                ], 400);
            }

            // Separate categories by type
            $categoriesI = array_filter($parsedCategories, fn($cat) => $cat['type'] === 'I');
            $categoriesE = array_filter($parsedCategories, fn($cat) => $cat['type'] === 'E');

            // Import both types
            $statsI = !empty($categoriesI)
                ? $this->categoryImporter->import($categoriesI, $compteCorrentId, 'I')
                : ['created' => 0, 'skipped' => 0, 'errors' => []];

            $statsE = !empty($categoriesE)
                ? $this->categoryImporter->import($categoriesE, $compteCorrentId, 'E')
                : ['created' => 0, 'skipped' => 0, 'errors' => []];

            // Merge stats
            $stats = [
                'ingressos' => $statsI,
                'despeses' => $statsE,
                'total_created' => $statsI['created'] + $statsE['created'],
                'total_skipped' => $statsI['skipped'] + $statsE['skipped'],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Categories importades correctament',
                'data' => [
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error importing categories', [
                'error' => $e->getMessage(),
                'file' => $request->file('file')?->getClientOriginalName(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error important les categories',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    /**
     * Delete imported categories for a specific compte corrent or all
     */
    public function deleteImported(Request $request): JsonResponse
    {
        $request->validate([
            'compte_corrent_id' => 'nullable|integer|exists:g_comptes_corrents,id',
            'confirmed' => 'required|boolean|accepted',
        ]);

        try {
            $compteCorrentId = $request->input('compte_corrent_id');

            DB::beginTransaction();

            if ($compteCorrentId) {
                // Delete for specific compte corrent
                $result = $this->categoryDeletion->deleteForCompteCorrent($compteCorrentId);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "S'han eliminat {$result['deleted_count']} categories del compte seleccionat",
                    'data' => $result,
                ]);
            } else {
                // Delete all categories globally
                $result = $this->categoryDeletion->deleteAll();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "S'han eliminat {$result['deleted_count']} categories de tots els comptes i s'ha reiniciat l'autoincrement",
                    'data' => $result,
                ]);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error deleting imported categories', [
                'error' => $e->getMessage(),
                'compte_corrent_id' => $request->input('compte_corrent_id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error eliminant les categories',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }
}
