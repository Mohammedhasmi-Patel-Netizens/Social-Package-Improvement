<?php

namespace BeePost\SocialPoster\Contracts;

use BeePost\SocialPoster\Models\SocialPost;

interface PlatformAccountInterface
{
    /**
     * Send a post to the platform. Must return array with 'status' (bool) key.
     */
    public function send(SocialPost $post): array;

    /**
     * Return the OAuth redirect URL for connecting an account.
     */
    public static function authRedirect(mixed $platform): string;

    /**
     * Return required OAuth scopes.
     */
    public static function getScopes(string $type = 'auth'): array;
}
