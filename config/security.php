<?php

return [
    'login_rate_limit' => (int) env('LOGIN_RATE_LIMIT', 5),
    'mfa_verify_rate_limit' => (int) env('MFA_VERIFY_RATE_LIMIT', 5),
    'mfa_code_ttl_minutes' => (int) env('MFA_CODE_TTL_MINUTES', 10),
    'mfa_app_activation_ttl_minutes' => (int) env('MFA_APP_ACTIVATION_TTL_MINUTES', 30),
    'trusted_device_ttl_days' => (int) env('TRUSTED_DEVICE_TTL_DAYS', 30),
    'trusted_device_cookie' => (string) env('TRUSTED_DEVICE_COOKIE', 'tenant_trusted_device'),
    'password_min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),
    'jwt_ttl_minutes' => (int) env('JWT_TTL_MINUTES', 480),
    'jwt_refresh_ttl_minutes' => (int) env('JWT_REFRESH_TTL_MINUTES', 10080),
    'jwt_active_kid' => (string) env('JWT_ACTIVE_KID', 'default'),
    'jwt_keys_json' => (string) env('JWT_KEYS_JSON', ''),
    'jwt_secret' => (string) env('JWT_SECRET', 'local-dev-tenant-jwt-secret-change-me'),
];
