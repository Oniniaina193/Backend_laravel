<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            // 1. Ajouter la colonne dossier_vente, nullable pour éviter l'erreur avec les anciennes données
            $table->string('dossier_vente', 100)->nullable()->after('numero_ordonnance');

            // 2. Supprimer l'ancienne contrainte unique sur numero_ordonnance
            $table->dropUnique(['numero_ordonnance']);
        });

        // 3. Remplir les anciennes lignes avec une valeur par défaut
        DB::table('ordonnances')->update(['dossier_vente' => 'DEFAULT']);

        Schema::table('ordonnances', function (Blueprint $table) {
            // 4. Rendre la colonne NOT NULL après avoir rempli les anciennes lignes
            $table->string('dossier_vente', 100)->default('DEFAULT')->nullable(false)->change();

            // 5. Créer la nouvelle contrainte composite
            $table->unique(['numero_ordonnance', 'dossier_vente']);

            // 6. Ajouter des index utiles
            $table->index(['dossier_vente']);
            $table->index(['dossier_vente', 'date']);
        });
    }

    public function down()
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            // Supprimer la contrainte composite
            $table->dropUnique(['numero_ordonnance', 'dossier_vente']);

            // Remettre l'ancienne contrainte unique
            $table->unique(['numero_ordonnance']);

            // Supprimer les index
            $table->dropIndex(['ordonnances_dossier_vente_index']);
            $table->dropIndex(['ordonnances_dossier_vente_date_index']);

            // Supprimer la colonne
            $table->dropColumn('dossier_vente');
        });
    }
};
