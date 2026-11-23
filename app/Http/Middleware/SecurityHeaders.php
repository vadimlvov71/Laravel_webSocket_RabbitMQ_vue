<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности для всех ответов сайта и API.
 *
 * CSP построен на nonce: скрипт выполняется, только если у тега есть nonce этого
 * запроса (его ставит @vite и вручную {{ Vite::cspNonce() }} у встроенных <script>).
 * Внедрённый в страницу <script> или onerror="..." без nonce браузер не выполнит,
 * даже если санитайзер когда-нибудь пропустит разметку.
 */
class SecurityHeaders
{
    /** reCAPTCHA: скрипт с google.com, ресурсы с gstatic.com, виджет во фрейме google.com. */
    private const RECAPTCHA = ['https://www.google.com', 'https://www.gstatic.com'];

    public function handle(Request $request, Closure $next): Response
    {
        $csp = config('security.csp.enabled') && ! $request->is(...config('security.csp.except'));

        if ($csp) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age='.config('security.hsts_max_age').'; includeSubDomains';
        }

        if ($csp) {
            $name = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers[$name] = $this->policy();
        }

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        $dev = $this->viteDevServer();
        $script = ["'self'", "'nonce-".Vite::cspNonce()."'", ...self::RECAPTCHA, ...$dev];
        $connect = ["'self'", ...config('security.csp.connect_src'), ...$dev, ...$this->websocketOrigins($dev)];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $script,
            // Vue и Tailwind задают стили через style-атрибуты, Vite в dev вставляет <style>;
            // внедрение стилей не выполняет код, поэтому здесь допускаем inline
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            // blob: превью выбранной картинки в форме, data: мелкие иконки
            'img-src' => ["'self'", 'data:', 'blob:', ...self::RECAPTCHA],
            'font-src' => ["'self'", 'data:', ...$dev],
            'connect-src' => $connect,
            'frame-src' => self::RECAPTCHA,
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * Адрес dev-сервера Vite (файл public/hot), пока идёт npm run dev: оттуда грузятся
     * модули, стили и HMR. В продакшене файла нет, и список пуст.
     *
     * @return list<string>
     */
    private function viteDevServer(): array
    {
        $hot = Vite::hotFile();

        return File::exists($hot) ? [rtrim(trim(File::get($hot)), '/')] : [];
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>
     */
    private function websocketOrigins(array $origins): array
    {
        return array_map(fn (string $o) => preg_replace('#^http#', 'ws', $o), $origins);
    }
}
