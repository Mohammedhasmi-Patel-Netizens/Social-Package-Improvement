<?php

namespace BeePost\SocialPoster\Models;

use BeePost\SocialPoster\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'account_information' => 'encrypted:object',
        'token'               => 'encrypted',
        'refresh_token'       => 'encrypted',
    ];


    protected static function booted(){
        static::creating(function (Model $model) {
            $model->uid        = Str::uuid();
        });
    }


    /**
     * Admin where account belongs
     *
     * @return BelongsTo
     */
    public function admin(): BelongsTo{
        return $this->belongsTo(config('social-poster.models.admin', \App\Models\Admin::class), "admin_id");
    }



    /**
     * User where account belongs
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo{
        return $this->belongsTo(config('social-poster.models.user', \App\Models\User::class), "user_id");
    }


    /**
     * Platform where account belongs
     *
     * @return BelongsTo
     */
    public function platform(): BelongsTo{
        return $this->belongsTo(config('social-poster.models.platform', \App\Models\MediaPlatform::class), "platform_id");
    }


    /**
     * subscription where account belongs
     *
     * @return BelongsTo
     */
    public function subscription(): BelongsTo{  
        return $this->belongsTo(config('social-poster.models.subscription', \App\Models\Subscription::class), "subscription_id");
    }


    /**
     * Get social post
     *
     * @return HasMany
     */
    public function posts(): HasMany{
        return $this->hasMany(SocialPost::class,"account_id");
    }
    

    public function scopeActive(Builder $q) :Builder{
        return $q->where('status',StatusEnum::true->status());
    }
    public function scopeInactive(Builder $q) :Builder{
        return $q->where('status',StatusEnum::false->status());
    }


    public function scopeConnected(Builder $q) :Builder{
        return $q->where('is_connected',StatusEnum::true->status());
    }

    
}
