{{ $greeting === '' ? __('mail.greeting_plain') : __('mail.greeting', ['name' => $greeting]) }}

@if ($kind->value === 'received')
{{ __('mail.received_intro', ['company' => $theme->company, 'number' => $number]) }}

{{ __('mail.received_detail') }}
@else
{{ __('mail.reply_intro', ['company' => $theme->company]) }}

{{ $body }}

{{ __('mail.reply_hint') }}
@endif

--
{{ __('mail.footer', ['number' => $number, 'company' => $theme->company]) }}
