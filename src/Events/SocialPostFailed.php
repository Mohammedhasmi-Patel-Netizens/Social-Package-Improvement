<?php

namespace BeePost\SocialPoster\Events;

use Illuminate\Queue\SerializesModels;
use BeePost\SocialPoster\Models\SocialPost;

class SocialPostFailed
{
    use SerializesModels;

    public $post;

    public function __construct(SocialPost $post)
    {
        $this->post = $post;
    }
}
