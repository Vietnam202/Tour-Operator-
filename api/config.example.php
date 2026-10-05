<?php
return [
    'app' => [
        'name' => 'VTA Tour Operator OS',
        'env' => 'staging',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'base_url' => 'https://v2quote.vietnamtraveladvisor.com.vn',
        'session_name' => 'vta_staging_session',
        'session_lifetime' => 28800,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'vta_staging',
        'username' => 'vta_staging',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'storage' => [
        // Use a separate private staging folder.
        'driver' => 'local', // google_drive | local
        // Option A: OAuth refresh token for a VTA-owned Google Drive account.
        'google_oauth_client_id' => '',
        'google_oauth_client_secret' => '',
        'google_oauth_refresh_token' => '',
        // Option B: service account (best with Shared Drive / domain setup).
        'google_service_account_json' => '/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/google-service-account.json',
        'google_drive_folder_id' => 'CHANGE_ME',
        // fallback only for staging/testing. Keep outside public_html.
        'local_path' => '/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/documents',
    ],
    'security' => [
        'allowed_origin' => 'https://v2quote.vietnamtraveladvisor.com.vn',
        'max_upload_bytes' => 15728640,
        'allowed_extensions' => ['pdf','docx','xlsx','csv','txt'],
    ],
    // Read-only media accounts are tenant scoped; never use a global Drive account.
    // Configure the OAuth app's refresh token with drive.readonly scope, or a service account.
    'media_drive' => [
        'accounts' => [
            // 1 => [
            //     'allowed_folder_ids' => ['SPECIFIC_SHARED_IMAGE_FOLDER_ID'],
            //     'oauth_client_id' => '', 'oauth_client_secret' => '', 'oauth_refresh_token' => '',
            //     'service_account_json' => '/home/.../vta_private/company-1-drive.json',
            // ],
        ],
    ],
    'ai' => [
        'enabled' => false,
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('VTA_AI_MODEL') ?: '',
        'daily_limit' => 30,
        'max_output_tokens' => 2000,
    ],
];
