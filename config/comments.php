<?php

return [
    'per_page' => 25,

    // время жизни закэшированной страницы списка, секунды
    'cache_ttl' => (int) env('COMMENTS_CACHE_TTL', 600),
];
