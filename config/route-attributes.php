<?php

return [
    'enabled' => true,
    'directories' => [
        app_path('Http/Controllers/Api') => [
            'patterns' => ['*Controller.php'],
            'not_patterns' => [],
        ],
    ],
    'middleware' => [],
    'scope-bindings' => null,
];
