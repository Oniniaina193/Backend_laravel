<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MedicamentController extends Controller
{
    /**
     * Liste paginée des médicaments avec recherche optimisée
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 20), 100); // Max 100 pour éviter surcharge
            $search = $request->get('search');
            $famille = $request->get('famille');

            // Requête optimisée avec eager loading si nécessaire
            $medicaments = Medicament::query()
                ->search($search)
                ->byFamily($famille)
                ->orderBy('nom') // Tri par nom pour cohérence
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'medicaments' => $medicaments->items(),
                    'pagination' => [
                        'current_page' => $medicaments->currentPage(),
                        'last_page' => $medicaments->lastPage(),
                        'per_page' => $medicaments->perPage(),
                        'total' => $medicaments->total(),
                        'from' => $medicaments->firstItem(),
                        'to' => $medicaments->lastItem(),
                    ]
                ],
                'message' => 'Médicaments récupérés avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médicaments',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Créer un nouveau médicament
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom' => 'required|string|max:255|unique:medicaments,nom',
                'famille' => 'required|string|max:255',
            ], [
                'nom.required' => 'Le nom du médicament est obligatoire',
                'nom.unique' => 'Ce médicament existe déjà',
                'famille.required' => 'La famille est obligatoire',
            ]);

            $medicament = Medicament::create($validated);

            // Vider le cache des familles si nouvelle famille
            cache()->forget('medicaments.families');

            return response()->json([
                'success' => true,
                'data' => $medicament,
                'message' => 'Médicament créé avec succès'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données de validation incorrectes',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du médicament',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Afficher un médicament spécifique
     */
    public function show(Medicament $medicament): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $medicament
        ]);
    }

    /**
     * Mettre à jour un médicament
     */
    public function update(Request $request, Medicament $medicament): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom' => 'required|string|max:255|unique:medicaments,nom,' . $medicament->id,
                'famille' => 'required|string|max:255',
            ], [
                'nom.required' => 'Le nom du médicament est obligatoire',
                'nom.unique' => 'Ce médicament existe déjà',
                'famille.required' => 'La famille est obligatoire',
            ]);

            $medicament->update($validated);

            // Vider le cache des familles si famille modifiée
            cache()->forget('medicaments.families');

            return response()->json([
                'success' => true,
                'data' => $medicament,
                'message' => 'Médicament modifié avec succès'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données de validation incorrectes',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du médicament',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Supprimer un médicament
     */
    public function destroy(Medicament $medicament): JsonResponse
    {
        try {
            $medicament->delete();

            // Vider le cache des familles
            cache()->forget('medicaments.families');

            return response()->json([
                'success' => true,
                'message' => 'Médicament supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du médicament',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Récupérer toutes les familles disponibles (avec cache)
     */
    public function families(): JsonResponse
    {
        try {
            $families = Medicament::getFamilies();

            return response()->json([
                'success' => true,
                'data' => $families,
                'message' => 'Familles récupérées avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des familles',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Recherche rapide (pour autocomplete)
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $search = $request->get('q', '');
            $limit = min($request->get('limit', 10), 20);

            if (strlen($search) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $medicaments = Medicament::where('nom', 'ILIKE', "%{$search}%")
                ->select('id', 'nom', 'famille')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $medicaments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }
}
