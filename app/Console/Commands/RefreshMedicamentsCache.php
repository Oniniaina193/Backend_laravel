<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Ordonnance;

class RefreshMedicamentsCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'medicaments:refresh-cache {dossier?} {--all}';

    /**
     * The console command description.
     */
    protected $description = 'Rafraîchit le cache des médicaments pour améliorer les performances de recherche';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dossier = $this->argument('dossier');
        $all = $this->option('all');

        if ($all) {
            $this->info('🔄 Rafraîchissement du cache pour tous les dossiers...');
            $this->refreshAllDossiers();
        } elseif ($dossier) {
            $this->info("🔄 Rafraîchissement du cache pour le dossier: {$dossier}");
            $this->refreshDossier($dossier);
        } else {
            $this->error('❌ Veuillez spécifier un dossier ou utiliser --all');
            $this->info('Usage: php artisan medicaments:refresh-cache [dossier] [--all]');
            return 1;
        }

        $this->info('✅ Cache des médicaments rafraîchi avec succès');
        return 0;
    }

    /**
     * Rafraîchir le cache pour tous les dossiers
     */
    private function refreshAllDossiers()
    {
        $dossiers = DB::table('ordonnances')
            ->select('dossier_vente')
            ->distinct()
            ->whereNotNull('dossier_vente')
            ->pluck('dossier_vente');

        $bar = $this->output->createProgressBar($dossiers->count());
        $bar->start();

        foreach ($dossiers as $dossier) {
            $this->refreshDossier($dossier, false);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Rafraîchir le cache pour un dossier spécifique
     */
    private function refreshDossier($dossier, $showProgress = true)
    {
        if ($showProgress) {
            $this->info("📊 Analyse des médicaments du dossier: {$dossier}");
        }

        // Recalculer les statistiques des médicaments
        $medicaments = DB::table('ordonnance_lignes')
            ->join('ordonnances', 'ordonnance_lignes.ordonnance_id', '=', 'ordonnances.id')
            ->where('ordonnances.dossier_vente', $dossier)
            ->select([
                'ordonnance_lignes.designation',
                DB::raw('COUNT(DISTINCT ordonnances.id) as total_ordonnances'),
                DB::raw('COUNT(ordonnance_lignes.id) as total_prescriptions'),
                DB::raw('SUM(ordonnance_lignes.quantite) as total_quantite'),
                DB::raw('MAX(ordonnances.date) as derniere_utilisation')
            ])
            ->groupBy('ordonnance_lignes.designation')
            ->get();

        if ($showProgress) {
            $this->info("📈 {$medicaments->count()} médicaments uniques trouvés");

            // Afficher le top 5 des médicaments les plus prescrits
            $top5 = $medicaments->sortByDesc('total_ordonnances')->take(5);
            $this->info("🏆 Top 5 des médicaments les plus prescrits:");
            foreach ($top5 as $med) {
                $this->info("   • {$med->designation}: {$med->total_ordonnances} ordonnances");
            }
        }

        // Mettre à jour la table de cache si elle existe
        if (Schema::hasTable('medicaments_cache')) {
            DB::table('medicaments_cache')
                ->where('dossier_vente', $dossier)
                ->delete();

            foreach ($medicaments as $med) {
                DB::table('medicaments_cache')->insert([
                    'dossier_vente' => $dossier,
                    'designation' => $med->designation,
                    'total_ordonnances' => $med->total_ordonnances,
                    'total_prescriptions' => $med->total_prescriptions,
                    'total_quantite' => $med->total_quantite ?? 0,
                    'derniere_utilisation' => $med->derniere_utilisation,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if ($showProgress) {
                $this->info("💾 Cache sauvegardé en base de données");
            }
        }
    }
}