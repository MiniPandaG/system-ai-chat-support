<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $theme->company }}</title>
</head>
<body style="margin:0;padding:0;background-color:{{ $theme->color('surface') }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $theme->color('surface') }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:{{ $theme->color('background') }};border:1px solid {{ $theme->color('border') }};">
                <tr>
                    <td align="center" height="64" style="height:64px;padding:12px 24px;background-color:{{ $theme->color('primary') }};color:{{ $theme->color('on_primary') }};font-family:'Work Sans',Arial,sans-serif;">
                        @if ($theme->logoUrl !== null)
                            <img src="{{ $theme->logoUrl }}" alt="{{ __('mail.logo_alt', ['company' => $theme->company]) }}" height="40" style="display:block;height:40px;max-width:200px;border:0;">
                        @else
                            <h1 style="margin:0;font-family:'Space Grotesk',Arial,sans-serif;font-size:20px;line-height:40px;font-weight:600;color:{{ $theme->color('on_primary') }};">{{ $theme->company }}</h1>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;font-family:'Work Sans',Arial,sans-serif;font-size:16px;line-height:24px;color:{{ $theme->color('text') }};">
                        <h2 style="margin:0 0 16px 0;font-family:'Space Grotesk',Arial,sans-serif;font-size:18px;line-height:26px;font-weight:600;color:{{ $theme->color('text') }};">
                            {{ $greeting === '' ? __('mail.greeting_plain') : __('mail.greeting', ['name' => $greeting]) }}
                        </h2>
                        @if ($kind->value === 'received')
                            <p style="margin:0 0 12px 0;">{{ __('mail.received_intro', ['company' => $theme->company, 'number' => $number]) }}</p>
                            <p style="margin:0;color:{{ $theme->color('muted') }};">{{ __('mail.received_detail') }}</p>
                        @else
                            <p style="margin:0 0 12px 0;">{{ __('mail.reply_intro', ['company' => $theme->company]) }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-left:3px solid {{ $theme->color('primary') }};padding:4px 0 4px 16px;white-space:pre-wrap;">{{ $body }}</td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0 0;color:{{ $theme->color('muted') }};">{{ __('mail.reply_hint') }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid {{ $theme->color('border') }};font-family:'Work Sans',Arial,sans-serif;font-size:13px;line-height:20px;color:{{ $theme->color('muted') }};">
                        {{ __('mail.footer', ['number' => $number, 'company' => $theme->company]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
