<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\PanicModeSetting;
use Illuminate\Support\Facades\Log;

class PanicModeController extends Controller
{
    /**
     * Display the Panic Mode settings page.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $settings = [
            'discord_webhook_url' => PanicModeSetting::getSetting('discord_webhook_url', ''),
            'bandwidth_threshold_mbps' => PanicModeSetting::getSetting('bandwidth_threshold_mbps', 1),
            'enabled' => (bool) PanicModeSetting::getSetting('enabled', false),
            'cooldown_minutes' => PanicModeSetting::getSetting('cooldown_minutes', 15),
            'embed_color' => PanicModeSetting::getSetting('embed_color', 16711680),
            'embed_title' => PanicModeSetting::getSetting('embed_title', '🚨 PANIC MODE ALERT: High Bandwidth Usage'),
            'embed_description' => PanicModeSetting::getSetting('embed_description', 'A server has exceeded the bandwidth threshold'),
        ];

        return view('admin.panicmode.index', [
            'settings' => $settings,
        ]);
    }

    /**
     * Update the Panic Mode settings.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'discord_webhook_url' => 'nullable|url',
            'bandwidth_threshold_mbps' => 'required|numeric|min:0.01',
            'enabled' => 'sometimes|boolean',
            'cooldown_minutes' => 'required|integer|min:0|max:1440',
            'embed_color' => 'required|integer|min:0',
            'embed_title' => 'required|string|max:255',
            'embed_description' => 'required|string|max:1024',
        ]);

        try {
            PanicModeSetting::setSetting('discord_webhook_url', $validated['discord_webhook_url'] ?? '');
            PanicModeSetting::setSetting('bandwidth_threshold_mbps', $validated['bandwidth_threshold_mbps']);
            PanicModeSetting::setSetting('enabled', (isset($validated['enabled']) && $validated['enabled'] == 1) ? 1 : 0);
            PanicModeSetting::setSetting('cooldown_minutes', $validated['cooldown_minutes']);
            PanicModeSetting::setSetting('embed_color', $validated['embed_color']);
            PanicModeSetting::setSetting('embed_title', $validated['embed_title']);
            PanicModeSetting::setSetting('embed_description', $validated['embed_description']);

            return redirect()->route('admin.panicmode')->with('success', 'Panic Mode settings have been updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update Panic Mode settings: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to update Panic Mode settings: ' . $e->getMessage());
        }
    }

    /**
     * Test the Discord webhook.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testWebhook()
    {
        $webhookUrl = PanicModeSetting::getSetting('discord_webhook_url');
        
        if (empty($webhookUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'No Discord webhook URL is configured.',
            ]);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::post($webhookUrl, [
                'embeds' => [
                    [
                        'title' => '🔔 Panic Mode Test Alert',
                        'description' => 'This is a test alert from the Pterodactyl Panic Mode system.',
                        'color' => 3447003,
                        'fields' => [
                            [
                                'name' => 'Status',
                                'value' => 'This is a test message. If you are seeing this, your webhook is configured correctly.',
                                'inline' => false,
                            ],
                            [
                                'name' => 'Time',
                                'value' => now()->format('Y-m-d H:i:s'),
                                'inline' => true,
                            ],
                        ],
                        'footer' => [
                            'text' => 'Pterodactyl Panic Mode Test',
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test message sent successfully to Discord webhook.',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test message: ' . $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send test webhook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test message: ' . $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Run a manual bandwidth check.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function manualCheck()
    {
        try {
            $bandwidthMonitor = app()->make('Pterodactyl\Services\PanicMode\BandwidthMonitor');
            $result = $bandwidthMonitor->checkAllServers();
            
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'alerts_sent' => $result['alerts_sent'],
                'servers_exceeding_threshold' => $result['servers_exceeding_threshold'] ?? [],
            ]);
        } catch (\Exception $e) {
            Log::error('Error running manual bandwidth check: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error running bandwidth check: ' . $e->getMessage(),
            ]);
        }
    }
}
