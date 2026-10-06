<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EventoFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'titulo' => 'required|string|max:191',
            'descricao' => 'required|string',
            'data_evento' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'Informe o título do evento.',
            'titulo.string' => 'O título deve ser um texto.',
            'titulo.max' => 'O título deve ter no máximo 191 caracteres.',
            'descricao.required' => 'Informe a descrição do evento.',
            'descricao.string' => 'A descrição deve ser um texto.',
            'data_evento.date' => 'Informe uma data válida para o evento.',
        ];
    }
}
