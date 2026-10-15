<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useTailwind();

        // OCR 比较吃 CPU，限制每人每分钟重试次数，免得一直狂按把服务器拖慢
        RateLimiter::for('ocr-retry', fn (Request $request) => Limit::perMinute((int) config('claims.ocr.retry_per_minute', 6))
            ->by($request->user()?->id ?: $request->ip()));

        // 按 email + IP 限流，防暴力猜密码，又不会因为同一个办公室 IP 连累其他同事
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
