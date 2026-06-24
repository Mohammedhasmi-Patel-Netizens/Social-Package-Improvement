<?php

namespace BeePost\SocialPoster\Jobs;

use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Traits\PostManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PostManager;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(public readonly SocialPost $socialPost) {}

    public function handle(): void
    {
        $this->publishPost($this->socialPost);
    }
}
