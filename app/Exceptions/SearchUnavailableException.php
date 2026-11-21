<?php

namespace App\Exceptions;

use RuntimeException;

/** Elasticsearch выключен, недоступен или ответил ошибкой. */
class SearchUnavailableException extends RuntimeException
{
}
