@extends('layouts.app')

@section('title', 'Activity Log')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/layout/data-pages.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/visitor_logs/logs.css') }}">
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

<div class="data-page visitor-logs-page activity-logs-page">
    <header class="vl-header">
        <div class="vl-header__text">
            <h1 class="vl-title">Activity Log</h1>
            <p class="vl-subtitle">Admin actions and SMS delivery history for your library system.</p>
        </div>
        <div class="vl-header__actions">
            @if($tab === 'sms')
                <a href="{{ route('sms.page') }}" class="vl-btn vl-btn--primary">SMS Blast</a>
            @endif
            <a href="{{ route('home') }}" class="vl-btn vl-btn--ghost">Dashboard</a>
        </div>
    </header>

    <div class="act-tabs" role="tablist" aria-label="Log type">
        <a href="{{ route('activity_logs.index', array_merge(request()->except(['page', 'status', 'source', 'action']), ['tab' => 'activity'])) }}"
           class="act-tab {{ $tab === 'activity' ? 'is-active' : '' }}"
           role="tab"
           aria-selected="{{ $tab === 'activity' ? 'true' : 'false' }}">Admin activity</a>
        <a href="{{ route('activity_logs.index', array_merge(request()->except(['page', 'action']), ['tab' => 'sms'])) }}"
           class="act-tab {{ $tab === 'sms' ? 'is-active' : '' }}"
           role="tab"
           aria-selected="{{ $tab === 'sms' ? 'true' : 'false' }}">SMS logs</a>
    </div>

    <div class="vl-stats">
        @if($tab === 'sms')
            <div class="vl-stat-card">
                <span class="vl-stat-card__label">Matching</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['total']) }}</strong>
            </div>
            <div class="vl-stat-card vl-stat-card--in">
                <span class="vl-stat-card__label">Sent</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['sent']) }}</strong>
            </div>
            <div class="vl-stat-card vl-stat-card--out">
                <span class="vl-stat-card__label">Failed / skipped</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['failed']) }}</strong>
            </div>
            <div class="vl-stat-card vl-stat-card--today">
                <span class="vl-stat-card__label">Today</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['today']) }}</strong>
            </div>
        @else
            <div class="vl-stat-card">
                <span class="vl-stat-card__label">Matching</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['total']) }}</strong>
            </div>
            <div class="vl-stat-card vl-stat-card--today">
                <span class="vl-stat-card__label">Today</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['today']) }}</strong>
            </div>
            <div class="vl-stat-card vl-stat-card--in">
                <span class="vl-stat-card__label">Active users</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['users']) }}</strong>
            </div>
            <div class="vl-stat-card">
                <span class="vl-stat-card__label">Distinct actions</span>
                <strong class="vl-stat-card__value">{{ number_format($summary['actions']) }}</strong>
            </div>
        @endif
    </div>

    <section class="vl-controls" aria-label="Filter activity logs">
        <form method="GET" class="vl-controls__form">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <div class="vl-search-row">
                <label class="vl-search" for="actSearch">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5"/>
                    </svg>
                    <input type="search" id="actSearch" name="search" value="{{ request('search') }}"
                           placeholder="{{ $tab === 'sms' ? 'Recipient, message, or source…' : 'User, action, or description…' }}"
                           autocomplete="off">
                </label>
                <button type="submit" class="vl-btn vl-btn--primary">Search</button>
            </div>

            <div class="vl-control-row">
                <div class="vl-control-group">
                    <span class="vl-control-group__label">Period</span>
                    <div class="vl-pills">
                        <a href="{{ $filterUrl(['from' => $today, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('today') ? 'is-active' : '' }}">Today</a>
                        <a href="{{ $filterUrl(['from' => $weekStart, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('week') ? 'is-active' : '' }}">This week</a>
                        <a href="{{ $filterUrl(['from' => $monthStart, 'to' => $today]) }}" class="vl-pill {{ $isDatePreset('month') ? 'is-active' : '' }}">This month</a>
                        <a href="{{ $filterUrl([], ['from', 'to']) }}" class="vl-pill {{ $isDatePreset('all') ? 'is-active' : '' }}">All time</a>
                    </div>
                </div>

                @if($tab === 'sms')
                    <div class="vl-control-group">
                        <span class="vl-control-group__label">Status</span>
                        <div class="vl-pills">
                            <a href="{{ $filterUrl([], ['status']) }}" class="vl-pill {{ ! request('status') ? 'is-active' : '' }}">All</a>
                            <a href="{{ $filterUrl(['status' => 'sent']) }}" class="vl-pill vl-pill--in {{ request('status') === 'sent' ? 'is-active' : '' }}">Sent</a>
                            <a href="{{ $filterUrl(['status' => 'failed']) }}" class="vl-pill vl-pill--out {{ request('status') === 'failed' ? 'is-active' : '' }}">Failed</a>
                            <a href="{{ $filterUrl(['status' => 'skipped']) }}" class="vl-pill {{ request('status') === 'skipped' ? 'is-active' : '' }}">Skipped</a>
                        </div>
                    </div>
                @endif
            </div>

            <details class="vl-more-filters" {{ request()->hasAny(['from', 'to', 'action', 'source']) ? 'open' : '' }}>
                <summary>More filters</summary>
                <div class="vl-more-filters__grid">
                    <div class="vl-field">
                        <label for="actFrom">From</label>
                        <input type="date" id="actFrom" name="from" value="{{ request('from') }}">
                    </div>
                    <div class="vl-field">
                        <label for="actTo">To</label>
                        <input type="date" id="actTo" name="to" value="{{ request('to') }}">
                    </div>
                    @if($tab === 'activity')
                        <div class="vl-field">
                            <label for="actAction">Action</label>
                            <input type="text" id="actAction" name="action" value="{{ request('action') }}" placeholder="e.g. students.update">
                        </div>
                    @else
                        <div class="vl-field">
                            <label for="actSource">Source</label>
                            <select id="actSource" name="source" class="act-select">
                                <option value="">All sources</option>
                                <option value="blast" @selected(request('source') === 'blast')>SMS blast</option>
                                <option value="scan" @selected(request('source') === 'scan')>Gate scan</option>
                                <option value="direct" @selected(request('source') === 'direct')>Direct</option>
                            </select>
                        </div>
                    @endif
                    <div class="vl-field vl-field--actions">
                        <button type="submit" class="vl-btn vl-btn--primary">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('activity_logs.index', ['tab' => $tab]) }}" class="vl-btn vl-btn--ghost">Clear</a>
                        @endif
                    </div>
                </div>
            </details>
        </form>
    </section>

    <section class="vl-table-card">
        <div class="vl-table-card__head">
            <h2 class="vl-table-card__title">{{ $tab === 'sms' ? 'SMS delivery log' : 'Admin activity' }}</h2>
            @if($logs->total() > 0)
                <p class="vl-table-card__meta">
                    Showing {{ number_format($logs->firstItem()) }}–{{ number_format($logs->lastItem()) }}
                    of {{ number_format($logs->total()) }}
                </p>
            @endif
        </div>

        <div class="vl-table-wrap">
            <table class="vl-table">
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
                            @php
                                $when = $log->created_at?->timezone($tz);
                                $status = strtolower((string) $log->status);
                            @endphp
                            <tr>
                                <td data-label="When">
                                    @if($when)
                                        <div class="vl-time">
                                            <span class="vl-time__date">{{ $when->format('M j, Y') }}</span>
                                            <span class="vl-time__clock">{{ $when->format('g:i A') }}</span>
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="Recipient"><code class="vl-code">{{ $log->recipient }}</code></td>
                                <td data-label="Message">
                                    <div class="act-msg" title="{{ $log->message }}">{{ \Illuminate\Support\Str::limit($log->message, 90) }}</div>
                                </td>
                                <td data-label="Source">
                                    <span class="act-source">{{ $log->source }}</span>
                                </td>
                                <td data-label="Status">
                                    <span class="vl-status act-status--{{ $status }}">{{ strtoupper($status) }}</span>
                                    @if($log->error)
                                        <div class="act-error" title="{{ $log->error }}">{{ \Illuminate\Support\Str::limit($log->error, 48) }}</div>
                                    @endif
                                </td>
                                <td data-label="By">
                                    @if($log->user)
                                        <span class="vl-visitor-name">{{ trim(($log->user->fname ?? '').' '.($log->user->lname ?? '')) ?: $log->user->email }}</span>
                                    @else
                                        <span class="vl-visitor-meta">System</span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            @php $when = $log->created_at?->timezone($tz); @endphp
                            <tr>
                                <td data-label="When">
                                    @if($when)
                                        <div class="vl-time">
                                            <span class="vl-time__date">{{ $when->format('M j, Y') }}</span>
                                            <span class="vl-time__clock">{{ $when->format('g:i A') }}</span>
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="User">
                                    <span class="vl-visitor-name">{{ $log->user_name ?: '—' }}</span>
                                </td>
                                <td data-label="Action"><code class="vl-code act-action-code">{{ $log->action }}</code></td>
                                <td data-label="Description">
                                    <span class="act-desc">{{ $log->description ?: '—' }}</span>
                                </td>
                                <td data-label="IP">
                                    <span class="vl-visitor-meta">{{ $log->ip_address ?: '—' }}</span>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'sms' ? 6 : 5 }}" class="vl-empty">
                                No {{ $tab === 'sms' ? 'SMS' : 'activity' }} log entries match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="vl-table-card__foot">
                {{ $logs->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </section>
</div>
@endsection
