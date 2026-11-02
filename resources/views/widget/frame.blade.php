<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $config['mode'] === 'oscuro' ? 'oscuro' : 'claro' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $config['company'] }}</title>
    @viteReactRefresh
    @vite(['resources/css/widget.css', 'resources/js/widget/main.tsx'])
    @if ($brandingCss !== '')
        <style id="brand-theme" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{{ $brandingCss }}</style>
    @endif
</head>
<body>
    <div id="widget-root" data-config="{{ json_encode($config, JSON_THROW_ON_ERROR) }}"></div>
</body>
</html>
