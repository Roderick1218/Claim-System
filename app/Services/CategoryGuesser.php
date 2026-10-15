<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\ClaimCategory;
use Illuminate\Support\Collection;

/*
 * 根据收据建议报销类别。不用 AI，规则简单、结果讲得出原因，分两步：
 *   1. 历史：同一商家在 submitted / approved 单上最常用的类别，用得越多越准。
 *   2. 关键词：各类别在 Settings → Claim Categories 设的关键词，出现在商家名算 3 分，
 *      出现在收据其他地方每个算 1 分。最高分至少 2 分并且要赢过第二名才建议，不然留给用户自己选。
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

    // 去掉大小写、公司后缀和标点，同一家店不同写法才对得上："ZUS COFFEE (M) SDN. BHD." → "zus coffee m"
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
            return null; // 名字太短容易误配
        }

        // 只取最近 5000 张单在 PHP 里比对，商家名要先 normalise 才能比，没法直接用 SQL 查
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
                // 整词匹配，避免 "bus" 命中 "business" 这种情况
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
            return null; // 分数太低或平手，交给用户选
        }

        $category = $categories->firstWhere('id', $ids[0]);

        return [
            'category_id' => $category->id,
            'name' => $category->name,
            'source' => 'keywords',
            'reason' => 'receipt mentions '.implode(', ', array_slice(array_unique($hits[$category->id]), 0, 4)),
        ];
    }

    // 关键词在后台是逗号或换行分隔的，单个字母的忽略
    /** @return array<int, string> */
    public static function keywordsOf(ClaimCategory $category): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($w) => mb_strtolower(trim($w)),
            preg_split('/[\r\n,]+/', (string) $category->keywords),
        ), fn ($w) => mb_strlen($w) >= 2)));
    }
}
