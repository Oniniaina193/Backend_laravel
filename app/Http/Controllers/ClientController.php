<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Liste paginée des clients avec recherche
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 20), 100);
            $search = $request->get('search');

            $clients = Client::query()
                ->when($search, function ($query, $search) {
                    $query->where('nom_complet', 'ILIKE', "%{$search}%")
                          ->orWhere('telephone', 'LIKE', "%{$search}%");
                })
                ->orderBy('nom_complet')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'clients' => $clients->items(),
                    'pagination' => [
                        'current_page' => $clients->currentPage(),
                        'last_page' => $clients->lastPage(),
                        'per_page' => $clients->perPage(),
                        'total' => $clients->total(),
                        'from' => $clients->firstItem(),
                        'to' => $clients->lastItem(),
                    ]
                ],
                'message' => 'Clients récupérés avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Créer un nouveau client
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom_complet' => 'required|string|max:255',
                'adresse' => 'required|string|max:255',
                'telephone' => 'nullable|string|max:20',
            ], [
                'nom_complet.required' => 'Le nom complet est obligatoire',
                'adresse.required' => 'L\'adresse est obligatoire',
            ]);

            $client = Client::create($validated);

            return response()->json([
                'success' => true,
                'data' => $client,
                'message' => 'Client créé avec succès'
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
                'message' => 'Erreur lors de la création du client',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Afficher un client spécifique
     */
    public function show(Client $client): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $client->load('ordonnances.medecin')
        ]);
    }

    /**
     * Mettre à jour un client
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        try {
            $validated = $request->validate([
                'nom_complet' => 'required|string|max:255',
                'adresse' => 'required|string|max:255',
                'telephone' => 'nullable|string|max:20',
            ]);

            $client->update($validated);

            return response()->json([
                'success' => true,
                'data' => $client,
                'message' => 'Client modifié avec succès'
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
                'message' => 'Erreur lors de la modification du client',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Supprimer un client
     */
    public function destroy(Client $client): JsonResponse
    {
        try {
            // Vérifier s'il y a des ordonnances liées
            if ($client->ordonnances()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce client car il a des ordonnances associées'
                ], 400);
            }

            $client->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du client',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    public function getAllClients(): JsonResponse
{
    try {
        $clients = Client::select('id', 'nom_complet', 'adresse', 'telephone')
            ->orderBy('nom_complet', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $clients
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du chargement des clients',
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

            $clients = Client::where('nom_complet', 'ILIKE', "%{$search}%")
                ->orWhere('telephone', 'LIKE', "%{$search}%")
                ->select('id', 'nom_complet', 'adresse', 'telephone')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $clients
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