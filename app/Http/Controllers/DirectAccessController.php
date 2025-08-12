<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use PDO;
use Exception;

class DirectAccessController extends Controller
{
    //Lire deuis access
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

            // Obtenir les chemins Access
            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            $facturationDbPath = $this->getFacturationDbPath($selectedFolder);
            
            if (!file_exists($accessDbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Fichier Access non trouvé: $accessDbPath"
                ], 404);
            }

            // Connexion directe à Access via ODBC
            $accessPdo = $this->connectToAccess($accessDbPath);

            // Construire la requête de recherche
            $whereConditions = [];
            $params = [];

            if (!empty($searchTerm)) {
                $whereConditions[] = "LCASE(Libelle) LIKE LCASE(?)";
                $params[] = "%$searchTerm%";
            }

            if (!empty($familyFilter)) {
                $whereConditions[] = "LCASE(CodeFam) LIKE LCASE(?)";
                $params[] = "%$familyFilter%";
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Compter le total (Access syntax)
            $countQuery = "SELECT COUNT(*) as total FROM Article $whereClause";
            $countStmt = $accessPdo->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Pagination compatible Access
            $articles = [];
            
            if ($page == 1) {
                // Page 1: Utiliser simplement TOP
                $query = "SELECT TOP $limit Code, Libelle, CodeFam, BaseTTC 
                         FROM Article 
                         $whereClause 
                         ORDER BY Libelle";
                
                $stmt = $accessPdo->prepare($query);
                $stmt->execute($params);
                $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } else {
                // Pages suivantes: Récupérer plus et filtrer
                $totalToFetch = $page * $limit;
                
                $query = "SELECT TOP $totalToFetch Code, Libelle, CodeFam, BaseTTC 
                         FROM Article 
                         $whereClause 
                         ORDER BY Libelle";
                
                $stmt = $accessPdo->prepare($query);
                $stmt->execute($params);
                $allArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Prendre seulement les articles de la page demandée
                $articles = array_slice($allArticles, $offset, $limit);
            }

            // Récupérer les stocks depuis caiss_facturation.mdb
            $stocksData = $this->getStocksForArticles($articles, $facturationDbPath);

            // Formater les résultats 
            $formattedArticles = array_map(function($article) use ($stocksData) {
                $code = $this->fixEncoding($article['Code'] ?? '');
                $stock = $stocksData[$code] ?? 0; // Stock par défaut à 0
                
                return [
                    'code' => $code,
                    'libelle' => $this->fixEncoding($article['Libelle'] ?? ''),
                    'famille' => $this->fixEncoding($article['CodeFam'] ?? ''),
                    'prix_ttc' => number_format(($article['BaseTTC'] ?? 0), 2, '.', ''),
                    'prix_display' => number_format(($article['BaseTTC'] ?? 0), 2, ',', ' ') . ' Ar',
                    'stock' => $stock,
                    'stock_display' => $stock . ' unité' . ($stock > 1 ? 's' : ''),
                    'stock_status' => $this->getStockStatus($stock)
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

        } catch (Exception $e) {
            Log::error('Erreur lors de la recherche directe Access', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche: ' . $e->getMessage()
            ], 500);
        }
    }

      //Récupérer les stocks depuis caiss_facturation.mdb
    private function getStocksForArticles(array $articles, string $facturationDbPath): array
    {
        $stocks = [];
        
        try {
            // Vérifier si le fichier caiss_facturation.mdb existe
            if (!file_exists($facturationDbPath)) {
                Log::warning('Fichier caiss_facturation.mdb non trouvé', [
                    'path' => $facturationDbPath
                ]);
                return $stocks; // Retourner un tableau vide, stocks à 0
            }

            // Connexion à caiss_facturation.mdb
            $facturationPdo = $this->connectToAccess($facturationDbPath);
            
            // Extraire les codes d'articles pour la requête
            $articleCodes = array_map(function($article) {
                return $this->fixEncoding($article['Code'] ?? '');
            }, $articles);

            if (empty($articleCodes)) {
                return $stocks;
            }

            // Construire la requête pour récupérer les stocks
            // On utilise une approche compatible Access avec IN()
            $placeholders = str_repeat('?,', count($articleCodes) - 1) . '?';
            
            $stockQuery = "SELECT CodeArticle, SUM(Quantite) as TotalStock 
                          FROM Mouvementstock 
                          WHERE CodeArticle IN ($placeholders)
                          GROUP BY CodeArticle";

            $stmt = $facturationPdo->prepare($stockQuery);
            $stmt->execute($articleCodes);
            $stockResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Traiter les résultats avec correction d'encodage
            foreach ($stockResults as $stockRow) {
                $codeArticle = $this->fixEncoding($stockRow['CodeArticle'] ?? '');
                $totalStock = intval($stockRow['TotalStock'] ?? 0);
                $stocks[$codeArticle] = $totalStock;
            }

            Log::info('Stocks récupérés depuis caiss_facturation', [
                'articles_count' => count($articleCodes),
                'stocks_found' => count($stocks),
                'facturation_path' => $facturationDbPath
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des stocks', [
                'error' => $e->getMessage(),
                'facturation_path' => $facturationDbPath
            ]);
            
        }

        return $stocks;
    }

    //Déterminer le statut du stock
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

    //Obtenir le chemin du fichier caiss_facturation.mdb
    private function getFacturationDbPath($selectedFolder): string
    {
        if (isset($selectedFolder['stored_file_path'])) {
            // Fichier uploadé - le fichier facturation est dans le même dossier
            $caissPath = storage_path('app/' . $selectedFolder['stored_file_path']);
            $folderPath = dirname($caissPath);
            return $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb';
        } else if (isset($selectedFolder['folder_path'])) {
            // Chemin traditionnel
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            return $normalizedPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb';
        }
        
        throw new Exception('Aucun chemin caiss_facturation.mdb disponible');
    }

    //Corriger l'encodage des données Access
    private function fixEncoding($value): string
    {
        if (empty($value)) {
            return '';
        }

        // Si déjà en UTF-8 valide, retourner tel quel
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Essayer différents encodages courants pour Access
        $encodings = ['Windows-1252', 'ISO-8859-1', 'CP1252', 'UTF-8'];
        
        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        // En dernier recours, nettoyer les caractères non-UTF-8
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    //Obtenir les familles directement depuis Access 
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
            $accessPdo = $this->connectToAccess($accessDbPath);

            $stmt = $accessPdo->prepare("
                SELECT DISTINCT CodeFam 
                FROM Article 
                WHERE CodeFam IS NOT NULL AND CodeFam <> '' 
                ORDER BY CodeFam
            ");
            $stmt->execute();
            $rawFamilies = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Corriger l'encodage des familles
            $families = array_map([$this, 'fixEncoding'], $rawFamilies);

            return response()->json([
                'success' => true,
                'data' => $families
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des familles', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des familles.'
            ], 500);
        }
    }

  //Connexion directe à Access via ODBC
    private function connectToAccess(string $accessDbPath): PDO
    {
        try {
            // Construire DSN ODBC pour Access
            $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $accessDbPath . ";";
            
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            return $pdo;
            
        } catch (Exception $e) {
            throw new Exception("Erreur connexion Access: " . $e->getMessage());
        }
    }

    /**
     * Obtenir le chemin du fichier Access
     */
    private function getAccessDbPath($selectedFolder): string
    {
        if (isset($selectedFolder['stored_file_path'])) {
            // Fichier uploadé
            return storage_path('app/' . $selectedFolder['stored_file_path']);
        } else if (isset($selectedFolder['folder_path'])) {
            // Chemin traditionnel
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            return $normalizedPath . DIRECTORY_SEPARATOR . 'Caiss.mdb';
        }
        
        throw new Exception('Aucun chemin Access disponible');
    }

    /**
     * Tester la connexion Access AVEC test du stock
     */
    public function testConnection(Request $request): JsonResponse
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
            $facturationDbPath = $this->getFacturationDbPath($selectedFolder);
            
            if (!file_exists($accessDbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Fichier non trouvé: $accessDbPath"
                ], 404);
            }

            $accessPdo = $this->connectToAccess($accessDbPath);
            
            // Test simple avec correction d'encodage
            $stmt = $accessPdo->query("SELECT COUNT(*) as total FROM Article");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // Test d'un échantillon pour vérifier l'encodage
            $sampleStmt = $accessPdo->query("SELECT TOP 3 Code, Libelle FROM Article");
            $samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $fixedSamples = array_map(function($sample) {
                return [
                    'code' => $this->fixEncoding($sample['Code'] ?? ''),
                    'libelle' => $this->fixEncoding($sample['Libelle'] ?? '')
                ];
            }, $samples);

            // NOUVEAU : Test de la connexion à caiss_facturation.mdb
            $facturationStatus = [
                'available' => false,
                'path' => $facturationDbPath,
                'total_movements' => 0
            ];

            try {
                if (file_exists($facturationDbPath)) {
                    $facturationPdo = $this->connectToAccess($facturationDbPath);
                    $stockStmt = $facturationPdo->query("SELECT COUNT(*) as total FROM Mouvementstock");
                    $stockResult = $stockStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $facturationStatus['available'] = true;
                    $facturationStatus['total_movements'] = $stockResult['total'];
                }
            } catch (Exception $e) {
                Log::warning('Erreur connexion caiss_facturation', ['error' => $e->getMessage()]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Connexion Access réussie',
                'data' => [
                    'file_path' => $accessDbPath,
                    'total_articles' => $result['total'],
                    'encoding_test' => $fixedSamples,
                    'facturation_db' => $facturationStatus
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Erreur test connexion Access', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur connexion: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les infos de structure de la table Article
     */
    public function getTableStructure(Request $request): JsonResponse
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
            $accessPdo = $this->connectToAccess($accessDbPath);

            // Lister les colonnes de la table Article
            $stmt = $accessPdo->query("SELECT TOP 1 * FROM Article");
            $rawSampleRow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $columns = $rawSampleRow ? array_keys($rawSampleRow) : [];
            
            // Corriger l'encodage de l'échantillon
            $sampleRow = [];
            if ($rawSampleRow) {
                foreach ($rawSampleRow as $key => $value) {
                    $sampleRow[$key] = is_string($value) ? $this->fixEncoding($value) : $value;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table_name' => 'Article',
                    'columns' => $columns,
                    'sample_row' => $sampleRow
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur structure: ' . $e->getMessage()
            ], 500);
        }
    }
}