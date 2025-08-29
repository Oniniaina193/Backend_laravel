<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Ordonnance extends Model
{
    use HasFactory;

    protected $fillable = [
        'numero_ordonnance',
        'dossier_vente', // NOUVEAU : dossier de vente
        'date',
        'medecin_id',
        'client_id'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    // Relations
    public function medecin()
    {
        return $this->belongsTo(Medecin::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes()
    {
        return $this->hasMany(OrdonnanceLigne::class);
    }

    // GLOBAL SCOPE : Automatiquement filtrer par dossier actif
    protected static function booted()
    {
        static::addGlobalScope('dossier_vente', function (Builder $builder) {
            // Récupérer le dossier actuel depuis la request
            if (request() && request()->has('current_dossier_vente')) {
                $builder->where('dossier_vente', request()->get('current_dossier_vente'));
            }
        });

        // Automatiquement ajouter le dossier lors de la création
        static::creating(function ($ordonnance) {
            if (!$ordonnance->dossier_vente && request() && request()->has('current_dossier_vente')) {
                $ordonnance->dossier_vente = request()->get('current_dossier_vente');
            }
        });
    }

    // Scopes existants (inchangés)
    public function scopeSearch(Builder $query, string $search = null)
    {
        if ($search) {
            return $query->where('numero_ordonnance', 'ILIKE', "%{$search}%")
                        ->orWhereHas('client', function ($q) use ($search) {
                            $q->where('nom_complet', 'ILIKE', "%{$search}%");
                        })
                        ->orWhereHas('medecin', function ($q) use ($search) {
                            $q->where('nom_complet', 'ILIKE', "%{$search}%");
                        });
        }
        return $query;
    }

    public function scopeByDateRange(Builder $query, $dateDebut = null, $dateFin = null)
    {
        if ($dateDebut) {
            $query->where('date', '>=', $dateDebut);
        }
        if ($dateFin) {
            $query->where('date', '<=', $dateFin);
        }
        return $query;
    }

    public function scopeByMedecin(Builder $query, $medecinId = null)
    {
        if ($medecinId) {
            return $query->where('medecin_id', $medecinId);
        }
        return $query;
    }

    public function scopeByClient(Builder $query, $clientId = null)
    {
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }
        return $query;
    }

    // NOUVEAU : Scope pour forcer un dossier spécifique (sans le global scope)
    public function scopeInDossier(Builder $query, string $dossierVente)
    {
        return $query->withoutGlobalScope('dossier_vente')->where('dossier_vente', $dossierVente);
    }

    // NOUVEAU : Scope pour voir toutes les ordonnances (tous dossiers)
    public function scopeAllDossiers(Builder $query)
    {
        return $query->withoutGlobalScope('dossier_vente');
    }

    // Accesseurs (inchangés)
    public function getFormattedDateAttribute()
    {
        return $this->date->format('d/m/Y');
    }

    public function getFormattedDateTimeAttribute()
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getTotalMedicamentsAttribute()
    {
        return $this->lignes()->count();
    }

    // MODIFIÉ : Suggestion de numéro par dossier
    public static function suggestNumeroOrdonnance($dossierVente = null)
    {
        $today = Carbon::now();
        $prefix = 'ORD' . $today->format('Ymd');
        
        // Utiliser le dossier actuel si pas spécifié
        if (!$dossierVente && request() && request()->has('current_dossier_vente')) {
            $dossierVente = request()->get('current_dossier_vente');
        }
        
        if (!$dossierVente) {
            return $prefix . '001'; // Fallback si pas de dossier
        }
        
        // Trouver le dernier numéro du jour DANS CE DOSSIER
        $lastOrdonnance = static::allDossiers()
                               ->where('dossier_vente', $dossierVente)
                               ->where('numero_ordonnance', 'LIKE', $prefix . '%')
                               ->orderBy('numero_ordonnance', 'desc')
                               ->first();
        
        if ($lastOrdonnance) {
            $lastNumber = (int) substr($lastOrdonnance->numero_ordonnance, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }
        
        return $prefix . $newNumber;
    }

    // MODIFIÉ : Vérifier l'existence du numéro dans le dossier actuel
    public static function numeroExists($numero, $dossierVente = null)
    {
        if (!$dossierVente && request() && request()->has('current_dossier_vente')) {
            $dossierVente = request()->get('current_dossier_vente');
        }
        
        if (!$dossierVente) {
            return false; // Si pas de dossier, on ne peut pas vérifier
        }
        
        return static::allDossiers()
                    ->where('numero_ordonnance', $numero)
                    ->where('dossier_vente', $dossierVente)
                    ->exists();
    }
}