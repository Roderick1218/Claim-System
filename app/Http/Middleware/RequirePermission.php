<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 路由级权限检查，用法：->middleware('permission:settings.users')，传多个时有其中一个就放行
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless($request->user()?->hasAnyPermission($permissions), 403);

        return $next($request);
    }
}
