@component('mail::message')
# Новое сообщение

**{{ $senderName }}** написал(а) вам по заказу «{{ $taskTitle }}»:

> {{ $messageText }}

@component('mail::button', ['url' => $chatUrl])
Открыть чат
@endcomponent

С уважением,<br>
{{ config('app.name') }}
@endcomponent
