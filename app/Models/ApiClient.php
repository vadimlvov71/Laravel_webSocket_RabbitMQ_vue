<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Внешний клиент REST API. Ключи (Sanctum personal access tokens) принадлежат ему;
 * в запросе он становится $request->user().
 */
class ApiClient extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'contact'];
}
