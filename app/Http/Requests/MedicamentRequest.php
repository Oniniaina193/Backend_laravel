<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicamentRequest extends FormRequest
{
    public function authorize()
    {
        return true; // ou logique d'autorisation
    }

    public function rules()
    {
        $medicamentId = $this->route('medicament')?->id;
        
        return [
            'nom' => 'required|string|max:255|unique:medicaments,nom,' . $medicamentId,
            'famille' => 'required|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'nom.required' => 'Le nom du médicament est obligatoire',
            'nom.unique' => 'Ce médicament existe déjà',
            'famille.required' => 'La famille est obligatoire',
        ];
    }
}