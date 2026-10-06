<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Pergunta;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Sprint02Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_questions_and_authors_have_inverse_relationships(): void
    {
        $autor = User::factory()->create();
        $outroAutor = User::factory()->create();
        $evento = Evento::create(['titulo' => 'Evento de teste']);
        $pergunta = Pergunta::create([
            'evento_id' => $evento->id,
            'user_id' => $autor->id,
            'texto' => 'Como funcionam os relacionamentos?',
        ]);
        Pergunta::create([
            'evento_id' => $evento->id,
            'user_id' => $outroAutor->id,
            'texto' => 'Pergunta de outro participante.',
        ]);

        $this->assertInstanceOf(BelongsTo::class, $pergunta->user());
        $this->assertInstanceOf(HasMany::class, $autor->perguntas());
        $this->assertTrue($pergunta->user->is($autor));
        $this->assertSame([$pergunta->id], $autor->perguntas->modelKeys());
    }

    public function test_cards_display_author_names_and_anonymous_fallback(): void
    {
        $autor = User::factory()->create(['name' => 'Participante da palestra']);
        $evento = Evento::create(['titulo' => 'Evento de teste']);

        foreach ([$autor->id, null] as $autorId) {
            Pergunta::create([
                'evento_id' => $evento->id,
                'user_id' => $autorId,
                'texto' => 'Qual o próximo assunto da palestra?',
            ]);
        }

        $this->get(route('eventos.show', $evento->id))
            ->assertOk()
            ->assertSee('Autor: Participante da palestra')
            ->assertSee('Autor: Anônimo');
    }

    public static function questionCounts(): array
    {
        return [
            'uma pergunta' => [1],
            'dez perguntas' => [10],
            'vinte e cinco perguntas' => [25],
        ];
    }

    #[DataProvider('questionCounts')]
    public function test_author_query_count_stays_constant_when_question_count_grows(int $quantidade): void
    {
        $evento = Evento::create(['titulo' => 'Evento de teste']);

        for ($i = 1; $i <= $quantidade; $i++) {
            $autor = User::factory()->create(['name' => 'Participante '.$i]);
            Pergunta::create([
                'evento_id' => $evento->id,
                'user_id' => $autor->id,
                'texto' => 'Pergunta do participante '.$i,
                'created_at' => now()->subMinutes($quantidade - $i),
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->get(route('eventos.show', $evento->id));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()
            ->assertViewHas('perguntas', function ($perguntas) use ($quantidade) {
                return $perguntas->perPage() === 10
                    && $perguntas->total() === $quantidade
                    && $perguntas->count() === min(10, $quantidade)
                    && $perguntas->every(fn ($pergunta) => $pergunta->relationLoaded('user'));
            });

        $consultasAutores = array_filter($queries, fn ($query) => str_contains($query['query'], '"users"'));
        $this->assertCount(1, $consultasAutores);
        $this->assertCount(4, $queries);
    }
}
