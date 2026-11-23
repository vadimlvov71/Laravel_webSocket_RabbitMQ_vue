<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Models\ApiClient;
use Illuminate\Console\Command;

/**
 * Выдаёт ключ REST API. Ключ показывается один раз: в БД хранится только его хэш.
 */
class ApiClientCreate extends Command
{
    protected $signature = 'api:client-create
                            {name : Who the key is for}
                            {--contact= : E-mail or other contact of the client}
                            {--ability=* : comments:read, comments:write (default: both)}';

    protected $description = 'Create an API client and issue an access token';

    public function handle(): int
    {
        $abilities = $this->option('ability') ?: ApiAbility::values();

        $unknown = array_diff($abilities, ApiAbility::values());
        if ($unknown !== []) {
            $this->error('Unknown ability: '.implode(', ', $unknown).'. Allowed: '.implode(', ', ApiAbility::values()));

            return self::FAILURE;
        }

        $client = ApiClient::create(['name' => $this->argument('name'), 'contact' => $this->option('contact')]);
        $token = $client->createToken('default', array_values($abilities));

        $this->info("Client #{$client->id} \"{$client->name}\", abilities: ".implode(', ', $abilities));
        $this->line('Token (shown once, store it now):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
