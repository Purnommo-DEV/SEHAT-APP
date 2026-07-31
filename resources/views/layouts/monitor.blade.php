<!DOCTYPE html>
<html lang="id" data-theme="emerald">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'TV Monitor') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen bg-slate-950 text-white antialiased"
    x-data="realtimeStatus"
>
    <span class="sr-only" role="status" aria-live="polite" x-text="label"></span>
    @yield('content')
</body>
</html>
