<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ordonnances', function (Blueprint $table) {
            $table->id();
            $table->string('numero_ordonnance')->unique()->index(); // Numéro unique
            $table->date('date'); // Date de l'ordonnance
            
            // Relations avec médecin et client
            $table->foreignId('medecin_id')->constrained('medecins')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            
            $table->timestamps();
            
            // Index pour optimiser les requêtes par date
            $table->index(['date']);
            $table->index(['medecin_id', 'date']);
            $table->index(['client_id', 'date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ordonnances');
    }
};