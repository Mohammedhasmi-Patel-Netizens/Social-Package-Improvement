<?php

namespace BeePost\SocialPoster\Services\Account\tiktok;

use BeePost\SocialPoster\Enums\ConnectionType;
use BeePost\SocialPoster\Traits\AccountManager;
use BeePost\SocialPoster\Enums\AccountType;
use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Contracts\PlatformAccountInterface;
use Illuminate\Support\Arr;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;

class Account implements PlatformAccountInterface
{


    use AccountManager;

    public $ttUrl, $params;


    const BASE_URL = 'https://www.tiktok.com';
    const API_URL = 'https://open.tiktokapis.com';




    public function __construct()
    {
        $this->ttUrl = "https://www.tiktok.com";

        $this->params = [
            'fields' => 'open_id,union_id,avatar_url,display_name',
        ];

    }





    /**
     * Summary of authRedirect
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return string
     */
    public static function authRedirect(mixed $mediaPlatform): string
    {

        $configuration = $mediaPlatform->configuration;


        $client_key = $configuration->client_key;
        $redirect_uri = url('/account/tiktok/callback');
        $scope = 'user.info.basic,video.publish,video.list';
        $state = bin2hex(random_bytes(16));
        $code_verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        $code_challenge_method = 'S256';

        session(['tiktok_code_verifier' => $code_verifier]);

        $auth_url = "https://www.tiktok.com/v2/auth/authorize/" .
            "?client_key=$client_key" .
            "&response_type=code" .
            "&redirect_uri=" . urlencode($redirect_uri) .
            "&scope=" . urlencode($scope) .
            "&state=" . urlencode($state) .
            "&code_challenge=" . urlencode($code_challenge) .
            "&code_challenge_method=" . urlencode($code_challenge_method);

        return $auth_url;

    }



    /**
     * Summary of getApiUrl
     * @param string $endpoint
     * @param array $params
     * @param mixed $configuration
     * @param bool $isBaseUrl
     * @return mixed
     */
    public static function getApiUrl(string $endpoint, array $params = [], mixed $configuration, bool $isBaseUrl = false): mixed
    {

        $apiUrl = $isBaseUrl ? self::BASE_URL : self::API_URL;

        if (str_starts_with($endpoint, '/'))
            $endpoint = substr($endpoint, 1);

        $v = $configuration->app_version ?? 'v2';

        $versionedUrlWithEndpoint = $apiUrl . '/' . ($v ? ($v . '/') : '') . $endpoint;

        if (count($params))
            $versionedUrlWithEndpoint .= '?' . http_build_query($params);

        return $versionedUrlWithEndpoint;


    }





    /**
     * Summary of getAccessToken
     * @param string $code
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return \Illuminate\Http\Client\Response
     */
    public static function getAccessToken(string $code, mixed $mediaPlatform)
    {

        $configuration = $mediaPlatform->configuration;

        $client_key = $configuration->client_key;
        $client_secret = $configuration->client_secret;
        $redirect_uri = url('/account/tiktok/callback');
        $code_verifier = session('tiktok_code_verifier');

        $params = [
            'client_key' => $client_key,
            'client_secret' => $client_secret,
            'code' => urldecode($code),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri,
            'code_verifier' => $code_verifier,
        ];

        $apiUrl = self::getApiUrl('oauth/token/', [], $configuration);

        $response = Http::asForm()->post($apiUrl, $params);

        if ($response->successful()) {
            return $response;
        } else {
            throw new Exception('Failed to get access token: ' . $response->body());
        }


    }



    /**
     * Summary of refreshAccessToken
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @param string $token
     * @return \Illuminate\Http\Client\Response
     */
    public static function refreshAccessToken(mixed $mediaPlatform, string $token): \Illuminate\Http\Client\Response
    {

        $configuration = $mediaPlatform->configuration;
        $client_key = $configuration->client_key;
        $client_secret = $configuration->client_secret;


        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token,
            'client_key' => $client_key,
            'client_secret' => $client_secret,
        ];

        $apiUrl = self::getApiUrl('oauth/token/', [], $configuration);

