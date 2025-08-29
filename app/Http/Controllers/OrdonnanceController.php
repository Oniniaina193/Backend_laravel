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
}