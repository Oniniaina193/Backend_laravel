<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Models\Ordonnance;
use App\Models\Medecin;
use PDO;
use Exception;
use Carbon\Carbon;

class StatistiquesController extends Controller
{
    /**
     * Vérifier qu'un dossier est sélectionné
     */
    private function checkDossierSelection(Request $request)
    {
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
        return null;
    }

    /**
     * Obtenir toutes les statistiques pour le tableau de bord
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        try {
            // Vérifier la sélection du dossier
            $folderCheck = $this->checkDossierSelection($request);
            if ($folderCheck) return $folderCheck;

            $selectedFolder = $request->session()->get('selected_folder');
            $folderName = $selectedFolder['folder_name'];

            // 1. Médicaments en stock (depuis Access)
            $medicamentsEnStock = $this->getMedicamentsEnStock($selectedFolder);

            // 2. Médecins partenaires (depuis PostgreSQL)
            $medecinsPartenaires = Medecin::count();

            // 3. Ordonnances du jour (depuis PostgreSQL + dossier actuel)
            $ordonnancesDuJour = $this->getOrdonnancesDuJour($folderName);

            // 4. Total ordonnances (depuis PostgreSQL + dossier actuel)
            $totalOrdonnances = $this->getTotalOrdonnances($folderName);

            return response()->json([
                'success' => true,
                'data' => [
                    'medicaments_en_stock' => $medicamentsEnStock,
                    'medecins_partenaires' => $medecinsPartenaires,
                    'ordonnances_du_jour' => $ordonnancesDuJour,
                    'total_ordonnances' => $totalOrdonnances,
                    'dossier_actuel' => $folderName
                ],
                'message' => 'Statistiques récupérées avec succès'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des statistiques', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les données pour le graphique des ventes mensuelles
     */
    public function getVentesMensuelles(Request $request): JsonResponse
    {
        try {
            // Vérifier la sélection du dossier
            $folderCheck = $this->checkDossierSelection($request);
            if ($folderCheck) return $folderCheck;

            $selectedFolder = $request->session()->get('selected_folder');
            
            // Connexions aux bases Access
            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            $frontofficeDbPath = $this->getFrontofficeDbPath($selectedFolder);
            
            if (!file_exists($frontofficeDbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fichier Caiss_frontoffice.mdb non trouvé pour les données de vente'
                ], 404);
            }

            $frontofficePdo = $this->connectToAccess($frontofficeDbPath);

            // Requête pour récupérer les ventes par mois
            $ventesQuery = "
                SELECT 
                    YEAR(t.DateDoc) as annee,
                    MONTH(t.DateDoc) as mois,
                    SUM(tl.Qte) as total_medicaments
                FROM Ticket t
                INNER JOIN TicketLigne tl ON t.Id = tl.CodeDoc
                WHERE t.DateDoc IS NOT NULL
                GROUP BY YEAR(t.DateDoc), MONTH(t.DateDoc)
                ORDER BY YEAR(t.DateDoc), MONTH(t.DateDoc)
            ";

            $stmt = $frontofficePdo->prepare($ventesQuery);
            $stmt->execute();
            $ventesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formater les données pour le graphique
            $ventesFormatees = [];
            $moisNoms = [
                1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
            ];

            foreach ($ventesData as $vente) {
                $moisNom = $moisNoms[(int)$vente['mois']] ?? 'Inconnu';
                $ventesFormatees[] = [
                    'mois' => $moisNom,
                    'mois_numero' => (int)$vente['mois'],
                    'annee' => (int)$vente['annee'],
                    'total_medicaments' => (int)$vente['total_medicaments'],
                    'label' => $moisNom . ' ' . $vente['annee']
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ventes_mensuelles' => $ventesFormatees,
                    'total_mois' => count($ventesFormatees),
                    'periode' => $this->getPeriodeFromData($ventesFormatees)
                ],
                'message' => 'Données de ventes mensuelles récupérées avec succès'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des ventes mensuelles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des ventes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir le top 10 des médicaments les plus vendus
     */
    public function getTopMedicaments(Request $request): JsonResponse
    {
        try {
            // Vérifier la sélection du dossier
            $folderCheck = $this->checkDossierSelection($request);
            if ($folderCheck) return $folderCheck;

            $selectedFolder = $request->session()->get('selected_folder');
            $frontofficeDbPath = $this->getFrontofficeDbPath($selectedFolder);
            
            if (!file_exists($frontofficeDbPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fichier Caiss_frontoffice.mdb non trouvé pour les données de vente'
                ], 404);
            }

            $frontofficePdo = $this->connectToAccess($frontofficeDbPath);

            // Requête pour récupérer le top des médicaments
            $topQuery = "
                SELECT TOP 10
                    tl.Designation,
                    SUM(tl.Qte) as total_vendu
                FROM TicketLigne tl
                INNER JOIN Ticket t ON tl.CodeDoc = t.Id
                WHERE tl.Designation IS NOT NULL AND tl.Designation <> ''
                GROUP BY tl.Designation
                ORDER BY SUM(tl.Qte) DESC
            ";

            $stmt = $frontofficePdo->prepare($topQuery);
            $stmt->execute();
            $topData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formater les données avec correction d'encodage
            $topFormate = array_map(function($item, $index) {
                return [
                    'position' => $index + 1,
                    'medicament' => $this->fixEncoding($item['Designation'] ?? ''),
                    'total_vendu' => (int)($item['total_vendu'] ?? 0),
                    'pourcentage' => 0 // Sera calculé côté client
                ];
            }, $topData, array_keys($topData));

            // Calculer les pourcentages
            $totalGlobal = array_sum(array_column($topFormate, 'total_vendu'));
            if ($totalGlobal > 0) {
                foreach ($topFormate as &$item) {
                    $item['pourcentage'] = round(($item['total_vendu'] / $totalGlobal) * 100, 1);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'top_medicaments' => $topFormate,
                    'total_global' => $totalGlobal,
                    'nombre_medicaments' => count($topFormate)
                ],
                'message' => 'Top des médicaments récupéré avec succès'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération du top médicaments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du top: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Méthodes privées utilitaires
     */

    /**
     * Obtenir le nombre total de médicaments en stock depuis Access
     */
    private function getMedicamentsEnStock($selectedFolder): int
    {
        try {
            $accessDbPath = $this->getAccessDbPath($selectedFolder);
            $accessPdo = $this->connectToAccess($accessDbPath);

            $stmt = $accessPdo->query("SELECT COUNT(*) as total FROM Article");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int)($result['total'] ?? 0);

        } catch (Exception $e) {
            Log::error('Erreur lors du comptage des médicaments en stock', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Obtenir le nombre d'ordonnances créées aujourd'hui
     */
    private function getOrdonnancesDuJour(string $folderName): int
    {
        try {
            return Ordonnance::allDossiers()
                ->where('dossier_vente', $folderName)
                ->whereDate('created_at', today())
                ->count();

        } catch (Exception $e) {
            Log::error('Erreur lors du comptage des ordonnances du jour', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Obtenir le nombre total d'ordonnances du dossier
     */
    private function getTotalOrdonnances(string $folderName): int
    {
        try {
            return Ordonnance::allDossiers()
                ->where('dossier_vente', $folderName)
                ->count();

        } catch (Exception $e) {
            Log::error('Erreur lors du comptage total des ordonnances', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Obtenir la période couverte par les données
     */
    private function getPeriodeFromData(array $ventesData): array
    {
        if (empty($ventesData)) {
            return ['debut' => null, 'fin' => null, 'duree_mois' => 0];
        }

        $premier = $ventesData[0];
        $dernier = $ventesData[count($ventesData) - 1];

        return [
            'debut' => $premier['label'],
            'fin' => $dernier['label'],
            'duree_mois' => count($ventesData),
            'annee_debut' => $premier['annee'],
            'annee_fin' => $dernier['annee']
        ];
    }

    /**
     * Corriger l'encodage des données Access
     */
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

    /**
     * Connexion directe à Access via ODBC
     */
    private function connectToAccess(string $accessDbPath): PDO
    {
        try {
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
     * Obtenir le chemin du fichier Access principal
     */
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

    /**
     * Obtenir le chemin du fichier Caiss_frontoffice.mdb
     */
    private function getFrontofficeDbPath($selectedFolder): string
    {
        if (isset($selectedFolder['stored_file_path'])) {
            $caissPath = storage_path('app/' . $selectedFolder['stored_file_path']);
            $folderPath = dirname($caissPath);
            return $folderPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb';
        } else if (isset($selectedFolder['folder_path'])) {
            $folderPath = $selectedFolder['folder_path'];
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folderPath);
            return $normalizedPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb';
        }
        
        throw new Exception('Aucun chemin Caiss_frontoffice.mdb disponible');
    }
}