<?php

/*
 * Заголовки безопасности (App\Http\Middleware\SecurityHeaders).
 */

$wsScheme = env('VITE_REVERB_SCHEME', 'https') === 'https' ? 'wss' : 'ws';
$wsPort = env('VITE_REVERB_PORT');

return [

    'csp' => [
        'enabled' => env('CSP_ENABLED', true),

        // только сообщать о нарушениях в консоль браузера, ничего не блокируя:
        // удобно включить на время проверки нового внешнего скрипта
        'report_only' => env('CSP_REPORT_ONLY', false),

        // куда страница может открывать соединения, кроме своего origin: WebSocket Reverb
        'connect_src' => array_filter([
            env('VITE_REVERB_HOST') ? "{$wsScheme}://".env('VITE_REVERB_HOST').($wsPort ? ":{$wsPort}" : '') : null,
        ]),

        // страницы со своими внешними и встроенными скриптами (документация API на
        // Stoplight Elements с unpkg, панели Telescope и Pulse) живут без CSP;
        // остальные заголовки на них действуют
        'except' => ['docs/*', 'telescope', 'telescope/*', 'pulse', 'pulse/*', 'livewire/*'],
    ],

    // HSTS отправляется только по HTTPS (по HTTP браузер его всё равно игнорирует)
    'hsts_max_age' => env('HSTS_MAX_AGE', 31536000),

];
