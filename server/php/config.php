<?php

return [
    'max_file_size'     => 500 * 1024 * 1024,
    'microservice_url'  => getenv('UPLOAD_MICROSERVICE_URL') ?: 'http://localhost:8080',
    'service_token'     => getenv('SERVICE_TOKEN') ?: 'change-me-service-token',
    'upload_dir'        => getenv('UPLOAD_DIR') ?: __DIR__ . '/../uploads',
];
