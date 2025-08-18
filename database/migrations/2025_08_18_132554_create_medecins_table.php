<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('medecins', function (Blueprint $table) {
            $table->id();
            $table->string('nom_complet')->index(); // Index pour recherche rapide
            $table->string('adresse')->nullable(); // Adresse facultative
            $table->string('ONM')->unique()->index(); // ONM unique et indexé
            $table->string('telephone')->nullable(); // Téléphone facultatif
            $table->timestamps();

            // Index composé pour optimiser les requêtes nom + ONM
            $table->index(['nom_complet', 'ONM']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('medecins');
    }
};
