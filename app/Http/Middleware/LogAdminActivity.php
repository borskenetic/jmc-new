<?php

namespace App\Http\Middleware;

use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminActivity
{
    /** Routes that should not create activity log noise. */
    private const SKIP_ROUTES = [
        'activity_logs.index',
        'sms.count',
        'sms.send',
        'logout',
        'login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldLog($request, $response)) {
            return $response;
        }

        $routeName = $request->route()?->getName() ?? 'unknown';
        $user = $request->user();
        $displayName = $user
            ? trim(($user->fname ?? '').' '.($user->lname ?? '')) ?: ($user->email ?? 'User')
            : 'Guest';

        $description = sprintf(
            '%s %s %s',
            $displayName,
            strtolower($request->method()),
            $routeName
        );

        ActivityLogger::log(
            action: $routeName,
            description: $description,
            properties: [
                'status' => $response->getStatusCode(),
            ],
            request: $request,
        );

        return $response;
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $user = $request->user();
        if (! $user || ! $user->can('isAdminOrStaff')) {
            return false;
        }

        $routeName = $request->route()?->getName();
        if ($routeName && in_array($routeName, self::SKIP_ROUTES, true)) {
            return false;
        }

        // Skip pure JSON counter/API polls and failed auth responses
        if ($response->getStatusCode() >= 500) {
            return false;
        }

        return true;
    }
}
