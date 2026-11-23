<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Дополняет документацию ответами, которые Scramble не выводит из кода:
 * 403 — у маршрута есть проверка прав ключа (ability/abilities),
 * 429 — у маршрута есть лимит (throttle).
 * Ответы выводятся из middleware маршрута, поэтому новый эндпоинт
 * получит их сам, если подключён к тем же middleware.
 */
class ApiErrorResponses implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = $routeInfo->route->gatherMiddleware();
        $has = fn (string ...$prefixes) => collect($middleware)
            ->contains(fn ($m) => is_string($m) && Str::startsWith($m, $prefixes));

        if ($has('ability:', 'abilities:')) {
            $abilities = collect($middleware)
                ->filter(fn ($m) => is_string($m) && Str::startsWith($m, ['ability:', 'abilities:']))
                ->map(fn ($m) => Str::after($m, ':'))
                ->implode(', ');

            $operation->addResponse(
                Response::make(403)
                    ->setDescription("The key lacks the required ability ({$abilities}).")
                    ->setContent('application/json', Schema::fromType($this->message()))
            );
        }

        if ($has('throttle:')) {
            $operation->addResponse(
                Response::make(429)
                    ->setDescription('Rate limit exceeded. Retry after the number of seconds in the `Retry-After` header.')
                    ->setContent('application/json', Schema::fromType($this->message()))
            );
        }
    }

    private function message(): ObjectType
    {
        return (new ObjectType())
            ->addProperty('message', new StringType())
            ->setRequired(['message']);
    }
}
