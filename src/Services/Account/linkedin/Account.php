<?php

namespace BeePost\SocialPoster\Services\Account\linkedin;

use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Traits\AccountManager;

use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Contracts\PlatformAccountInterface;
use Illuminate\Support\Facades\Http;


use GuzzleHttp\Client;
use Illuminate\Support\Arr;

class Account implements PlatformAccountInterface
{
    
    use AccountManager;


    const BASE_URL = 'https://linkedin.com';
    const API_URL  = 'https://api.linkedin.com';


    public static function getApiUrl(string $endpoint, array $params = [],mixed $configuration , bool $isBaseUrl = false): mixed{

        $apiUrl = $isBaseUrl ? self::BASE_URL: self::API_URL;

        if (str_starts_with($endpoint, '/'))       $endpoint = substr($endpoint, 1);

        $v = @$configuration?->app_version ?? null;

        $versionedUrlWithEndpoint = $apiUrl . '/' . (!$isBaseUrl && $v ? ($v . '/') : '') . $endpoint;

        if (count($params)) $versionedUrlWithEndpoint .= '?' . http_build_query($params);

        return $versionedUrlWithEndpoint;
    }




        /**
        * Summary of getScopes
        * @return array
        */
        public static function getScopes(string $type = 'auth'): array{

        
            switch ($type) {

               case 'auth':
                   return ['openid profile email w_member_social'];
               
               default:

               return ['openid profile email w_member_social'];
            }

      }







    /**
     * Summary of authRedirect
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return string
     */
    public static function authRedirect(mixed $mediaPlatform): string
    {


        $scopes = collect(self::getScopes())->join(' ');

        $configuration =  $mediaPlatform->configuration;

        return  self::getApiUrl('oauth/v2/authorization', [
            'response_type' => 'code',
            'client_id' => $configuration->client_id,
            'redirect_uri' =>  url('/account/linkedin/callback?medium='.$mediaPlatform->slug),

            'scope' => $scopes,
        ], $configuration,true);

     
    }



       /**
        * Summary of getAccessToken
        * @param string $code
        * @param \App\Models\MediaPlatform $mediaPlatform
        * @return \Illuminate\Http\Client\Response
        */
        public static function getAccessToken(string $code , mixed $mediaPlatform)
        {
 
            $configuration =  $mediaPlatform->configuration;

            $apiUrl =  self::getApiUrl('oauth/v2/accessToken', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' =>  $configuration->client_id,
                'client_secret' => $configuration->client_secret,
                'redirect_uri' => url('/account/linkedin/callback?medium='.$mediaPlatform->slug),
            ], $configuration,true);
    
