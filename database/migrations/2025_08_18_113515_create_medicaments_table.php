<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('medicaments', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->index(); // Index pour recherche rapide
            $table->string('famille')->index(); // Index pour filtrage rapide
            $table->timestamps();
            
            // Index composé pour optimiser les requêtes nom + famille
            $table->index(['nom', 'famille']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('medicaments');
    }
};
