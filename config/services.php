<?php

return [
    'slack' => [
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'notification_channel' => env('SLACK_NOTIFICATION_CHANNEL', '#bifrost'),
    ],

    'github_app' => [
        'app_id' => env('GITHUB_APP_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        'private_key_base64' => env('GITHUB_APP_PRIVATE_KEY_BASE64'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],

    // Qdrant + embeddings now handled by the-shit/vector via config/vector.php

    'bifrost' => [
        'api_url' => env('BIFROST_API_URL', 'http://localhost:8000'),
        'app_path' => env('BIFROST_APP_PATH', '/opt/bifrost/app'),
        'deploy_sha_path' => env('BIFROST_DEPLOY_SHA_PATH', '/opt/bifrost/.deploy_sha'),
    ],

    'bifrost_agent' => [
        'model' => env('BIFROST_AGENT_MODEL', 'x-ai/grok-4-fast'),
    ],

    'mattermost' => [
        'url' => env('MATTERMOST_URL', 'http://localhost:8065'),
        'bot_token' => env('MATTERMOST_BOT_TOKEN'),
        'team_id' => env('MATTERMOST_TEAM_ID'),
        'channel_id' => env('MATTERMOST_CHANNEL_ID'),
        'bot_user_id' => env('MATTERMOST_BOT_USER_ID'),
    ],
];
