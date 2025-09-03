<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\FileWatcherService;
use Illuminate\Support\Facades\Log;

class FileWatcherController extends Controller
{
    private FileWatcherService $fileWatcher;

    public function __construct(FileWatcherService $fileWatcher)
    {
        $this->fileWatcher = $fileWatcher;
    }

    /**
     * Vérifier les changements (endpoint léger pour polling)
     */
    public function checkChanges(Request $request): JsonResponse
    {
        try {
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selectedFolder = $request->session()->get('selected_folder');
            if (!$selectedFolder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun dossier sélectionné.'
                ], 400);
            }

            // Surveillance des changements
            $changes = $this->fileWatcher->watchFiles($selectedFolder);

            $response = [
                'success' => true,
                'has_changes' => !empty($changes),
                'timestamp' => now()->toISOString()
            ];

            // Si des changements sont détectés, inclure les détails
            if (!empty($changes)) {
                $response['changes'] = $changes;
                $response['affected_areas'] = $this->getAffectedAreas($changes);
                
                Log::info('Changements détectés dans les fichiers Access', [
                    'changes_count' => count($changes),
                    'affected_files' => array_column($changes, 'file_type')
                ]);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Erreur vérification changements', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification'
            ], 500);
        }
    }

    /**
     * Réinitialiser la surveillance
     */
    public function resetWatcher(Request $request): JsonResponse
    {
        try {
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selectedFolder = $request->session()->get('selected_folder');
            if (!$selectedFolder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun dossier sélectionné.'
                ], 400);
            }

            $this->fileWatcher->resetWatcher($selectedFolder);

            return response()->json([
                'success' => true,
                'message' => 'Surveillance réinitialisée'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation'
            ], 500);
        }
    }

    /**
     * Obtenir le statut de la surveillance
     */
    public function getWatcherStatus(Request $request): JsonResponse
    {
        try {
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selectedFolder = $request->session()->get('selected_folder');
            if (!$selectedFolder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun dossier sélectionné.'
                ], 400);
            }

            // Obtenir les chemins et vérifier l'existence
            $filePaths = $this->getAccessFilePaths($selectedFolder);
            $fileStatus = [];

            foreach ($filePaths as $type => $path) {
                $fileStatus[$type] = [
                    'exists' => file_exists($path),
                    'path' => $path,
                    'last_modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'folder_name' => $selectedFolder['name'] ?? 'Unknown',
                    'files_status' => $fileStatus,
                    'watcher_active' => true
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut'
            ], 500);
        }
    }

    /**
     * Déterminer quelles zones de l'interface sont affectées
     */
    private function getAffectedAreas(array $changes): array
    {
        $areas = [];

        foreach ($changes as $change) {
            switch ($change['file_type']) {
                case 'caiss':
                    $areas[] = 'articles';
                    $areas[] = 'families';
                    break;
                case 'facturation':
                    $areas[] = 'stocks';
                    $areas[] = 'statistics';
                    break;
                case 'frontoffice':
                    $areas[] = 'tickets';
                    break;
            }
        }

        return array_unique($areas);
    }

    /**
     * Obtenir les chemins des fichiers (copie de la logique du service)
     */
    private function getAccessFilePaths($selectedFolder): array
    {
        $paths = [];
        
        if (isset($selectedFolder['stored_file_path'])) {
            $caissPath = storage_path('app/' . $selectedFolder['stored_file_path']);
            $folderPath = dirname($caissPath);
            
            $paths = [
                'caiss' => $caissPath,
                'facturation' => $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb',
                'frontoffice' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb'
            ];
        } else if (isset($selectedFolder['folder_path'])) {
            $folderPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $selectedFolder['folder_path']);
            
            $paths = [
                'caiss' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss.mdb',
                'facturation' => $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb',
                'frontoffice' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb'
            ];
        }
        
        return $paths;
    }
}