<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Pergunta;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpvotePerguntaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Evento $evento;

    private Pergunta $pergunta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->usuario = User::factory()->create();
        $this->evento = Evento::create(['titulo' => 'Evento de teste']);
        $this->pergunta = $this->criarPergunta('Como funciona o ranking de perguntas?');
    }

    private function criarPergunta(string $texto): Pergunta
    {
        return Pergunta::create([
            'evento_id' => $this->evento->id,
            'user_id' => $this->usuario->id,
            'texto' => $texto,
        ]);
    }

    private function urlVoto(): string
    {
        return route('perguntas.votar', $this->pergunta);
    }

    public function test_relationships_are_many_to_many_and_save_timestamps(): void
    {
        $this->assertInstanceOf(BelongsToMany::class, $this->pergunta->votos());
        $this->assertInstanceOf(BelongsToMany::class, $this->usuario->perguntasVotadas());

        $this->pergunta->votos()->attach($this->usuario->id);

        $this->assertTrue($this->pergunta->votos->first()->is($this->usuario));
        $this->assertTrue($this->usuario->perguntasVotadas->first()->is($this->pergunta));
        $this->assertNotNull($this->pergunta->votos->first()->pivot->created_at);
        $this->assertNotNull($this->pergunta->votos->first()->pivot->updated_at);
    }

    public function test_authenticated_user_can_vote(): void
    {
        $this->actingAs($this->usuario)
            ->from(route('eventos.show', $this->evento->id))
            ->post($this->urlVoto())
            ->assertRedirect(route('eventos.show', $this->evento->id))
            ->assertSessionHas('sucesso', 'Voto registrado!');

        $this->assertDatabaseHas('pergunta_user', [
            'pergunta_id' => $this->pergunta->id,
            'user_id' => $this->usuario->id,
        ]);
    }

    public function test_second_click_removes_the_vote(): void
    {
        $this->pergunta->votos()->attach($this->usuario->id);

        $this->actingAs($this->usuario)->post($this->urlVoto())
            ->assertRedirect()
            ->assertSessionHas('sucesso', 'Voto removido!');

        $this->assertDatabaseCount('pergunta_user', 0);
    }

    public function test_repeated_clicks_toggle_without_creating_duplicate_votes(): void
    {
        $this->actingAs($this->usuario);

        foreach ([1, 0, 1] as $quantidade) {
            $this->post($this->urlVoto())->assertRedirect();
            $this->assertDatabaseCount('pergunta_user', $quantidade);
        }
    }

    public function test_removing_a_vote_preserves_other_users_votes(): void
    {
        $outroUsuario = User::factory()->create();
        $this->actingAs($this->usuario)->post($this->urlVoto())->assertRedirect();
        $this->actingAs($outroUsuario)->post($this->urlVoto())->assertRedirect();
        $this->actingAs($this->usuario)->post($this->urlVoto())->assertRedirect();

        $this->assertDatabaseCount('pergunta_user', 1);
        $this->assertDatabaseHas('pergunta_user', [
            'pergunta_id' => $this->pergunta->id,
            'user_id' => $outroUsuario->id,
        ]);
    }

    public function test_guest_cannot_vote_and_is_redirected_to_login(): void
    {
        $this->post($this->urlVoto())->assertRedirect(route('login.create'));

        $this->assertDatabaseCount('pergunta_user', 0);
    }

    public function test_guest_json_request_returns_401(): void
    {
        $this->postJson($this->urlVoto())->assertUnauthorized();

        $this->assertDatabaseCount('pergunta_user', 0);
    }

    public function test_missing_question_returns_404(): void
    {
        $this->actingAs($this->usuario)
            ->post(route('perguntas.votar', 999999))
            ->assertNotFound();

        $this->assertDatabaseCount('pergunta_user', 0);
    }

    public function test_database_rejects_a_duplicate_vote(): void
    {
        $this->pergunta->votos()->attach($this->usuario->id);

        $this->expectException(QueryException::class);
        $this->pergunta->votos()->attach($this->usuario->id);
    }

    public function test_deleting_a_question_cascades_to_its_votes(): void
    {
        $this->pergunta->votos()->attach($this->usuario->id);
        $this->pergunta->delete();

        $this->assertDatabaseCount('pergunta_user', 0);
    }

    public function test_deleting_a_voter_cascades_to_their_votes(): void
    {
        $votante = User::factory()->create();
        $this->pergunta->votos()->attach($votante->id);
        $votante->delete();

        $this->assertDatabaseCount('pergunta_user', 0);
        $this->assertDatabaseHas('perguntas', ['id' => $this->pergunta->id]);
    }

    public function test_ranking_orders_by_votes_then_by_newest_question_and_filters_event(): void
    {
        $maisVotada = $this->criarPergunta('Pergunta mais votada e antiga');
        $maisVotada->forceFill(['created_at' => now()->subDay()])->save();
        $empatadaAntiga = $this->criarPergunta('Pergunta empatada e antiga');
        $empatadaAntiga->forceFill(['created_at' => now()->subHour()])->save();
        $empatadaNova = $this->criarPergunta('Pergunta empatada e recente');
        $usuarios = User::factory()->count(3)->create();

        $maisVotada->votos()->attach($usuarios->modelKeys());
        $empatadaAntiga->votos()->attach($usuarios->take(2)->modelKeys());
        $empatadaNova->votos()->attach($usuarios->take(2)->modelKeys());

        $outroEvento = Evento::create(['titulo' => 'Outro evento']);
        $outraPergunta = Pergunta::create([
            'evento_id' => $outroEvento->id,
            'texto' => 'Pergunta exclusiva de outro evento',
        ]);
        $outraPergunta->votos()->attach($usuarios->modelKeys());

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('3 votos')
            ->assertDontSee($outraPergunta->texto)
            ->assertViewHas('perguntas', function ($perguntas) use ($maisVotada, $empatadaNova, $empatadaAntiga) {
                return $perguntas->pluck('id')->all() === [
                    $maisVotada->id, $empatadaNova->id, $empatadaAntiga->id, $this->pergunta->id,
                ] && $perguntas->pluck('votos_count')->all() === [3, 2, 2, 0];
            });
    }

    public function test_ranking_updates_after_removing_a_vote(): void
    {
        $outra = $this->criarPergunta('Pergunta que mantém seu voto');
        $this->pergunta->votos()->attach($this->usuario->id);
        $outra->votos()->attach($this->usuario->id);

        $this->actingAs($this->usuario)->post($this->urlVoto())->assertRedirect();

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->pluck('id')->all() === [$outra->id, $this->pergunta->id]
                && $perguntas->pluck('votos_count')->all() === [1, 0]);
    }

    public function test_ranking_keeps_ten_questions_per_page(): void
    {
        $usuarios = User::factory()->count(12)->create();
        $ids = [];

        for ($i = 1; $i <= 12; $i++) {
            $pergunta = $this->criarPergunta('Pergunta do ranking '.$i);
            $pergunta->votos()->attach($usuarios->take($i)->modelKeys());
            $ids[] = $pergunta->id;
        }

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('?page=2', false)
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->total() === 13
                && $perguntas->perPage() === 10
                && $perguntas->pluck('id')->all() === array_slice(array_reverse($ids), 0, 10));

        $this->get(route('eventos.show', $this->evento->id).'?page=2')
            ->assertOk()
            ->assertViewHas('perguntas', fn ($perguntas) => $perguntas->pluck('id')->all() === [$ids[1], $ids[0], $this->pergunta->id]);
    }

    public function test_button_highlights_the_current_users_vote_and_reverts_after_removal(): void
    {
        $this->actingAs($this->usuario)->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee($this->urlVoto(), false)
            ->assertSee('aria-pressed="false"', false)
            ->assertSee('btn-outline-primary')
            ->assertDontSee('Remover voto');

        $this->post($this->urlVoto())->assertRedirect();

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('1 votos')
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('Remover voto');

        $this->post($this->urlVoto())->assertRedirect();

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('0 votos')
            ->assertDontSee('Remover voto');
    }

    public function test_another_users_vote_does_not_highlight_the_button(): void
    {
        $outroUsuario = User::factory()->create();
        $this->pergunta->votos()->attach($outroUsuario->id);

        $this->actingAs($this->usuario)->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('1 votos')
            ->assertSee('aria-pressed="false"', false)
            ->assertDontSee('Remover voto');
    }

    public function test_guests_see_the_count_and_login_link_without_voting_form(): void
    {
        $this->pergunta->votos()->attach($this->usuario->id);

        $this->get(route('eventos.show', $this->evento->id))
            ->assertOk()
            ->assertSee('1 votos')
            ->assertSee('Entre para votar')
            ->assertDontSee($this->urlVoto(), false);
    }

    public function test_authenticated_ranking_does_not_issue_queries_per_question(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $pergunta = $this->criarPergunta('Pergunta com voto '.$i);
            $pergunta->votos()->attach($this->usuario->id);
        }

        $this->actingAs($this->usuario);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->get(route('eventos.show', $this->evento->id));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()->assertViewHas('perguntas', fn ($perguntas) => $perguntas->count() === 10
            && $perguntas->every(fn ($pergunta) => $pergunta->usuario_votou && $pergunta->votos_count === 1));
        $this->assertCount(4, $queries);
    }
}