        return Http::asForm()->post($apiUrl, $params);
    }





    /**
     * Summary of getAcccount
     * @return \Illuminate\Http\Client\Response
     */
    public function getAccount(string|null $token, mixed $mediaPlatform): \Illuminate\Http\Client\Response
    {

        $configuration = $mediaPlatform->configuration;

        $apiUrl = self::getApiUrl('user/info/', [
            'fields' => 'open_id,union_id,avatar_url,display_name',
        ], $configuration);


        return Http::withToken($token)->get($apiUrl);

    }





    /**
     * Summary of saveTwAccount
     * @param mixed $pages
     * @param string $guard
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @param string $account_type
     * @param string $is_official
     * @param int|string $dbId
     *
     */
    public static function saveTtAccount(
        mixed $responseData,
        string $guard,
        mixed $mediaPlatform,
        string $account_type,
        string $is_official,
        int|string $dbId = null
    ) {
        $tt = new self();

        $responseData = $responseData->json();

        $expireIn = Arr::get($responseData, 'expires_in');
        $token = Arr::get($responseData, 'access_token');
        $refresh_token = Arr::get($responseData, 'refresh_token');
        $refresh_token_expires_in = Arr::get($responseData, 'refresh_expires_in');
        
        $response = $tt->getAccount($token, $mediaPlatform)->throw();

        $user = $response->json('data.user');

        $accountInfo = [
            'id' => $user['open_id'],
            'account_id' => $user['open_id'],
            'name' => Arr::get($user, 'display_name', null),
            'avatar' => Arr::get($user, 'avatar_url'),
            'email' => null,
            'token' => $token,
            'access_token_expire_at' => now()->addSeconds($expireIn ?: 86400),
            'refresh_token' => $refresh_token,
            'refresh_token_expire_at' => now()->addSeconds($refresh_token_expires_in ?: 0),
        ];

        $response = $tt->saveAccount($guard, $mediaPlatform, $accountInfo, $account_type, $is_official, $dbId);

        return $response;
    }






    /**
     * Summary of getPost
     * @param string $tweetId
     * @param string $token
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return \Illuminate\Http\Client\Response
     */
    public static function getPost(string $videoId, string $token, mixed $mediaPlatform): \Illuminate\Http\Client\Response
    {
        $configuration = $mediaPlatform->configuration;

        $apiUrl = self::getApiUrl('video/query/', [
            'fields' => 'id,title,create_time,view_count,like_count,comment_count',
            'filters' => json_encode(['video_ids' => [$videoId]]),
        ], $configuration);

        return Http::withToken($token)->get($apiUrl);
    }



    /**
     * Instagram account connecton
     *
     * @param MediaPlatform $platform
     * @param array $request
     * @param string $guard
     * @return array
     */
    public function tiktok(mixed $platform, array $request, string $guard = 'admin'): array
    {
        $responseStatus = social_poster_response_status(social_poster_trans('Authentication failed incorrect keys'), 'error');


        try {
            $accountId = Arr::get($request, 'account_id', null);

            $responseStatus = social_poster_response_status(social_poster_trans('Api error'), 'error');
            $client_key = Arr::get($request, 'client_key', $platform->configuration->client_id);
            $client_secret = Arr::get($request, 'client_secret', $platform->configuration->client_secret);
            $access_token = Arr::get($request, 'access_token', null);
            $refresh_token = Arr::get($request, 'refresh_token', null);

            if (!$access_token) {
                throw new \Exception('No access token provided');
            }

            $config = [
                'client_key' => $client_key,
                'client_secret' => $client_secret,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
            ];


            $response = $this->getAccount($access_token, $platform);

            if ($response->successful()) {
                $userData = $response->json()['data'];

                if ($userData && isset($userData['open_id'])) {
                    $responseStatus = social_poster_response_status(social_poster_trans('Account Created'));
                    $config = array_merge($config, $userData);


                    $config['link'] = "https://www.tiktok.com/@" . Arr::get($config, 'display_name');
                    $config['avatar'] = Arr::get($config, 'avatar_url');
                    $config['account_id'] = Arr::get($config, 'open_id');

                    $response = $this->saveAccount(
                        $guard,
                        $platform,
                        $config,
                        AccountType::PROFILE->value,
                        ConnectionType::OFFICIAL->value,
                        $accountId
                    );
                }
            } else {
                throw new \Exception('API request failed: ' . $response->body());
            }

        } catch (\Exception $ex) {

        }

        return $responseStatus;
    }




    public function send(SocialPost $post): array
    {
        try {
            $status = false;
            $message = 'Failed to post to TikTok!!! Configuration error';

            $account = $post->account;
            $accountToken = $account->token;
            $platform = @$account?->platform;

            if (!$platform) {
                throw new \Exception('No platform associated with account');
            }

            $configuration = $platform->configuration;
            $postDescription = $post->content ?: 'Test Video ' . time();

            if ($post->link) {
            $postDescription .= ' ' . $post->link;
            }
            if (strlen($postDescription) > 2200) {
                $postDescription = substr($postDescription, 0, 2197) . '...';
            }

            if (!$post->file || $post->file->count() === 0) {
                return [
                    'status' => false,
                    'response' => 'TikTok requires a video file'
                ];
            }

            $file = $post->file->first();
            $fileURL = imageURL($file, "post", true);


            if (!str_starts_with($fileURL, 'https://')) {
                throw new \Exception('TikTok requires an HTTPS URL for PULL_FROM_URL');
            }
            $urlCheck = @file_get_contents($fileURL, false, null, 0, 1);
            if ($urlCheck === false) {
                throw new \Exception('Video URL is not publicly accessible: ' . $fileURL);
            }

            $apiUrl = self::getApiUrl('post/publish/video/init/', [], $configuration);

            $privacyLevel = (isset($configuration->app_mode) && $configuration->app_mode === 'sandbox') ? 'SELF_ONLY' : 'PUBLIC_TO_EVERYONE';
            // For audited accounts, use 'PUBLIC_TO_EVERYONE'. For unaudited accounts, use 'SELF_ONLY'.


            $payload = [
                'post_info' => [
                    'title' => $postDescription,
                    'privacy_level' => $privacyLevel,
                    'disable_comment' => false,
                    'disable_duet' => false,
                    'disable_stitch' => false,
                    'video_cover_timestamp_ms' => 1000,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $fileURL,
                ]
            ];


            $response = Http::withToken($accountToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($apiUrl, $payload);

            $responseJson = $response->json();


            if ($response->successful() && isset($responseJson['data']['publish_id'])) {
                $publishId = $responseJson['data']['publish_id'];
                return [
                    'status' => true,
                    'response' => social_poster_trans("Video posting initiated successfully"),
                    'url' => null, // TikTok doesn't return URL immediately, video needs processing
                    'post_id' => $publishId,
                    'publish_id' => $publishId,
                ];
            }

            // Handle specific TikTok errors
            $errorCode = $responseJson['error']['code'] ?? null;
            $errorMessage = $responseJson['error']['message'] ?? 'Failed to publish video';

            if ($errorCode === 'spam_risk_too_many_pending_share') {
                $errorMessage = social_poster_trans('Too many pending posts. Please wait for previous videos to finish processing.');
            } elseif ($errorCode === 'spam_risk_user_banned_from_posting') {
                $errorMessage = social_poster_trans('Account is temporarily banned from posting.');
            }

            return [
                'status' => false,
                'response' => $errorMessage,
                'url' => null
            ];
        } catch (\Exception $ex) {
            $status = false;
            $message = strip_tags($ex->getMessage());
        }

        return [
            'status' => $status,
            'response' => $message,
            'url' => null
        ];
    }


    public function accountDetails(SocialAccount $account): array
    {
        try {

            $token  = $account->token;
            $fields = 'id,title,video_description,cover_image_url,embed_link,create_time,view_count,like_count,comment_count';

            $queryParams = [
                'fields' => $fields,
            ];

            $apiUrl = self::getApiUrl('/video/list/', $queryParams, $account->platform->configuration);

            $body = [
                'max_count' => 20,
                'cursor' => '0',
            ];

            $response = Http::withToken($token)
                            ->withHeaders(['Content-Type' => 'application/json'])
                            ->post($apiUrl, $body);

            $apiResponse = $response->json();

            $formattedResponse  = $this->formatResponse($apiResponse);

            if (isset($apiResponse['error']) && $apiResponse['error']['code'] !== 'ok') {
                $this->disConnectAccount($account);
                return [
                    'status' => false,
                    'message' => $apiResponse['error']['message']
                ];
            }

            return [
                'status' => true,
                'response' => $formattedResponse,
            ];
        } catch (\Exception $ex) {
            return [
                'status' => false,
                'message' => strip_tags($ex->getMessage())
            ];
        }
    }

    public function formatResponse(array $response)
    {
        if (!isset($response['data']['videos']) || !is_array($response['data']['videos'])) {
            return [
                'data' => [],
            ];
        }

        $formattedData = array_map(function ($video) {
            return [
                'full_picture' => $video['cover_image_url'] ?? get_default_img(),
                'message' => $video['video_description'] ?? $video['title'] ?? '',
                'created_time' => $video['create_time'] ?? \Carbon\Carbon::now()->timestamp,
                'reactions' => [
                    'summary' => [
                        'total_count' => $video['like_count'] ?? 0,
                    ],
                ],
                'comments' => [
                    'summary' => [
                        'total_count' => $video['comment_count'] ?? 0,
                    ],
                ],
                'shares' => [
                    'count' => 0,
                ],
                'permalink_url' => isset($video['id']) ? "https://www.tiktok.com/@username/video/{$video['id']}" : $video['embed_link'] ?? '',
                'privacy' => [
                    'value' => 'EVERYONE',
                ],
                'type' => 'video',
            ];
        }, $response['data']['videos']);


        return [
            'data' => $formattedData,
        ];
    }


    /**
     * Get insights/analytics for a TikTok post
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
                    'message' => 'Missing access token or video ID',
                    'metrics' => [],
                ];
            }

            $fields = 'id,like_count,comment_count,share_count,view_count';
            $apiUrl = self::API_URL . "/v2/video/query/?fields={$fields}";

            $response = Http::withToken($token)
                ->post($apiUrl, [
                    'filters' => [
                        'video_ids' => [$post->platform_post_id]
                    ]
                ]);

            $data = $response->json();
            $videos = $data['data']['videos'] ?? [];
            $stats = $videos[0] ?? [];

            $metrics = [
                'impressions' => $stats['view_count'] ?? 0,
                'engagements' => ($stats['like_count'] ?? 0) + ($stats['comment_count'] ?? 0) + ($stats['share_count'] ?? 0),
                'likes' => $stats['like_count'] ?? 0,
                'comments' => $stats['comment_count'] ?? 0,
                'shares' => $stats['share_count'] ?? 0,
                'reactions' => $stats['like_count'] ?? 0,
                'reach' => $stats['view_count'] ?? 0,
            ];

            return [
                'status' => true,
                'message' => 'Metrics fetched successfully',
                'metrics' => $metrics,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error fetching TikTok metrics: ' . $e->getMessage(),
                'metrics' => [],
            ];
        }
    }
}
