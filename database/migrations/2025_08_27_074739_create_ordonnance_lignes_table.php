<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ordonnance_lignes', function (Blueprint $table) {
            $table->id();
            
            // Relation avec l'ordonnance
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->onDelete('cascade');
            
            // Données du médicament (récupérées depuis Access)
            $table->string('code_medicament'); // Code du médicament depuis Access
            $table->string('designation'); // Nom du médicament depuis TicketLigne
            $table->integer('quantite'); // Quantité depuis TicketLigne
            
            // Données ajoutées par le médecin
            $table->text('posologie'); // Comment prendre le médicament
            $table->string('duree'); // Durée du traitement
            
            $table->timestamps();
            
            // Index pour optimiser les requêtes
            $table->index(['ordonnance_id']);
            $table->index(['code_medicament']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ordonnance_lignes');
    }
};