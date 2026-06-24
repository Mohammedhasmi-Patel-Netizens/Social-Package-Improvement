<?php

namespace BeePost\SocialPoster\Services\Account\threads;

use BeePost\SocialPoster\Traits\AccountManager;
use BeePost\SocialPoster\Enums\AccountType;
use BeePost\SocialPoster\Enums\ConnectionType;
use BeePost\SocialPoster\Enums\PostType;
use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Contracts\PlatformAccountInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Account implements PlatformAccountInterface
{
    use AccountManager;

    public $thUrl, $params;

    const BASE_URL = 'https://www.threads.net';
    const API_URL = 'https://graph.threads.net';

    public function __construct()
    {
        $this->thUrl = "https://www.threads.net/";

        $this->params = [
            'fields' => 'id,username,name,threads_profile_picture_url'
        ];
    }

    
   

    /**
     * Summary of authRedirect
     * @param \App\Models\MediaPlatform $platform
     * @return string
     */
    public static function authRedirect(mixed $platform): string
    {
        $configuration = $platform->configuration;

        $client_id = $configuration->client_id;
        $redirect_uri = url('/account/threads/callback?medium=' . $platform->slug);
        $scope = 'threads_basic threads_content_publish threads_manage_replies threads_read_replies threads_manage_insights';
        $state = 'state123';

        return "https://www.threads.net/oauth/authorize?client_id={$client_id}&redirect_uri={$redirect_uri}&scope={$scope}&response_type=code&state={$state}";
    }



    /**
     * Build API URL
     */
    public static function getApiUrl(string $endpoint, array $params = [], mixed $configuration, bool $isBaseUrl = false, bool $isUploadUrl = false): string
    {
        // API_URL and UPLOAD_URL already contain /2 version, so don't add version again
        // BASE_URL (x.com) doesn't need version
        $apiUrl = $isBaseUrl ? self::BASE_URL : self::API_URL;

        if (str_starts_with($endpoint, '/')) {
            $endpoint = substr($endpoint, 1);
        }

        $url = $apiUrl . '/' . $endpoint;

        if (count($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Summary of getAccessToken
     * @param string $code
     * @param \App\Models\MediaPlatform $platform
     * @return \Illuminate\Http\Client\Response
     */
    public static function getAccessToken(string $code, mixed $platform)
    {
        $configuration = $platform->configuration;

        return Http::asForm()->post("https://graph.threads.net/oauth/access_token", [
            'client_id' => $configuration->client_id,
            'client_secret' => $configuration->client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => url('/account/threads/callback?medium=' . $platform->slug),
        ]);
    }


    public static function getLongLiveToken(string $shortToken, mixed $platform)
    {
        $configuration = $platform->configuration;

        return Http::get("https://graph.threads.net/access_token", [
            'client_secret' => $configuration->client_secret,
            'grant_type' => 'th_exchange_token',
            'access_token' => $shortToken,
        ]);

    }

    /**
     * Refresh token
     */
    public static function refreshAccessToken(mixed $platform, string $token)
    {
        $configuration = $platform->configuration;

        return Http::asForm()->post("https://graph.threads.net/refresh_access_token", [
            'grant_type' => 'th_refresh_token',
            'access_token' => $token,
        ]);
    }

    /**
     * Get logged-in user info
     */
    public function getAcccount(string $token, mixed $platform)
    {
        $configuration = $platform->configuration;

        $apiUrl = self::getApiUrl('me', [
            'fields' => 'id,username,name,threads_profile_picture_url',
            'access_token' => $token
        ], $configuration);

        return Http::get($apiUrl);
    }

    /**
     * Save Threads account
     */
    public static function saveThAccount(
        mixed $responseData,
        string $guard,
        mixed $platform,
        string $account_type,
        string $is_official,
        int|string $dbId = null
    ) {
        $th = new self();

        // Handle both Response object and array
        if (is_array($responseData)) {
            $data = $responseData;
        } elseif (is_object($responseData) && method_exists($responseData, 'json')) {
            $data = $responseData->json();
        } else {
            $data = (array) $responseData;
        }

        $token = Arr::get($data, 'access_token');
        $expireIn = Arr::get($responseData, 'expires_in');
        $refresh_token = Arr::get($data, 'refresh_token');

        $response = $th->getAcccount($token, $platform)->throw();
        
        $user = $response->json();

        $accountInfo = [
            'id' => $user['id'],
            'account_id' => $user['id'],
            'name' => Arr::get($user, 'name') ?? Arr::get($user, 'username'),
            'avatar' => Arr::get($user, 'threads_profile_picture_url'),
            'email' => null,
            'token' => $token,
            'access_token_expire_at' => now()->addSeconds($expireIn),
            'refresh_token' => $refresh_token,
            'refresh_token_expire_at' => now()->addMonths(2),
        ];

        return $th->saveAccount($guard, $platform, $accountInfo, $account_type, $is_official, $dbId);
    }

    /**
     * Get post details
     */
    public static function getPost(string $postId, string $token, mixed $platform)
    {
        $configuration = $platform->configuration;

        $apiUrl = self::getApiUrl($postId, [
            'fields' => 'id,caption,media_type,media_url,permalink',
            'access_token' => $token
        ], $configuration);

        return Http::get($apiUrl);
    }

    /**
     * Publish post
     */
    public function send(SocialPost $post): array
    {
        try {
            $account = $post->account;
            $accountToken = $account->token;
            $platform = $account->platform;
            $configuration = $platform->configuration;

            $caption = $post->content ?? '';
            if ($post->link) {
                $caption .= ' ' . $post->link;
            }

            $containerUrl = self::getApiUrl("{$account->account_id}/threads", [], $configuration);

            $payload = [
                'access_token' => $accountToken,
                'text' => $caption,
            ];

            $isVideo = false;

            // Handle media files (images and videos)
            if ($post->file && $post->file->count() > 0) {
                $file = $post->file->first();
                $mediaUrl = imageURL($file, "post", true);

                if (isValidVideoUrl($mediaUrl)) {
                    // Video post
                    $payload['media_type'] = 'VIDEO';
                    $payload['video_url'] = $mediaUrl;
                    $isVideo = true;
                } else {
                    // Image post
                    $payload['media_type'] = 'IMAGE';
                    $payload['image_url'] = $mediaUrl;
                }
            } else {
                // Text-only post
                $payload['media_type'] = 'TEXT';
            }

            // Step 1: Create container
            $container = Http::post($containerUrl, $payload)->json();

            if (!isset($container['id'])) {
                return [
                    'status' => false,
                    'response' => $container['error']['message'] ?? 'Failed to create container',
                    'url' => null
                ];
            }

            // Step 2: Wait for media processing (required for videos, recommended for images)
            if ($isVideo) {
                // Poll for container status for videos
                $maxAttempts = 30;
                $attempt = 0;
                $isReady = false;

                while ($attempt < $maxAttempts && !$isReady) {
                    sleep(2); // Wait 2 seconds between checks
                    $statusUrl = self::getApiUrl($container['id'], [
                        'fields' => 'status',
                        'access_token' => $accountToken
                    ], $configuration);

                    $statusResponse = Http::get($statusUrl)->json();
                    $status = $statusResponse['status'] ?? '';

                    if ($status === 'FINISHED') {
                        $isReady = true;
                    } elseif (in_array($status, ['ERROR', 'EXPIRED'])) {
                        return [
                            'status' => false,
                            'response' => 'Video processing failed: ' . $status,
                            'url' => null
                        ];
                    }

                    $attempt++;
                }

                if (!$isReady) {
                    return [
                        'status' => false,
                        'response' => 'Video processing timed out',
                        'url' => null
                    ];
                }
            } else {
                // For images/text, wait a shorter time
                sleep(3);
            }

            // Step 3: Publish container
            $publishUrl = self::getApiUrl("{$account->account_id}/threads_publish", [], $configuration);
            $publish = Http::post($publishUrl, [
                'creation_id' => $container['id'],
                'access_token' => $accountToken,
            ])->json();

            if (isset($publish['id'])) {
                $postId = $publish['id'];
                return [
                    'status' => true,
                    'response' => social_poster_trans("Posted Successfully"),
                    'url' => "https://www.threads.net/@" . ($account->name ?? $account->account_id) . "/post/" . $postId,
                    'post_id' => $postId,
                ];
            }

            return [
                'status' => false,
                'response' => $publish['error']['message'] ?? 'Failed to publish',
                'url' => null
            ];

        } catch (\Exception $ex) {
            return [
                'status' => false,
                'response' => strip_tags($ex->getMessage()),
                'url' => null
            ];
        }
    }



    /**
     * Get metrics/insights for a Threads post
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

            $platform = $account->platform;
            $configuration = $platform->configuration ?? null;
            $postId = $post->platform_post_id;

            // Use correct Threads API URL (graph.threads.net, not graph.facebook.com)
            $apiUrl = self::getApiUrl("{$postId}/insights", [
                'metric' => 'views,likes,replies,reposts,quotes',
                'access_token' => $token,
            ], $configuration);

            $response = Http::get($apiUrl);
            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'] ?? 'API Error',
                    'metrics' => [],
                ];
            }

            $insights = collect($data['data'] ?? [])->keyBy('name');

            $views = $insights->get('views')['values'][0]['value'] ?? 0;
            $likes = $insights->get('likes')['values'][0]['value'] ?? 0;
            $replies = $insights->get('replies')['values'][0]['value'] ?? 0;
            $reposts = $insights->get('reposts')['values'][0]['value'] ?? 0;
            $quotes = $insights->get('quotes')['values'][0]['value'] ?? 0;

            // Use consistent metric names across all platforms
            $metrics = [
                'impressions' => $views,
                'engagements' => $likes + $replies + $reposts + $quotes,
                'likes' => $likes,
                'comments' => $replies,
                'shares' => $reposts + $quotes,
                'reactions' => $likes,
                'reach' => $views,
            ];

            return [
                'status' => true,
                'message' => 'Metrics fetched successfully',
                'metrics' => $metrics,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error fetching Threads metrics: ' . $e->getMessage(),
                'metrics' => [],
            ];
        }
    }

    public function accountDetails(SocialAccount $account): array
    {
        try {
            $token = $account->token;
            $userId = $account->account_id;

            $fields = 'id,text,media_type,media_url,permalink,like_count,reply_count,repost_count,quote_count,view_count,timestamp';

            $params = [
                'fields' => $fields,
                'access_token' => $token,
                'limit' => 50, // Adjust as needed
            ];

            $apiUrl = self::getApiUrl("{$userId}/threads", $params, $account->platform->configuration);

            $apiResponse = Http::get($apiUrl);
            $apiResponse = $apiResponse->json();

            if (isset($apiResponse['error'])) {
                // Optional: $this->disConnectAccount($account);
                return [
                    'status' => false,
                    'message' => $apiResponse['error']['message'] ?? social_poster_trans('API error')
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
