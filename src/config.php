<?php

return [
    'request' => [
        'files' => [
            'storage' => env('REQUEST_FILES_STORAGE_DIRECTORY'),
        ],
        'response' => [
            'enable' => env('REQUEST_RESPONSE_ENABLE', true),
            'timeout_sec' => env('REQUEST_RESPONSE_TIMEOUT_SEC', 5),
            'timeout_max_sec' => env('REQUEST_RESPONSE_TIMEOUT_MAX_SEC', 30),
            'timeout_param_name' => env('REQUEST_RESPONSE_TIMEOUT_PARAM_NAME', 'sync'),
            'message' => [
                'name' => env('REQUEST_RESPONSE_MESSAGE_NAME', 'Gateway.Response'),
            ],
        ],
        'cache' => [
            'prefix' => env('REQUEST_CACHE_PREFIX', 'Gateway.Response-'),
            'expire_sec' => env('REQUEST_CACHE_EXPIRE_SEC', 600),
        ],
        'message' => [
            'name' => env('REQUEST_MESSAGE_NAME', 'Gateway.Request'),
        ],
    ],
];
