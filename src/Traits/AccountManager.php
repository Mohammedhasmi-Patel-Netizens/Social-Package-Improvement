<?php

namespace BeePost\SocialPoster\Traits;

use BeePost\SocialPoster\Enums\StatusEnum;
use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Events\SocialAccountCreated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

trait AccountManager
{
    /**
     * Save social account
     *
     * @param string $guard
     * @param mixed $platform
     * @param array $accountInfo
     * @param string $account_type
     * @param string $is_official
     * @param int|string|null $dbId
     * @return array
     */
    protected function saveAccount(string $guard , $platform , array $accountInfo, string  $account_type , string $is_official , int | string  $dbId = null ) :array{

        $socialAccount = DB::transaction(function() use ($guard,$platform,$accountInfo,$account_type ,$is_official ,$dbId ) {

                        $user      = auth($guard)->user();
                        $accountId = Arr::get($accountInfo,"account_id",null);

                        $findBy = ['account_id' => $accountId ,'platform_id' => $platform->id ];

                        switch ($guard) {
                            case 'web':
                                if ($user) {
                                    $findBy ['user_id'] = $user->id;
                                }
                                break;
                            case 'admin':
                                if ($user) {
                                    $findBy ['admin_id'] = $user->id;
                                }
                                break;
                        }

                        $account = $dbId ? SocialAccount::find($dbId) : SocialAccount::firstOrNew($findBy);

                        $account->platform_id                 = $platform->id;
                        $account->name                        = Arr::get($accountInfo,'name');
                        $account->account_information         = $accountInfo;
                        $account->status                      = StatusEnum::true->status();
                        $account->is_connected                = StatusEnum::true->status();
                        $account->account_type                = $account_type;
                        $account->is_official                 = $is_official;

                        $account->token                                  = Arr::get($accountInfo,"token",null);
                        $account->access_token_expire_at                 = Arr::get($accountInfo,"access_token_expire_at",null);
                        $account->refresh_token                           = Arr::get($accountInfo,"refresh_token",null);
                        $account->refresh_token_expire_at                 = Arr::get($accountInfo,"refresh_token_expire_at",null);

                        switch ($guard) {
                            case 'web':
                                if ($user && method_exists($user, 'runningSubscription')) {
                                    $account->subscription_id = $user->runningSubscription?->id;
                                } elseif ($user && isset($user->runningSubscription)) {
                                    $account->subscription_id = $user->runningSubscription?->id;
                                }
                                if ($user) {
                                    $account->user_id         = $user->id;
                                }
                                break;
                            case 'admin':
                                if ($user) {
                                    $account->admin_id        = $user->id;
                                }
                                break;
                        }
                        
                        $account->save();

                        return $account;

                    });

        // Dispatch decoupled event for billing/credits
        event(new SocialAccountCreated($socialAccount, $guard, $is_official, $dbId));

        return [
            'status'  => true,
            'account' => $socialAccount
        ];
    }

    public function disConnectAccount(SocialAccount $account) :void{
        
        $account->is_connected = StatusEnum::false->status();
        $account->update();

    }
}
