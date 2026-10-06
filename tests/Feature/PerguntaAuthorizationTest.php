<?php

use App\Models\Evento;
use App\Models\Pergunta;
use App\Models\User;

beforeEach(function () {
    $this->dono = User::factory()->create();
    $this->autor = User::factory()->create();
    $this->terceiro = User::factory()->create();
    $this->evento = Evento::create([
        'titulo' => 'Evento de teste',
        'user_id' => $this->dono->id,
    ]);
    $this->pergunta = Pergunta::create([
        'evento_id' => $this->evento->id,
        'user_id' => $this->autor->id,
        'texto' => 'Como funciona a autorização das perguntas?',
    ]);
    $this->url = route('eventos.perguntas.destroy', [$this->evento->id, $this->pergunta->id]);
});

it('permite que o autor exclua sua pergunta', function () {
    $this->actingAs($this->autor)->delete($this->url)
        ->assertRedirect(route('eventos.show', $this->evento->id))
        ->assertSessionHas('sucesso');

    $this->assertDatabaseMissing('perguntas', ['id' => $this->pergunta->id]);
});

it('permite que o dono do evento exclua perguntas de outros autores', function () {
    $this->actingAs($this->dono)->delete($this->url)->assertRedirect();

    $this->assertDatabaseMissing('perguntas', ['id' => $this->pergunta->id]);
});

it('bloqueia outro usuário mesmo quando ele envia DELETE diretamente', function () {
    $this->actingAs($this->terceiro)->delete($this->url)->assertForbidden();

    $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
});

it('bloqueia a exclusão por visitantes', function () {
    $this->delete($this->url)->assertRedirect(route('login.create'));

    $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
});

it('mostra o botão de exclusão para o autor', function () {
    $this->actingAs($this->autor)->get(route('eventos.show', $this->evento->id))
        ->assertOk()
        ->assertSee($this->url, false)
        ->assertSee('Excluir pergunta')
        ->assertSee('btn-danger');
});

it('mostra o botão de exclusão para o dono do evento', function () {
    $this->actingAs($this->dono)->get(route('eventos.show', $this->evento->id))
        ->assertOk()
        ->assertSee($this->url, false)
        ->assertSee('Excluir pergunta');
});

it('oculta o botão de exclusão para outros usuários', function () {
    $this->actingAs($this->terceiro)->get(route('eventos.show', $this->evento->id))
        ->assertOk()
        ->assertDontSee($this->url, false)
        ->assertDontSee('Excluir pergunta');
});

it('oculta o botão de exclusão para visitantes', function () {
    $this->get(route('eventos.show', $this->evento->id))
        ->assertOk()
        ->assertDontSee($this->url, false)
        ->assertDontSee('Excluir pergunta');
});

it('não exclui uma pergunta enviada com o ID de outro evento', function () {
    $outroEvento = Evento::create([
        'titulo' => 'Outro evento',
        'user_id' => $this->dono->id,
    ]);

    $this->actingAs($this->dono)
        ->delete(route('eventos.perguntas.destroy', [$outroEvento->id, $this->pergunta->id]))
        ->assertNotFound();

    $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
});

it('lista somente perguntas do evento aberto', function () {
    $outroEvento = Evento::create(['titulo' => 'Outro evento', 'user_id' => $this->autor->id]);
    $outraPergunta = Pergunta::create([
        'evento_id' => $outroEvento->id,
        'user_id' => $this->autor->id,
        'texto' => 'Esta pergunta pertence a outro evento.',
    ]);

    $this->actingAs($this->autor)->get(route('eventos.show', $this->evento->id))
        ->assertOk()
        ->assertSee($this->pergunta->texto)
        ->assertDontSee($outraPergunta->texto);
});

it('retorna 404 ao tentar excluir uma pergunta inexistente', function () {
    $this->actingAs($this->dono)
        ->delete(route('eventos.perguntas.destroy', [$this->evento->id, 99999]))
        ->assertNotFound();

    $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
});

it('permite que o dono exclua uma pergunta sem autor', function () {
    $this->pergunta->update(['user_id' => null]);

    $this->actingAs($this->dono)->delete($this->url)->assertRedirect();

    $this->assertDatabaseMissing('perguntas', ['id' => $this->pergunta->id]);
});

it('não permite que um terceiro exclua uma pergunta sem autor ou dono de evento', function () {
    $this->pergunta->update(['user_id' => null]);
    $this->evento->update(['user_id' => null]);

    $this->actingAs($this->terceiro)->delete($this->url)->assertForbidden();

    $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
});
