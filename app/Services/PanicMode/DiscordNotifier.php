<?php

namespace Pterodactyl\Services\PanicMode;

use Pterodactyl\Models\PanicModeSetting;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DiscordNotifier
{
    /**
     * Send a Panic Mode alert to Discord for a server exceeding bandwidth threshold.
     *
     * @param Server $server
     * @param float $currentBandwidthMbps
     * @return bool
     */
    public function sendBandwidthAlert(Server $server, float $currentBandwidthMbps): bool
    {
        if (!PanicModeSetting::isPanicModeEnabled()) {
            return false;
        }

        $webhookUrl = PanicModeSetting::getSetting('discord_webhook_url');
        if (empty($webhookUrl)) {
            return false;
        }
        $cacheKey = 'panic_mode_cooldown_' . $server->id;
        if (Cache::has($cacheKey)) {
            return false;
        }
        $cooldownMinutes = PanicModeSetting::getCooldownMinutes();
        Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));

        try {
            $threshold = PanicModeSetting::getBandwidthThreshold();
            $embedTitle = PanicModeSetting::getEmbedTitle();
            $embedDescription = PanicModeSetting::getEmbedDescription() . " of {$threshold} Mbps";
            $embedColor = PanicModeSetting::getEmbedColor();
            $placeholders = [
                '{SERVER_NAME}' => $server->name,
                '{SERVER_UUID}' => $server->uuid,
                '{SERVER_ID}' => $server->id,
                '{SERVER_IP}' => $server->allocation->ip,
                '{SERVER_PORT}' => $server->allocation->port,
                '{SERVER_IP_PORT}' => $server->allocation->ip . ':' . $server->allocation->port,
                '{BANDWIDTH}' => number_format($currentBandwidthMbps, 2),
                '{THRESHOLD}' => $threshold,
            ];
            
            $embedTitle = str_replace(array_keys($placeholders), array_values($placeholders), $embedTitle);
            $embedDescription = str_replace(array_keys($placeholders), array_values($placeholders), $embedDescription);
            $embed = [
                'title' => $embedTitle,
                'description' => $embedDescription,
                'color' => $embedColor,
                'fields' => [
                    [
                        'name' => 'Server',
                        'value' => $server->name,
                        'inline' => true
                    ],
                    [
                        'name' => 'Server ID',
                        'value' => $server->id,
                        'inline' => true
                    ],
                    [
                        'name' => 'Node',
                        'value' => $server->node->name ?? 'Unknown',
                        'inline' => true
                    ],
                    [
                        'name' => 'Current Bandwidth',
                        'value' => number_format($currentBandwidthMbps, 2) . ' Mbps',
                        'inline' => true
                    ],
                    [
                        'name' => 'Threshold',
                        'value' => $threshold . ' Mbps',
                        'inline' => true
                    ],
                    [
                        'name' => 'Time',
                        'value' => now()->format('Y-m-d H:i:s'),
                        'inline' => true
                    ]
                ],
                'footer' => [
                    'text' => 'Pterodactyl Panic Mode Alert'
                ],
                'timestamp' => now()->toIso8601String()
            ];

            $response = Http::post($webhookUrl, [
                'embeds' => [$embed]
            ]);

            if ($response->successful()) {
                return true;
            } else {
                Log::error('Failed to send Panic Mode alert: ' . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Error sending Panic Mode alert: ' . $e->getMessage());
            return false;
        }
    }
}
