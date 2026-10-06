<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventoCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_authenticated_user_sees_the_complete_form(): void
    {
        $this->actingAs(User::factory()->create())->get(route('eventos.create'))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="titulo"', false)
            ->assertSee('name="descricao"', false)
            ->assertSee('name="data_evento"', false)
            ->assertSee(route('eventos.store'), false)
            ->assertSee('bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700', false)
            ->assertDontSee('border-red-500');
    }

    public function test_blank_form_displays_both_errors_and_red_borders(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('eventos.create'))
            ->post(route('eventos.store'), [
                'titulo' => '',
                'descricao' => '',
                'data_evento' => '',
            ])
            ->assertRedirect(route('eventos.create'))
            ->assertSessionHasErrors(['titulo', 'descricao'])
            ->assertSessionDoesntHaveErrors('data_evento');

        $this->get(route('eventos.create'))->assertOk()
            ->assertSee('Informe o título do evento.')
            ->assertSee('Informe a descrição do evento.')
            ->assertSee('id="titulo-error" class="text-red-500', false)
            ->assertSee('id="descricao-error" class="text-red-500', false)
            ->assertSee('border-red-500')
            ->assertSee('aria-invalid="true"', false);

        $this->assertDatabaseCount('eventos', 0);
    }

    public function test_validation_failure_preserves_title_description_and_date(): void
    {
        $titulo = str_repeat('a', 192);
        $descricao = 'Descrição com texto que deve continuar no formulário.';

        $this->actingAs(User::factory()->create())
            ->from(route('eventos.create'))
            ->post(route('eventos.store'), [
                'titulo' => $titulo,
                'descricao' => $descricao,
                'data_evento' => '2026-10-20',
            ])
            ->assertRedirect(route('eventos.create'))
            ->assertSessionHasErrors('titulo');

        $response = $this->get(route('eventos.create'))->assertOk()
            ->assertSee('value="'.$titulo.'"', false)
            ->assertSee($descricao)
            ->assertSee('value="2026-10-20"', false)
            ->assertSee('O título deve ter no máximo 191 caracteres.');

        $this->assertSame(1, substr_count($response->getContent(), 'border-red-500'));
        $this->assertDatabaseCount('eventos', 0);
    }

    public function test_description_error_preserves_the_valid_title(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('eventos.create'))
            ->post(route('eventos.store'), ['titulo' => 'Palestra de tecnologia', 'descricao' => ''])
            ->assertRedirect(route('eventos.create'))
            ->assertSessionHasErrors('descricao');

        $response = $this->get(route('eventos.create'))->assertOk()
            ->assertSee('value="Palestra de tecnologia"', false)
            ->assertSee('Informe a descrição do evento.');

        $this->assertSame(1, substr_count($response->getContent(), 'border-red-500'));
        $this->assertDatabaseCount('eventos', 0);
    }

    public function test_invalid_date_displays_feedback_and_preserves_the_other_fields(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('eventos.create'))
            ->post(route('eventos.store'), [
                'titulo' => 'Palestra de tecnologia',
                'descricao' => 'Introdução ao desenvolvimento web.',
                'data_evento' => 'data-inválida',
            ])
            ->assertRedirect(route('eventos.create'))
            ->assertSessionHasErrors('data_evento');

        $this->get(route('eventos.create'))->assertOk()
            ->assertSee('Informe uma data válida para o evento.')
            ->assertSee('value="Palestra de tecnologia"', false)
            ->assertSee('Introdução ao desenvolvimento web.');

        $this->assertDatabaseCount('eventos', 0);
    }

    public function test_valid_form_creates_an_event_for_the_authenticated_user(): void
    {
        $usuario = User::factory()->create();
        $outroUsuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('eventos.store'), [
            'titulo' => 'Palestra de tecnologia',
            'descricao' => 'Introdução ao desenvolvimento web.',
            'data_evento' => '2026-10-20',
            'user_id' => $outroUsuario->id,
        ])->assertRedirect(route('eventos.show', Evento::firstOrFail()->id));

        $this->assertDatabaseHas('eventos', [
            'titulo' => 'Palestra de tecnologia',
            'descricao' => 'Introdução ao desenvolvimento web.',
            'user_id' => $usuario->id,
        ]);
    }

    public function test_event_can_be_created_without_optional_date(): void
    {
        $this->actingAs(User::factory()->create())->post(route('eventos.store'), [
            'titulo' => 'Palestra sem data definida',
            'descricao' => 'A data será informada posteriormente.',
            'data_evento' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('eventos', [
            'titulo' => 'Palestra sem data definida',
            'data_evento' => null,
        ]);
    }

    public function test_guests_cannot_access_or_submit_the_form(): void
    {
        $this->get(route('eventos.create'))->assertRedirect(route('login.create'));
        $this->post(route('eventos.store'), [
            'titulo' => 'Evento não autorizado',
            'descricao' => 'Um visitante não pode cadastrar eventos.',
        ])->assertRedirect(route('login.create'));

        $this->assertDatabaseCount('eventos', 0);
    }
}
