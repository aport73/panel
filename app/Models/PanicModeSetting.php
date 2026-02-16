<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PanicModeSetting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'panic_mode_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['key', 'value', 'description'];

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getSetting(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }
        if ($key === 'discord_webhook_url' && !empty($setting->value)) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (\Exception $e) {
                return '';
            }
        }
        
        return $setting->value;
    }

    /**
     * Set a setting value by key.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function setSetting(string $key, $value)
    {
        if ($key === 'discord_webhook_url' && !empty($value)) {
            $value = Crypt::encryptString($value);
        }
        
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        )->exists;
    }

    /**
     * Check if Panic Mode is enabled.
     *
     * @return bool
     */
    public static function isPanicModeEnabled()
    {
        return (bool) self::getSetting('enabled', false);
    }

    /**
     * Get the bandwidth threshold in Mbps.
     *
     * @return float
     */
    public static function getBandwidthThreshold()
    {
        return (float) self::getSetting('bandwidth_threshold_mbps', 1);
    }

    /**
     * Get the cooldown period in minutes.
     *
     * @return int
     */
    public static function getCooldownMinutes()
    {
        return (int) self::getSetting('cooldown_minutes', 15);
    }
    
    /**
     * Get the embed color in decimal format.
     *
     * @return int
     */
    public static function getEmbedColor()
    {
        return (int) self::getSetting('embed_color', 16711680); 
    }
    
    /**
     * Get the embed title.
     *
     * @return string
     */
    public static function getEmbedTitle()
    {
        return self::getSetting('embed_title', '🚨 PANIC MODE ALERT: High Bandwidth Usage');
    }
    
    /**
     * Get the embed description.
     *
     * @return string
     */
    public static function getEmbedDescription()
    {
        return self::getSetting('embed_description', 'A server has exceeded the bandwidth threshold');
    }
}
