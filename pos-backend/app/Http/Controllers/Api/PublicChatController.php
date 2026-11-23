<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FAQ publik yang terisolasi dari chatbot internal (assistant/chat).
 * Tidak membaca model pelanggan, produk, transaksi, stok, atau laporan.
 */
class PublicChatController extends Controller
{
    public function faq(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'topics' => $this->topics(),
            ],
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:300'],
        ]);

        $question = trim(preg_replace('/\s+/u', ' ', $validated['question']) ?? '');
        if ($question === '') {
            throw ValidationException::withMessages([
                'question' => 'Pertanyaan tidak boleh kosong.',
            ]);
        }

        $normalized = Str::lower($question);
        $rules = config('public_chatbot', []);
        $topics = $this->topics();

        foreach ($rules['private_patterns'] ?? [] as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $this->result('private', $rules['private_answer'], $topics, true);
            }
        }

        $matches = [];
        foreach ($rules['intents'] ?? [] as $key => $intent) {
            foreach ($intent['patterns'] ?? [] as $pattern) {
                if (preg_match($pattern, $normalized) === 1) {
                    $matches[] = $key;
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            $intent = $rules['intents'][$matches[0]];

            return $this->result($matches[0], $intent['answer'], $topics, false);
        }

        return $this->result(
            count($matches) > 1 ? 'ambiguous' : 'fallback',
            count($matches) > 1 ? $rules['ambiguous'] : $rules['fallback'],
            $topics,
            true,
        );
    }

    private function topics(): array
    {
        $topics = [];
        foreach (config('public_chatbot.intents', []) as $key => $intent) {
            $topics[] = [
                'id' => $key,
                'label' => $intent['label'],
                'question' => $intent['question'],
            ];
        }

        return $topics;
    }

    private function result(string $intent, string $answer, array $topics, bool $fallback): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'intent' => $intent,
                'answer' => $answer,
                'fallback' => $fallback,
                'topics' => $topics,
            ],
        ]);
    }
}
