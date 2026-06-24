<?php

namespace BeePost\SocialPoster\Services\Account\twitter;

use BeePost\SocialPoster\Enums\ConnectionType;
use BeePost\SocialPoster\Traits\AccountManager;
use BeePost\SocialPoster\Enums\AccountType;
use BeePost\SocialPoster\Models\SocialAccount;
use BeePost\SocialPoster\Models\SocialPost;
use BeePost\SocialPoster\Contracts\PlatformAccountInterface;
use Illuminate\Support\Arr;
use Coderjerk\BirdElephant\BirdElephant;
use Illuminate\Support\Facades\Http;
use Abraham\TwitterOAuth\TwitterOAuth;
use Coderjerk\BirdElephant\Compose\Tweet;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;


/* 
    24 June 2026

    This class is Implement The PlatformAccountInterface & there is no implementation of the method : `getScopes`
        so we need to implement the method `getScopes` in this class to avoid the error.

*/

class Account implements PlatformAccountInterface
{
    use AccountManager;

    public $twUrl, $params;


    const BASE_URL = 'https://x.com';
    const API_URL = 'https://api.x.com/2';
    // const UPLOAD_URL = 'https://api.x.com/2';
    const UPLOAD_URL = 'https://upload.twitter.com/1.1';


    public function __construct()
    {
        $this->twUrl = "https://x.com/";

        $this->params = [
            'expansions' => 'pinned_tweet_id',
            'user.fields' => 'id,name,url,verified,username,profile_image_url'
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
        $redirect_uri = url('/account/twitter/callback?medium=' . $mediaPlatform->slug);

        $scope = 'tweet.read tweet.write users.read offline.access media.write';
        
        $codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state         = bin2hex(random_bytes(16));

        // Store verifier in cache keyed by state for callback validation
        cache()->put('sp_twitter_pkce_' . $state, $codeVerifier, now()->addMinutes(15));
        session(['twitter_oauth_state' => $state]);

        return "https://x.com/i/oauth2/authorize?response_type=code&client_id=$client_id&redirect_uri=$redirect_uri&scope=$scope&state=$state&code_challenge=$codeChallenge&code_challenge_method=S256";

    }



    /**
     * Summary of getApiUrl
     * @param string $endpoint
     * @param array $params
     * @param mixed $configuration
     * @param bool $isBaseUrl
     * @return string
     */
    public static function getApiUrl(string $endpoint, array $params = [], mixed $configuration, bool $isBaseUrl = false, bool $isUploadUrl = false): string
    {
        // API_URL and UPLOAD_URL already contain /2 version, so don't add version again
        // BASE_URL (x.com) doesn't need version
        $apiUrl = $isUploadUrl ? self::UPLOAD_URL : ($isBaseUrl ? self::BASE_URL : self::API_URL);

        if (str_starts_with($endpoint, '/')) {
            $endpoint = substr($endpoint, 1);
        }

        $url = $apiUrl . '/' . $endpoint;

        if (count($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }





    public static function getAccessToken(string $code, mixed $mediaPlatform)
    {
        $configuration = $mediaPlatform->configuration;

        $client_id = $configuration->client_id;
        $client_secret = $configuration->client_secret;

        $state = request('state');
        $codeVerifier = cache()->pull('sp_twitter_pkce_' . $state);
        if (! $codeVerifier || $state !== session('twitter_oauth_state')) {
            abort(403, 'Invalid OAuth state or PKCE verifier');
        }

        $apiUrl = self::getApiUrl('oauth2/token', [], $configuration);

        $basicAuthCredential = base64_encode($client_id . ':' . $client_secret);

        return Http::withHeaders([
            'Authorization' => "Basic $basicAuthCredential",
            'Content-Type' => 'application/x-www-form-urlencoded'
        ])->asForm()->post($apiUrl, [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'redirect_uri' => url('/account/twitter/callback?medium=' . $mediaPlatform->slug),
            'code_verifier' => $codeVerifier,
        ]);
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
        $basicAuthCredential = base64_encode($client_id . ':' . $client_secret);

        $apiUrl = self::getApiUrl('oauth2/token', [], $configuration);

        return Http::withHeaders([
            'Authorization' => "Basic $basicAuthCredential",
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
        ])->asForm()->post($apiUrl, [
            'refresh_token' => $token,
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
        ]);
    }





    /**
     * Summary of getAcccount
     * @return \Illuminate\Http\Client\Response
     */
    public function getAcccount(string $token, mixed $mediaPlatform): \Illuminate\Http\Client\Response
    {
        return Http::withToken($token)->get('https://api.x.com/2/users/me', [
            'user.fields' => 'name,profile_image_url,username'
        ]);
    }





    /**
     * Summary of saveTwAccount
     * @param mixed $responseData
     * @param string $guard
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @param string $account_type
     * @param string $is_official
     * @param int|string $dbId
     * @return void
     */
    public static function saveTwAccount(
        mixed $responseData,
        string $guard,
        mixed $mediaPlatform,
        string $account_type,
        string $is_official,
        int|string $dbId = null
    ) {
        $tw = new self();

        // Handle both Response object and array
        if ($responseData instanceof \Illuminate\Http\Client\Response) {
            $responseData = $responseData->json();
        }

        $expireIn       = Arr::get($responseData, 'expires_in');
        $token          = Arr::get($responseData, 'access_token');
        $refresh_token  = Arr::get($responseData, 'refresh_token');

        $response = $tw->getAcccount($token, $mediaPlatform);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch user info: ' . $response->body());
        }
        
        $user = $response->json('data');

        $accountInfo = [
            'id' => $user['id'],
            'account_id' => $user['id'],
            'name' => Arr::get($user, 'name', null),
            'avatar' => Arr::get($user, 'profile_image_url'),
            'email' => Arr::get($user, 'email'),
            'token' => $token,
            'access_token_expire_at' => now()->addSeconds($expireIn),
            'refresh_token' => $refresh_token,
            'refresh_token_expire_at' => now()->addMonths(6),
        ];

        $response = $tw->saveAccount($guard, $mediaPlatform, $accountInfo, $account_type, $is_official, $dbId);
        
    }






    /**
     * Summary of getPost
     * @param string $tweetId
     * @param string $token
     * @param \App\Models\MediaPlatform $mediaPlatform
     * @return \Illuminate\Http\Client\Response
     */
    public static function getPost(string $tweetId, string $token, mixed $mediaPlatform): \Illuminate\Http\Client\Response
    {

        $configuration = $mediaPlatform->configuration;

        $apiUrl = self::getApiUrl("tweets/{$tweetId}", [
            'tweet.fields' => 'public_metrics,organic_metrics,non_public_metrics'
        ], $configuration);

        return Http::withToken($token)->post($apiUrl);
    }


















    /**
     * Instagram account connecton
     *
     * @param MediaPlatform $platform
     * @param array $request
     * @param string $guard
     * @return array
     */
    public function twitter(mixed $platform, array $request, string $guard = 'admin'): array
    {


        $responseStatus = social_poster_response_status(social_poster_trans('Authentication failed incorrect keys'), 'error');

        try {

            $accountId = Arr::get($request, 'account_id', null);

            $responseStatus = social_poster_response_status(social_poster_trans('Api error'), 'error');
            $consumer_key = Arr::get($request, 'consumer_key', null);
            $consumer_secret = Arr::get($request, 'consumer_secret', null);
            $access_token = Arr::get($request, 'access_token', null);
            $token_secret = Arr::get($request, 'token_secret', null);
            $bearer_token = Arr::get($request, 'bearer_token', null);

            $config = array(
                'consumer_key' => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'bearer_token' => $bearer_token,
                'token_identifier' => $access_token,
                'token_secret' => $token_secret
            );


            $twitter = new BirdElephant($config);

            $response = $twitter->me()->myself([
                'expansions' => 'pinned_tweet_id',
                'user.fields' => 'id,name,url,verified,username,profile_image_url'
            ]);

            if ($response->data && $response->data->id) {
                $responseStatus = social_poster_response_status(social_poster_trans('Account Created'));
                $config = array_merge($config, (array) $response->data);

                $config['link'] = $this->twUrl . Arr::get($config, 'username');
                $config['avatar'] = Arr::get($config, 'profile_image_url');

                $config['account_id'] = Arr::get($config, 'id');

                $response = $this->saveAccount($guard, $platform, $config, AccountType::PROFILE->value, ConnectionType::OFFICIAL->value, $accountId);
            }



        } catch (\Exception $ex) {

        }


        return $responseStatus;


    }

    public function send(SocialPost $post): array
    {
        try {
            $account = $post->account;
            $accountToken = $account->token;
            $platform = @$account?->platform;
            $configuration = $platform->configuration;

            $tweetFeed = '';
            if ($post->content)
                $tweetFeed .= $post->content;
            if ($post->link)
                $tweetFeed .= $post->link;

            $mediaIds = [];

            $uploadBaseUrl = self::UPLOAD_URL . '/media/upload'; // https://api.x.com/2/media/upload
            $apiUrl = self::getApiUrl('tweets', [], $configuration);

            if ($post->file && $post->file->count() > 0) {
                foreach ($post->file as $key => $file) {
                    $fileURL = imageURL($file, "post", true);

                    // Validate file URL
                    if (!$fileURL || !is_string($fileURL)) {
                        continue;
                    }

                    // Determine if file is remote
                    $isRemote = str_starts_with($fileURL, 'http');

                    // Get MIME type
                    $extension = strtolower(pathinfo($fileURL, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'mp4' => 'video/mp4',
                        'mov' => 'video/quicktime',
                    ];
                    $mime = $mimeMap[$extension] ?? 'application/octet-stream';

                    // For videos, validate MIME type with File::mimeType
                    $tempPath = null;
                    if (isValidVideoUrl($fileURL)) {
                        if ($isRemote) {
                            // Ensure public temp directory exists
                            $tempDir = public_path('temp');
                            if (!file_exists($tempDir)) {
                                mkdir($tempDir, 0755, true);
                            }
                            
                            // Download to public temp file for MIME detection
                            $tempPath = $tempDir . '/' . uniqid() . '_' . basename($fileURL);
                            $content = @file_get_contents($fileURL);
                            if ($content === false || file_put_contents($tempPath, $content) === false) {
                                Log::error('[Twitter/X] Failed to save temp file', [
                                    'temp_path' => $tempPath,
                                    'temp_dir_exists' => file_exists($tempDir),
                                    'temp_dir_writable' => is_writable($tempDir),
                                ]);
                                continue;
                            }
                            $fileURL = $tempPath; // Use temp path for MIME detection
                        }

                        try {
                            $mime = File::mimeType($fileURL);
                            if (!$mime) {
                                $mime = $mimeMap[$extension] ?? 'video/mp4';
                            }
                        } catch (\Exception $e) {
                            $mime = $mimeMap[$extension] ?? 'video/mp4';
                        }
                    }

                    // Fetch content for upload
                    $content = $isRemote ? @file_get_contents($fileURL) : file_get_contents($fileURL);
                    if ($content === false) {
                        if ($tempPath && file_exists($tempPath))
                            unlink($tempPath);
                        continue;
                    }

                    $size = strlen($content);
                    if ($size === 0) {
                        if ($tempPath && file_exists($tempPath))
                            unlink($tempPath);
                        continue;
                    }

                    $filename = basename($fileURL);

                    // X allows only 1 video per tweet
                    if (isValidVideoUrl($fileURL) && $key > 0) {
                        if ($tempPath && file_exists($tempPath))
                            unlink($tempPath);
                        continue;
                    }

                    if (isValidVideoUrl($fileURL)) {
                        // ===== VIDEO UPLOAD using X API v2 query parameters =====
                        $mediaCategory = 'tweet_video';
                        
                        // STEP 1: INIT
                        // POST https://api.x.com/2/media/upload?command=INIT&media_type=video/mp4&media_category=tweet_video&total_bytes=62835744
                        $initResponse = Http::withToken($accountToken)
                            ->asForm()
                            ->post($uploadBaseUrl, [
                                'command' => 'INIT',
                                'media_type' => $mime,
                                'media_category' => $mediaCategory,
                                'total_bytes' => $size,
                            ]);
                        
                        if ($initResponse->failed()) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Video INIT failed: ' . $initResponse->body());
                        }
                        
                        $mediaId = $initResponse->json()['data']['id'] ?? null;
                        if (!$mediaId) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Missing media_id in INIT response');
                        }
                        
                        // STEP 2: APPEND chunks
                        // POST https://api.x.com/2/media/upload?command=APPEND&media_id=XXX&segment_index=0
                        $chunkSize = 5 * 1024 * 1024; // 5MB chunks
                        $offset = 0;
                        $segmentIndex = 0;
                        
                        while ($offset < $size) {
                            $chunk = substr($content, $offset, $chunkSize);
                            
                            $appendResponse = Http::withToken($accountToken)
                                ->attach('media', $chunk, 'video_chunk_' . $segmentIndex)
                                ->post($uploadBaseUrl, [
                                    'command' => 'APPEND',
                                    'media_id' => $mediaId,
                                    'segment_index' => $segmentIndex,
                                ]);
                            
                            if ($appendResponse->failed()) {
                                if ($tempPath && file_exists($tempPath))
                                    unlink($tempPath);
                                throw new \Exception('Video APPEND failed at segment ' . $segmentIndex . ': ' . $appendResponse->body());
                            }
                            
                            $offset += $chunkSize;
                            $segmentIndex++;
                        }
                        
                        // STEP 3: FINALIZE
                        // POST https://api.x.com/2/media/upload?command=FINALIZE&media_id=XXX
                        $finalizeResponse = Http::withToken($accountToken)
                            ->asForm()
                            ->post($uploadBaseUrl, [
                                'command' => 'FINALIZE',
                                'media_id' => $mediaId,
                            ]);
                        
                        if ($finalizeResponse->failed()) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Video FINALIZE failed: ' . $finalizeResponse->body());
                        }
                        
                        $finalizeData = $finalizeResponse->json()['data'] ?? [];
                        
                        // STEP 4: STATUS check for async video processing
                        // GET https://api.x.com/2/media/upload?command=STATUS&media_id=XXX
                        if (isset($finalizeData['processing_info'])) {
                            $processingInfo = $finalizeData['processing_info'];
                            $checkAfter = $processingInfo['check_after_secs'] ?? 1;
                            sleep($checkAfter);
                            
                            $maxAttempts = 20;
                            $attempt = 0;
                            
                            while ($attempt < $maxAttempts) {
                                $statusResponse = Http::withToken($accountToken)
                                    ->get($uploadBaseUrl, [
                                        'command' => 'STATUS',
                                        'media_id' => $mediaId,
                                    ]);
                                
                                if ($statusResponse->failed()) {
                                    if ($tempPath && file_exists($tempPath))
                                        unlink($tempPath);
                                    throw new \Exception('Video STATUS check failed: ' . $statusResponse->body());
                                }
                                
                                $statusData = $statusResponse->json()['data'] ?? [];
                                $processingInfo = $statusData['processing_info'] ?? null;
                                
                                if (!$processingInfo) {
                                    // No processing_info means media is ready
                                    break;
                                }
                                
                                $state = $processingInfo['state'] ?? 'unknown';
                                
                                if ($state === 'succeeded') {
                                    break;
                                } elseif ($state === 'failed') {
                                    $errorMsg = $processingInfo['error']['message'] ?? 'Unknown processing error';
                                    if ($tempPath && file_exists($tempPath))
                                        unlink($tempPath);
                                    throw new \Exception('Video processing failed: ' . $errorMsg);
                                } elseif ($state === 'in_progress' || $state === 'pending') {
                                    $checkAfter = $processingInfo['check_after_secs'] ?? 5;
                                    sleep($checkAfter);
                                    $attempt++;
                                } else {
                                    if ($tempPath && file_exists($tempPath))
                                        unlink($tempPath);
                                    throw new \Exception('Unknown processing state: ' . $state);
                                }
                            }
                            
                            if ($attempt >= $maxAttempts) {
                                if ($tempPath && file_exists($tempPath))
                                    unlink($tempPath);
                                throw new \Exception('Video processing timeout after ' . $maxAttempts . ' attempts');
                            }
                        }
                        
                        $mediaIds[] = $mediaId;
                        
                        // Clean up temp file
                        if ($tempPath && file_exists($tempPath)) {
                            unlink($tempPath);
                        }
                    }
                    else {
                        // ===== IMAGE UPLOAD using X API v2 query parameters =====
                        $mediaCategory = str_contains($mime, 'gif') ? 'tweet_gif' : 'tweet_image';

                        // STEP 1: INIT
                        // POST https://api.x.com/2/media/upload?command=INIT&media_type=image/jpeg&media_category=tweet_image&total_bytes=12345
                        $initResponse = Http::withToken($accountToken)
                            ->asForm()
                            ->post($uploadBaseUrl, [
                                'command' => 'INIT',
                                'media_type' => $mime,
                                'media_category' => $mediaCategory,
                                'total_bytes' => $size,
                            ]);

                        if ($initResponse->failed()) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Image INIT failed: ' . $initResponse->body());
                        }

                        $mediaId = $initResponse->json()['data']['id'] ?? null;
                        if (!$mediaId) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Missing media_id in INIT response');
                        }

                        // STEP 2: APPEND - single chunk for images
                        // POST https://api.x.com/2/media/upload?command=APPEND&media_id=XXX&segment_index=0
                        $appendResponse = Http::withToken($accountToken)
                            ->attach('media', $content, $filename)
                            ->post($uploadBaseUrl, [
                                'command' => 'APPEND',
                                'media_id' => $mediaId,
                                'segment_index' => 0,
                            ]);

                        if ($appendResponse->failed()) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Image APPEND failed: ' . $appendResponse->body());
                        }

                        // STEP 3: FINALIZE
                        // POST https://api.x.com/2/media/upload?command=FINALIZE&media_id=XXX
                        $finalizeResponse = Http::withToken($accountToken)
                            ->asForm()
                            ->post($uploadBaseUrl, [
                                'command' => 'FINALIZE',
                                'media_id' => $mediaId,
                            ]);

                        if ($finalizeResponse->failed()) {
                            if ($tempPath && file_exists($tempPath))
                                unlink($tempPath);
                            throw new \Exception('Image FINALIZE failed: ' . $finalizeResponse->body());
                        }

                        $mediaIds[] = $mediaId;
                    }

                    // Clean up temp file if used
                    if ($tempPath && file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }
            }

