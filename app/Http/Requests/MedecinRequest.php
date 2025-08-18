<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedecinRequest extends FormRequest
{
    public function authorize()
    {
        return true; // ou logique d'autorisation
    }

    public function rules()
    {
        $medecinId = $this->route('medecin')?->id;

        return [
            'nom_complet' => 'required|string|max:255|unique:medecins,nom_complet,' . $medecinId,
            'adresse' => 'nullable|string|max:255',
            'ONM' => 'required|string|max:100|unique:medecins,ONM,' . $medecinId,
            'telephone' => 'nullable|string|max:20',
        ];
    }

    public function messages()
    {
        return [
            'nom_complet.required' => 'Le nom complet est obligatoire',
            'nom_complet.unique' => 'Ce médecin existe déjà',
            'ONM.required' => 'L\'ONM est obligatoire',
            'ONM.unique' => 'Un médecin avec ce ONM existe déjà',
        ];
    }
}
