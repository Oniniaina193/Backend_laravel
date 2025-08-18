<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Medecin extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom_complet',
        'adresse',
        'ONM',
        'telephone'
    ];

    // Optimisation : pas de timestamps si non nécessaires
    // public $timestamps = false;

    // Scope pour recherche rapide
    public function scopeSearch(Builder $query, string $search = null)
    {
        if ($search) {
            return $query->where('nom_complet', 'ILIKE', "%{$search}%")
                         ->orWhere('ONM', 'ILIKE', "%{$search}%");
        }
        return $query;
    }

    // Optionnel : scope par adresse ou ONM si besoin
    public function scopeByONM(Builder $query, string $onm = null)
    {
        if ($onm) {
            return $query->where('ONM', $onm);
        }
        return $query;
    }

    // Récupérer tous les ONM uniques (avec cache) - utile pour autocomplete
    public static function getONMs()
    {
        return cache()->remember('medecins.ONMs', 3600, function () {
            return self::distinct('ONM')
                      ->orderBy('ONM')
                      ->pluck('ONM')
                      ->filter()
                      ->values();
        });
    }
}
