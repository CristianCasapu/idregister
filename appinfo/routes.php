<?php

declare(strict_types=1);

return [
    'routes' => [
        // public registration
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#verify', 'url' => '/verify/{token}', 'verb' => 'GET'],
        ['name' => 'api#scan', 'url' => '/api/scan', 'verb' => 'POST'],
        ['name' => 'api#register', 'url' => '/api/register', 'verb' => 'POST'],
        ['name' => 'api#verifyCode', 'url' => '/api/verify', 'verb' => 'POST'],
        ['name' => 'api#resend', 'url' => '/api/resend', 'verb' => 'POST'],
        ['name' => 'api#selfie', 'url' => '/api/selfie', 'verb' => 'POST'],
        ['name' => 'api#handoffCreate', 'url' => '/api/handoff', 'verb' => 'POST'],
        ['name' => 'api#handoffStatus', 'url' => '/api/handoff/{token}', 'verb' => 'GET'],
        // administration
        ['name' => 'admin#config', 'url' => '/api/admin/config', 'verb' => 'GET'],
        ['name' => 'admin#setConfig', 'url' => '/api/admin/config', 'verb' => 'PUT'],
        ['name' => 'admin#list', 'url' => '/api/admin/registrations', 'verb' => 'GET'],
        ['name' => 'admin#approve', 'url' => '/api/admin/registrations/{id}/approve', 'verb' => 'POST'],
        ['name' => 'admin#delete', 'url' => '/api/admin/registrations/{id}', 'verb' => 'DELETE'],
        ['name' => 'admin#testScan', 'url' => '/api/admin/test-scan', 'verb' => 'POST'],
    ],
];
