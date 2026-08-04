@extends('layouts.app')

@section('title', 'Activity Log')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/activity_logs/logs.css') }}">
@endpush

@section('content')
@php
    $query = request()->query();
    $hasFilters = collect($query)->except(['page', 'tab'])->filter()->isNotEmpty();
    $tz = config('app.timezone', 'Asia/Manila');
    $today = now($tz)->toDateString();
    $weekStart = now($tz)->startOfWeek()->toDateString();
    $monthStart = now($tz)->startOfMonth()->toDateString();

    $filterUrl = function (array $merge = [], array $except = []) use ($query, $tab) {
        $params = collect($query)
            ->except(array_merge(['page'], $except))
            ->merge(['tab' => $tab])
            ->merge($merge)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();

        return route('activity_logs.index', $params);
    };

    $isDatePreset = fn (string $preset) => match ($preset) {
        'today' => request('from') === $today && request('to') === $today,
        'week' => request('from') === $weekStart && request('to') === $today,
        'month' => request('from') === $monthStart && request('to') === $today,
        'all' => ! request('from') && ! request('to'),
        default => false,
    };
@endphp

<div class="data-page activity-logs-page">
    <header class="act-header">
        <div class="act-header__text">
            <h1 class="act-title">Activity Log</h1>
            <p class="act-subtitle">Track admin actions and every SMS sent from this system.</p>
        </div>
    </header>

    <div class="act-tabs">
        <a href="{{ route('activity_logs.index', array_merge(request()->except(['page', 'status', 'source', 'action']), ['tab' => 'activity'])) }}"
           class="act-tab {{ $tab === 'activity' ? 'is-active' : '' }}">Admin activity</a>
        <a href="{{ route('activity_logs.index', array_merge(request()->except(['page', 'action']), ['tab' => 'sms'])) }}"
           class="act-tab {{ $tab === 'sms' ? 'is-active' : '' }}">SMS logs</a>
    </div>

    <div class="act-stats">
        @if($tab === 'sms')
            <div class="act-stat-card">
                <span class="act-stat-card__label">Matching</span>
                <strong class="act-stat-card__value">{{ number_format($summary['total']) }}</strong>
            </div>
            <div class="act-stat-card act-stat-card--ok">
                <span class="act-stat-card__label">Sent</span>
                <strong class="act-stat-card__value">{{ number_format($summary['sent']) }}</strong>
            </div>
            <div class="act-stat-card act-stat-card--fail">
                <span class="act-stat-card__label">Failed / skipped</span>
                <strong class="act-stat-card__value">{{ number_format($summary['failed']) }}</strong>
            </div>
            <div class="act-stat-card act-stat-card--today">
                <span class="act-stat-card__label">Today</span>
                <strong class="act-stat-card__value">{{ number_format($summary['today']) }}</strong>
            </div>
        @else
            <div class="act-stat-card">
                <span class="act-stat-card__label">Matching</span>
                <strong class="act-stat-card__value">{{ number_format($summary['total']) }}</strong>
            </div>
            <div class="act-stat-card act-stat-card--today">
                <span class="act-stat-card__label">Today</span>
                <strong class="act-stat-card__value">{{ number_format($summary['today']) }}</strong>
            </div>
            <div class="act-stat-card act-stat-card--ok">
                <span class="act-stat-card__label">Active users</span>
                <strong class="act-stat-card__value">{{ number_format($summary['users']) }}</strong>
            </div>
            <div class="act-stat-card">
                <span class="act-stat-card__label">Distinct actions</span>
                <strong class="act-stat-card__value">{{ number_format($summary['actions']) }}</strong>
            </div>
        @endif
    </div>

    <section class="act-controls">
        <form method="GET" class="act-controls__form">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="act-search-row">
                <label class="act-search" for="actSearch">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="actSearch" name="search" value="{{ request('search') }}"
                           placeholder="{{ $tab === 'sms' ? 'Recipient, message, or source…' : 'User, action, or description…' }}"
                           autocomplete="off">
                </label>
                <button type="submit" class="act-btn act-btn--primary">Search</button>
            </div>

            <div class="act-control-row">
                <div class="act-control-group">
                    <span class="act-control-group__label">Period</span>
                    <div class="act-pills">
                        <a href="{{ $filterUrl(['from' => $today, 'to' => $today]) }}" class="act-pill {{ $isDatePreset('today') ? 'is-active' : '' }}">Today</a>
                        <a href="{{ $filterUrl(['from' => $weekStart, 'to' => $today]) }}" class="act-pill {{ $isDatePreset('week') ? 'is-active' : '' }}">This week</a>
                        <a href="{{ $filterUrl(['from' => $monthStart, 'to' => $today]) }}" class="act-pill {{ $isDatePreset('month') ? 'is-active' : '' }}">This month</a>
                        <a href="{{ $filterUrl([], ['from', 'to']) }}" class="act-pill {{ $isDatePreset('all') ? 'is-active' : '' }}">All time</a>
                    </div>
                </div>

                @if($tab === 'sms')
                    <div class="act-control-group">
                        <span class="act-control-group__label">Status</span>
                        <div class="act-pills">
                            <a href="{{ $filterUrl([], ['status']) }}" class="act-pill {{ !request('status') ? 'is-active' : '' }}">All</a>
                            <a href="{{ $filterUrl(['status' => 'sent']) }}" class="act-pill {{ request('status') === 'sent' ? 'is-active' : '' }}">Sent</a>
                            <a href="{{ $filterUrl(['status' => 'failed']) }}" class="act-pill {{ request('status') === 'failed' ? 'is-active' : '' }}">Failed</a>
                            <a href="{{ $filterUrl(['status' => 'skipped']) }}" class="act-pill {{ request('status') === 'skipped' ? 'is-active' : '' }}">Skipped</a>
                        </div>
                    </div>
                @endif
            </div>

            <details class="act-more-filters" {{ request()->hasAny(['from', 'to', 'action', 'source']) ? 'open' : '' }}>
                <summary>More filters</summary>
                <div class="act-more-filters__grid">
                    <div class="act-field">
                        <label for="actFrom">From</label>
                        <input type="date" id="actFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="act-field">
                        <label for="actTo">To</label>
                        <input type="date" id="actTo" name="to" value="{{ request('to') }}">
                    </div>
                    @if($tab === 'activity')
                        <div class="act-field">
                            <label for="actAction">Action</label>
                            <input type="text" id="actAction" name="action" value="{{ request('action') }}" placeholder="e.g. students.update">
                        </div>
                    @else
                        <div class="act-field">
                            <label for="actSource">Source</label>
                            <select id="actSource" name="source">
                                <option value="">All sources</option>
                                <option value="blast" @selected(request('source') === 'blast')>SMS blast</option>
                                <option value="scan" @selected(request('source') === 'scan')>Gate scan</option>
                                <option value="direct" @selected(request('source') === 'direct')>Direct</option>
                            </select>
                        </div>
                    @endif
                    <div class="act-field act-field--actions">
                        <button type="submit" class="act-btn act-btn--primary">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('activity_logs.index', ['tab' => $tab]) }}" class="act-btn act-btn--ghost">Clear</a>
                        @endif
                    </div>
                </div>
            </details>
        </form>
    </section>

    <div class="act-table-wrap">
        <table class="act-table">
            <thead>
                @if($tab === 'sms')
                    <tr>
                        <th>When</th>
                        <th>Recipient</th>
                        <th>Message</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                @else
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP</th>
                    </tr>
                @endif
            </thead>
            <tbody>
                @forelse($logs as $log)
                    @if($tab === 'sms')
                        <tr>
                            <td data-label="When">{{ $log->created_at?->timezone($tz)->format('M j, Y g:i A') }}</td>
                            <td data-label="Recipient"><code>{{ $log->recipient }}</code></td>
                            <td data-label="Message" class="act-message">{{ \Illuminate\Support\Str::limit($log->message, 100) }}</td>
                            <td data-label="Source"><span class="act-badge">{{ $log->source }}</span></td>
                            <td data-label="Status">
                                <span class="act-status act-status--{{ $log->status }}">{{ $log->status }}</span>
                                @if($log->error)
                                    <div class="act-error" title="{{ $log->error }}">{{ \Illuminate\Support\Str::limit($log->error, 60) }}</div>
                                @endif
                            </td>
                            <td data-label="By">
                                @if($log->user)
                                    {{ trim(($log->user->fname ?? '').' '.($log->user->lname ?? '')) ?: $log->user->email }}
                                @else
                                    <span class="text-muted">System</span>
                                @endif
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td data-label="When">{{ $log->created_at?->timezone($tz)->format('M j, Y g:i A') }}</td>
                            <td data-label="User">{{ $log->user_name ?: '—' }}</td>
                            <td data-label="Action"><code class="act-action">{{ $log->action }}</code></td>
                            <td data-label="Description">{{ $log->description }}</td>
                            <td data-label="IP">{{ $log->ip_address ?: '—' }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="act-empty">No log entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="act-pagination">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
