@extends('layouts.app')

@section('title', 'Criar Evento — FalaQ')

@section('content')
<div class="max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md text-gray-900">
    <h1 class="text-2xl font-bold mb-2">Criar evento</h1>
    <p class="text-gray-600 mb-6">Preencha o título e a descrição do seu evento.</p>

    <form action="{{ route('eventos.store') }}" method="POST" class="space-y-5" novalidate>
        @csrf

        <div>
            <label for="titulo" class="block text-sm font-medium mb-1">Título do evento</label>
            <input type="text" name="titulo" id="titulo" value="{{ old('titulo') }}" maxlength="191" required
                   class="w-full border p-3 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('titulo') border-red-500 @else border-gray-300 @enderror"
                   @error('titulo') aria-invalid="true" aria-describedby="titulo-error" @enderror>
            @error('titulo')
                <p id="titulo-error" class="text-red-500 text-sm mt-1" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="descricao" class="block text-sm font-medium mb-1">Descrição do evento</label>
            <textarea name="descricao" id="descricao" rows="5" required
                      class="w-full border p-3 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('descricao') border-red-500 @else border-gray-300 @enderror"
                      @error('descricao') aria-invalid="true" aria-describedby="descricao-error" @enderror>{{ old('descricao') }}</textarea>
            @error('descricao')
                <p id="descricao-error" class="text-red-500 text-sm mt-1" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="data_evento" class="block text-sm font-medium mb-1">Data do evento (opcional)</label>
            <input type="date" name="data_evento" id="data_evento" value="{{ old('data_evento') }}"
                   class="w-full border p-3 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('data_evento') border-red-500 @else border-gray-300 @enderror"
                   @error('data_evento') aria-invalid="true" aria-describedby="data_evento-error" @enderror>
            @error('data_evento')
                <p id="data_evento-error" class="text-red-500 text-sm mt-1" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Criar evento
            </button>
            <a href="{{ route('eventos.index') }}" class="text-gray-600 hover:text-gray-900">Cancelar</a>
        </div>
    </form>
</div>
@endsection
