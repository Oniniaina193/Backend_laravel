<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Client;
use App\Models\Medecin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Exception;

class OrdonnanceController extends Controller
{
    /**
     * Vérifier qu'un dossier est sélectionné avant toute opération
     */
    private function checkDossierSelection(Request $request)
    {
        if (!$request->has('current_dossier_vente') || !$request->get('current_dossier_vente')) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun dossier de vente sélectionné. Veuillez d\'abord sélectionner un dossier.',
                'error_code' => 'NO_FOLDER_SELECTED'
            ], 400);
        }
        return null;
    }

    /**
     * Liste paginée des ordonnances avec recherche
     * AUTOMATIQUEMENT filtrée par le dossier sélectionné
     */
    public function index(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $perPage = min($request->get('per_page', 20), 100);
            $search = $request->get('search');
            $medecinId = $request->get('medecin_id');
            $clientId = $request->get('client_id');
            $dateDebut = $request->get('date_debut');
            $dateFin = $request->get('date_fin');

            // Le global scope s'applique automatiquement - seules les ordonnances du dossier actuel
            $ordonnances = Ordonnance::with(['medecin', 'client'])
                ->when($search, function ($query, $search) {
                    $query->search($search);
                })
                ->when($medecinId, function ($query, $medecinId) {
                    $query->byMedecin($medecinId);
                })
                ->when($clientId, function ($query, $clientId) {
                    $query->byClient($clientId);
                })
                ->when($dateDebut || $dateFin, function ($query) use ($dateDebut, $dateFin) {
                    $query->byDateRange($dateDebut, $dateFin);
                })
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Ajouter le nombre de médicaments pour chaque ordonnance
            $ordonnances->getCollection()->transform(function ($ordonnance) {
                $ordonnance->total_medicaments = $ordonnance->lignes()->count();
                return $ordonnance;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'ordonnances' => $ordonnances->items(),
                    'pagination' => [
                        'current_page' => $ordonnances->currentPage(),
                        'last_page' => $ordonnances->lastPage(),
                        'per_page' => $ordonnances->perPage(),
                        'total' => $ordonnances->total(),
                        'from' => $ordonnances->firstItem(),
                        'to' => $ordonnances->lastItem(),
                    ],
                    'current_dossier' => $request->get('current_dossier_vente')
                ],
                'message' => 'Ordonnances récupérées avec succès'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des ordonnances',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle ordonnance
     * AUTOMATIQUEMENT liée au dossier sélectionné
     */
    public function store(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $currentDossier = $request->get('current_dossier_vente');

            $validated = $request->validate([
                'numero_ordonnance' => [
                    'required',
                    'string',
                    'max:50',
                    // NOUVELLE RÈGLE : unique seulement dans le dossier actuel
                    function ($attribute, $value, $fail) use ($currentDossier) {
                        if (Ordonnance::numeroExists($value, $currentDossier)) {
                            $fail("Ce numéro d'ordonnance existe déjà dans le dossier $currentDossier");
                        }
                    }
                ],
                'medecin_id' => 'required|exists:medecins,id',
                'date' => 'required|date',
                
                // Données client
                'client_id' => 'nullable|exists:clients,id',
                'client' => 'required_without:client_id|array',
                'client.nom_complet' => 'required_without:client_id|string|max:255',
                'client.adresse' => 'required_without:client_id|string|max:255',
                'client.telephone' => 'nullable|string|max:20',
                
                // Médicaments prescrits
                'medicaments' => 'required|array|min:1',
                'medicaments.*.code_medicament' => 'required|string|max:50',
                'medicaments.*.designation' => 'required|string|max:255',
                'medicaments.*.quantite' => 'required|integer|min:1',
                'medicaments.*.posologie' => 'required|string|max:500',
                'medicaments.*.duree' => 'required|string|max:100',
            ], [
                'numero_ordonnance.required' => 'Le numéro d\'ordonnance est obligatoire',
                'medecin_id.required' => 'Veuillez sélectionner un médecin',
                'medecin_id.exists' => 'Le médecin sélectionné n\'existe pas',
            ]);

            DB::beginTransaction();

            // 1. Créer ou récupérer le client
            if ($request->has('client_id') && $request->client_id) {
                $client = Client::findOrFail($request->client_id);
            } else {
                $client = Client::create($validated['client']);
            }

            // 2. Créer l'ordonnance - le dossier sera ajouté automatiquement par le model
            $ordonnance = Ordonnance::create([
                'numero_ordonnance' => $validated['numero_ordonnance'],
                // dossier_vente sera ajouté automatiquement par le model booted()
                'date' => $validated['date'],
                'medecin_id' => $validated['medecin_id'],
                'client_id' => $client->id,
            ]);

            // 3. Créer les lignes de médicaments
            foreach ($validated['medicaments'] as $medicament) {
                OrdonnanceLigne::create([
                    'ordonnance_id' => $ordonnance->id,
                    'code_medicament' => $medicament['code_medicament'],
                    'designation' => $medicament['designation'],
                    'quantite' => $medicament['quantite'],
                    'posologie' => $medicament['posologie'],
                    'duree' => $medicament['duree'],
                ]);
            }

            DB::commit();

            // Charger les relations pour la réponse
            $ordonnance->load(['medecin', 'client', 'lignes']);

            return response()->json([
                'success' => true,
                'data' => $ordonnance,
                'message' => "Ordonnance créée avec succès dans le dossier $currentDossier"
            ], 201);

        } catch (ValidationException $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Données de validation incorrectes',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'ordonnance',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Afficher une ordonnance spécifique
     * AUTOMATIQUEMENT limitée au dossier sélectionné
     */
    public function show(Ordonnance $ordonnance): JsonResponse
    {
        try {
            // Le global scope garantit qu'on ne peut voir que les ordonnances du dossier actuel
            $ordonnance->load(['medecin', 'client', 'lignes']);

            return response()->json([
                'success' => true,
                'data' => $ordonnance
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'ordonnance',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Mettre à jour une ordonnance
     * AUTOMATIQUEMENT limitée au dossier sélectionné
     */
    public function update(Request $request, Ordonnance $ordonnance): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $validated = $request->validate([
                'medecin_id' => 'required|exists:medecins,id',
                'date' => 'required|date',
                
                // Données client modifiables
                'client.nom_complet' => 'required|string|max:255',
                'client.adresse' => 'required|string|max:255',
                'client.telephone' => 'nullable|string|max:20',
                
                // Médicaments - posologie et durée modifiables
                'medicaments' => 'required|array|min:1',
                'medicaments.*.id' => 'nullable|exists:ordonnance_lignes,id',
                'medicaments.*.code_medicament' => 'required|string|max:50',
                'medicaments.*.designation' => 'required|string|max:255',
                'medicaments.*.quantite' => 'required|integer|min:1',
                'medicaments.*.posologie' => 'required|string|max:500',
                'medicaments.*.duree' => 'required|string|max:100',
            ]);

            DB::beginTransaction();

            // 1. Mettre à jour l'ordonnance (SANS le numéro et SANS le dossier)
            $ordonnance->update([
                'medecin_id' => $validated['medecin_id'],
                'date' => $validated['date'],
            ]);

            // 2. Mettre à jour le client
            $ordonnance->client->update($validated['client']);

            // 3. Gérer les médicaments
            $existingIds = [];
            
            foreach ($validated['medicaments'] as $medicament) {
                if (isset($medicament['id']) && $medicament['id']) {
                    // Modifier médicament existant
                    $ligne = OrdonnanceLigne::find($medicament['id']);
                    if ($ligne && $ligne->ordonnance_id == $ordonnance->id) {
                        $ligne->update([
                            'code_medicament' => $medicament['code_medicament'],
                            'designation' => $medicament['designation'],
                            'quantite' => $medicament['quantite'],
                            'posologie' => $medicament['posologie'],
                            'duree' => $medicament['duree'],
                        ]);
                        $existingIds[] = $ligne->id;
                    }
                } else {
                    // Créer nouveau médicament
                    $nouvelleLigne = OrdonnanceLigne::create([
                        'ordonnance_id' => $ordonnance->id,
                        'code_medicament' => $medicament['code_medicament'],
                        'designation' => $medicament['designation'],
                        'quantite' => $medicament['quantite'],
                        'posologie' => $medicament['posologie'],
                        'duree' => $medicament['duree'],
                    ]);
                    $existingIds[] = $nouvelleLigne->id;
                }
            }

            // Supprimer les lignes supprimées
            OrdonnanceLigne::where('ordonnance_id', $ordonnance->id)
                           ->whereNotIn('id', $existingIds)
                           ->delete();

            DB::commit();

            // Recharger les données
            $ordonnance->load(['medecin', 'client', 'lignes']);

            return response()->json([
                'success' => true,
                'data' => $ordonnance,
                'message' => 'Ordonnance modifiée avec succès'
            ]);

        } catch (ValidationException $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Données de validation incorrectes',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l\'ordonnance',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Supprimer une ordonnance
     * AUTOMATIQUEMENT limitée au dossier sélectionné
     */
    public function destroy(Ordonnance $ordonnance): JsonResponse
    {
        try {
            DB::beginTransaction();
            $ordonnance->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ordonnance supprimée avec succès'
            ]);

        } catch (Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'ordonnance',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

/**
 * Générer une version imprimable HTML de l'ordonnance
 */
public function generatePrintableHtml(Ordonnance $ordonnance): JsonResponse
{
    try {
        // Charger toutes les relations nécessaires
        $ordonnance->load(['medecin', 'client', 'lignes']);

        // Générer le HTML formaté pour l'impression
        $html = $this->buildPrintableHtml($ordonnance);

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'ordonnance' => $ordonnance,
                'print_date' => now()->format('d/m/Y à H:i')
            ],
            'message' => 'Version imprimable générée avec succès'
        ]);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la génération de l\'ordonnance imprimable',
            'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
        ], 500);
    }
}

/**
 * Construire le HTML formaté pour l'impression
 */
private function buildPrintableHtml(Ordonnance $ordonnance): string
{
    $html = '
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ordonnance ' . htmlspecialchars($ordonnance->numero_ordonnance) . '</title>
        <style>
            @media print {
                @page {
                    margin: 1cm;
                    size: A4;
                }
                body { margin: 0; }
                .no-print { display: none !important; }
            }
            
            body {
                font-family: "Arial", sans-serif;
                font-size: 12pt;
                line-height: 1.4;
                color: #000;
                max-width: 21cm;
                margin: 0 auto;
                padding: 1cm;
                background: white;
            }
            
            .ordonnance-header {
                text-align: center;
                border-bottom: 2px solid #2563eb;
                padding-bottom: 15px;
                margin-bottom: 25px;
            }
            
            .ordonnance-title {
                font-size: 24pt;
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 5px;
            }
            
            .ordonnance-subtitle {
                font-size: 14pt;
                color: #6b7280;
                margin-bottom: 15px;
            }
            
            .ordonnance-info {
                display: flex;
                justify-content: space-between;
                margin-bottom: 25px;
                gap: 20px;
            }
            
            .info-section {
                flex: 1;
            }
            
            .info-section h3 {
                font-size: 14pt;
                font-weight: bold;
                color: #1f2937;
                margin-bottom: 8px;
                border-bottom: 1px solid #e5e7eb;
                padding-bottom: 3px;
            }
            
            .info-section p {
                margin: 4px 0;
                font-size: 11pt;
            }
            
            .info-label {
                font-weight: bold;
                display: inline-block;
                width: 80px;
            }
            
            .medicaments-section {
                margin-top: 30px;
            }
            
            .medicaments-title {
                font-size: 16pt;
                font-weight: bold;
                color: #1f2937;
                margin-bottom: 15px;
                text-align: center;
                background-color: #f3f4f6;
                padding: 8px;
                border-radius: 4px;
            }
            
            .medicament-item {
                border: 1px solid #d1d5db;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 12px;
                background-color: #fafafa;
                page-break-inside: avoid;
            }
            
            .medicament-name {
                font-size: 13pt;
                font-weight: bold;
                color: #1f2937;
                margin-bottom: 8px;
            }
            
            .medicament-details {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 15px;
                font-size: 11pt;
            }
            
            .detail-item {
                display: flex;
                flex-direction: column;
            }
            
            .detail-label {
                font-weight: bold;
                color: #4b5563;
                font-size: 10pt;
                margin-bottom: 2px;
            }
            
            .detail-value {
                color: #1f2937;
                font-size: 11pt;
            }
            
            .ordonnance-footer {
                margin-top: 40px;
                display: flex;
                justify-content: space-between;
                align-items: end;
                border-top: 1px solid #e5e7eb;
                padding-top: 20px;
            }
            
            .signature-section {
                text-align: center;
                width: 200px;
            }
            
            .signature-label {
                font-size: 11pt;
                font-weight: bold;
                margin-bottom: 40px;
                color: #4b5563;
            }
            
            .signature-line {
                border-bottom: 1px solid #000;
                width: 180px;
                margin: 0 auto;
            }
            
            .print-info {
                font-size: 9pt;
                color: #6b7280;
                text-align: left;
            }
            
            .prescription-note {
                background-color: #fef3c7;
                border: 1px solid #f59e0b;
                border-radius: 4px;
                padding: 10px;
                margin: 20px 0;
                font-size: 10pt;
                color: #92400e;
            }
            
            @media print {
                .ordonnance-info {
                    display: table;
                    width: 100%;
                }
                .info-section {
                    display: table-cell;
                    vertical-align: top;
                    padding-right: 20px;
                }
                .medicament-details {
                    display: table;
                    width: 100%;
                }
                .detail-item {
                    display: table-cell;
                    padding-right: 15px;
                    vertical-align: top;
                }
            }
        </style>
    </head>
    <body>
        <div class="ordonnance-container">
            <!-- En-tête -->
            <div class="ordonnance-header">
                <h1 class="ordonnance-title">ORDONNANCE MÉDICALE</h1>
                <p class="ordonnance-subtitle">Prescription médicamenteuse</p>
            </div>
            
            <!-- Informations principales -->
            <div class="ordonnance-info">
                <!-- Médecin prescripteur -->
                <div class="info-section">
                    <h3>Médecin prescripteur</h3>
                    <p><span class="info-label">Dr.</span> ' . htmlspecialchars($ordonnance->medecin->nom_complet) . '</p>
                    <p><span class="info-label">ONM:</span> ' . htmlspecialchars($ordonnance->medecin->ONM) . '</p>';
    
    if ($ordonnance->medecin->adresse) {
        $html .= '<p><span class="info-label">Adresse:</span> ' . htmlspecialchars($ordonnance->medecin->adresse) . '</p>';
    }
    
    if ($ordonnance->medecin->telephone) {
        $html .= '<p><span class="info-label">Tél:</span> ' . htmlspecialchars($ordonnance->medecin->telephone) . '</p>';
    }
    
    $html .= '
                </div>
                
                <!-- Patient -->
                <div class="info-section">
                    <h3>Patient</h3>
                    <p><span class="info-label">Nom:</span> ' . htmlspecialchars($ordonnance->client->nom_complet) . '</p>
                    <p><span class="info-label">Adresse:</span> ' . htmlspecialchars($ordonnance->client->adresse) . '</p>';
    
    if ($ordonnance->client->telephone) {
        $html .= '<p><span class="info-label">Tél:</span> ' . htmlspecialchars($ordonnance->client->telephone) . '</p>';
    }
    
    $html .= '
                </div>
                
                <!-- Ordonnance -->
                <div class="info-section">
                    <h3>Ordonnance</h3>
                    <p><span class="info-label">N°:</span> ' . htmlspecialchars($ordonnance->numero_ordonnance) . '</p>
                    <p><span class="info-label">Date:</span> ' . $ordonnance->date->format('d/m/Y') . '</p>
                    <p><span class="info-label">Dossier:</span> ' . htmlspecialchars($ordonnance->dossier_vente) . '</p>
                </div>
            </div>
            
            <!-- Note de prescription -->
            <div class="prescription-note">
                <strong>Important:</strong> Cette ordonnance doit être présentée dans les 3 mois suivant sa date d\'établissement. 
                Respectez scrupuleusement les posologies et durées prescrites.
            </div>
            
            <!-- Médicaments prescrits -->
            <div class="medicaments-section">
                <h2 class="medicaments-title">MÉDICAMENTS PRESCRITS</h2>';
    
    foreach ($ordonnance->lignes as $index => $ligne) {
        $html .= '
                <div class="medicament-item">
                    <div class="medicament-name">' . ($index + 1) . '. ' . htmlspecialchars($ligne->designation) . '</div>
                    <div class="medicament-details">
                        <div class="detail-item">
                            <span class="detail-label">Quantité</span>
                            <span class="detail-value">' . htmlspecialchars($ligne->quantite) . '</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Posologie</span>
                            <span class="detail-value">' . htmlspecialchars($ligne->posologie) . '</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Durée</span>
                            <span class="detail-value">' . htmlspecialchars($ligne->duree) . '</span>
                        </div>
                    </div>
                </div>';
    }
    
    $html .= '
            </div>
            
            <!-- Pied de page -->
            <div class="ordonnance-footer">
                <div class="print-info">
                    <p>Imprimé le ' . now()->format('d/m/Y à H:i') . '</p>
                    <p>Système de gestion pharmaceutique</p>
                </div>
                
                <div class="signature-section">
                    <p class="signature-label">Signature et cachet du médecin</p>
                    <div class="signature-line"></div>
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

/**
 * OPTIONNEL : Générer un PDF de l'ordonnance
 * Nécessite l'installation de dompdf : composer require dompdf/dompdf
 */
public function generatePdf(Ordonnance $ordonnance)
{
    try {
        // Charger les relations
        $ordonnance->load(['medecin', 'client', 'lignes']);
        
        // Générer le HTML
        $html = $this->buildPrintableHtml($ordonnance);
        
        // Créer le PDF avec DomPDF
        $pdf = new \Dompdf\Dompdf([
            'defaultFont' => 'Arial',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false
        ]);
        
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        
        // Nom du fichier
        $filename = 'ordonnance_' . $ordonnance->numero_ordonnance . '_' . date('Y-m-d') . '.pdf';
        
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la génération du PDF',
            'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
        ], 500);
    }
}

    /**
     * Récupérer la liste des médicaments qui ont des ordonnances
     * AUTOMATIQUEMENT filtrée par le dossier sélectionné
     */
    public function getMedicamentsAvecOrdonnances(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $currentDossier = $request->get('current_dossier_vente');

            $medicaments = DB::table('ordonnance_lignes')
                ->join('ordonnances', 'ordonnance_lignes.ordonnance_id', '=', 'ordonnances.id')
                ->where('ordonnances.dossier_vente', $currentDossier) // FILTRE PAR DOSSIER
                ->select('ordonnance_lignes.designation')
                ->selectRaw('COUNT(DISTINCT ordonnances.id) as total_ordonnances')
                ->groupBy('ordonnance_lignes.designation')
                ->orderBy('ordonnance_lignes.designation')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $medicaments,
                'current_dossier' => $currentDossier,
                'message' => 'Liste des médicaments récupérée avec succès'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médicaments',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Récupérer l'historique des ordonnances par médicament et/ou par date
     * AUTOMATIQUEMENT filtrée par le dossier sélectionné
     */
    public function getHistoriqueParMedicament(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $validated = $request->validate([
                'medicament' => 'nullable|string',
                'date' => 'nullable|date',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100'
            ]);

            $perPage = $validated['per_page'] ?? 10;
            $medicament = $validated['medicament'] ?? null;
            $dateFiltre = $validated['date'] ?? null;
            $currentDossier = $request->get('current_dossier_vente');

            if (!$medicament && !$dateFiltre) {
                return response()->json([
                    'success' => false,
                    'message' => 'Veuillez fournir au moins un critère de recherche (médicament ou date)',
                    'errors' => ['criteres' => 'Au moins un critère de recherche est requis']
                ], 422);
            }

            // Le global scope filtre automatiquement par dossier
            $query = Ordonnance::with(['medecin', 'client', 'lignes']);

            if ($medicament) {
                $query->whereHas('lignes', function ($q) use ($medicament) {
                    $q->where('designation', $medicament);
                });
            }

            if ($dateFiltre) {
                $query->whereDate('date', $dateFiltre);
            }

            $ordonnances = $query->orderBy('date', 'desc')->orderBy('created_at', 'desc')
                                ->paginate($perPage);

            // Compter le total avec les mêmes critères
            $queryCount = Ordonnance::query();
            
            if ($medicament) {
                $queryCount->whereHas('lignes', function ($q) use ($medicament) {
                    $q->where('designation', $medicament);
                });
            }

            if ($dateFiltre) {
                $queryCount->whereDate('date', $dateFiltre);
            }

            $totalOrdonnances = $queryCount->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'ordonnances' => $ordonnances->items(),
                    'pagination' => [
                        'current_page' => $ordonnances->currentPage(),
                        'last_page' => $ordonnances->lastPage(),
                        'per_page' => $ordonnances->perPage(),
                        'total' => $ordonnances->total(),
                        'from' => $ordonnances->firstItem(),
                        'to' => $ordonnances->lastItem(),
                    ],
                    'total_ordonnances' => $totalOrdonnances,
                    'medicament_recherche' => $medicament,
                    'date_filtre' => $dateFiltre,
                    'current_dossier' => $currentDossier,
                    'criteres_recherche' => [
                        'par_medicament' => !empty($medicament),
                        'par_date' => !empty($dateFiltre),
                        'les_deux' => !empty($medicament) && !empty($dateFiltre)
                    ]
                ],
                'message' => 'Historique récupéré avec succès'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paramètres de recherche invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Récupérer la liste des médecins pour sélection 
     */
    public function getMedecinsForSelection(): JsonResponse
    {
        try {
            $medecins = Medecin::select('id', 'nom_complet', 'ONM')
                               ->orderBy('nom_complet')
                               ->get()
                               ->map(function($medecin) {
                                   return [
                                       'id' => $medecin->id,
                                       'label' => $medecin->nom_complet . ' (' . $medecin->ONM . ')',
                                       'nom_complet' => $medecin->nom_complet,
                                       'ONM' => $medecin->ONM
                                   ];
                               });
            
            return response()->json([
                'success' => true,
                'data' => $medecins,
                'message' => 'Liste des médecins récupérée avec succès'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médecins',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * NOUVELLE MÉTHODE : Suggérer un numéro d'ordonnance pour le dossier actuel
     */
    public function suggestNumeroOrdonnance(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $currentDossier = $request->get('current_dossier_vente');
            $suggestion = Ordonnance::suggestNumeroOrdonnance($currentDossier);

            return response()->json([
                'success' => true,
                'data' => [
                    'numero_suggere' => $suggestion,
                    'dossier' => $currentDossier
                ],
                'message' => 'Numéro d\'ordonnance suggéré'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suggestion du numéro',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * NOUVELLE MÉTHODE : Statistiques du dossier actuel
     */
    public function getStatistiquesDossier(Request $request): JsonResponse
    {
        // Vérifier la sélection du dossier
        $folderCheck = $this->checkDossierSelection($request);
        if ($folderCheck) return $folderCheck;

        try {
            $currentDossier = $request->get('current_dossier_vente');
            $dossierInfo = $request->get('dossier_info', []);

            $stats = [
                'dossier_nom' => $currentDossier,
                'dossier_info' => $dossierInfo,
                'total_ordonnances' => Ordonnance::count(),
                'ordonnances_ce_mois' => Ordonnance::whereMonth('date', now()->month)
                                                  ->whereYear('date', now()->year)
                                                  ->count(),
                'ordonnances_aujourd_hui' => Ordonnance::whereDate('date', today())->count(),
                'derniere_ordonnance' => Ordonnance::orderBy('created_at', 'desc')->first(),
                'medicaments_uniques' => DB::table('ordonnance_lignes')
                    ->join('ordonnances', 'ordonnance_lignes.ordonnance_id', '=', 'ordonnances.id')
                    ->where('ordonnances.dossier_vente', $currentDossier)
                    ->distinct('ordonnance_lignes.designation')
                    ->count('ordonnance_lignes.designation')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Statistiques du dossier récupérées'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

// Ajoutez ces méthodes à votre OrdonnanceController.php

/**
 * Exporter la liste des ordonnances de l'historique en PDF
 */
public function exportHistoriqueList(Request $request)
{
    // Vérifier la sélection du dossier
    $folderCheck = $this->checkDossierSelection($request);
    if ($folderCheck) return $folderCheck;

    try {
        $validated = $request->validate([
            'medicament' => 'nullable|string',
            'date' => 'nullable|date',
            'titre' => 'nullable|string',
            'format' => 'nullable|string|in:pdf'
        ]);

        $medicament = $validated['medicament'] ?? null;
        $dateFiltre = $validated['date'] ?? null;
        $titre = $validated['titre'] ?? 'Liste des ordonnances';
        $currentDossier = $request->get('current_dossier_vente');

        if (!$medicament && !$dateFiltre) {
            return response()->json([
                'success' => false,
                'message' => 'Au moins un critère de recherche est requis (médicament ou date)'
            ], 422);
        }

        // Récupérer les ordonnances avec les mêmes critères que l'historique
        $query = Ordonnance::with(['medecin', 'client', 'lignes']);

        if ($medicament) {
            $query->whereHas('lignes', function ($q) use ($medicament) {
                $q->where('designation', $medicament);
            });
        }

        if ($dateFiltre) {
            $query->whereDate('date', $dateFiltre);
        }

        $ordonnances = $query->orderBy('date', 'desc')
                            ->orderBy('created_at', 'desc')
                            ->get();

        if ($ordonnances->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune ordonnance trouvée pour ces critères'
            ], 404);
        }

        // Générer le HTML pour la liste
        $html = $this->buildHistoriqueListHtml($ordonnances, $titre, $medicament, $dateFiltre, $currentDossier);

        // Si c'est un export PDF, utiliser DomPDF
        if ($validated['format'] === 'pdf') {
            // Vérifier si DomPDF est installé
            if (!class_exists('\Dompdf\Dompdf')) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF export non disponible. Veuillez installer dompdf: composer require dompdf/dompdf'
                ], 500);
            }

            $pdf = new \Dompdf\Dompdf([
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false
            ]);
            
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();
            
            $filename = 'historique_ordonnances_' . date('Y-m-d_H-i-s') . '.pdf';
            
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'total_ordonnances' => $ordonnances->count(),
                'criteres' => [
                    'medicament' => $medicament,
                    'date' => $dateFiltre,
                    'dossier' => $currentDossier
                ]
            ],
            'message' => 'Export généré avec succès'
        ]);

    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Paramètres invalides',
            'errors' => $e->errors()
        ], 422);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l\'export',
            'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
        ], 500);
    }
}

/**
 * Imprimer la liste des ordonnances de l'historique
 */
public function printHistoriqueList(Request $request)
{
    // Vérifier la sélection du dossier
    $folderCheck = $this->checkDossierSelection($request);
    if ($folderCheck) return $folderCheck;

    try {
        $validated = $request->validate([
            'medicament' => 'nullable|string',
            'date' => 'nullable|date',
            'titre' => 'nullable|string'
        ]);

        $medicament = $validated['medicament'] ?? null;
        $dateFiltre = $validated['date'] ?? null;
        $titre = $validated['titre'] ?? 'Liste des ordonnances';
        $currentDossier = $request->get('current_dossier_vente');

        if (!$medicament && !$dateFiltre) {
            return response()->json([
                'success' => false,
                'message' => 'Au moins un critère de recherche est requis (médicament ou date)'
            ], 422);
        }

        // Récupérer les ordonnances
        $query = Ordonnance::with(['medecin', 'client', 'lignes']);

        if ($medicament) {
            $query->whereHas('lignes', function ($q) use ($medicament) {
                $q->where('designation', $medicament);
            });
        }

        if ($dateFiltre) {
            $query->whereDate('date', $dateFiltre);
        }

        $ordonnances = $query->orderBy('date', 'desc')
                            ->orderBy('created_at', 'desc')
                            ->get();

        if ($ordonnances->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune ordonnance trouvée pour ces critères'
            ], 404);
        }

        // Générer le HTML imprimable
        $html = $this->buildHistoriqueListHtml($ordonnances, $titre, $medicament, $dateFiltre, $currentDossier);

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'total_ordonnances' => $ordonnances->count()
            ],
            'message' => 'Liste préparée pour impression'
        ]);

    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Paramètres invalides',
            'errors' => $e->errors()
        ], 422);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la préparation d\'impression',
            'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
        ], 500);
    }
}

