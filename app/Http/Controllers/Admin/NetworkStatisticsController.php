<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\StatisticsDay;
use Illuminate\Support\Facades\DB;

class NetworkStatisticsController extends Controller
{
    /**
     * Get the latest statistics for each server.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getLatestStatistics(?int $nodeId = null, ?string $search = null)
    {
        $latestIds = DB::table('statistics_days')
            ->select('statistics_days.server_uuid', DB::raw('MAX(statistics_days.id) as id'))
            ->whereNotNull('statistics_days.server_uuid')
            ->join('servers', 'statistics_days.server_uuid', '=', 'servers.uuid')
            ->when($nodeId, function($query) use ($nodeId) {
                return $query->where('servers.node_id', '=', $nodeId);
            })
            ->groupBy('statistics_days.server_uuid')
            ->pluck('id');
            
        $serverStatus = [];
        $servers = Server::select(['id', 'uuid'])->get();
        $fiveMinutesAgo = now()->subMinutes(5);
        
        foreach ($servers as $server) {
            try {
                $recentStats = DB::table('statistics_days')
                    ->where('server_uuid', $server->uuid)
                    ->where('collected_at', '>=', $fiveMinutesAgo)
                    ->first();
                $hasRecentNetworkActivity = false;
                if ($recentStats) {
                    $previousStats = DB::table('statistics_days')
                        ->where('server_uuid', $server->uuid)
                        ->where('collected_at', '<', $recentStats->collected_at)
                        ->orderBy('collected_at', 'desc')
                        ->first();
                    
                    if ($previousStats) {
                        $rxDiff = $recentStats->rx_bytes - $previousStats->rx_bytes;
                        $txDiff = $recentStats->tx_bytes - $previousStats->tx_bytes;
                        $hasRecentNetworkActivity = ($rxDiff > 0 || $txDiff > 0);
                    }
                }
                $recentDaemonPing = DB::table('servers')
                    ->where('uuid', $server->uuid)
                    ->where('updated_at', '>=', $fiveMinutesAgo)
                    ->exists();
                
                $isRunning = ($recentStats !== null || $hasRecentNetworkActivity || $recentDaemonPing);
                $serverStatus[$server->uuid] = $isRunning ? 'running' : 'idle';
            } catch (\Exception $e) {
                $serverStatus[$server->uuid] = 'idle';
            }
        }
        $prevStatsQuery = DB::table(function($query) use ($latestIds) {
            $query->from('statistics_days')
                  ->select([
                      'server_uuid',
                      'id',
                      'rx_bytes',
                      'tx_bytes',
                      'collected_at',
                      DB::raw('ROW_NUMBER() OVER (PARTITION BY server_uuid ORDER BY id DESC) as rn')
                  ])
                  ->whereIn('server_uuid', function($q) use ($latestIds) {
                      $q->from('statistics_days')
                        ->whereIn('id', $latestIds)
                        ->select('server_uuid');
                  });
        }, 'ranked_stats')
        ->where('rn', '=', 2)
        ->select([
            'server_uuid',
            'id',
            'rx_bytes as prev_rx_bytes',
            'tx_bytes as prev_tx_bytes',
            'collected_at as prev_collected_at'
        ]);

        return DB::table('statistics_days AS current')
            ->select([
                'current.id',
                'current.server_uuid',
                'current.rx_bytes',
                'current.tx_bytes',
                'current.rx_packets',
                'current.tx_packets',
                'current.collected_at',
                'servers.name as server_name',
                'servers.memory as allocated_memory',
                'servers.disk as allocated_disk',
                'servers.cpu as allocated_cpu',
                'servers.id as server_id',
                'nodes.name as node_name',
                'prev.rx_bytes as prev_rx_bytes',
                'prev.tx_bytes as prev_tx_bytes',
                'prev.collected_at as prev_collected_at'
            ])
            ->join('servers', function($join) {
                $join->on('current.server_uuid', '=', 'servers.uuid');
            })
            ->join('nodes', 'servers.node_id', '=', 'nodes.id')
            ->leftJoinSub($prevStatsQuery, 'prev_stats', function($join) {
                $join->on('current.server_uuid', '=', 'prev_stats.server_uuid');
            })
            ->leftJoin('statistics_days as prev', 'prev.id', '=', 'prev_stats.id')
            ->whereIn('current.id', $latestIds)
            ->when($search, function($query) use ($search) {
                $searchTerm = '%' . $search . '%';
                return $query->where(function($q) use ($searchTerm) {
                    $q->whereRaw('servers.name LIKE ?', [$searchTerm])
                      ->orWhereRaw('nodes.name LIKE ?', [$searchTerm]);
                });
            })
            ->orderBy('servers.id', 'asc')
            ->get()
            ->map(function($stat) use ($serverStatus) {
                if ($stat->prev_collected_at) {
                    $timeDiff = strtotime($stat->collected_at) - strtotime($stat->prev_collected_at);
                    if ($timeDiff > 0) {
                        $stat->rx_bytes_per_sec = round(($stat->rx_bytes - $stat->prev_rx_bytes) / $timeDiff, 2);
                        $stat->tx_bytes_per_sec = round(($stat->tx_bytes - $stat->prev_tx_bytes) / $timeDiff, 2);
                    }
                }

                $stat->status = isset($serverStatus[$stat->server_uuid]) ? $serverStatus[$stat->server_uuid] : 'idle';
                
                if ($stat->status === 'idle' && (($stat->rx_bytes_per_sec ?? 0) > 0 || ($stat->tx_bytes_per_sec ?? 0) > 0)) {
                    $stat->status = 'running';
                }

                $stat->formatted_memory = isset($stat->allocated_memory) ? $stat->allocated_memory . 'MB' : '0MB';
                $stat->formatted_cpu = (isset($stat->allocated_cpu) && $stat->allocated_cpu > 0) ? $stat->allocated_cpu . '%' : '0%';
                $stat->formatted_disk = isset($stat->allocated_disk) ? $stat->allocated_disk . 'MB' : '0MB';
                
                return $stat;
            });
    }

    /**
     * Display network statistics.
     */
    private function calculateTotalTraffic(?int $nodeId = null)
    {
        $query = DB::table('statistics_days AS current')
            ->join('servers', function($join) {
                $join->on('current.server_uuid', '=', 'servers.uuid');
            })
            ->join('nodes', 'servers.node_id', '=', 'nodes.id')
            ->select([
                'nodes.id as node_id',
                'nodes.name as node_name',
                DB::raw('COALESCE(SUM(current.rx_bytes), 0) as total_rx_bytes'),
                DB::raw('COALESCE(SUM(current.tx_bytes), 0) as total_tx_bytes'),
                DB::raw('COALESCE(SUM(current.rx_packets), 0) as total_rx_packets'),
                DB::raw('COALESCE(SUM(current.tx_packets), 0) as total_tx_packets'),
                DB::raw('COUNT(DISTINCT current.server_uuid) as server_count')
            ])
            ->whereIn('current.id', function($query) use ($nodeId) {
                $query->select(DB::raw('MAX(statistics_days.id)'))
                    ->from('statistics_days')
                    ->join('servers', 'statistics_days.server_uuid', '=', 'servers.uuid')
                    ->whereNotNull('statistics_days.server_uuid')
                    ->when($nodeId, function($subquery) use ($nodeId) {
                        return $subquery->where('servers.node_id', '=', $nodeId);
                    })
                    ->groupBy('statistics_days.server_uuid');
            })
            ->when($nodeId, function($query) use ($nodeId) {
                return $query->where('servers.node_id', '=', $nodeId);
            })
            ->groupBy('nodes.id', 'nodes.name');

        return $query->get();
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'node' => 'nullable|integer|exists:nodes,id',
            'search' => 'nullable|string|max:255'
        ]);

        $nodes = Node::query()->select(['id', 'name'])->orderBy('name')->get();
        $selectedNode = isset($validated['node']) ? (int) $validated['node'] : null;
        $search = isset($validated['search']) ? e($validated['search']) : null;

        try {
            $stats = $this->getLatestStatistics($selectedNode, $search);
            $trafficStats = $this->calculateTotalTraffic($selectedNode);
            $totalStats = (object)[
                'rx_bytes' => max(0, $trafficStats->sum('total_rx_bytes')),
                'tx_bytes' => max(0, $trafficStats->sum('total_tx_bytes')),
                'rx_packets' => max(0, $trafficStats->sum('total_rx_packets')),
                'tx_packets' => max(0, $trafficStats->sum('total_tx_packets')),
                'server_count' => max(0, $trafficStats->sum('server_count')),
                'active_servers' => $stats->where('status', 'running')->count(),
                'node_count' => max(0, $trafficStats->count())
            ];

            return view('admin.statistics.network', [
                'statistics' => $stats,
                'nodes' => $nodes,
                'selectedNode' => $selectedNode,
                'search' => $search,
                'trafficStats' => $trafficStats,
                'totalStats' => $totalStats
            ]);
        } catch (\Exception $e) {
            report($e);
            $context = [
                'node_id' => $selectedNode,
                'error_type' => class_basename($e),
            ];
            \Log::error('Network statistics error occurred', $context);
            
            return redirect()->back()->withErrors(['error' => 'An error occurred while fetching network statistics.']);
        }
    }

    /**
     * Return network statistics as JSON for API requests.
     */
    public function apiIndex()
    {
        return response()->json([
            'data' => $this->getLatestStatistics()
        ]);
    }
    
    /**
     * Display the network statistics introduction page.
     */
    public function intro()
    {
        return view('admin.statistics.intro');
    }
}
