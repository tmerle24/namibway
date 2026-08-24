<?php

namespace Tests\Feature\Kaia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kaia's questions are answered by tapping, not only by typing, and the thing
 * that makes that possible is the interview naming what it just asked for.
 * The frontend owns the answers themselves (lib/kaia-suggestions.ts); all the
 * backend owes it is a slot it can trust — so what is pinned here is that a
 * declared slot arrives intact, and that anything else arrives as *no* slot
 * rather than as a wrong one. A traveler falling back to the text field for
 * one turn is a small loss; buttons offering vehicle types under a question
 * about children is a broken conversation.
 */
class InterviewSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key']);
    }

    private function fakeReply(string $text, mixed $awaiting): void
    {
        $input = ['text' => $text];

        if ($awaiting !== null) {
            $input['awaiting'] = $awaiting;
        }

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'name' => 'reply_to_traveler',
                    'input' => $input,
                ]],
            ], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $text = 'I want to plan a trip'): array
    {
        $response = $this->postJson('/kaia/message', [
            'history' => [['role' => 'user', 'text' => $text]],
        ]);

        $response->assertOk();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    public function test_a_declared_slot_reaches_the_chat(): void
    {
        $this->fakeReply('How many nights do you have?', 'nights');

        $json = $this->ask();

        $this->assertSame('question', $json['type']);
        $this->assertSame('How many nights do you have?', $json['text']);
        $this->assertSame('nights', $json['awaiting']);
    }

    public function test_a_general_answer_offers_nothing(): void
    {
        // Mode 1: a factual question about Namibia. There is no closed set of
        // sensible follow-ups, and inventing one would put words in the
        // traveler's mouth — so "none" comes through as null.
        $this->fakeReply('May to October is the dry season.', 'none');

        $this->assertNull($this->ask('When should I visit?')['awaiting']);
    }

    public function test_a_slot_outside_the_enum_is_dropped(): void
    {
        // The model is asked for one of a fixed list; this is what happens
        // when it answers with something else.
        $this->fakeReply('Which airline are you flying?', 'airline');

        $json = $this->ask();

        $this->assertSame('Which airline are you flying?', $json['text']);
        $this->assertNull($json['awaiting']);
    }

    public function test_a_missing_slot_is_dropped(): void
    {
        $this->fakeReply('Tell me more about your trip.', null);

        $this->assertNull($this->ask()['awaiting']);
    }

    public function test_prose_still_answers_the_traveler(): void
    {
        // The tool choice is forced, so this should not happen — but a reply
        // the traveler can read is worth more than a strict parser, and the
        // only thing lost is that turn's buttons.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Namibia is lovely in June.']],
            ], 200),
        ]);

        $json = $this->ask('Tell me about Namibia');

        $this->assertSame('question', $json['type']);
        $this->assertSame('Namibia is lovely in June.', $json['text']);
        $this->assertNull($json['awaiting']);
    }

    public function test_the_interview_call_forces_a_tool_call(): void
    {
        $this->fakeReply('How many nights?', 'nights');

        $this->ask();

        Http::assertSent(function (ClientRequest $request) {
            /** @var array<string, mixed> $payload */
            $payload = $request->data();

            $this->assertSame(['type' => 'any'], $payload['tool_choice'] ?? null);

            /** @var array<int, array<string, mixed>> $tools */
            $tools = $payload['tools'];
            $names = array_column($tools, 'name');

            $this->assertContains('reply_to_traveler', $names);
            $this->assertContains('ready_for_itinerary', $names);

            $reply = $tools[array_search('reply_to_traveler', $names, true)];

            // The enum is the contract the frontend's chip sets are keyed on.
            $this->assertSame(
                ['nights', 'travel_period', 'interests', 'budget_tier', 'travelers', 'vehicle_type', 'start_end', 'none'],
                $reply['input_schema']['properties']['awaiting']['enum'],
            );

            return true;
        });
    }
}
