<?php

if (! function_exists('social_poster_trans')) {
    function social_poster_trans(string $message): string
    {
        // If host app has a translate() helper, delegate to it; otherwise return as-is
        if (function_exists('translate')) {
            return translate($message);
        }
        return $message;
    }
}

if (! function_exists('social_poster_response_status')) {
    function social_poster_response_status(string $message, string $type = 'success'): array
    {
        // If host app has response_status(), delegate; otherwise return standard array
        if (function_exists('response_status')) {
            return response_status($message, $type);
        }
        return [
            'status'  => $type === 'success' || $type === 'ok',
            'message' => $message,
            'type'    => $type,
        ];
    }
}
