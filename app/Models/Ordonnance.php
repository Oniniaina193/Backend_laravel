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
        'numero_ordonnance', // MODIFICATION 1: Maintenu mais plus d'auto-génération
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

    // Scopes
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

    // Accesseurs
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

    // MODIFICATION 1: Méthode conservée mais optionnelle pour suggestion
    // Cette méthode peut être utilisée côté frontend pour suggérer un numéro
    public static function suggestNumeroOrdonnance()
    {
        $today = Carbon::now();
        $prefix = 'ORD' . $today->format('Ymd');
        
        // Trouver le dernier numéro du jour
        $lastOrdonnance = static::where('numero_ordonnance', 'LIKE', $prefix . '%')
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

    // MODIFICATION 4: Méthode pour vérifier si le numéro existe déjà
    public static function numeroExists($numero)
    {
        return static::where('numero_ordonnance', $numero)->exists();
    }
}