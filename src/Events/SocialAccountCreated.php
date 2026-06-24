<?php

namespace BeePost\SocialPoster\Events;

use Illuminate\Queue\SerializesModels;
use BeePost\SocialPoster\Models\SocialAccount;

class SocialAccountCreated
{
    use SerializesModels;

    public $account;
    public $guard;
    public $isOfficial;
    public $dbId;

    public function __construct(SocialAccount $account, string $guard, string $isOfficial, $dbId = null)
    {
        $this->account = $account;
        $this->guard = $guard;
        $this->isOfficial = $isOfficial;
        $this->dbId = $dbId;
    }
}
