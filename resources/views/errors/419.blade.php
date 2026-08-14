<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session expired — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ \App\Support\Branding::stylesheetUrl() }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/auth/auth.css') }}">
</head>
<body class="session-expired-page">
    <header class="session-expired-top">
        <div class="session-expired-brand">
            <p class="session-expired-brand__name">{{ config('app.name') }}</p>
            <p class="session-expired-brand__meta">Powered by Pantas</p>
        </div>
        <a href="{{ route('home') }}" class="session-expired-home">← Home</a>
    </header>

    <main class="session-expired-main">
        <div class="session-expired-card">
            <div class="session-expired-seal" aria-hidden="true">
                <img src="{{ asset('images/pantasLogo.png') }}" alt="">
            </div>
            <h1>Your session expired</h1>
            <p>This page was left open too long, so your sign-in timed out. Nothing was saved from that last action. Sign in again to continue.</p>
            <a href="{{ route('login') }}" class="auth-btn auth-btn--primary">Sign in again</a>
            <a href="{{ route('home') }}" class="auth-btn auth-btn--outline">Go to home</a>
        </div>
    </main>
</body>
</html>
