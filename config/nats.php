<?php

return [
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    'jetstream' => [
        'enabled' => true,
        'stream' => env('NATS_QA_STREAM', 'QA_EVENTS'),
        'subjects' => ['qa.v1.>'],
    ],
    /**
     * Add streams here as new projects appear.
     * Each stream gets its own durable pull consumer.
     */
    'streams' => [
        [
            'name' => env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => env('NATS_AUTH_DURABLE', 'QA_AUTH_CONSUMER'),
            'filter_subject' => 'auth.v1.>', // match your stream subjects
        ],

        // Example additional stream later:
        // [
        //   'name' => env('NATS_PROJECT_STREAM', 'PROJECT_EVENTS'),
        //   'durable' => env('NATS_PROJECT_DURABLE', 'QA_PROJECT_CONSUMER'),
        //   'filter_subject' => 'project.v1.>',
        // ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
