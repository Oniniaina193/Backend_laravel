<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class OrdonnanceLigne extends Model
{
    use HasFactory;

    protected $fillable = [
        'ordonnance_id',
        'code_medicament',
        'designation',
        'quantite',
        'posologie',
        'duree'
    ];

    protected $casts = [
        'quantite' => 'integer'
    ];

    // Relations
    public function ordonnance()
    {
        return $this->belongsTo(Ordonnance::class);
    }

    // Scopes
    public function scopeByMedicament(Builder $query, $codeMedicament = null)
    {
        if ($codeMedicament) {
            return $query->where('code_medicament', $codeMedicament);
        }
        return $query;
    }

    public function scopeByOrdonnance(Builder $query, $ordonnanceId = null)
    {
        if ($ordonnanceId) {
            return $query->where('ordonnance_id', $ordonnanceId);
        }
        return $query;
    }

    // Accesseurs
    public function getFormattedQuantiteAttribute()
    {
        return $this->quantite . ' unité' . ($this->quantite > 1 ? 's' : '');
    }

    public function getFormattedDesignationAttribute()
    {
        return ucfirst(strtolower($this->designation));
    }

    public function getDisplayInfoAttribute()
    {
        return "{$this->designation} - {$this->formatted_quantite}";
    }

    // Méthodes utilitaires
    public function getResumeAttribute()
    {
        return [
            'designation' => $this->designation,
            'quantite' => $this->quantite,
            'posologie' => $this->posologie,
            'duree' => $this->duree
        ];
    }
}