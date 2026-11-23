<?php

namespace App\Providers;

use App\Models\ApiClient;
use App\OpenApi\ApiErrorResponses;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureApiRateLimits();
        $this->configureApiDocs();
    }

    /**
     * Лимиты на ключ, а не на IP: у одного клиента может быть много серверов,
     * а за одним IP (NAT) много клиентов. Без ключа (401) считаем по IP.
     */
    private function configureApiRateLimits(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by('api:'.self::rateLimitKey($request)));

        RateLimiter::for('api-write', fn (Request $request) => Limit::perMinute(20)
            ->by('api-write:'.self::rateLimitKey($request)));
    }

    private static function rateLimitKey(Request $request): string
    {
        $client = $request->user();

        return $client instanceof ApiClient ? 'key:'.$client->currentAccessToken()->getKey() : 'ip:'.$request->ip();
    }

    /**
     * Документация OpenAPI (Scramble): /docs/api и /docs/api.json.
     * Публичная: в ней только то, что клиент и так видит в ответах API.
     */
    private function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->as('accessKey')
                        ->setDescription('API key issued by `php artisan api:client-create`.')
                );
            })
            ->withOperationTransformers(ApiErrorResponses::class);
    }
}
