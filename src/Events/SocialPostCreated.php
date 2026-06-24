<?php

namespace BeePost\SocialPoster\Events;

use Illuminate\Queue\SerializesModels;
use BeePost\SocialPoster\Models\SocialPost;

class SocialPostCreated
{
    use SerializesModels;

    public function __construct(
        public readonly array $posts,       // all created SocialPost models
        public readonly int $accountsCount,
        public readonly mixed $user,
        public readonly mixed $admin = null
    ) {}
}
