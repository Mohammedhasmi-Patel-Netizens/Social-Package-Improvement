<?php

namespace BeePost\SocialPoster\Services\Account\youtube;

use BeePost\SocialPoster\Enums\ConnectionType;
use BeePost\SocialPoster\Enums\PostType;
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

    public $ytUrl, $params;

    const BASE_URL = 'https://www.youtube.com';
    const API_URL = 'https://www.googleapis.com/youtube';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube';




    public function __construct()
    {
        $this->ytUrl = self::BASE_URL;

        $this->params = [
            'part' => 'snippet,contentDetails',
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

        $client_id = $configuration->client_id;
        $redirect_uri = url('/account/youtube/callback?medium=' . $mediaPlatform->slug);
        $scope = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';
        $state = bin2hex(random_bytes(16));
        $code_verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        $code_challenge_method = 'S256';

        session(['youtube_code_verifier' => $code_verifier]);

        $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

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
    public static function getApiUrl(string $endpoint, array $params = [], mixed $configuration, bool $isBaseUrl = false, bool $isUpload = false): string
    {
        $apiUrl = $isBaseUrl ? self::BASE_URL : ($isUpload ? self::UPLOAD_URL : self::API_URL);

        if (str_starts_with($endpoint, '/')) {
            $endpoint = substr($endpoint, 1);
        }

        $v = $configuration->app_version ?? 'v3';

        $versionedUrlWithEndpoint = $apiUrl . '/' . ($v ? ($v . '/') : '') . $endpoint;

        if (!empty($params)) {
            $versionedUrlWithEndpoint .= '?' . http_build_query($params);
        }

        return $versionedUrlWithEndpoint;
    }





    /**
     * Summary of getAccessToken
     * @param string $code
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @param string|null $state - The state parameter from callback (unused, kept for compatibility)
     * @return \Illuminate\Http\Client\Response
     */
    public static function getAccessToken(string $code, mixed $mediaPlatform, ?string $state = null)
    {
        $configuration = $mediaPlatform->configuration;

        $client_id = $configuration->client_id;
        $client_secret = $configuration->client_secret;
        $redirect_uri = url('/account/youtube/callback?medium=' . $mediaPlatform->slug);

        // Get code_verifier from session
        $code_verifier = session('youtube_code_verifier');

        if (!$code_verifier) {
            throw new Exception('YouTube OAuth failed: code_verifier not found. Please try connecting again.');
        }

        $params = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => urldecode($code),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri,
            'code_verifier' => $code_verifier,
        ];

        // Google's fixed token endpoint
        $apiUrl = 'https://oauth2.googleapis.com/token';

        $response = Http::asForm()->post($apiUrl, $params);

        // Clean up session
        session()->forget(['youtube_code_verifier']);

        if ($response->successful()) {
            return $response;
        } else {
            $errorBody = $response->json();
            $errorMsg = $errorBody['error_description'] ?? $errorBody['error'] ?? $response->body();
            throw new Exception('Failed to get YouTube access token: ' . $errorMsg);
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
        $client_id = $configuration->client_id;
        $client_secret = $configuration->client_secret;

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ];

        $apiUrl = 'https://oauth2.googleapis.com/token';

        return Http::asForm()->post($apiUrl, $params);
    }





    /**
     * Summary of getAcccount
     * @return \Illuminate\Http\Client\Response
     */
    public function getAccount(string $token, mixed $mediaPlatform): \Illuminate\Http\Client\Response
    {
        $configuration = $mediaPlatform->configuration;

        $apiUrl = self::getApiUrl('channels', [
            'part' => 'snippet,contentDetails',
            'mine' => 'true',
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
    public static function saveYtAccount(
        mixed $responseData,
        string $guard,
        mixed $mediaPlatform,
        string $account_type,
        string $is_official,
        int|string $dbId = null
    ): mixed {
        $yt = new self();

        $responseData = $responseData->json();


        $expireIn = Arr::get($responseData, 'expires_in');
        $token = Arr::get($responseData, 'access_token');
        $refresh_token = Arr::get($responseData, 'refresh_token');

        $response = $yt->getAccount($token, $mediaPlatform);
        $apiResponse = $response->json();


        if (!$response->successful() || !isset($apiResponse['items'][0])) {
            $errorMsg = $apiResponse['error']['message'] ?? 'No YouTube channel found for this account';
            throw new \Exception($errorMsg);
        }

        $channel = $apiResponse['items'][0];


        $accountInfo = [
            'id' => $channel['id'],
            'account_id' => $channel['id'],
            'name' => Arr::get($channel, 'snippet.title', null),
            'avatar' => Arr::get($channel, 'snippet.thumbnails.default.url'),
            'email' => null,
            'token' => $token,
            'access_token_expire_at' => now()->addSeconds($expireIn ?: 3600), // Default 1 hour
            'refresh_token' => $refresh_token,
            'refresh_token_expire_at' => now()->addYear(),
        ];

        $response = $yt->saveAccount($guard, $mediaPlatform, $accountInfo, $account_type, $is_official, $dbId);

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

        $apiUrl = self::getApiUrl('videos', [
            'part' => 'snippet,statistics',
            'id' => $videoId,
        ], $configuration);

        return Http::withToken($token)->get($apiUrl);
    }



    /**
     * account connecton
     *
     * @param MediaPlatform $platform
     * @param array $request
     * @param string $guard
     * @return array
     */
    public function youtube(mixed $platform, array $request, string $guard = 'admin'): array
    {
        $responseStatus = social_poster_response_status(social_poster_trans('Authentication failed incorrect keys'), 'error');

        try {
            $accountId = Arr::get($request, 'account_id', null);

            $responseStatus = social_poster_response_status(social_poster_trans('Api error'), 'error');
            $client_id = Arr::get($request, 'client_id', $platform->configuration->client_id);
            $client_secret = Arr::get($request, 'client_secret', $platform->configuration->client_secret);
            $access_token = Arr::get($request, 'access_token', null);
            $refresh_token = Arr::get($request, 'refresh_token', null);

            if (!$access_token) {
                throw new \Exception('No access token provided');
            }

            $config = [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
            ];

            $response = $this->getAccount($access_token, $platform);

            if ($response->successful()) {
                $channelData = $response->json('items.0');

                if ($channelData && isset($channelData['id'])) {
                    $responseStatus = social_poster_response_status(social_poster_trans('Account Created'));
                    $config = array_merge($config, $channelData);

                    $config['link'] = "https://www.youtube.com/channel/" . Arr::get($channelData, 'id');
                    $config['avatar'] = Arr::get($channelData, 'snippet.thumbnails.default.url');
                    $config['account_id'] = Arr::get($channelData, 'id');

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
            $responseStatus = social_poster_response_status(social_poster_trans($ex->getMessage()), 'error');
            \Log::error('YouTube Auth Error: ' . $ex->getMessage());
        }

        return $responseStatus;
    }




    public function send(SocialPost $post): array
    {
        try {
            $status = false;
            $message = 'Failed to post to YouTube!!! Configuration error';

            $account        = $post->account;
            $accountToken   = $account->token;
            $platform       = @$account?->platform;

            if (!$platform) {
                throw new \Exception('No platform associated with account');
            }

            $configuration = $platform->configuration;
            $postDescription = $post->content ?: 'Test Video ' . time();
            $isShorts = $post->post_type === PostType::SHORTS->value;

            if ($post->link) {
                $postDescription .= "\n" . $post->link;
            }

            if ($isShorts) {
                // Shorts
                $title = substr($postDescription, 0, 97) . '...';
                $description = $postDescription . "\n#Shorts";
                if (strlen($description) > 5000) {
                    $description = substr($description, 0, 4997) . '...';
                }
            } else {
                // Regular video
                if (strlen($postDescription) > 100) {
                    $title = substr($postDescription, 0, 97) . '...';
                    $description = $postDescription;
                } else {
                    $title = $postDescription;
                    $description = $postDescription;
                }
                if (strlen($description) > 5000) {
                    $description = substr($description, 0, 4997) . '...';
                }
            }


            if (!$post->file || $post->file->count() === 0) {
                return [
                    'status' => false,
                    'response' => 'YouTube requires a video file'
                ];
            }

            $file = $post->file->first();
            $filePath = public_path(filePath($file, "post"));

            if (!file_exists($filePath)) {
                throw new \Exception('Video file not found: ' . $filePath);
            }

            $mimeType = mime_content_type($filePath);
            $allowedMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-m4v', 'video/mpeg', 'video/3gpp'];
            if (!in_array($mimeType, $allowedMimes)) {
                throw new \Exception('Invalid video format: ' . $mimeType . '. YouTube requires MP4, MOV, AVI, WebM, or similar formats.');
            }

            $apiUrl = self::getApiUrl('videos', ['part' => 'snippet,status'], $configuration, false, true); // Use UPLOAD_URL

            $payload = [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                    'categoryId' => '22',       // People & Blogs (default)
                    'tags' => $isShorts ? ['Shorts'] : [],
                ],
                'status' => [
                    'privacyStatus' => 'public',
                ]
            ];

            $response = Http::withToken($accountToken)
                          ->attach(
                              'metadata',
                              json_encode($payload),
                              null,
                              ['Content-Type' => 'application/json']
                          )
                          ->attach(
                              'video',
                              file_get_contents($filePath),
                              basename($filePath),
                              ['Content-Type' => $mimeType]
                          )
                          ->post($apiUrl);

            $responseJson = $response->json();

            if ($response->successful() && isset($responseJson['id'])) {
                $videoId = $responseJson['id'];
                return [
                    'status' => true,
                    'response' => social_poster_trans($isShorts ? "Short posted successfully to YouTube" : "Video posted successfully to YouTube"),
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                    'post_id' => $videoId,
                    'video_id' => $videoId,
                ];
            }

            // Handle quota exceeded error
            $errorReason = $responseJson['error']['errors'][0]['reason'] ?? null;
            if ($errorReason === 'quotaExceeded') {
                return [
                    'status' => false,
                    'response' => social_poster_trans('YouTube API quota exceeded. Please try again tomorrow.'),
                    'url' => null
                ];
            }

            return [
                'status' => false,
                'response' => @$responseJson['error']['message'] ?? 'Failed to publish video',
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
            $token = $account->token;

            // Step 1: Get uploads playlist ID
            $channelUrl = self::getApiUrl('channels', [
                'part' => 'contentDetails',
                'mine' => 'true',
            ], $account->platform->configuration);

            $channelResponse = Http::withToken($token)->get($channelUrl);
            $channelData = $channelResponse->json();

            if (!$channelResponse->successful() || !isset($channelData['items'][0])) {
                $this->disConnectAccount($account);
                return [
                    'status' => false,
                    'message' => $channelData['error']['message'] ?? 'Failed to fetch channel data'
                ];
            }

            $uploadsPlaylistId = $channelData['items'][0]['contentDetails']['relatedPlaylists']['uploads'];

            // Step 2: Fetch videos
            $apiUrl = self::getApiUrl('playlistItems', [
                'part' => 'snippet',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 20,
            ], $account->platform->configuration);

            $response = Http::withToken($token)->get($apiUrl);
            $apiResponse = $response->json();

            if (!$response->successful()) {
                $this->disConnectAccount($account);
                return [
                    'status' => false,
                    'message' => $apiResponse['error']['message'] ?? 'Failed to fetch video list'
                ];
            }

            // Optional: Fetch stats for likes and comments
            $videoIds = array_map(fn($item) => $item['snippet']['resourceId']['videoId'], $apiResponse['items']);
            $statsUrl = self::getApiUrl('videos', [
                'part' => 'statistics',
                'id' => implode(',', $videoIds),
            ], $account->platform->configuration);
            $statsResponse = Http::withToken($token)->get($statsUrl);
            $statsMap = array_column($statsResponse->json('items', []), null, 'id');


            $formattedResponse = $this->formatResponse($apiResponse, $statsMap);

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

    protected function formatResponse(array $response, array $statsMap = []): array
    {
        if (!isset($response['items']) || !is_array($response['items'])) {
            return [
                'data' => [],
            ];
        }

        $formattedData = array_map(function ($item) use ($statsMap) {
            $snippet = $item['snippet'];
            $videoId = $snippet['resourceId']['videoId'];
            $stats = $statsMap[$videoId] ?? [];

            return [
                'full_picture' => $snippet['thumbnails']['default']['url'] ?? get_default_img(),
                'message' => $snippet['description'] ?? $snippet['title'] ?? '',
                'created_time' => $snippet['publishedAt'] ?? \Carbon\Carbon::now()->timestamp,
                'reactions' => [
                    'summary' => [
                        'total_count' => $stats['statistics']['likeCount'] ?? 0,
                    ],
                ],
                'comments' => [
                    'summary' => [
                        'total_count' => $stats['statistics']['commentCount'] ?? 0,
                    ],
                ],
                'views' => [
                    'count' => $stats['statistics']['viewCount'] ?? 0,
                ],
                'permalink_url' => "https://www.youtube.com/watch?v={$videoId}",
                'privacy' => [
                    'value' => 'EVERYONE',
                ],
                'type' => 'video',
            ];
        }, $response['items']);

        return [
            'data' => $formattedData,
        ];
    }


    /**
     * Get insights/analytics for a YouTube video
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

            // YouTube API: videos.list endpoint
            $apiUrl = "https://www.googleapis.com/youtube/v3/videos";
            $params = [
                'id' => $post->platform_post_id,
                'part' => 'statistics',
            ];

            $response = Http::withToken($token)->get($apiUrl, $params);
            $data = $response->json();

            if ($response->failed() || isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'API request failed';
                return [
                    'status' => false,
                    'message' => $errorMsg,
                    'metrics' => [],
                ];
            }

            $stats = $data['items'][0]['statistics'] ?? [];

            // YouTube provides viewCount which we use for both impressions and reach
            $viewCount = (int) ($stats['viewCount'] ?? 0);
            $likeCount = (int) ($stats['likeCount'] ?? 0);
            $commentCount = (int) ($stats['commentCount'] ?? 0);

            $metrics = [
                'impressions' => $viewCount,
                'engagements' => $likeCount + $commentCount,
                'likes' => $likeCount,
                'comments' => $commentCount,
                'shares' => 0, // YouTube doesn't expose share count via API
                'reactions' => $likeCount,
                'reach' => $viewCount,
            ];

            return [
                'status' => true,
                'message' => 'Metrics fetched successfully',
                'metrics' => $metrics,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error fetching YouTube metrics: ' . $e->getMessage(),
                'metrics' => [],
            ];
        }
    }
}
