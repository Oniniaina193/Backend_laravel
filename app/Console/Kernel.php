<?php

// app/Console/Kernel.php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Commandes Artisan disponibles pour l'application
     */
    protected $commands = [
        Commands\SyncAccessDatabase::class,
    ];

    /**
     * Définition du planning des tâches
     */
    protected function schedule(Schedule $schedule)
    {
        // OPTION 1: Synchronisation toutes les secondes (très fréquent)
        //$schedule->command('sync:access-db')
           // ->everySecond()
           // ->withoutOverlapping() // Évite les exécutions simultanées
            //->runInBackground()    // Exécution en arrière-plan
            //->appendOutputTo(storage_path('logs/sync-access.log'));

        // OPTION 2: Synchronisation toutes les 10 secondes (recommandé)
         $schedule->command('sync:access-db')
            ->everyTenSeconds()
             ->withoutOverlapping()
             ->runInBackground()
             ->appendOutputTo(storage_path('logs/sync-access.log'));

        // OPTION 3: Synchronisation toutes les 30 secondes (plus raisonnable)
        // $schedule->command('sync:access-db')
        //     ->everyThirtySeconds()
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->appendOutputTo(storage_path('logs/sync-access.log'));

        // OPTION 4: Synchronisation chaque minute (standard)
        // $schedule->command('sync:access-db')
        //     ->everyMinute()
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->appendOutputTo(storage_path('logs/sync-access.log'));

        // Nettoyage des logs de synchronisation (optionnel)
        $schedule->command('sync:cleanup-logs')
            ->dailyAt('02:00')
            ->runInBackground();
    }

    /**
     * Enregistrement des commandes pour l'application
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

// CONFIGURATION ALTERNATIVE POUR PLUS DE CONTRÔLE
// Si vous voulez plus de flexibilité, vous pouvez aussi utiliser:

class AlternativeKernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Synchronisation avec gestion d'erreurs avancée
        $schedule->command('sync:access-db')
            ->everyTenSeconds()
            ->withoutOverlapping(10) // Timeout après 10 minutes
            ->runInBackground()
            ->onSuccess(function () {
                // Actions à effectuer en cas de succès
                Log::info('Synchronisation Access réussie');
            })
            ->onFailure(function () {
                // Actions à effectuer en cas d'échec
                Log::error('Échec de la synchronisation Access');
                // Optionnel: envoyer un email d'alerte
                // Mail::to('admin@votresite.com')->send(new SyncFailedMail());
            })
            ->appendOutputTo(storage_path('logs/sync-access.log'))
            ->emailOutputTo('admin@votresite.com'); // Email des logs (optionnel)

        // Synchronisation conditionnelle (seulement pendant les heures de bureau)
        $schedule->command('sync:access-db')
            ->everyTenSeconds()
            ->between('08:00', '18:00') // Seulement de 8h à 18h
            ->weekdays() // Seulement en semaine
            ->withoutOverlapping()
            ->runInBackground();

        // Synchronisation différente selon l'environnement
        if (app()->environment('production')) {
            // En production: moins fréquent
            $schedule->command('sync:access-db')
                ->everyThirtySeconds()
                ->withoutOverlapping()
                ->runInBackground();
        } else {
            // En développement: plus fréquent pour les tests
            $schedule->command('sync:access-db')
                ->everyTenSeconds()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }
}