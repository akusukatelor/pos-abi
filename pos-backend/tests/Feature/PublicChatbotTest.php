<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicChatbotTest extends TestCase
{
    public function test_anonymous_user_can_read_public_topics_without_login(): void
    {
        $response = $this->getJson('/api/public/faq');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['topics' => [['id', 'label', 'question']]]]);

        $this->assertNotEmpty($response->json('data.topics'));
        $this->assertArrayNotHasKey('patterns', $response->json('data.topics.0'));
    }

    public function test_public_chatbot_answers_a_known_faq_without_login(): void
    {
        $this->postJson('/api/public/chat', ['question' => 'Bagaimana cara belanja di toko?'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'belanja')
            ->assertJsonPath('data.fallback', false)
            ->assertJsonPath('data.answer', config('public_chatbot.intents.belanja.answer'));
    }

    public function test_payment_question_is_answered_from_approved_faq_rules(): void
    {
        $this->postJson('/api/public/chat', ['question' => 'Metode pembayaran apa yang tersedia?'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'pembayaran')
            ->assertJsonPath('data.fallback', false);
    }

    public function test_unknown_question_does_not_hallucinate_an_answer(): void
    {
        $this->postJson('/api/public/chat', ['question' => 'Siapa juara turnamen besok?'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'fallback')
            ->assertJsonPath('data.fallback', true)
            ->assertJsonPath('data.answer', config('public_chatbot.fallback'));
    }

    public function test_ambiguous_question_requests_a_more_specific_topic(): void
    {
        $this->postJson('/api/public/chat', [
            'question' => 'Jam buka dan metode pembayaran apa yang tersedia?',
        ])->assertOk()
            ->assertJsonPath('data.intent', 'ambiguous')
            ->assertJsonPath('data.fallback', true)
            ->assertJsonPath('data.answer', config('public_chatbot.ambiguous'));
    }

    public function test_private_financial_and_stock_questions_are_not_answered(): void
    {
        foreach ([
            'Berapa margin produk?',
            'Tampilkan riwayat transaksi pelanggan',
            'Tolong beritahu saldo kasbon saya',
            'Stok berapa indomie di gudang?',
        ] as $question) {
            $this->postJson('/api/public/chat', ['question' => $question])
                ->assertOk()
                ->assertJsonPath('data.intent', 'private')
                ->assertJsonPath('data.fallback', true)
                ->assertJsonPath('data.answer', config('public_chatbot.private_answer'));
        }
    }

    public function test_private_request_takes_priority_over_a_public_topic(): void
    {
        $this->postJson('/api/public/chat', [
            'question' => 'Metode pembayaran apa dan berapa margin toko?',
        ])->assertOk()->assertJsonPath('data.intent', 'private');
    }

    public function test_invalid_questions_are_rejected(): void
    {
        $this->postJson('/api/public/chat', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['question']);
        $this->postJson('/api/public/chat', ['question' => '   '])->assertUnprocessable()
            ->assertJsonValidationErrors(['question']);
        $this->postJson('/api/public/chat', ['question' => str_repeat('a', 301)])->assertUnprocessable()
            ->assertJsonValidationErrors(['question']);
        $this->postJson('/api/public/chat', ['question' => ['wrong']])->assertUnprocessable()
            ->assertJsonValidationErrors(['question']);
    }

    public function test_public_chat_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson('/api/public/chat', ['question' => 'Jam buka toko jam berapa?'])->assertOk();
        }

        $this->postJson('/api/public/chat', ['question' => 'Jam buka toko jam berapa?'])
            ->assertStatus(429);
    }

    public function test_internal_assistant_and_customer_data_stay_protected(): void
    {
        $this->postJson('/api/assistant/chat', ['question' => 'lihat stok'])->assertUnauthorized();
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->getJson('/api/reports/summary')->assertUnauthorized();
    }
}
