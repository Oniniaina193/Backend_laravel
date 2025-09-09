<?php

// Migration pour optimiser les performances de recherche
// Fichier: database/migrations/xxxx_xx_xx_optimize_ordonnance_search.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Optimisations pour la recherche rapide de médicaments
     */
    public function up()
    {
        Schema::table('ordonnance_lignes', function (Blueprint $table) {
            // Index composé pour optimiser la recherche par désignation + dossier
            $table->index(['designation'], 'idx_designation');
            
            // Index pour les recherches LIKE sur designation
            // Note: Cet index aide pour les recherches commençant par le début du mot
            $table->index(['designation', 'ordonnance_id'], 'idx_designation_ordonnance');
        });

        Schema::table('ordonnances', function (Blueprint $table) {
            // Index composé pour dossier + date (très utilisé ensemble)
            $table->index(['dossier_vente', 'date'], 'idx_dossier_date');
            
            // Index pour améliorer les jointures
            $table->index(['dossier_vente', 'created_at'], 'idx_dossier_created');
        });

        // Ajouter un index FULLTEXT pour la recherche textuelle avancée (MySQL seulement)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ordonnance_lignes ADD FULLTEXT(designation)');
        }
    }

    /**
     * Supprimer les optimisations
     */
    public function down()
    {
        Schema::table('ordonnance_lignes', function (Blueprint $table) {
            $table->dropIndex('idx_designation');
            $table->dropIndex('idx_designation_ordonnance');
        });

        Schema::table('ordonnances', function (Blueprint $table) {
            $table->dropIndex('idx_dossier_date');
            $table->dropIndex('idx_dossier_created');
        });

        // Supprimer l'index FULLTEXT
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::statement('ALTER TABLE ordonnance_lignes DROP INDEX designation');
            } catch (Exception $e) {
                // Index might not exist, ignore
            }
        }
    }
};