/**
 * Construire le HTML pour la liste de l'historique
 */
private function buildHistoriqueListHtml($ordonnances, $titre, $medicament = null, $dateFiltre = null, $dossier = null)
{
    $totalOrdonnances = $ordonnances->count();
    $dateGeneration = now()->format('d/m/Y à H:i');
    
    // Construire les critères de recherche pour l'affichage
    $criteres = [];
    if ($medicament) {
        $criteres[] = "Médicament: " . $medicament;
    }
    if ($dateFiltre) {
        $criteres[] = "Date: " . \Carbon\Carbon::parse($dateFiltre)->format('d/m/Y');
    }
    if ($dossier && $dossier !== 'default') {
        $criteres[] = "Dossier: " . $dossier;
    }
    
    $criteresText = implode(' | ', $criteres);

    $html = '
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($titre) . '</title>
        <style>
            @media print {
                @page {
                    margin: 1.5cm;
                    size: A4;
                }
                body { margin: 0; }
                .no-print { display: none !important; }
                .page-break { page-break-before: always; }
                table { page-break-inside: auto; }
                tr { page-break-inside: avoid; page-break-after: auto; }
                thead { display: table-header-group; }
                tfoot { display: table-footer-group; }
            }
            
            body {
                font-family: "Arial", sans-serif;
                font-size: 11pt;
                line-height: 1.4;
                color: #000;
                max-width: 100%;
                margin: 0;
                padding: 0;
                background: white;
            }
            
            .container {
                max-width: 21cm;
                margin: 0 auto;
                padding: 1cm;
            }
            
            .header {
                text-align: center;
                border-bottom: 2px solid #2563eb;
                padding-bottom: 15px;
                margin-bottom: 20px;
            }
            
            .header h1 {
                font-size: 13pt;
                font-weight: bold;
                color: #1e40af;
                margin: 0 0 8px 0;
            }
            
            .header .criteres {
                font-size: 12pt;
                color: #4b5563;
                margin: 5px 0;
            }
            
            .header .total {
                font-size: 13pt;
                font-weight: bold;
                color: #059669;
                margin: 8px 0;
            }
            
            .meta-info {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding: 10px;
                background-color: #f8fafc;
                border-radius: 4px;
                font-size: 10pt;
                color: #6b7280;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
                font-size: 10pt;
                background: white;
            }
            
            thead th {
                background-color: #1f2937;
                color: white;
                font-weight: bold;
                text-align: center;
                padding: 8px 6px;
                border: 1px solid #374151;
                font-size: 9pt;
            }
            
            tbody td {
                padding: 6px;
                border: 1px solid #d1d5db;
                text-align: center;
                vertical-align: middle;
                font-size: 9pt;
            }
            
            tbody tr:nth-child(even) {
                background-color: #f9fafb;
            }
            
            tbody tr:hover {
                background-color: #e5f3ff;
            }
            
            .ordonnance-numero {
                font-weight: bold;
                color: #1e40af;
            }
            
            .client-nom {
                font-weight: 600;
                color: #1f2937;
            }
            
            .medicament-principal {
                font-weight: 500;
                color: #059669;
            }
            
            .date-ordonnance {
                font-weight: 500;
                color: #7c2d12;
            }
            
            .medicaments-list {
                text-align: left;
                max-width: 250px;
            }
            
            .medicament-item {
                margin: 2px 0;
                padding: 2px 4px;
                background-color: #e0f2fe;
                border-radius: 3px;
                font-size: 8pt;
                display: inline-block;
                margin-right: 4px;
            }
            
            .footer {
                margin-top: 30px;
                border-top: 1px solid #e5e7eb;
                padding-top: 15px;
                text-align: center;
                font-size: 9pt;
                color: #6b7280;
            }
            
            .summary-box {
                background-color: #ecfdf5;
                border: 1px solid #10b981;
                border-radius: 6px;
                padding: 12px;
                margin-bottom: 20px;
                text-align: center;
            }
            
            .summary-title {
                font-size: 12pt;
                font-weight: bold;
                color: #065f46;
                margin-bottom: 5px;
            }
            
            .summary-text {
                font-size: 10pt;
                color: #047857;
            }
            
            @media print {
                .table-container {
                    overflow: visible;
                }
                table {
                    font-size: 9pt;
                }
                thead th {
                    font-size: 8pt;
                    padding: 6px 4px;
                }
                tbody td {
                    font-size: 8pt;
                    padding: 4px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <!-- En-tête -->
            <div class="header">
                <h1>' . htmlspecialchars($titre) . '</h1>
                <div class="total">Total: ' . $totalOrdonnances . ' ordonnance(s)</div>
            </div>
            
            <!-- Informations de génération -->
            <div class="meta-info">
                <div>Généré le ' . $dateGeneration . '</div>
                <div>Système de gestion pharmaceutique</div>
            </div>
            
            <!-- Résumé -->
            <div class="summary-box">
                <div class="summary-title">Résumé de la recherche</div>
                <div class="summary-text">' . htmlspecialchars($criteresText) . '</div>
            </div>
            
            <!-- Tableau des ordonnances -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 12%;">N° Ordonnance</th>
                            <th style="width: 18%;">Patient</th>
                            <th style="width: 15%;">Médecin</th>
                            <th style="width: 12%;">Date</th>
                            <th style="width: 43%;">Médicaments prescrits</th>
                        </tr>
                    </thead>
                    <tbody>';

    foreach ($ordonnances as $ordonnance) {
        $medicamentsList = '';
        foreach ($ordonnance->lignes as $ligne) {
            $medicamentsList .= '<span class="medicament-item">' . 
                               htmlspecialchars($ligne->designation) . 
                               ' (Qté: ' . $ligne->quantite . ')</span>';
        }

        $html .= '
                        <tr>
                            <td class="ordonnance-numero">' . htmlspecialchars($ordonnance->numero_ordonnance) . '</td>
                            <td class="client-nom">' . htmlspecialchars($ordonnance->client->nom_complet) . '</td>
                            <td>Dr. ' . htmlspecialchars($ordonnance->medecin->nom_complet) . '</td>
                            <td class="date-ordonnance">' . $ordonnance->date->format('d/m/Y') . '</td>
                            <td class="medicaments-list">' . $medicamentsList . '</td>
                        </tr>';
    }

    $html .= '
                    </tbody>
                </table>
            </div>
            
            <!-- Pied de page -->
            <div class="footer">
                <p><strong>Statistiques:</strong></p>
                <p>Total des ordonnances: ' . $totalOrdonnances . '</p>
                <p>Période analysée: ' . ($dateFiltre ? \Carbon\Carbon::parse($dateFiltre)->format('d/m/Y') : 'Toutes les dates') . '</p>
                <p>Document généré le ' . $dateGeneration . '</p>
            </div>
        </div>
    </body>
    </html>';

    return $html;
}
}