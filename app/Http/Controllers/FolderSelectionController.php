<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Exception;

class FolderSelectionController extends Controller
{
    /**
     * NOUVELLE MÉTHODE - Uploader et traiter le fichier Caiss.mdb
     */
    public function uploadCaissFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'caiss_file' => 'required|file|mimes:mdb|max:50000', // Max 50MB
                'folder_name' => 'required|string',
                'total_access_files' => 'nullable|integer'
            ]);

            $file = $request->file('caiss_file');
            $folderName = $request->input('folder_name');
            $totalAccessFiles = $request->input('total_access_files', 1);

            // Créer un nom de fichier unique
            $timestamp = now()->format('Y-m-d_H-i-s');
            $safeFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $folderName);
            $storedFileName = $safeFolderName . '_' . $timestamp . '.mdb';

            // Stocker le fichier dans storage/app/uploaded_databases
            $storedPath = $file->storeAs('uploaded_databases', $storedFileName);

            if (!$storedPath) {
                throw new Exception('Échec de l\'enregistrement du fichier');
            }

            // Obtenir le chemin complet
            $fullStoredPath = Storage::path($storedPath);

            // Tester la connexion au fichier uploadé
            try {
                $testPdo = $this->testAccessConnection($fullStoredPath);
                $stmt = $testPdo->query("SELECT COUNT(*) as total FROM Article");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $articleCount = $result['total'] ?? 0;
            } catch (Exception $e) {
                // Supprimer le fichier en cas d'erreur de connexion
                Storage::delete($storedPath);
                throw new Exception('Fichier uploadé invalide: ' . $e->getMessage());
            }

            // Enregistrer les informations en session
            $selectionData = [
                'folder_name' => $folderName,
                'folder_path' => $fullStoredPath, // Chemin complet vers le fichier uploadé
                'stored_file_path' => $storedPath,
                'access_file_path' => $fullStoredPath, // Même chose pour la cohérence
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'uploaded_at' => now()->toISOString(),
                'quarter' => $this->detectQuarter($folderName),
                'year' => $this->detectYear($folderName),
                'total_access_files' => $totalAccessFiles,
                'method' => 'file_upload',
                'article_count' => $articleCount,
                'validated' => true
            ];

            // Démarrer la session si elle n'existe pas
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            // Stocker en session
            $request->session()->put('selected_folder', $selectionData);
            $request->session()->save();

            // Log de l'activité
            Log::info('Fichier Caiss.mdb uploadé et traité', [
                'folder_name' => $folderName,
                'stored_path' => $storedPath,
                'file_size' => $file->getSize(),
                'article_count' => $articleCount
            ]);

            $this->triggerAutoSync();

            return response()->json([
                'success' => true,
                'message' => 'Fichier Caiss.mdb uploadé et traité avec succès.',
                'data' => [
                    'folder_name' => $folderName,
                    'file_size_mb' => round($file->getSize() / 1024 / 1024, 2),
                    'uploaded_at' => $selectionData['uploaded_at'],
                    'quarter' => $selectionData['quarter'],
                    'year' => $selectionData['year'],
                    'total_access_files' => $totalAccessFiles,
                    'method' => 'file_upload',
                    'article_count' => $articleCount
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier invalide. Seuls les fichiers .mdb sont acceptés (max 50MB).',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Erreur lors de l\'upload du fichier Caiss.mdb', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload: ' . $e->getMessage()
            ], 500);
        }
    }

   //Sélectionner un dossier avec recherche automatique intelligente
    public function selectFolder(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'folder_name' => 'required|string|max:100',
                'folder_path' => 'nullable|string|max:500',
                'access_method' => 'nullable|string'
            ]);

            $folderName = $request->input('folder_name');
            $providedPath = $request->input('folder_path', '');
            $accessMethod = $request->input('access_method', 'direct_only');

            Log::info('Tentative de sélection de dossier', [
                'folder_name' => $folderName,
                'provided_path' => $providedPath,
                'access_method' => $accessMethod
            ]);

            // Nettoyer le nom du dossier
            $cleanFolderName = str_replace('Dossier: ', '', $folderName);

            // RECHERCHE AUTOMATIQUE du fichier Caiss.mdb
            $foundCaissPath = $this->findCaissFile($cleanFolderName, $providedPath);

            if (!$foundCaissPath) {
                // Loguer les tentatives pour debug
                $searchLocations = $this->getSearchLocations($cleanFolderName, $providedPath);
                
                Log::warning('Fichier Caiss.mdb non trouvé', [
                    'folder_name' => $cleanFolderName,
                    'provided_path' => $providedPath,
                    'searched_locations' => $searchLocations
                ]);

                $this->triggerAutoSync();

                return response()->json([
                    'success' => false,
                    'message' => "Le fichier Caiss.mdb n'existe pas dans : Dossier: $cleanFolderName",
                    'technical_details' => "Impossible de localiser le fichier Caiss.mdb pour le dossier '$cleanFolderName'",
                    'suggestion' => 'upload_file',
                    'debug_info' => [
                        'searched_folder' => $cleanFolderName,
                        'provided_path' => $providedPath,
                        'searched_locations' => array_slice($searchLocations, 0, 10) // Limiter pour l'affichage
                    ]
                ], 400);
            }

            // TESTER LA CONNEXION au fichier trouvé
            try {
                $testPdo = $this->testAccessConnection($foundCaissPath);
                
                // Compter les articles pour validation
                $stmt = $testPdo->query("SELECT COUNT(*) as total FROM Article");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $articleCount = $result['total'] ?? 0;

                Log::info('Connexion Access réussie', [
                    'file_path' => $foundCaissPath,
                    'article_count' => $articleCount
                ]);

            } catch (Exception $e) {
                Log::error('Connexion Access échouée', [
                    'file_path' => $foundCaissPath,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Impossible d'accéder à la base Access: " . $e->getMessage(),
                    'found_path' => $foundCaissPath,
                    'suggestion' => 'Vérifiez que les drivers ODBC Microsoft Access sont installés et que le fichier n\'est pas corrompu.'
                ], 500);
            }

            // STOCKER EN SESSION les informations complètes
            $folderData = [
                'folder_name' => $cleanFolderName,
                'folder_path' => dirname($foundCaissPath), // Le dossier parent
                'access_file_path' => $foundCaissPath,     // Le chemin complet vers Caiss.mdb
                'access_method' => 'direct_access',
                'selected_at' => now()->toISOString(),
                'article_count' => $articleCount,
                'quarter' => $this->detectQuarter($cleanFolderName),
                'year' => $this->detectYear($cleanFolderName),
                'method' => 'direct_access',
                'validated' => true,
                'file_size' => file_exists($foundCaissPath) ? filesize($foundCaissPath) : 0
            ];

            // Stocker en session
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $request->session()->put('selected_folder', $folderData);
            $request->session()->save();

            Log::info('Dossier sélectionné avec succès', [
                'folder_data' => $folderData,
                'session_id' => $request->session()->getId()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accès direct établi avec succès',
                'data' => [
                    'folder_name' => $cleanFolderName,
                    'folder_path' => dirname($foundCaissPath),
                    'article_count' => $articleCount,
                    'quarter' => $folderData['quarter'],
                    'year' => $folderData['year'],
                    'method' => 'direct_access',
                    'file_size_mb' => round($folderData['file_size'] / 1024 / 1024, 2)
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Erreur lors de la sélection de dossier', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la sélection: ' . $e->getMessage()
            ], 500);
        }
    }

    //Rechercher le fichier Caiss.mdb dans tous les emplacements possibles
    private function findCaissFile(string $folderName, string $providedPath = ''): ?string
    {
        $searchLocations = $this->getSearchLocations($folderName, $providedPath);

        foreach ($searchLocations as $location) {
            $caissPath = $location . DIRECTORY_SEPARATOR . 'Caiss.mdb';
            
            Log::debug('Test de localisation', ['path' => $caissPath]);
            
            if (file_exists($caissPath) && is_readable($caissPath)) {
                Log::info('Fichier Caiss.mdb trouvé', ['path' => $caissPath]);
                return $caissPath;
            }
        }

        Log::warning('Aucun fichier Caiss.mdb trouvé', [
            'folder_name' => $folderName,
            'provided_path' => $providedPath,
            'total_locations_tested' => count($searchLocations)
        ]);

        return null;
    }

    /**
     * NOUVELLE MÉTHODE À AJOUTER - Déclenche la synchronisation automatique
     */
    private function triggerAutoSync(): void
    {
        try {
            // Exécuter la commande en arrière-plan (asynchrone)
            if (PHP_OS_FAMILY === 'Windows') {
                // Windows
                $command = 'start /B php ' . base_path('artisan') . ' sync:selected-folder --auto > NUL 2>&1';
                pclose(popen($command, 'r'));
            } else {
                // Linux/Unix
                $command = 'php ' . base_path('artisan') . ' sync:selected-folder --auto > /dev/null 2>&1 &';
                exec($command);
            }
            
            Log::info('Auto sync triggered in background');
            
        } catch (Exception $e) {
            // Ne pas interrompre le processus principal si la sync échoue
            Log::warning('Failed to trigger auto sync: ' . $e->getMessage());
        }
    }

    //Générer tous les emplacements de recherche possibles
    private function getSearchLocations(string $folderName, string $providedPath = ''): array
    {
        $locations = [];

        // 1. Si un chemin est fourni et semble complet ou partiellement utilisable
        if ($providedPath && strlen($providedPath) > 3) {
            $cleanPath = str_replace(['Dossier: ', '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $providedPath);
            
            // Si c'est différent du nom du dossier seul
            if ($cleanPath !== $folderName) {
                $locations[] = $cleanPath;
                
                // Essayer aussi en tant que chemin relatif depuis différentes bases
                if (!preg_match('/^[A-Z]:/i', $cleanPath)) {
                    $locations[] = 'C:' . DIRECTORY_SEPARATOR . $cleanPath;
                    $locations[] = 'D:' . DIRECTORY_SEPARATOR . $cleanPath;
                }
            }
        }

        // 2. Emplacements basés sur VOTRE structure connue (D:\Apicommerce\PdV)
        $knownBaseStructures = [
            // Votre structure principale
            'D:' . DIRECTORY_SEPARATOR . 'Apicommerce' . DIRECTORY_SEPARATOR . 'PdV',
            'C:' . DIRECTORY_SEPARATOR . 'Apicommerce' . DIRECTORY_SEPARATOR . 'PdV',
            
            // Variations possibles
            'D:' . DIRECTORY_SEPARATOR . 'ApiCommerce' . DIRECTORY_SEPARATOR . 'PdV',
            'C:' . DIRECTORY_SEPARATOR . 'ApiCommerce' . DIRECTORY_SEPARATOR . 'PdV',
            'D:' . DIRECTORY_SEPARATOR . 'API_Commerce' . DIRECTORY_SEPARATOR . 'PdV',
            'C:' . DIRECTORY_SEPARATOR . 'API_Commerce' . DIRECTORY_SEPARATOR . 'PdV',
            
            // Autres structures courantes pour pharmacies
            'D:' . DIRECTORY_SEPARATOR . 'Pharmacie' . DIRECTORY_SEPARATOR . 'Data',
            'C:' . DIRECTORY_SEPARATOR . 'Pharmacie' . DIRECTORY_SEPARATOR . 'Data',
            'D:' . DIRECTORY_SEPARATOR . 'Pharmacie' . DIRECTORY_SEPARATOR . 'PdV',
            'C:' . DIRECTORY_SEPARATOR . 'Pharmacie' . DIRECTORY_SEPARATOR . 'PdV',
            
            // Emplacements génériques
            'D:' . DIRECTORY_SEPARATOR . 'Database',
            'C:' . DIRECTORY_SEPARATOR . 'Database',
            'D:' . DIRECTORY_SEPARATOR . 'Data',
            'C:' . DIRECTORY_SEPARATOR . 'Data',
            
            // Emplacements Laravel (si fichiers copiés)
            storage_path('databases'),
            base_path('databases'),
            public_path('databases')
        ];

        foreach ($knownBaseStructures as $base) {
            if (is_dir($base) || strlen($base) > 10) { // Ajouter même si le répertoire n'existe pas encore
                $locations[] = $base . DIRECTORY_SEPARATOR . $folderName;
            }
        }

        // 3. Emplacements configurables via .env
        for ($i = 1; $i <= 5; $i++) {
            $envPath = env("CAISS_SEARCH_PATH_$i");
            if ($envPath) {
                $locations[] = $envPath . DIRECTORY_SEPARATOR . $folderName;
            }
        }

        // 4. Recherche dans les dossiers utilisateurs (Windows)
        if (isset($_SERVER['USERPROFILE']) && $_SERVER['USERPROFILE']) {
            $userProfile = $_SERVER['USERPROFILE'];
            $userLocations = [
                $userProfile . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . $folderName,
                $userProfile . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . $folderName,
                $userProfile . DIRECTORY_SEPARATOR . 'Downloads' . DIRECTORY_SEPARATOR . $folderName
            ];
            $locations = array_merge($locations, $userLocations);
        }

        // 5. Racines des lecteurs courants
        $driveRoots = ['C:', 'D:', 'E:', 'F:'];
        foreach ($driveRoots as $drive) {
            if (is_dir($drive . DIRECTORY_SEPARATOR)) {
                $locations[] = $drive . DIRECTORY_SEPARATOR . $folderName;
            }
        }

        // Nettoyer et dédupliquer
        $locations = array_unique($locations);
        
        // Trier par probabilité (chemins les plus spécifiques en premier)
        usort($locations, function($a, $b) {
            // Privilégier les chemins contenant "Apicommerce"
            $aHasApi = stripos($a, 'apicommerce') !== false;
            $bHasApi = stripos($b, 'apicommerce') !== false;
            
            if ($aHasApi && !$bHasApi) return -1;
            if (!$aHasApi && $bHasApi) return 1;
            
            // Puis par longueur (plus spécifique = plus long)
            return strlen($b) - strlen($a);
        });

        return $locations;
    }
    //Tester la connexion ODBC au fichier Access
    private function testAccessConnection(string $accessDbPath): PDO
    {
        $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $accessDbPath . ";";
        
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    }

   //Récupérer la sélection actuelle
    public function getCurrentSelection(Request $request): JsonResponse
    {
        try {
            // Démarrer la session si elle n'existe pas
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selection = $request->session()->get('selected_folder');

            if (!$selection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun dossier sélectionné.',
                    'data' => null
                ], 404);
            }

            // Vérifier que le fichier existe encore (pour accès direct)
            if (isset($selection['access_file_path']) && !file_exists($selection['access_file_path'])) {
                // Fichier n'existe plus, nettoyer la session
                $request->session()->forget('selected_folder');
                
                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier précédemment sélectionné n\'est plus accessible.',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sélection trouvée.',
                'data' => $selection
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération de la sélection', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la sélection.'
            ], 500);
        }
    }
    //Réinitialiser la sélection
    public function resetSelection(Request $request): JsonResponse
    {
        try {
            // Démarrer la session si elle n'existe pas
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selection = $request->session()->get('selected_folder');

            // Supprimer le fichier uploadé si c'était un upload
            if ($selection && isset($selection['stored_file_path']) && isset($selection['method']) && $selection['method'] === 'file_upload') {
                try {
                    if (Storage::exists($selection['stored_file_path'])) {
                        Storage::delete($selection['stored_file_path']);
                        Log::info('Fichier uploadé supprimé', ['path' => $selection['stored_file_path']]);
                    }
                } catch (Exception $e) {
                    Log::warning('Erreur lors de la suppression du fichier uploadé', [
                        'path' => $selection['stored_file_path'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $request->session()->forget('selected_folder');
            $request->session()->save();

            return response()->json([
                'success' => true,
                'message' => 'Sélection réinitialisée.'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la réinitialisation', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation.'
            ], 500);
        }
    }

    //Recherche globale des fichiers Caiss.mdb
    public function globalSearch(Request $request): JsonResponse
    {
        try {
            $foundFiles = [];
            $searchStartTime = microtime(true);
            
            // Rechercher sur les lecteurs principaux
            $drives = ['C:', 'D:', 'E:'];
            
            foreach ($drives as $drive) {
                if (is_dir($drive . DIRECTORY_SEPARATOR)) {
                    Log::info("Recherche globale sur $drive");
                    $found = $this->recursiveSearch($drive . DIRECTORY_SEPARATOR, 'Caiss.mdb', 4);
                    $foundFiles = array_merge($foundFiles, $found);
                }
            }

            $searchDuration = round(microtime(true) - $searchStartTime, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    'found_files' => $foundFiles,
                    'total_found' => count($foundFiles),
                    'search_drives' => $drives,
                    'search_duration_seconds' => $searchDuration
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche globale: ' . $e->getMessage()
            ], 500);
        }
    }

    //Recherche recurive des fichiers
    private function recursiveSearch(string $directory, string $filename, int $maxDepth = 3, int $currentDepth = 0): array
    {
        $found = [];
        
        if ($currentDepth >= $maxDepth) {
            return $found;
        }

        try {
            $iterator = new \DirectoryIterator($directory);
            
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) continue;
                
                // Si c'est le fichier recherché
                if ($fileInfo->isFile() && strtolower($fileInfo->getFilename()) === strtolower($filename)) {
                    $found[] = [
                        'path' => $fileInfo->getPathname(),
                        'directory' => $fileInfo->getPath(),
                        'size' => $fileInfo->getSize(),
                        'size_mb' => round($fileInfo->getSize() / 1024 / 1024, 2),
                        'modified' => date('Y-m-d H:i:s', $fileInfo->getMTime())
                    ];
                }
                
                // Recherche récursive dans les sous-dossiers (en évitant certains dossiers système)
                if ($fileInfo->isDir() && !in_array($fileInfo->getFilename(), [
                    'System Volume Information', '$RECYCLE.BIN', 'Windows', 'Program Files', 
                    'Program Files (x86)', 'ProgramData', '.git', 'node_modules', 'vendor'
                ])) {
                    try {
                        $subFound = $this->recursiveSearch($fileInfo->getPathname(), $filename, $maxDepth, $currentDepth + 1);
                        $found = array_merge($found, $subFound);
                    } catch (Exception $e) {
                        // Ignorer les erreurs d'accès aux sous-dossiers
                        continue;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignorer les erreurs d'accès aux dossiers
        }

        return $found;
    }
    
    //Lister les dossiers disponibles
    public function listAvailableFolders(Request $request): JsonResponse
    {
        // Cette fonctionnalité n'est pas disponible pour une app web
        // car on ne peut pas accéder au système de fichiers client
        return response()->json([
            'success' => true,
            'message' => 'Fonctionnalité non disponible en mode web.',
            'data' => []
        ]);
    }

    /**
     * Détecter le trimestre à partir du nom du dossier (AMÉLIORÉ)
     */
    private function detectQuarter(string $folderName): ?string
    {
        $folderLower = strtolower($folderName);
        
        // Patterns pour détecter les trimestres
        $patterns = [
            'T1' => ['q1', 't1', 'trim1', 'quarter1', 'jan', 'fev', 'mar', 'janvier', 'fevrier', 'mars'],
            'T2' => ['q2', 't2', 'trim2', 'quarter2', 'avr', 'mai', 'jun', 'avril', 'juin'],
            'T3' => ['q3', 't3', 'trim3', 'quarter3', 'jul', 'aou', 'sep', 'juillet', 'aout', 'septembre'],
            'T4' => ['q4', 't4', 'trim4', 'quarter4', 'oct', 'nov', 'dec', 'octobre', 'novembre', 'decembre']
        ];

        foreach ($patterns as $quarter => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($folderLower, $keyword) !== false) {
                    return $quarter;
                }
            }
        }

        // Détecter par numéros dans le nom
        if (preg_match('/[tq](\d)/i', $folderName, $matches)) {
            return 'T' . $matches[1];
        }

        return "Non détecté - $folderName";
    }

    //Détecter l'année à partir du nom du dossier 
    private function detectYear(string $folderName): ?int
    {
        if (preg_match('/20\d{2}/', $folderName, $matches)) {
            return (int) $matches[0];
        }
        
        return null;
    }
}