<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SmsLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'activity');
        if (! in_array($tab, ['activity', 'sms'], true)) {
            $tab = 'activity';
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $today = now($tz)->toDateString();

        if ($tab === 'sms') {
            $query = SmsLog::with('user')
                ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
                ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
                ->when($request->status, fn ($q) => $q->where('status', $request->status))
                ->when($request->source, fn ($q) => $q->where('source', $request->source))
                ->when($request->search, function ($q) use ($request) {
                    $search = $request->search;
                    $q->where(function ($inner) use ($search) {
                        $inner->where('recipient', 'like', "%{$search}%")
                            ->orWhere('message', 'like', "%{$search}%")
                            ->orWhere('source', 'like', "%{$search}%");
                    });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $logs = (clone $query)->paginate(30)->withQueryString();

            $base = SmsLog::query()
                ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
                ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
                ->when($request->status, fn ($q) => $q->where('status', $request->status))
                ->when($request->source, fn ($q) => $q->where('source', $request->source))
                ->when($request->search, function ($q) use ($request) {
                    $search = $request->search;
                    $q->where(function ($inner) use ($search) {
                        $inner->where('recipient', 'like', "%{$search}%")
                            ->orWhere('message', 'like', "%{$search}%")
                            ->orWhere('source', 'like', "%{$search}%");
                    });
                });

            $summary = [
                'total' => (clone $base)->count(),
                'sent' => (clone $base)->where('status', 'sent')->count(),
                'failed' => (clone $base)->whereIn('status', ['failed', 'skipped'])->count(),
                'today' => (clone $base)->whereDate('created_at', $today)->count(),
            ];

            return view('activity_logs.index', compact('logs', 'summary', 'tab'));
        }

        $query = ActivityLog::with('user')
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->action, fn ($q) => $q->where('action', 'like', '%'.$request->action.'%'))
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $logs = (clone $query)->paginate(30)->withQueryString();

        $base = ActivityLog::query()
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->action, fn ($q) => $q->where('action', 'like', '%'.$request->action.'%'))
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            });

        $summary = [
            'total' => (clone $base)->count(),
            'today' => (clone $base)->whereDate('created_at', $today)->count(),
            'users' => (clone $base)->whereNotNull('user_id')->select('user_id')->distinct()->count('user_id'),
            'actions' => (clone $base)->select('action')->distinct()->count('action'),
        ];

        return view('activity_logs.index', compact('logs', 'summary', 'tab'));
    }
}