            // Post the tweet
            $postParams = ['text' => $tweetFeed];
            if (count($mediaIds) > 0) {
                $postParams['media'] = ['media_ids' => $mediaIds];
            }

            $response = Http::withToken($accountToken)
                ->post($apiUrl, $postParams);

            $responseJson = $response->json();

            if (isset($responseJson['data']['id'])) {
                $tweetId = $responseJson['data']['id'];
                // Build proper X.com URL with account username
                $username = $account->name ?? $account->account_id;
                return [
                    'status' => true,
                    'response' => social_poster_trans("Posted Successfully"),
                    'url' => "https://x.com/{$username}/status/{$tweetId}",
                    'post_id' => $tweetId,
                ];
            }

            // Handle rate limiting
            if ($response->status() === 429) {
                return [
                    'status' => false,
                    'response' => social_poster_trans('Rate limit exceeded. Please try again later.'),
                    'url' => null
                ];
            }

            return [
                'status' => false,
                'response' => $responseJson['detail'] ?? $responseJson['title'] ?? 'Failed to post',
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
     * Debug Twitter/X access token (similar to Facebook's /debug_token)
     * Validates the token and returns user details/scopes.
     */
    public function debugToken(SocialAccount $account): array
    {
        try {
            $accountToken = $account->token;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accountToken
            ])->get('https://api.twitter.com/2/users/me');

            $responseData = $response->json();

            

            if ($response->successful()) {
                return [
                    'status' => true,
                    'response' => $responseData,
                    'message' => 'Token is valid. User: ' . ($responseData['data']['username'] ?? 'Unknown')
                ];
            }


            return [
                'status' => false,
                'message' => $responseData['title'] ?? 'Invalid or unauthorized token',
                'error_details' => $responseData
            ];
        } catch (\Exception $ex) {
            return [
                'status' => false,
                'message' => strip_tags($ex->getMessage())
            ];
        }
    }


