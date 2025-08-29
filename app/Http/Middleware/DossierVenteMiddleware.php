<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DossierVenteMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Démarrer la session si elle n'existe pas
        if (!$request->session()->isStarted()) {
            $request->session()->start();
        }

        // Récupérer les informations du dossier sélectionné
        $selectedFolder = $request->session()->get('selected_folder');

        if ($selectedFolder && isset($selectedFolder['folder_name'])) {
            // Ajouter le dossier_vente à la request pour qu'il soit accessible partout
            $request->merge(['current_dossier_vente' => $selectedFolder['folder_name']]);
            
            // Ajouter aussi toutes les infos du dossier si nécessaire
            $request->merge(['dossier_info' => $selectedFolder]);
        }

        return $next($request);
    }
}

// N'oubliez pas d'enregistrer ce middleware dans app/Http/Kernel.php :
// Dans $middlewareGroups['web'], ajoutez :
// \App\Http\Middleware\DossierVenteMiddleware::class,