<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(
        string $action,
        ?string $description = null,
        array $properties = [],
        ?Request $request = null,
    ): void {
        $request ??= request();
        $user = Auth::user();

        $name = null;
        if ($user) {
            $name = trim(($user->fname ?? '').' '.($user->lname ?? ''));
            if ($name === '') {
                $name = $user->name ?? $user->email;
            }
        }

        try {
            ActivityLog::create([
                'user_id' => $user?->id,
                'user_name' => $name,
                'action' => $action,
                'description' => $description,
                'method' => $request?->method(),
                'route_name' => $request?->route()?->getName(),
                'url' => $request ? substr($request->fullUrl(), 0, 500) : null,
                'ip_address' => $request?->ip(),
                'properties' => $properties ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
