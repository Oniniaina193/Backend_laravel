<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MedecinController extends Controller
{
    /**
     * Liste paginée des médecins avec recherche optimisée
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 20), 100);
            $search = $request->get('search');

            $medecins = Medecin::query()
                ->when($search, function ($query, $search) {
                    $query->where('nom_complet', 'ILIKE', "%{$search}%")
                          ->orWhere('ONM', 'ILIKE', "%{$search}%");
                })
                ->orderBy('nom_complet')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'medecins' => $medecins->items(),
                    'pagination' => [
                        'current_page' => $medecins->currentPage(),
                        'last_page' => $medecins->lastPage(),
                        'per_page' => $medecins->perPage(),
                        'total' => $medecins->total(),
                        'from' => $medecins->firstItem(),
                        'to' => $medecins->lastItem(),
                    ]
                ],
                'message' => 'Médecins récupérés avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médecins',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Créer un nouveau médecin
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom_complet' => 'required|string|max:255|unique:medecins,nom_complet',
                'adresse' => 'nullable|string|max:255',
                'ONM' => 'required|string|max:100|unique:medecins,ONM',
                'telephone' => 'nullable|string|max:20',
            ], [
                'nom_complet.required' => 'Le nom complet est obligatoire',
                'nom_complet.unique' => 'Ce médecin existe déjà',
                'ONM.required' => 'L\'ONM est obligatoire',
                'ONM.unique' => 'Un médecin avec ce ONM existe déjà',
            ]);

            $medecin = Medecin::create($validated);

            return response()->json([
                'success' => true,
                'data' => $medecin,
                'message' => 'Médecin créé avec succès'
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
                'message' => 'Erreur lors de la création du médecin',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Afficher un médecin spécifique
     */
    public function show(Medecin $medecin): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $medecin
        ]);
    }

    /**
     * Mettre à jour un médecin
     */
    public function update(Request $request, Medecin $medecin): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom_complet' => 'required|string|max:255|unique:medecins,nom_complet,' . $medecin->id,
                'adresse' => 'nullable|string|max:255',
                'ONM' => 'required|string|max:100|unique:medecins,ONM,' . $medecin->id,
                'telephone' => 'nullable|string|max:20',
            ]);

            $medecin->update($validated);

            return response()->json([
                'success' => true,
                'data' => $medecin,
                'message' => 'Médecin modifié avec succès'
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
                'message' => 'Erreur lors de la modification du médecin',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Supprimer un médecin
     */
    public function destroy(Medecin $medecin): JsonResponse
    {
        try {
            $medecin->delete();

            return response()->json([
                'success' => true,
                'message' => 'Médecin supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du médecin',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Recherche rapide pour autocomplete
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

            $medecins = Medecin::where('nom_complet', 'ILIKE', "%{$search}%")
                ->orWhere('ONM', 'ILIKE', "%{$search}%")
                ->select('id', 'nom_complet', 'ONM', 'telephone')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $medecins
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
