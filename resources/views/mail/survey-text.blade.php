{{ $greeting === '' ? __('mail.greeting_plain') : __('mail.greeting', ['name' => $greeting]) }}

{{ __('mail.survey_intro', ['company' => $theme->company, 'number' => $number]) }}

{{ __('mail.survey_question') }}
@foreach ($links as $score => $link)

{{ $score }} ({{ __('mail.survey_scale.'.$score) }}): {{ $link }}
@endforeach

{{ __('mail.survey_scale_hint') }}

{{ __('mail.survey_reopen') }}

--
{{ __('mail.footer', ['number' => $number, 'company' => $theme->company]) }}
