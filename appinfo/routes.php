<?php

declare(strict_types=1);

return [
    'routes' => [
        // public registration
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#verify', 'url' => '/verify/{token}', 'verb' => 'GET'],
        ['name' => 'api#scan', 'url' => '/api/scan', 'verb' => 'POST'],
        ['name' => 'api#scanFrame', 'url' => '/api/scan/frame', 'verb' => 'POST'],
        ['name' => 'api#register', 'url' => '/api/register', 'verb' => 'POST'],
        ['name' => 'api#verifyCode', 'url' => '/api/verify', 'verb' => 'POST'],
        ['name' => 'api#resend', 'url' => '/api/resend', 'verb' => 'POST'],
        ['name' => 'api#selfie', 'url' => '/api/selfie', 'verb' => 'POST'],
        ['name' => 'api#selfieGuide', 'url' => '/api/selfie/guide', 'verb' => 'POST'],
        ['name' => 'api#chip', 'url' => '/api/chip', 'verb' => 'POST'],
        ['name' => 'api#finish', 'url' => '/api/finish', 'verb' => 'POST'],
        ['name' => 'api#ping', 'url' => '/api/ping', 'verb' => 'GET'],
        ['name' => 'api#handoffCreate', 'url' => '/api/handoff', 'verb' => 'POST'],
        ['name' => 'api#handoffStatus', 'url' => '/api/handoff/{token}', 'verb' => 'GET'],
        ['name' => 'api#express', 'url' => '/api/express', 'verb' => 'POST'],
        // the profile an express account completes after signing in
        ['name' => 'profile#state', 'url' => '/api/profile', 'verb' => 'GET'],
        ['name' => 'profile#email', 'url' => '/api/profile/email', 'verb' => 'POST'],
        ['name' => 'profile#emailConfirm', 'url' => '/api/profile/email/confirm', 'verb' => 'POST'],
        ['name' => 'profile#phone', 'url' => '/api/profile/phone', 'verb' => 'POST'],
        ['name' => 'profile#nickname', 'url' => '/api/profile/nickname', 'verb' => 'POST'],
        // signing in with a linked Google account
        ['name' => 'google#login', 'url' => '/google/login', 'verb' => 'GET'],
        ['name' => 'google#callback', 'url' => '/google/callback', 'verb' => 'GET'],
        ['name' => 'google#start', 'url' => '/api/google/link', 'verb' => 'POST'],
        ['name' => 'google#unlink', 'url' => '/api/google/unlink', 'verb' => 'POST'],
        // signing in with a paired phone
        ['name' => 'device#page', 'url' => '/device/login', 'verb' => 'GET'],
        ['name' => 'device#pair', 'url' => '/api/device/pair', 'verb' => 'POST'],
        ['name' => 'device#pairStatus', 'url' => '/api/device/pair', 'verb' => 'GET'],
        ['name' => 'device#pairComplete', 'url' => '/api/device/pair/complete', 'verb' => 'POST'],
        ['name' => 'device#devices', 'url' => '/api/device', 'verb' => 'GET'],
        ['name' => 'device#forget', 'url' => '/api/device/forget', 'verb' => 'POST'],
        ['name' => 'device#describe', 'url' => '/api/device/auth', 'verb' => 'POST'],
        ['name' => 'device#approve', 'url' => '/api/device/auth/approve', 'verb' => 'POST'],
        ['name' => 'device#deny', 'url' => '/api/device/auth/deny', 'verb' => 'POST'],
        ['name' => 'device#loginStart', 'url' => '/api/device/login', 'verb' => 'POST'],
        ['name' => 'device#loginPoll', 'url' => '/api/device/login/poll', 'verb' => 'POST'],
        // administration
        ['name' => 'admin#config', 'url' => '/api/admin/config', 'verb' => 'GET'],
        ['name' => 'admin#setConfig', 'url' => '/api/admin/config', 'verb' => 'PUT'],
        ['name' => 'admin#reader', 'url' => '/api/admin/reader', 'verb' => 'GET'],
        ['name' => 'admin#installReader', 'url' => '/api/admin/reader', 'verb' => 'POST'],
        ['name' => 'admin#removeReader', 'url' => '/api/admin/reader', 'verb' => 'DELETE'],
        ['name' => 'admin#list', 'url' => '/api/admin/registrations', 'verb' => 'GET'],
        ['name' => 'admin#approve', 'url' => '/api/admin/registrations/{id}/approve', 'verb' => 'POST'],
        ['name' => 'admin#delete', 'url' => '/api/admin/registrations/{id}', 'verb' => 'DELETE'],
        ['name' => 'admin#testScan', 'url' => '/api/admin/test-scan', 'verb' => 'POST'],
    ],
];
