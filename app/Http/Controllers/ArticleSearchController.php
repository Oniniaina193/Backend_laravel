<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

class ArticleSearchController extends Controller
{
    /**
     * Rechercher des articles dans la base sélectionnée
     */
    public function searchArticles(Request $request): JsonResponse
    {
        try {
            // Vérifier qu'un dossier est sélectionné
            if (!$request->session()->isStarted()) {
                $request->session()->start();
            }

            $selectedFolder = $request->session()->get('selected_folder');
            if (!$selectedFolder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun dossier sélectionné. Veuillez d\'abord sélectionner un dossier.',
                    'redirect' => '/folder-selection'
                ], 400);
            }

            $request->validate([
                'search' => 'nullable|string|max:100',
                'family' => 'nullable|string|max:50',
                'page' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100'
            ]);

            $searchTerm = $request->input('search', '');
            $familyFilter = $request->input('family', '');
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 20);
            $offset = ($page - 1) * $limit;

            // Construire le chemin vers la base SQLite
            $sqliteDbPath = $this->getSQLiteDbPath($selectedFolder);
            
            // Vérifier si la base SQLite existe, sinon la créer
            if (!file_exists($sqliteDbPath)) {
                $conversionResult = $this->convertAccessToSQLite($selectedFolder);
                if (!$conversionResult['success']) {
                    return response()->json($conversionResult, 500);
                }
            }

            // Connexion à SQLite
            $sqlite = new PDO("sqlite:$sqliteDbPath");
            $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Construire la requête de recherche
            $whereConditions = [];
            $params = [];

            if (!empty($searchTerm)) {
                $whereConditions[] = "LOWER(Libelle) LIKE LOWER(?)";
                $params[] = "%$searchTerm%";
            }

            if (!empty($familyFilter)) {
                $whereConditions[] = "LOWER(CodeFam) LIKE LOWER(?)";
                $params[] = "%$familyFilter%";
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Requête pour compter le total
            $countQuery = "SELECT COUNT(*) as total FROM Article $whereClause";
            $countStmt = $sqlite->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Requête principale avec pagination
            $query = "SELECT Code, Libelle, CodeFam, BaseTTC 
                     FROM Article 
                     $whereClause 
                     ORDER BY Libelle 
                     LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $sqlite->prepare($query);
            $stmt->execute($params);
            $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formater les résultats
            $formattedArticles = array_map(function($article) {
                return [
                    'code' => $article['Code'],
                    'libelle' => $article['Libelle'],
                    'famille' => $article['CodeFam'],
                    'prix_ttc' => number_format($article['BaseTTC'], 2, '.', ''), // Pas de division
                    'prix_display' => number_format($article['BaseTTC'], 2, ',', ' ') . ' Ar'
                ];
            }, $articles);

            return response()->json([
                'success' => true,
                'data' => [
                    'articles' => $formattedArticles,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => ceil($total / $limit),
                        'total_items' => $total,
                        'items_per_page' => $limit,
                        'has_next' => $page < ceil($total / $limit),
                        'has_prev' => $page > 1
                    ],
                    'search_info' => [
                        'search_term' => $searchTerm,
                        'family_filter' => $familyFilter,
                        'results_count' => count($formattedArticles)
                    ]
                ]
            ]);

        } catch (\PDOException $e) {
            Log::error('Erreur PDO lors de la recherche d\'articles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur d\'accès à la base de données.'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche d\'articles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les familles disponibles pour filtrage
     */
    public function getFamilies(Request $request): JsonResponse
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

            $sqliteDbPath = $this->getSQLiteDbPath($selectedFolder);
            
            if (!file_exists($sqliteDbPath)) {
                $conversionResult = $this->convertAccessToSQLite($selectedFolder);
                if (!$conversionResult['success']) {
                    return response()->json($conversionResult, 500);
                }
            }

            $sqlite = new PDO("sqlite:$sqliteDbPath");
            $stmt = $sqlite->prepare("SELECT DISTINCT CodeFam FROM Article WHERE CodeFam IS NOT NULL AND CodeFam != '' ORDER BY CodeFam");
            $stmt->execute();
            $families = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return response()->json([
                'success' => true,
                'data' => $families
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des familles', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des familles.'
            ], 500);
        }
    }

    /**
     * Convertir la base Access en SQLite
     */
    private function convertAccessToSQLite($selectedFolder): array
{
    try {
        // Déterminer le chemin du fichier Access
        $accessDbPath = null;
        
        if (isset($selectedFolder['stored_file_path'])) {
            // Fichier uploadé - utiliser le chemin stocké
            $accessDbPath = Storage::path($selectedFolder['stored_file_path']);
            
            Log::info('Utilisation du fichier uploadé', [
                'stored_path' => $selectedFolder['stored_file_path'],
                'full_path' => $accessDbPath
            ]);
            
        } else if (isset($selectedFolder['folder_path'])) {
            // Chemin traditionnel (pour compatibilité)
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            $accessDbPath = $normalizedPath . DIRECTORY_SEPARATOR . 'Caiss.mdb';
            
            Log::info('Utilisation du chemin traditionnel', [
                'folder_path' => $folderPath,
                'access_path' => $accessDbPath
            ]);
        }

        if (!$accessDbPath) {
            return [
                'success' => false,
                'message' => 'Aucun chemin de fichier Access disponible.'
            ];
        }

        $sqliteDbPath = $this->getSQLiteDbPath($selectedFolder);

        // Vérifier que le fichier Access existe
        if (!file_exists($accessDbPath)) {
            Log::error('Fichier Access non trouvé', [
                'expected_path' => $accessDbPath,
                'file_exists' => file_exists($accessDbPath),
                'is_readable' => is_readable($accessDbPath)
            ]);
            
            return [
                'success' => false,
                'message' => "Fichier Access non accessible.\nChemin : $accessDbPath\nVérifiez que le fichier existe et est lisible."
            ];
        }

        // Créer le répertoire pour SQLite si nécessaire
        $sqliteDir = dirname($sqliteDbPath);
        if (!is_dir($sqliteDir)) {
            mkdir($sqliteDir, 0755, true);
        }

        // Vérifier si mdb-tools est disponible
        if (!command_exists('mdb-export')) {
            return [
                'success' => false,
                'message' => 'mdb-tools non installé.\n\nPour installer sur Windows :\n1. Installer WSL2\n2. Dans WSL : sudo apt-get install mdb-tools\n\nOu utiliser une alternative comme mdbtools-win ou convertir le fichier manuellement.'
            ];
        }

        // Commande mdb-tools pour convertir
        $command = "mdb-export " . escapeshellarg($accessDbPath) . " Article";
        $csvData = shell_exec($command);
        
        if (!$csvData) {
            throw new \Exception('Échec de l\'export depuis Access avec mdb-tools');
        }

        // Créer la base SQLite
        $sqlite = new PDO("sqlite:$sqliteDbPath");
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Créer la table
        $sqlite->exec("
            CREATE TABLE IF NOT EXISTS Article (
                Code TEXT,
                Libelle TEXT,
                CodeFam TEXT,
                BaseTTC INTEGER
            )
        ");

        // Créer des index pour les recherches
        $sqlite->exec("CREATE INDEX IF NOT EXISTS idx_libelle ON Article(Libelle)");
        $sqlite->exec("CREATE INDEX IF NOT EXISTS idx_codefam ON Article(CodeFam)");
        $sqlite->exec("CREATE INDEX IF NOT EXISTS idx_code ON Article(Code)");

        // Parser et insérer les données CSV
        $lines = explode("\n", trim($csvData));
        $header = str_getcsv(array_shift($lines));
        
        $stmt = $sqlite->prepare("INSERT INTO Article (Code, Libelle, CodeFam, BaseTTC) VALUES (?, ?, ?, ?)");
        
        $insertedCount = 0;
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $data = str_getcsv($line);
            if (count($data) >= 4) {
                try {
                    $stmt->execute([
                        $data[0], // Code
                        $data[1], // Libelle
                        $data[2], // CodeFam
                        intval($data[3] * 100) // BaseTTC (convertir en centimes si nécessaire)
                    ]);
                    $insertedCount++;
                } catch (\PDOException $e) {
                    Log::warning('Erreur insertion ligne CSV', [
                        'line' => $line,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        Log::info('Conversion Access vers SQLite réussie', [
            'access_file' => $accessDbPath,
            'sqlite_path' => $sqliteDbPath,
            'records_inserted' => $insertedCount
        ]);

        return [
            'success' => true, 
            'message' => "Conversion réussie. $insertedCount articles importés."
        ];

    } catch (\Exception $e) {
        Log::error('Erreur lors de la conversion Access vers SQLite', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'access_path' => $accessDbPath ?? 'non défini'
        ]);

        return [
            'success' => false,
            'message' => 'Erreur lors de la conversion: ' . $e->getMessage()
        ];
    }
}

    /**
     * Obtenir le chemin vers la base SQLite pour un dossier donné
     */
    private function getSQLiteDbPath($selectedFolder): string
    {
        $safeFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $selectedFolder['folder_name']);
        $cacheDir = storage_path('app/database_cache');
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        return $cacheDir . '/' . $safeFolderName . '_articles.sqlite';
    }
}

// Fonction helper pour vérifier si une commande existe
function command_exists($command) {
    $whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';
    $process = proc_open(
        "$whereIsCommand $command",
        array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        ),
        $pipes
    );
    if ($process !== false) {
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return !empty(trim($output));
    }
    return false;
}