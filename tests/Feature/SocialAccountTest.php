<?php

use BeePost\SocialPoster\Models\SocialAccount;

it('stores token encrypted', function () {
    $account = SocialAccount::create([
        'platform_id'  => 1,
        'name'         => 'Test Account',
        'token'        => 'secret-oauth-token',
        'account_type' => '0',
        'status'       => '1',
        'is_connected' => '1',
        'is_official'  => '1',
    ]);

    // Raw DB value must NOT equal the plain token
    $raw = DB::table('social_accounts')->where('id', $account->id)->value('token');
    expect($raw)->not->toBe('secret-oauth-token');

    // But reading via model must decrypt correctly
    expect($account->fresh()->token)->toBe('secret-oauth-token');
});