    /**
     * Get insights/analytics for a Twitter/X post
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

            // Request all available metrics: public, non_public, and organic
            $tweetFields = 'public_metrics,non_public_metrics,organic_metrics';
            $apiUrl = self::API_URL . "/tweets/{$post->platform_post_id}?tweet.fields={$tweetFields}";

            $response = Http::withToken($token)->get($apiUrl);
            $data = $response->json();

            // Handle rate limiting (429)
            if ($response->status() === 429) {
                $resetTime = $response->header('x-rate-limit-reset');
                $waitTime = $resetTime ? (int)$resetTime - time() : 60;
                return [
                    'status' => false,
                    'message' => "Rate limited. Try again in {$waitTime} seconds.",
                    'metrics' => [],
                    'rate_limited' => true,
                ];
            }

            if ($response->failed() || isset($data['errors'])) {
                // If non_public_metrics fails, fallback to public only
                $apiUrl = self::API_URL . "/tweets/{$post->platform_post_id}?tweet.fields=public_metrics";
                $response = Http::withToken($token)->get($apiUrl);
                $data = $response->json();

                if ($response->status() === 429) {
                    return [
                        'status' => false,
                        'message' => 'Rate limited by X API. Please try again later.',
                        'metrics' => [],
                        'rate_limited' => true,
                    ];
                }
            }

            if ($response->failed() || isset($data['errors'])) {
                $errorMsg = $data['errors'][0]['message'] ?? $data['detail'] ?? 'API request failed';
                return [
                    'status' => false,
                    'message' => $errorMsg,
                    'metrics' => [],
                ];
            }

            $tweetData = $data['data'] ?? [];
            $publicMetrics = $tweetData['public_metrics'] ?? [];
            $nonPublicMetrics = $tweetData['non_public_metrics'] ?? [];
            $organicMetrics = $tweetData['organic_metrics'] ?? [];

            // Prefer non_public_metrics for impressions (more accurate)
            $impressions = $nonPublicMetrics['impression_count']
                ?? $organicMetrics['impression_count']
                ?? $publicMetrics['impression_count']
                ?? 0;

            $likes = $organicMetrics['like_count'] ?? $publicMetrics['like_count'] ?? 0;
            $retweets = $organicMetrics['retweet_count'] ?? $publicMetrics['retweet_count'] ?? 0;
            $replies = $organicMetrics['reply_count'] ?? $publicMetrics['reply_count'] ?? 0;
            $quotes = $publicMetrics['quote_count'] ?? 0;

            $urlClicks = $nonPublicMetrics['url_link_clicks'] ?? $organicMetrics['url_link_clicks'] ?? 0;
            $profileClicks = $nonPublicMetrics['user_profile_clicks'] ?? $organicMetrics['user_profile_clicks'] ?? 0;

            $engagements = $likes + $retweets + $replies + $quotes + $urlClicks + $profileClicks;

            $metrics = [
                'impressions' => $impressions,
                'engagements' => $engagements,
                'likes' => $likes,
                'shares' => $retweets + $quotes,
                'comments' => $replies,
                'reactions' => $likes,
                'reach' => $impressions,
            ];

            return [
                'status' => true,
                'message' => 'Metrics fetched successfully',
                'metrics' => $metrics,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error fetching X metrics: ' . $e->getMessage(),
                'metrics' => [],
            ];
        }
    }

    public function accountDetails(SocialAccount $account): array
    {
        try {
            $token = $account->token;
            $userId = $account->account_id;

            $fields = 'created_at,text,public_metrics,organic_metrics,non_public_metrics,attachments,author_id,conversation_id,in_reply_to_user_id,referenced_tweets';

            $params = [
                'tweet.fields' => $fields,
                'expansions' => 'attachments.media_keys,author_id',
                'media.fields' => 'preview_image_url,url,type,width,height,duration_ms',
                'max_results' => 100,
                'exclude' => 'retweets,replies', 
            ];

            $apiUrl = self::getApiUrl("users/{$userId}/tweets", $params, $account->platform->configuration);

            $apiResponse = Http::withToken($token)->get($apiUrl);
            $apiResponse = $apiResponse->json();

            if (isset($apiResponse['error']) || $apiResponse['meta']['result_count'] ?? 0 === 0) {
               
                return [
                    'status' => false,
                    'message' => $apiResponse['error']['message'] ?? social_poster_trans('No recent posts or API error')
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

    public static function getScopes(string $type = 'auth'): array
    {
        return [];
    }
}
