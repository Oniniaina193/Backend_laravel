<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom_complet',
        'adresse',
        'telephone'
    ];

    // Relations
    public function ordonnances()
    {
        return $this->hasMany(Ordonnance::class);
    }

    // Scopes pour recherche
    public function scopeSearch(Builder $query, string $search = null)
    {
        if ($search) {
            return $query->where('nom_complet', 'ILIKE', "%{$search}%")
                        ->orWhere('telephone', 'LIKE', "%{$search}%");
        }
        return $query;
    }

    // Scope par nom complet
    public function scopeByNom(Builder $query, string $nom = null)
    {
        if ($nom) {
            return $query->where('nom_complet', 'ILIKE', "%{$nom}%");
        }
        return $query;
    }

    // Accesseur pour formater le nom complet
    public function getFormattedNameAttribute()
    {
        return ucwords(strtolower($this->nom_complet));
    }

    // Accesseur pour affichage complet avec téléphone si disponible
    public function getDisplayInfoAttribute()
    {
        $info = $this->nom_complet;
        if ($this->telephone) {
            $info .= " ({$this->telephone})";
        }
        return $info;
    }
}