<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        // 1. Tolak pertanyaan data internal/sensitif terlebih dahulu
        foreach ($rules['private_patterns'] ?? [] as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $this->result('private', $rules['private_answer'], $topics, true);
            }
        }

        // 2. Cek apakah pelanggan menanyakan ketersediaan atau harga jual produk spesifik
        $productAnswer = $this->lookupPublicProduct($normalized);
        if ($productAnswer !== null) {
            return $this->result('product_lookup', $productAnswer, $topics, false);
        }

        // 3. Cocokkan dengan intent FAQ statis
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

    private function lookupPublicProduct(string $normalized): ?string
    {
        $clean = preg_replace('/[^a-z0-9\s]+/u', ' ', $normalized) ?? '';
        $words = array_filter(preg_split('/\s+/u', trim($clean)) ?: [], fn ($w) => $w !== '');

        $stopWords = [
            'apakah', 'ada', 'tersedia', 'ready', 'jual', 'menjual', 'harga', 'berapa',
            'beli', 'cari', 'produk', 'barang', 'yang', 'di', 'toko', 'ini', 'itu', 'gak', 'nggak', 'tidak',
        ];
        $keywords = array_values(array_diff($words, $stopWords));

        if (count($keywords) === 0) {
            return null;
        }

        $searchTerm = implode(' ', $keywords);
        if (mb_strlen($searchTerm) < 3) {
            return null;
        }

        $products = Product::query()
            ->where('is_active', true)
            ->where(function ($query) use ($searchTerm, $keywords): void {
                $query->where('name', 'LIKE', "%{$searchTerm}%");
                foreach ($keywords as $word) {
                    if (mb_strlen($word) >= 4) {
                        $query->orWhere('name', 'LIKE', "%{$word}%");
                    }
                }
            })
            ->limit(3)
            ->get(['name', 'sell_price', 'stock']);

        if ($products->isEmpty()) {
            return null;
        }

        $lines = $products->map(function (Product $product): string {
            $price = 'Rp ' . number_format((float) $product->sell_price, 0, ',', '.');
            $status = (int) $product->stock > 0 ? 'Tersedia' : 'Sedang habis';

            return "• {$product->name} — {$price} ({$status})";
        })->implode("\n");

        return "Informasi katalog untuk pencarian tersebut:\n{$lines}\n\nAnda dapat langsung memesannya melalui halaman Toko Online.";
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