            return Http::post($apiUrl);
        }




        /**
         * Summary of refreshAccessToken
         * @param \App\Models\MediaPlatform $mediaPlatform
         * @param string $token
         * @return \Illuminate\Http\Client\Response
         */
        public static function refreshAccessToken(mixed $mediaPlatform , string $token): \Illuminate\Http\Client\Response
        {

            $configuration =  $mediaPlatform->configuration;

            $apiUrl = self::getApiUrl('oauth/v2/accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token,
                'client_id' =>  $configuration->client_id,
                'client_secret' => $configuration->client_secret,
            ],           $configuration, true);
    
            return Http::asForm()->post($apiUrl);
        }
    




    /**
     * Summary of getAccount
     * @param string $token
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return \Illuminate\Http\Client\Response
     */
    public static function getAccount(string $token , mixed $mediaPlatform)
    {
        $apiUrl = self::getApiUrl('v2/userinfo',[], $mediaPlatform);
        return Http::withToken($token)->get($apiUrl);
    }





    /**
     * Summary of saveLdAccount
     * @param mixed $user
     * @param string $guard
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @param string $account_type
     * @param string $is_official
     * @param int|string $dbId
     * @return void
     */
    public static function saveLdAccount(
        mixed $user,
        string $guard,
        mixed $mediaPlatform,
        string $account_type,
        string $is_official,
        string $token,
        mixed $tokenExpireIn,
        int|string $dbId = null
    ) {
        $ld = new self();

        $accountInfo = [
            'id' => $user['sub'],
            'account_id' => $user['sub'],
            'name' => Arr::get($user, 'name', null),
            'avatar' => Arr::get($user, 'picture'),
            'email' => Arr::get($user, 'email'),
            'token' => $token,
            'access_token_expire_at' => now()->addSeconds($tokenExpireIn),
            'refresh_token' => $token,
            'refresh_token_expire_at' => now()->addSeconds($tokenExpireIn),
        ];

        $response = $ld->saveAccount($guard, $mediaPlatform, $accountInfo, $account_type, $is_official, $dbId);
    }






    public function send(SocialPost $post): array
    {
        $status = false;
        $message = social_poster_trans('Failed to post to LinkedIn');

        try {
            $account           = $post->account;
            $token             = $account->token;

            
            $linkedin_id = $account->account_id;

            $platform          = @$account?->platform;

            $configuration =  $platform->configuration;



            if($post->file && $post->file->count() > 0){

                $uploadedMedia = collect([]);

                foreach ($post->file as $file) {

                    $fileURL = imageURL($file,"post",true);

                    $imageContainer = $this->apiClient($token )
                    ->post(self::getApiUrl('rest/images', [
                        'action' => 'initializeUpload'
                    ],$configuration), [
                        "initializeUploadRequest" => ["owner" => "urn:li:person:{$linkedin_id}"]
                    ])
                    ->json('value');
    
    
                    $response = $this->apiClient($token)
                        ->attach('file', fopen($fileURL, 'r'))
                        ->post($imageContainer['uploadUrl']);
        
                    if ($response->created()) {
                        $uploadedMedia->push($imageContainer);
                    }

                }

                
                    $postImages = $uploadedMedia->map(function ($item) {
                        return ['id' => $item['image']];
                    });

                    $attachMediaObj = ($postImages->count() > 1) ? [
                        "content" => [
                            "multiImage" => [
                                "images" => $postImages->toArray()
                            ]
                        ]
                    ] : [
                        "content" => [
                            "media" => [
                                "id" => $postImages->value('id')
                            ]
                        ]
                    ];


                    $postData = [
                        "author" => "urn:li:person:{$linkedin_id}",
                        "commentary" => $post->content ?? '',
                        "visibility" => 'PUBLIC',
                        "distribution" => [
                            "feedDistribution" => 'MAIN_FEED',
                            "targetEntities" => [],
                            "thirdPartyDistributionChannels" => []
                        ],
                        "lifecycleState" => "PUBLISHED",
                        "isReshareDisabledByAuthor" => false,
                        ...$attachMediaObj
                    ];

                    $response = $this->apiClient($token)->post(self::getApiUrl('rest/posts',[],$configuration), $postData);


                    if ($response->successful()) {

                        return [
                            'status'   =>true,
                            'response' => social_poster_trans("Posted Successfully"),
                            'url'      => null
                        ];
    

                    }

                    return [
                        'status'   => false,
                        'response' => @$response->json('message') ?? social_poster_trans("Failed to post"),
                        'url'      => null
                    ];
            

            } else {

                $postData = [
                    "author" => "urn:li:person:{$linkedin_id}",
                    "commentary" => $post->content ?? '',
                    "visibility" => 'PUBLIC',
                    "distribution" => [
                        "feedDistribution" => 'MAIN_FEED',
                        "targetEntities" => [],
                        "thirdPartyDistributionChannels" => []
                    ],
                    "lifecycleState" => "PUBLISHED",
                    "isReshareDisabledByAuthor" => false
                ];

                $response = $this->apiClient($token)->post(self::getApiUrl('rest/posts', [], $configuration), $postData);

      

         
                if ($response->successful()) {

                    return [
                        'status'   =>true,
                        'response' => social_poster_trans("Posted Successfully"),
                        'url'      => null
                    ];

                }

                return [
                    'status'   => false,
                    'response' => @$response->json('message') ?? social_poster_trans("Failed to post"),
                    'url'      => null
                ];

            }
          
            
         } catch (\Exception $ex) {
            $status  = false;
            $message = strip_tags($ex->getMessage());
         }

         return [
            'status'   => $status,
            'response' => $message,
            'url'      => null
        ];

    }


    private function uploadImage(string $imagePath ,string $token , int | string $clinetId)
    {
        $url = 'https://api.linkedin.com/v2/assets?action=registerUpload';
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $response = Http::post($url, [
            'registerUploadRequest' => [
                'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                'owner' => 'urn:li:person:' . $clinetId,
            ],
        ], $headers);

        $uploadUrl = $response['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];

        $imageResponse = Http::put($uploadUrl, [
            'Content-Type' => 'application/octet-stream',
            'Authorization' => 'Bearer ' . $token ,
        ], file_get_contents($imagePath));

        return $imageResponse;
    }






    private function apiClient($token)
    {
        return Http::withHeaders([
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => '202411',
        ])->withToken($token)->retry(1, 3000);
    }


    /**
     * Get insights/analytics for a LinkedIn post
     *
     * @param SocialPost $post
     * @param SocialAccount $account
     * @return array
     */
    public function getInsight(SocialPost $post, SocialAccount $account): array
    {
        try {
            $token = $account->token ?? $account->access_token ?? null;

            if (!$token || !$post->platform_post_id) {
                return [
                    'status' => false,
                    'message' => 'Missing access token or post ID',
                    'metrics' => [],
                ];
            }

            $metrics = [
                'impressions' => 0,
                'engagements' => 0,
                'reactions' => 0,
                'comments' => 0,
                'shares' => 0,
                'likes' => 0,
                'reach' => 0,
            ];

            // Fetch each metric type from the new API
            // LinkedIn requires separate calls for each metric type
            $metricTypes = ['IMPRESSION', 'REACTION', 'COMMENT', 'RESHARE', 'MEMBERS_REACHED'];
            $postUrn = urlencode($post->platform_post_id);

            foreach ($metricTypes as $metricType) {
                $apiUrl = self::API_URL . "/rest/memberCreatorPostAnalytics?q=entity&entity=(share:{$postUrn})&queryType={$metricType}&aggregation=TOTAL";

                $response = $this->apiClient($token)->get($apiUrl);

                if ($response->successful()) {
                    $data = $response->json();
                    $count = $data['elements'][0]['count'] ?? 0;

                    switch ($metricType) {
                        case 'IMPRESSION':
                            $metrics['impressions'] = $count;
                            break;
                        case 'REACTION':
                            $metrics['reactions'] = $count;
                            $metrics['likes'] = $count;
                            break;
                        case 'COMMENT':
                            $metrics['comments'] = $count;
                            break;
                        case 'RESHARE':
                            $metrics['shares'] = $count;
                            break;
                        case 'MEMBERS_REACHED':
                            $metrics['reach'] = $count;
                            break;
                    }
                }
            }

            $metrics['engagements'] = $metrics['reactions'] + $metrics['comments'] + $metrics['shares'];

            return [
                'status' => true,
                'message' => 'Metrics fetched successfully',
                'metrics' => $metrics,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error fetching LinkedIn metrics: ' . $e->getMessage(),
                'metrics' => [],
            ];
        }
    }

    public function accountDetails(SocialAccount $account): array
    {
        try {
            $token = $account->token;
            $authorUrn = 'urn:li:person:' . $account->account_id;

            $params = [
                'author' => $authorUrn,
                'q' => 'author',
                'count' => 50,
                'sortBy' => 'LAST_MODIFIED',
            ];

            $apiUrl = self::getApiUrl('rest/posts', $params, $account->platform->configuration);

            $apiResponse = $this->apiClient($token)->get($apiUrl);
            $apiResponse = $apiResponse->json();

            if ($apiResponse['errors'] ?? false) {
                return [
                    'status' => false,
                    'message' => $apiResponse['errors'][key($apiResponse['errors'])]['message'] ?? social_poster_trans('API error')
                ];
            }

            return [
                'status' => true,
                'response' => $apiResponse,
            ];

        } catch (\Exception $ex) {
            return [
                'status' => false,
                'message' => strip_tags($ex->getMessage())
            ];
        }
    }
}
