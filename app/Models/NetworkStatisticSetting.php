<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkStatisticSetting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'network_statistics_settings';

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
        
        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function setSetting(string $key, $value)
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        )->exists;
    }

    /**
     * Get the collection interval in seconds.
     *
     * @return int
     */
    public static function getCollectionInterval()
    {
        return (int) self::getSetting('collection_interval', 60);
    }

    /**
     * Get the retention period in hours.
     *
     * @return int
     */
    public static function getRetentionHours()
    {
        return (int) self::getSetting('retention_hours', 24);
    }

    /**
     * Get available collection interval options.
     *
     * @return array
     */
    public static function getCollectionIntervalOptions()
    {
        return [
            60 => '1 Minute',
            120 => '2 Minutes',
            300 => '5 Minutes',
            600 => '10 Minutes',
            900 => '15 Minutes',
        ];
    }

    /**
     * Get available retention period options.
     *
     * @return array
     */
    public static function getRetentionOptions()
    {
        return [
            12 => '12 Hours',
            24 => '1 Day',
            48 => '2 Days',
            72 => '3 Days',
            168 => '7 Days',
        ];
    }
}
