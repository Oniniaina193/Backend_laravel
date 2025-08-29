<?php

use App\Http\Controllers\FolderSelectionController;
use App\Http\Controllers\ArticleSearchController;
use App\Http\Controllers\DirectAccessController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MedecinController;
use App\Http\Controllers\MedicamentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\OrdonnanceController;
use App\Http\Controllers\StatistiquesController;
use Illuminate\Support\Facades\Route;

// Routes pour la gestion des medecins
Route::prefix('medecins')->group(function () {
    Route::get('/', [MedecinController::class, 'index']);
    Route::post('/', [MedecinController::class, 'store']);
    Route::get('/search', [MedecinController::class, 'search']);
    Route::get('/{medecin}', [MedecinController::class, 'show']);
    Route::put('/{medecin}', [MedecinController::class, 'update']);
    Route::delete('/{medecin}', [MedecinController::class, 'destroy']);
});

// Routes pour médicaments avec middleware auth si nécessaire
Route::prefix('medicaments')->group(function () {
    Route::get('/', [MedicamentController::class, 'index']); // Liste avec pagination
    Route::post('/', [MedicamentController::class, 'store']); // Créer
    Route::get('/families', [MedicamentController::class, 'families']); // Familles
    Route::get('/search', [MedicamentController::class, 'search']); // Recherche rapide
    Route::get('/{medicament}', [MedicamentController::class, 'show']); // Détails
    Route::put('/{medicament}', [MedicamentController::class, 'update']); // Modifier
    Route::delete('/{medicament}', [MedicamentController::class, 'destroy']); // Supprimer
});

// ===========================================
// ROUTES D'AUTHENTIFICATION
// ===========================================
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('check', [AuthController::class, 'checkAuth'])->name('auth.check');
});

// Groupe des routes pour la sélection de dossiers
Route::prefix('folder-selection')->group(function () {
    Route::post('upload', [FolderSelectionController::class, 'uploadCaissFile'])
         ->name('folder.upload'); // renommé
    Route::post('select', [FolderSelectionController::class, 'selectFolder'])
         ->name('folder.select');
    Route::get('current', [FolderSelectionController::class, 'getCurrentSelection'])
         ->name('folder.current');
    Route::delete('reset', [FolderSelectionController::class, 'resetSelection'])
         ->name('folder.reset');
    Route::get('available', [FolderSelectionController::class, 'listAvailableFolders'])
         ->name('folder.available');
    Route::get('global-search', [FolderSelectionController::class, 'globalSearch'])
         ->name('folder.global-search');
});

// Groupe des routes pour accès direct Access (avec stocks)
Route::prefix('direct-access')->group(function () {
    Route::get('search', [DirectAccessController::class, 'searchArticles'])
         ->name('direct-access.search');
    Route::get('families', [DirectAccessController::class, 'getFamilies'])
         ->name('direct-access.families');
    Route::get('test-connection', [DirectAccessController::class, 'testConnection'])
         ->name('direct-access.test');
    Route::get('table-structure', [DirectAccessController::class, 'getTableStructure'])
         ->name('direct-access.structure');

     // NOUVELLES routes pour les tickets
    Route::get('tickets/search', [DirectAccessController::class, 'searchTickets'])->name('direct-access.tickets.search');
    Route::get('tickets/{codeTicket}', [DirectAccessController::class, 'getTicketDetails'])->name('direct-access.tickets.details');
});

// Routes fallback pour compatibilité avec ancien système
Route::prefix('articles')->group(function () {
    Route::get('search', [DirectAccessController::class, 'searchArticles'])
         ->name('articles.search');
    Route::get('families', [DirectAccessController::class, 'getFamilies'])
         ->name('articles.families');
});

// Debug et test API
Route::get('test-session', function () {
    return response()->json([
        'session_id' => session()->getId(),
        'selected_folder' => session('selected_folder'),
        'session_started' => session()->isStarted()
    ]);
});

Route::get('ping', function () {
    return response()->json([
        'message' => 'Laravel API fonctionne !',
        'timestamp' => now()->toISOString()
    ]);
});

// Routes pour la gestion des clients
Route::prefix('clients')->group(function () {
    Route::get('/', [ClientController::class, 'index']);
    Route::post('/', [ClientController::class, 'store']);
    Route::get('/search', [ClientController::class, 'search']);
    Route::get('/all', [ClientController::class, 'getAllClients']); // ✅ Correction ici
    Route::get('/{client}', [ClientController::class, 'show']);
    Route::put('/{client}', [ClientController::class, 'update']);
    Route::delete('/{client}', [ClientController::class, 'destroy']);
});

// Routes des ordonnances (TOUTES automatiquement filtrées par le middleware)
Route::prefix('ordonnances')->group(function () {
    
    // ❌ PROBLÈME : Vos routes actuelles ne correspondent pas aux appels frontend
    
    // ✅ CORRECTIONS - Routes dans le bon ordre :
    
    // 1. Routes spécifiques AVANT les routes paramétrées
    
    // ✅ CORRECT: /api/ordonnances/data/medecins-selection
    Route::get('data/medecins-selection', [OrdonnanceController::class, 'getMedecinsForSelection']);
    
    // ✅ CORRECT: /api/ordonnances/suggest-numero  
    Route::get('suggest-numero', [OrdonnanceController::class, 'suggestNumeroOrdonnance']);
    
    // ✅ CORRECT: /api/ordonnances/historique/medicaments
    Route::get('historique/medicaments', [OrdonnanceController::class, 'getMedicamentsAvecOrdonnances']);
    
    // ✅ CORRECT: /api/ordonnances/historique
    Route::get('historique', [OrdonnanceController::class, 'getHistoriqueParMedicament']);
    
    // ✅ CORRECT: /api/ordonnances/statistiques
    Route::get('statistiques', [OrdonnanceController::class, 'getStatistiquesDossier']);
    
    // 2. CRUD principal (routes paramétrées APRÈS les spécifiques)
    Route::get('/', [OrdonnanceController::class, 'index']);
    Route::post('/', [OrdonnanceController::class, 'store']);
    Route::get('/{ordonnance}', [OrdonnanceController::class, 'show']);  // ✅ Ajout du slash
    Route::put('/{ordonnance}', [OrdonnanceController::class, 'update']); // ✅ Ajout du slash
    Route::delete('/{ordonnance}', [OrdonnanceController::class, 'destroy']); // ✅ Ajout du slash
});

// Routes pour les statistiques - Regroupées sous le préfixe 'statistiques'
Route::prefix('statistiques')->middleware(['web'])->group(function () {
    
    // Route principale - Toutes les stats du dashboard
    Route::get('/dashboard', [StatistiquesController::class, 'getDashboardStats'])
         ->name('statistiques.dashboard');
    
    // Route pour les graphiques des ventes mensuelles
    Route::get('/ventes-mensuelles', [StatistiquesController::class, 'getVentesMensuelles'])
         ->name('statistiques.ventes.mensuelles');
    
    // Route pour le top 10 des médicaments
    Route::get('/top-medicaments', [StatistiquesController::class, 'getTopMedicaments'])
         ->name('statistiques.top.medicaments');
});
