<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\NetworkStatisticSetting;
use Pterodactyl\Models\StatisticsDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class NetworkSettingsController extends Controller
{
    /**
     * Display network statistics settings.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $settings = [
            'collection_interval' => NetworkStatisticSetting::getCollectionInterval(),
            'retention_hours' => NetworkStatisticSetting::getRetentionHours(),
        ];
        
        $intervalOptions = NetworkStatisticSetting::getCollectionIntervalOptions();
        $retentionOptions = NetworkStatisticSetting::getRetentionOptions();
        
        return view('admin.networksettings.index', [
            'settings' => $settings,
            'intervalOptions' => $intervalOptions,
            'retentionOptions' => $retentionOptions,
        ]);
    }
    
    /**
     * Update network statistics settings.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'collection_interval' => 'required|integer|in:' . implode(',', array_keys(NetworkStatisticSetting::getCollectionIntervalOptions())),
            'retention_hours' => 'required|integer|min:12|max:168',
        ]);
        
        try {
            NetworkStatisticSetting::setSetting('collection_interval', $validated['collection_interval']);
            NetworkStatisticSetting::setSetting('retention_hours', $validated['retention_hours']);

            return redirect()->route('admin.networksettings')->with('success', 'Network statistics settings have been updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'An error occurred while saving settings: ' . $e->getMessage())->withInput();
        }
    }
    
    /**
     * Clear all network statistics data.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearStatistics()
    {
        try {
            DB::beginTransaction();
            $count = StatisticsDay::count();
            DB::statement('TRUNCATE TABLE statistics_days');
            
            DB::commit();
            
            return redirect()->route('admin.networksettings')
                ->with('success', 'All network statistics have been cleared successfully. ' . number_format($count) . ' records were deleted.');
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to clear network statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->back()
                ->with('error', 'An error occurred while clearing statistics: ' . $e->getMessage());
        }
    }
    
    /**
     * Collect statistics for all servers immediately.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function collectAllStatistics()
    {
        try {
            $servers = \Pterodactyl\Models\Server::all();
            $count = $servers->count();
            \Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob::dispatch(20); 
            
            return redirect()->route('admin.networksettings')
                ->with('success', "Statistics collection started for {$count} servers. This may take a few moments to complete.");
                
        } catch (\Exception $e) {
            Log::error('Failed to collect server statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->back()
                ->with('error', 'An error occurred while collecting statistics: ' . $e->getMessage());
        }
    }
}
