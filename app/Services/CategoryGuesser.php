<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\ClaimCategory;
use Illuminate\Support\Collection;

/**
 * Suggests a claim category for a receipt — no AI, two explainable steps:
 *  1. History: the category most often used for the same merchant on submitted / approved claims (gets better with use).
 *  2. Keywords: each active category's keywords (Settings → Claim Categories) found in the merchant name
 *     (worth 3) or anywhere in the receipt text (worth 1 each). The best category must score at least 2
 *     and beat the runner-up; otherwise nothing is suggested.
 */
class CategoryGuesser
{
    /** @return array{category_id: int, name: string, source: string, reason: string}|null */
    public function guess(?string $merchant, string $receiptText): ?array
    {
        $categories = ClaimCategory::query()->where('is_active', true)->get();
        if ($categories->isEmpty()) {
            return null;
        }

        return $this->fromHistory($merchant, $categories) ?? $this->fromKeywords($merchant, $receiptText, $categories);
    }

    /** Lower-case, no company suffixes / punctuation: "ZUS COFFEE (M) SDN. BHD." → "zus coffee m". */
    public static function normaliseMerchant(?string $merchant): string
    {
        $m = mb_strtolower((string) $merchant);
        $m = preg_replace('/\b(sdn|bhd|berhad|plt|enterprise|trading|\(m\))\b|\(\s*\d[^)]*\)/u', ' ', $m);
        $m = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $m);

        return trim(preg_replace('/\s+/', ' ', $m));
    }

    private function fromHistory(?string $merchant, Collection $categories): ?array
    {
        $key = self::normaliseMerchant($merchant);
        if (mb_strlen($key) < 3) {
            return null;
        }

        $counts = Claim::query()
            ->whereIn('status', [Claim::STATUS_SUBMITTED, Claim::STATUS_APPROVED])
            ->whereNotNull('merchant_name')
            ->whereIn('category_id', $categories->pluck('id'))
            ->latest('id')
            ->limit(5000)
            ->get(['merchant_name', 'category_id'])
            ->filter(fn ($c) => self::normaliseMerchant($c->merchant_name) === $key)
            ->countBy('category_id')
            ->sortDesc();

        if ($counts->isEmpty()) {
            return null;
        }

        $category = $categories->firstWhere('id', $counts->keys()->first());
        $times = $counts->first();

        return [
            'category_id' => $category->id,
            'name' => $category->name,
            'source' => 'history',
            'reason' => "used for \"{$merchant}\" before ({$times} submitted claim".($times > 1 ? 's' : '').')',
        ];
    }

    private function fromKeywords(?string $merchant, string $text, Collection $categories): ?array
    {
        $merchantText = ' '.mb_strtolower((string) $merchant).' ';
        $body = ' '.mb_strtolower($text).' ';
        $scores = [];
        $hits = [];

        foreach ($categories as $category) {
            foreach (self::keywordsOf($category) as $word) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u';
                if (preg_match($pattern, $merchantText)) {
                    $scores[$category->id] = ($scores[$category->id] ?? 0) + 3;
                    $hits[$category->id][] = $word;
                } elseif (preg_match($pattern, $body)) {
                    $scores[$category->id] = ($scores[$category->id] ?? 0) + 1;
                    $hits[$category->id][] = $word;
                }
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $ids = array_keys($scores);
        $best = $scores[$ids[0]];
        $second = $scores[$ids[1] ?? -1] ?? 0;
        if ($best < 2 || $best === $second) {
            return null; // too weak or a tie — let the user choose
        }

        $category = $categories->firstWhere('id', $ids[0]);

        return [
            'category_id' => $category->id,
            'name' => $category->name,
            'source' => 'keywords',
            'reason' => 'receipt mentions '.implode(', ', array_slice(array_unique($hits[$category->id]), 0, 4)),
        ];
    }

    /** @return array<int, string> */
    public static function keywordsOf(ClaimCategory $category): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($w) => mb_strtolower(trim($w)),
            preg_split('/[\r\n,]+/', (string) $category->keywords),
        ), fn ($w) => mb_strlen($w) >= 2)));
    }
}
