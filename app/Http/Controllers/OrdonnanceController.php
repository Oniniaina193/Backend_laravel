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
     * Liste paginée des ordonnances avec recherche
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 20), 100);
            $search = $request->get('search');
            $medecinId = $request->get('medecin_id');
            $clientId = $request->get('client_id');
            $dateDebut = $request->get('date_debut');
            $dateFin = $request->get('date_fin');

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
                    ]
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
     * Créer une nouvelle ordonnance avec client et médicaments
     * MODIFICATION 1: Numéro d'ordonnance saisi manuellement (string)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                // MODIFICATION 1: numéro d'ordonnance manuel obligatoire (string)
                'numero_ordonnance' => 'required|string|max:50|unique:ordonnances,numero_ordonnance',
                'medecin_id' => 'required|exists:medecins,id',
                'date' => 'required|date',
                
                // Données client (création ou sélection)
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
                'numero_ordonnance.unique' => 'Ce numéro d\'ordonnance existe déjà',
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

            // 2. Créer l'ordonnance avec le numéro fourni par l'utilisateur
            $ordonnance = Ordonnance::create([
                'numero_ordonnance' => $validated['numero_ordonnance'],
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
                'message' => 'Ordonnance créée avec succès'
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
     * Afficher une ordonnance spécifique avec tous les détails
     */
    public function show(Ordonnance $ordonnance): JsonResponse
    {
        try {
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
     * MODIFICATIONS 3 & 4: Empêcher modification numéro, permettre modification posologie/durée/infos client
     */
    public function update(Request $request, Ordonnance $ordonnance): JsonResponse
    {
        try {
            $validated = $request->validate([
                // MODIFICATION 4: Le numéro d'ordonnance n'est PAS modifiable
                'medecin_id' => 'required|exists:medecins,id',
                'date' => 'required|date',
                
                // MODIFICATION 4: Données client modifiables
                'client.nom_complet' => 'required|string|max:255',
                'client.adresse' => 'required|string|max:255',
                'client.telephone' => 'nullable|string|max:20',
                
                // MODIFICATION 4: Médicaments - posologie et durée modifiables
                'medicaments' => 'required|array|min:1',
                'medicaments.*.id' => 'nullable|exists:ordonnance_lignes,id',
                'medicaments.*.code_medicament' => 'required|string|max:50',
                'medicaments.*.designation' => 'required|string|max:255',
                'medicaments.*.quantite' => 'required|integer|min:1',
                'medicaments.*.posologie' => 'required|string|max:500',
                'medicaments.*.duree' => 'required|string|max:100',
            ], [
                'medecin_id.required' => 'Veuillez sélectionner un médecin',
                'medecin_id.exists' => 'Le médecin sélectionné n\'existe pas',
                'client.nom_complet.required' => 'Le nom du client est obligatoire',
                'medicaments.required' => 'Au moins un médicament est requis',
            ]);

            DB::beginTransaction();

            // 1. Mettre à jour l'ordonnance (SANS le numéro d'ordonnance)
            $ordonnance->update([
                'medecin_id' => $validated['medecin_id'],
                'date' => $validated['date'],
            ]);

            // 2. Mettre à jour le client (MODIFICATION 4: autorisé)
            $ordonnance->client->update($validated['client']);

            // 3. Gérer les médicaments - MODIFICATION 4: posologie/durée modifiables
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
                            'posologie' => $medicament['posologie'], // MODIFIABLE
                            'duree' => $medicament['duree'], // MODIFIABLE
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
     */
    public function destroy(Ordonnance $ordonnance): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Les lignes seront supprimées automatiquement grâce à la contrainte CASCADE
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
 * Pour le filtre de sélection dans l'historique
 */
public function getMedicamentsAvecOrdonnances(): JsonResponse
{
    try {
        $medicaments = DB::table('ordonnance_lignes')
            ->select('designation')
            ->selectRaw('COUNT(DISTINCT ordonnance_id) as total_ordonnances')
            ->groupBy('designation')
            ->orderBy('designation')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $medicaments,
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
 * MODIFICATION: Permettre recherche par date seule
 */
public function getHistoriqueParMedicament(Request $request): JsonResponse
{
    try {
        $validated = $request->validate([
            'medicament' => 'nullable|string', // MODIFIÉ: optionnel maintenant
            'date' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $perPage = $validated['per_page'] ?? 10;
        $medicament = $validated['medicament'] ?? null;
        $dateFiltre = $validated['date'] ?? null;

        // MODIFICATION: Vérifier qu'au moins un critère est fourni
        if (!$medicament && !$dateFiltre) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir au moins un critère de recherche (médicament ou date)',
                'errors' => ['criteres' => 'Au moins un critère de recherche est requis']
            ], 422);
        }

        // Construire la requête pour récupérer les ordonnances
        $query = Ordonnance::with(['medecin', 'client', 'lignes']);

        // MODIFICATION: Appliquer le filtre médicament seulement s'il est fourni
        if ($medicament) {
            $query->whereHas('lignes', function ($q) use ($medicament) {
                $q->where('designation', $medicament);
            });
        }

        // Appliquer le filtre de date si fourni
        if ($dateFiltre) {
            $query->whereDate('date', $dateFiltre);
        }

        // Ordonner par date décroissante
        $query->orderBy('date', 'desc')->orderBy('created_at', 'desc');

        // Pagination
        $ordonnances = $query->paginate($perPage);

        // MODIFICATION: Compter le total des ordonnances avec les mêmes critères
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
     * MODIFICATION 2 & 5: Récupérer la liste des médecins pour sélection 
     * Format: "Nom Complet (ONM)"
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
}