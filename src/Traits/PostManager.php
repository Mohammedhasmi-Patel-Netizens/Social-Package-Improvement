<?php

namespace BeePost\SocialPoster\Traits;

use BeePost\SocialPoster\Enums\PostStatus;
use BeePost\SocialPoster\Enums\PostType;
use BeePost\SocialPoster\Enums\StatusEnum;
use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Events\SocialPostCreated;
use BeePost\SocialPoster\Events\SocialPostFailed;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

trait PostManager
{

    /**
     * Save a post
     *
     * @return array
     */
    protected function savePost(array $request , $admin =  null  , $user = null ) :array{

        $accounts     = SocialAccount::with(['platform'])
                                       ->whereIn('id',Arr::get($request,"account_id", []))
                                       ->get();

        $scheduleTime = Arr::get($request,"schedule_date", null);

        $postTypes    = Arr::get($request,"post_type", []);

        $postsCreated = [];

        DB::transaction(function() use ($request ,$admin ,$user ,$accounts ,$scheduleTime ,$postTypes, &$postsCreated ) {

            foreach($accounts as $account){

                $post                     = new SocialPost();
                $post->account_id         = $account->id;
                $post->platform_id        = $account->platform_id;
                $post->subscription_id    = $user && isset($user->runningSubscription) ? $user->runningSubscription->id : null;
                $post->user_id            = $user ? $user->id : null;
                $post->admin_id           = $admin ? $admin->id : null;
                $post->content            = Arr::get($request,"text", []);
                $post->link               = Arr::get($request,"link", []);
                $post->is_scheduled       = $scheduleTime ? StatusEnum::true->status() : StatusEnum::false->status() ;
                $post->schedule_time      = $scheduleTime;
                $post->status             = strval($scheduleTime ? PostStatus::value('SCHEDULE',true): PostStatus::value('PENDING',true));
                $post->post_type          = Arr::get($postTypes ,@$account->platform->slug ,strval(PostType::value("FEED",true)));
                $post->save();

                $postsCreated[] = $post;

                // No file handling in package — passed to event for host app
            }
        });

        $totalPost = count($accounts);
        if($totalPost > 0 && ($user || $admin) && count($postsCreated) > 0){
            // Dispatch event for billing/credits
            event(new SocialPostCreated($postsCreated, $totalPost, $user, $admin));
        }

        return [
            'status'     => true,
            'message'    => social_poster_trans('Successfully created posts. Refer to the logs for more information')
        ];
    }

    /**
     * publish a post
     *
     * @param SocialPost $post
     * @return void
     */
    public function publishPost(SocialPost $post) :void{

        $account = $post->account;

        if(!$account) return;

        $class        = 'BeePost\\SocialPoster\\Services\\Account\\'.$account->platform->slug.'\\Account';
        if (!class_exists($class)) {
            throw new \RuntimeException("No service class found for platform slug: {$account->platform->slug}");
        }
        $service      =  new  $class();
        if (! $service instanceof \BeePost\SocialPoster\Contracts\PlatformAccountInterface) {
            throw new \RuntimeException("Platform service class {$class} must implement PlatformAccountInterface");
        }

        $response     = $service->send($post);

        $is_success   = Arr::get($response,'status' ,false);
        $post->status =  strval($is_success ? PostStatus::value('SUCCESS') : PostStatus::value('FAILED'));
        $post->platform_response  = $response;
        $post->save();

        if(!$is_success && ($post->user || $post->admin)){
            // Dispatch event for failed post refund
            event(new SocialPostFailed($post));
        }
    }
}
