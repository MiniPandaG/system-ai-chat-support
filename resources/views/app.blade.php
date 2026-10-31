<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="oscuro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @if (($brandingCss ?? '') !== '')
        <style id="brand-theme" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{{ $brandingCss }}</style>
    @endif
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
