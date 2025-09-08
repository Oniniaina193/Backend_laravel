<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use PDO;
use Exception;

class OptimizedDirectAccessController extends Controller
{
    // Cache pour éviter les reconnexions répétées
    private $accessConnection = null;
    private $lastAccessPath = null;

    /**
     * Recherche d'articles optimisée - sans test de connexion lourd
     */
    public function searchArticles(Request $request): JsonResponse
    {
        try {
            // Vérification de session rapide
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

            // Obtenir les chemins des bases
            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            $facturationDbPath = $this->getFacturationDbPath($selectedFolder);
            
            // Connexion optimisée avec cache
            $accessPdo = $this->getOptimizedConnection($accessDbPath);

            // Construire la requête de recherche - PERMETTRE TOUS LES ARTICLES
            $whereConditions = [];
            $params = [];

            if (!empty(trim($searchTerm))) {
                $whereConditions[] = "LCASE(Libelle) LIKE LCASE(?)";
                $params[] = "%$searchTerm%";
            }

            if (!empty(trim($familyFilter))) {
                $whereConditions[] = "LCASE(CodeFam) LIKE LCASE(?)";
                $params[] = "%$familyFilter%";
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Requête optimisée - récupérer directement les résultats avec LIMIT
            if ($page == 1) {
                $query = "SELECT TOP $limit Code, Libelle, CodeFam, BaseTTC 
                         FROM Article 
                         $whereClause 
                         ORDER BY Libelle";
            } else {
                // Pour les pages suivantes, utiliser une approche optimisée
                $totalToFetch = $page * $limit;
                $query = "SELECT TOP $totalToFetch Code, Libelle, CodeFam, BaseTTC 
                         FROM Article 
                         $whereClause 
                         ORDER BY Libelle";
            }
            
            $stmt = $accessPdo->prepare($query);
            $stmt->execute($params);
            $allArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Pour la pagination > 1, prendre seulement la slice appropriée
            $articles = ($page == 1) ? $allArticles : array_slice($allArticles, $offset, $limit);

            // Récupération optimisée des stocks
            $stocksData = $this->getOptimizedStocks($articles, $facturationDbPath);

            // Formatage des résultats avec stock_status
            $formattedArticles = array_map(function($article) use ($stocksData) {
                $code = $this->fixEncoding($article['Code'] ?? '');
                $stock = $stocksData[$code] ?? 0;
                
                return [
                    'code' => $code,
                    'libelle' => $this->fixEncoding($article['Libelle'] ?? ''),
                    'famille' => $this->fixEncoding($article['CodeFam'] ?? ''),
                    'prix_ttc' => number_format(($article['BaseTTC'] ?? 0), 2, '.', ''),
                    'stock' => $stock,
                    'stock_status' => $this->getStockStatus($stock) // Ajouter le statut de stock
                ];
            }, $articles);

            // Estimation du total pour la pagination (évite COUNT() coûteux)
            $estimatedTotal = count($allArticles);
            if (count($allArticles) == ($totalToFetch ?? $limit)) {
                $estimatedTotal += $limit; // Il y a probablement plus d'éléments
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'articles' => $formattedArticles,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => max(1, ceil($estimatedTotal / $limit)),
                        'total_items' => $estimatedTotal,
                        'items_per_page' => $limit,
                        'has_next' => count($formattedArticles) == $limit,
                        'has_prev' => $page > 1
                    ],
                    'search_info' => [
                        'search_term' => $searchTerm,
                        'family_filter' => $familyFilter,
                        'results_count' => count($formattedArticles)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Erreur recherche optimisée', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm ?? 'N/A',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Déterminer le statut du stock (comme dans DirectAccessController)
     */
    private function getStockStatus(int $stock): string
    {
        if ($stock <= 0) {
            return 'rupture';
        } elseif ($stock <= 5) {
            return 'faible';
        } elseif ($stock <= 20) {
            return 'moyen';
        } else {
            return 'bon';
        }
    }

    /**
     * Connexion optimisée avec cache pour éviter les reconnexions
     */
    private function getOptimizedConnection(string $accessDbPath): PDO
    {
        // Utiliser la connexion en cache si c'est le même fichier
        if ($this->accessConnection !== null && $this->lastAccessPath === $accessDbPath) {
            try {
                // Tester rapidement la connexion avec une requête simple
                $this->accessConnection->query("SELECT 1");
                return $this->accessConnection;
            } catch (Exception $e) {
                // Connexion fermée, on va la recréer
                $this->accessConnection = null;
            }
        }

        // Vérification d'existence du fichier
        if (!file_exists($accessDbPath)) {
            throw new Exception("Fichier Access non trouvé: $accessDbPath");
        }

        // Nouvelle connexion
        try {
            $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $accessDbPath . ";";
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Mettre en cache
            $this->accessConnection = $pdo;
            $this->lastAccessPath = $accessDbPath;
            
            return $pdo;
            
        } catch (Exception $e) {
            throw new Exception("Erreur connexion Access: " . $e->getMessage());
        }
    }

    /**
     * Récupération optimisée des stocks - uniquement si facturation disponible
     */
    private function getOptimizedStocks(array $articles, string $facturationDbPath): array
    {
        $stocks = [];
        
        // Si pas de fichier facturation ou articles vides, retourner stocks vides
        if (!file_exists($facturationDbPath) || empty($articles)) {
            return $stocks;
        }

        try {
            $facturationPdo = $this->connectToAccess($facturationDbPath);
            
            // Extraire seulement les codes nécessaires
            $articleCodes = array_map(function($article) {
                return $this->fixEncoding($article['Code'] ?? '');
            }, $articles);

            if (empty($articleCodes)) {
                return $stocks;
            }

            // Requête optimisée avec limite sur les codes
            $placeholders = str_repeat('?,', count($articleCodes) - 1) . '?';
            
            // OPTIMISATION: Limiter aux mouvements récents si la table est volumineuse
            $stockQuery = "SELECT TOP 1000 CodeArticle, SUM(Quantite) as TotalStock 
                          FROM Mouvementstock 
                          WHERE CodeArticle IN ($placeholders)
                          GROUP BY CodeArticle";

            $stmt = $facturationPdo->prepare($stockQuery);
            $stmt->execute($articleCodes);
            $stockResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($stockResults as $stockRow) {
                $codeArticle = $this->fixEncoding($stockRow['CodeArticle'] ?? '');
                $totalStock = intval($stockRow['TotalStock'] ?? 0);
                $stocks[$codeArticle] = $totalStock;
            }

        } catch (Exception $e) {
            // En cas d'erreur de stock, ne pas faire échouer la recherche
            Log::warning('Erreur stocks (non bloquante)', [
                'error' => $e->getMessage(),
                'facturation_path' => $facturationDbPath
            ]);
        }

        return $stocks;
    }

    /**
     * Test de connexion SIMPLIFIÉ - juste vérifier l'accès au fichier
     */
    public function quickConnectionTest(Request $request): JsonResponse
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

            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            
            if (!file_exists($accessDbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Fichier non trouvé: $accessDbPath"
                ], 404);
            }

            // Test minimal - juste s'assurer qu'on peut ouvrir la connexion
            $accessPdo = $this->getOptimizedConnection($accessDbPath);
            
            return response()->json([
                'success' => true,
                'message' => 'Connexion Access disponible',
                'data' => [
                    'file_path' => $accessDbPath,
                    'status' => 'ready'
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur connexion: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les familles - version optimisée
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

            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            $accessPdo = $this->getOptimizedConnection($accessDbPath);

            // Requête optimisée avec limite pour éviter de surcharger
            $stmt = $accessPdo->prepare("
                SELECT DISTINCT TOP 100 CodeFam 
                FROM Article 
                WHERE CodeFam IS NOT NULL AND CodeFam <> '' 
                ORDER BY CodeFam
            ");
            $stmt->execute();
            $rawFamilies = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $families = array_map([$this, 'fixEncoding'], $rawFamilies);

            return response()->json([
                'success' => true,
                'data' => $families
            ]);

        } catch (Exception $e) {
            Log::error('Erreur récupération familles', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des familles.'
            ], 500);
        }
    }

    // ===== MÉTHODES UTILITAIRES =====

    private function fixEncoding($value): string
    {
        if (empty($value)) return '';
        if (mb_check_encoding($value, 'UTF-8')) return $value;

        $encodings = ['Windows-1252', 'ISO-8859-1', 'CP1252', 'UTF-8'];
        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function connectToAccess(string $accessDbPath): PDO
    {
        $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $accessDbPath . ";";
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function getAccessDbPath($selectedFolder): string
    {
        if (isset($selectedFolder['stored_file_path'])) {
            return storage_path('app/' . $selectedFolder['stored_file_path']);
        } else if (isset($selectedFolder['folder_path'])) {
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            return $normalizedPath . DIRECTORY_SEPARATOR . 'Caiss.mdb';
        }
        throw new Exception('Aucun chemin Access disponible');
    }

    private function getFacturationDbPath($selectedFolder): string
    {
        if (isset($selectedFolder['stored_file_path'])) {
            $caissPath = storage_path('app/' . $selectedFolder['stored_file_path']);
            $folderPath = dirname($caissPath);
            return $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb';
        } else if (isset($selectedFolder['folder_path'])) {
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            return $normalizedPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb';
        }
        throw new Exception('Aucun chemin caiss_facturation.mdb disponible');
    }
}