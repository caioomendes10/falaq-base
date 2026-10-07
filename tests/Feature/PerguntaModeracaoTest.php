<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Pergunta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerguntaModeracaoTest extends TestCase
{
    use RefreshDatabase;

    private Evento $evento;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->autor = User::factory()->create(['name' => 'Participante da palestra']);
        $this->evento = Evento::create(['titulo' => 'Evento de teste']);
    }

    private function criarPergunta(string $texto, bool $publica = false): Pergunta
    {
        return Pergunta::create([
            'evento_id' => $this->evento->id,
            'user_id' => $this->autor->id,
            'texto' => $texto,
            'is_public' => $publica,
        ]);
    }

    public function test_guests_cannot_create_questions_via_web_or_json(): void
    {
        $dados = ['evento_id' => $this->evento->id, 'texto' => 'Uma pergunta válida para o evento.'];

        $this->post(route('eventos.perguntas.store', $this->evento->id), $dados)
            ->assertRedirect(route('login'));
        $this->postJson(route('eventos.perguntas.store', $this->evento->id), $dados)
            ->assertUnauthorized();

        $this->assertDatabaseCount('perguntas', 0);
    }

    public function test_authenticated_questions_start_pending_even_if_public_flag_is_submitted(): void
    {
        $outroUsuario = User::factory()->create();

        $this->actingAs($this->autor)
            ->post(route('eventos.perguntas.store', $this->evento->id), [
                'evento_id' => $this->evento->id,
                'texto' => 'Pergunta aguardando aprovação do organizador.',
                'is_public' => true,
                'user_id' => $outroUsuario->id,
            ])
            ->assertRedirect(route('eventos.show', $this->evento->id));

        $this->assertDatabaseHas('perguntas', [
            'evento_id' => $this->evento->id,
            'user_id' => $this->autor->id,
            'texto' => 'Pergunta aguardando aprovação do organizador.',
            'is_public' => false,
            'status' => 'pendente',
        ]);
        $this->assertSame(1, $this->autor->perguntas()->count());
    }

    public function test_public_wall_hides_pending_and_other_events_questions(): void
    {
        $this->criarPergunta('Pergunta aprovada para o telão.', true);
        $this->criarPergunta('Pergunta pendente e confidencial.');
        $anonima = $this->criarPergunta('Pergunta aprovada sem autor.', true);
        $anonima->update(['user_id' => null]);
        $outroEvento = Evento::create(['titulo' => 'Outro evento']);
        Pergunta::create([
            'evento_id' => $outroEvento->id,
            'texto' => 'Pergunta aprovada de outro evento.',
            'is_public' => true,
        ]);

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('Pergunta aprovada para o telão.')
            ->assertSee('Autor: Participante da palestra')
            ->assertSee('Autor: Anônimo')
            ->assertSee('Perguntas aprovadas: 2')
            ->assertSee('Entre na sua conta')
            ->assertDontSee('Pergunta pendente e confidencial.')
            ->assertDontSee('Pergunta aprovada de outro evento.')
            ->assertDontSee('name="texto"', false);
    }

    public function test_pending_questions_stay_hidden_for_logged_in_users(): void
    {
        $pendente = $this->criarPergunta('Pergunta ainda não aprovada.');

        $this->actingAs($this->autor)->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('name="texto"', false)
            ->assertSee('name="evento_id"', false)
            ->assertDontSee($pendente->texto)
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->total() === 0);
    }

    public function test_question_appears_only_after_becoming_public(): void
    {
        $pergunta = $this->criarPergunta('Pergunta que será aprovada.');

        $this->get(route('eventos.show', $this->evento->id))->assertOk()->assertDontSee($pergunta->texto);

        $pergunta->update(['is_public' => true]);

        $this->get(route('eventos.show', $this->evento->id))->assertOk()->assertSee($pergunta->texto);
    }

    public function test_filter_runs_before_ordering_and_ten_item_pagination(): void
    {
        $ids = [];

        for ($i = 1; $i <= 12; $i++) {
            $pergunta = $this->criarPergunta(sprintf('Pergunta pública %02d', $i), true);
            $pergunta->forceFill(['created_at' => now()->subMinutes(13 - $i)])->save();
            $ids[] = $pergunta->id;
        }
        $this->criarPergunta('Pergunta pendente mais recente.');

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('Perguntas aprovadas: 12')
            ->assertSee('?page=2', false)
            ->assertDontSee('Pergunta pendente mais recente.')
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->perPage() === 10
                && $perguntas->total() === 12
                && $perguntas->pluck('id')->all() === array_slice(array_reverse($ids), 0, 10));

        $this->get(route('eventos.show', $this->evento->id).'?page=2')
            ->assertOk()
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->pluck('id')->all() === [$ids[1], $ids[0]])
            ->assertDontSee('Pergunta pendente mais recente.');
    }

    public function test_authors_are_eager_loaded_without_queries_per_card(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $autor = User::factory()->create();
            $pergunta = $this->criarPergunta('Pergunta pública do participante '.$i, true);
            $pergunta->update(['user_id' => $autor->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->get(route('eventos.show', $this->evento->id));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()->assertViewHas('perguntas', fn ($perguntas) => $perguntas->count() === 10
            && $perguntas->every(fn ($pergunta) => $pergunta->relationLoaded('user')));
        $this->assertCount(4, $queries);
    }

    public function test_question_validation_from_previous_sprint_is_preserved(): void
    {
        $this->actingAs($this->autor)
            ->postJson(route('eventos.perguntas.store', $this->evento->id), [
                'evento_id' => $this->evento->id,
                'texto' => 'a',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('texto');

        $this->assertDatabaseCount('perguntas', 0);
    }

    public function test_missing_event_returns_404(): void
    {
        $this->get(route('eventos.show', 999999))->assertNotFound();
    }

    public function test_event_creation_remains_protected_without_duplicate_public_route(): void
    {
        $this->post(route('eventos.store'), ['titulo' => 'Evento de visitante'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('eventos', 1);
    }
}
