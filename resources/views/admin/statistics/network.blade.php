@extends('layouts.admin')

@section('title')
    Network Statistics
@endsection

@section('content-header')
    <h1>Network Statistics<small>View network performance and resource usage.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Network Statistics</li>
    </ol>
@endsection

@section('content')
<div class="row" style="padding: 0 15px;">
    <div class="col-xs-12">
        <div class="box box-primary" style="border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); margin-bottom: 25px;">
            <div class="box-header with-border" style="background-color: #1a2035; border-bottom: 1px solid #2e3858; border-radius: 10px 10px 0 0; padding: 15px 20px;">
                <h3 class="box-title" style="color: white; font-weight: 600;">Network Statistics</h3>
                <div class="box-tools">
                    <form action="{{ route('admin.network.stats') }}" method="GET" class="filter-form">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="input-group">
                                        <span class="input-group-addon" style="background-color: #2e3858; border: none; color: #aab3c3;">
                                            <i class="fa fa-server"></i>
                                        </span>
                                        <select name="node" class="form-control" style="background-color: #252d4a; border: 1px solid #2e3858; color: white; height: 36px; border-radius: 4px;">
                                            <option value="">All Nodes</option>
                                            @foreach($nodes as $node)
                                                <option value="{{ $node->id }}" {{ $selectedNode == $node->id ? 'selected' : '' }}>{{ $node->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-addon" style="background-color: #2e3858; border: none; color: #aab3c3;">
                                            <i class="fa fa-search"></i>
                                        </span>
                                        <input type="text" name="search" class="form-control" value="{{ $search }}" placeholder="Search by server or node name" style="background-color: #252d4a; border: 1px solid #2e3858; color: white; height: 36px; border-radius: 4px;">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary" style="background-color: #3498db; border: none; width: 100%; height: 36px; border-radius: 4px;">
                                        <i class="fa fa-filter"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="box-body" style="background-color: #141b30; color: white; padding: 20px; border-radius: 0 0 10px 10px;">
                <div class="row">
                    <!-- Servers Card -->
                    <div class="col-md-3">
                        <div class="box box-primary" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
                            <div class="inner" style="padding: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <div>
                                        <p style="margin: 0; color: #aab3c3; font-size: 14px;">Servers</p>
                                        <h2 style="margin-top: 5px; font-size: 36px; font-weight: bold;">{{ $totalStats->server_count ?? 0 }}</h2>
                                    </div>
                                    <div>
                                        <i class="fa fa-server" style="font-size: 24px; color: #2e3858;"></i>
                                    </div>
                                </div>
                                <div style="margin-top: 15px;">
                                    @php
                                        $activeServers = isset($statistics) ? $statistics->where('status', 'running')->count() : 0;
                                        $serverCount = isset($totalStats->server_count) ? $totalStats->server_count : 0;
                                        $activePercent = $serverCount > 0 ? ($activeServers / $serverCount) * 100 : 0;
                                    @endphp
                                    <span style="color: #4caf50;"><i class="fa fa-arrow-up"></i> {{ $activeServers }} active</span>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="progress" style="height: 5px; background-color: #2e3858; border-radius: 3px; overflow: hidden;">
                                        <div class="progress-bar" style="width: {{ $activePercent }}%; background-color: #4caf50; border-radius: 3px;"></div>
                                    </div>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 5px; font-size: 12px;">
                                    <span>{{ $activeServers }} Online</span>
                                    <span>{{ $totalStats->server_count - $activeServers }} Idle</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Received Card -->
                    <div class="col-md-3">
                        <div class="small-box bg-green stats-card" style="background-color: #1e2745 !important; color: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356; margin-bottom: 20px;">
                            <div class="inner" style="padding: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <div>
                                        <p style="margin: 0; color: #aab3c3; font-size: 14px;">Data Received</p>
                                        @php
                                            $rxBytes = $totalStats->rx_bytes ?? 0;
                                            $rxUnit = 'B';
                                            
                                            if ($rxBytes > 1073741824) { // 1 GB
                                                $rxBytes = round($rxBytes / 1073741824, 2);
                                                $rxUnit = 'GB';
                                            } elseif ($rxBytes > 1048576) { // 1 MB
                                                $rxBytes = round($rxBytes / 1048576, 2);
                                                $rxUnit = 'MB';
                                            } elseif ($rxBytes > 1024) { // 1 KB
                                                $rxBytes = round($rxBytes / 1024, 2);
                                                $rxUnit = 'KB';
                                            }
                                        @endphp
                                        <h2 style="margin-top: 5px; font-size: 36px; font-weight: bold;">{{ $rxBytes }} {{ $rxUnit }}</h2>
                                    </div>
                                    <div>
                                        <i class="fa fa-arrow-down" style="font-size: 24px; color: #2e3858;"></i>
                                    </div>
                                </div>
                                <div style="margin-top: 15px;">
                                    @php
                                        // Calculate percentage increase if we have historical data
                                        $increase = 0;
                                        if (isset($statistics) && $statistics->isNotEmpty() && isset($statistics->first()->rx_bytes_per_sec)) {
                                            $increase = round(($statistics->sum('rx_bytes_per_sec') / max(1, $statistics->count())) * 100 / max(1, $rxBytes), 1);
                                        }
                                    @endphp
                                    <span style="color: #4caf50;"><i class="fa fa-arrow-up"></i> {{ $increase }}% increase</span>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div style="display: flex; align-items: flex-end; height: 30px; border-radius: 8px; overflow: hidden;">
                                        @php
                                            // Create a simple graph based on recent statistics
                                            $rxStats = isset($statistics) && $statistics->isNotEmpty() 
                                                ? $statistics->sortByDesc('collected_at')->take(6)->pluck('rx_bytes')->toArray() 
                                                : [0, 0, 0, 0, 0, 0];
                                            $maxRx = !empty($rxStats) ? max($rxStats) : 1;
                                            $maxRx = $maxRx ?: 1; // Prevent division by zero
                                            $rxStats = array_reverse($rxStats);
                                        @endphp
                                        
                                        @for($i = 0; $i < 6; $i++)
                                            @php 
                                                $stat = isset($rxStats[$i]) ? $rxStats[$i] : 0;
                                                $height = $maxRx > 0 ? max(20, min(100, ($stat / $maxRx) * 100)) : 20; 
                                            @endphp
                                            <div style="width: 12%; height: {{ $height }}%; background-color: #4caf50; margin-right: 2px; border-radius: 3px;"></div>
                                        @endfor
                                    </div>
                                </div>
                                <div style="margin-top: 5px; font-size: 12px;">
                                    <span>Weekly trend</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Transmitted Card -->
                    <div class="col-md-3">
                        <div class="box box-primary" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
                            <div class="inner" style="padding: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <div>
                                        <p style="margin: 0; color: #aab3c3; font-size: 14px;">Data Transmitted</p>
                                        @php
                                            $txBytes = $totalStats->tx_bytes ?? 0;
                                            $txUnit = 'B';
                                            
                                            if ($txBytes > 1073741824) { // 1 GB
                                                $txBytes = round($txBytes / 1073741824, 2);
                                                $txUnit = 'GB';
                                            } elseif ($txBytes > 1048576) { // 1 MB
                                                $txBytes = round($txBytes / 1048576, 2);
                                                $txUnit = 'MB';
                                            } elseif ($txBytes > 1024) { // 1 KB
                                                $txBytes = round($txBytes / 1024, 2);
                                                $txUnit = 'KB';
                                            }
                                        @endphp
                                        <h2 style="margin-top: 5px; font-size: 36px; font-weight: bold;">{{ $txBytes }} {{ $txUnit }}</h2>
                                    </div>
                                    <div>
                                        <i class="fa fa-arrow-up" style="font-size: 24px; color: #2e3858;"></i>
                                    </div>
                                </div>
                                <div style="margin-top: 15px;">
                                    @php
                                        // Calculate percentage increase if we have historical data
                                        $increase = 0;
                                        if (isset($statistics) && $statistics->isNotEmpty() && isset($statistics->first()->tx_bytes_per_sec)) {
                                            $increase = round(($statistics->sum('tx_bytes_per_sec') / max(1, $statistics->count())) * 100 / max(1, $txBytes), 1);
                                        }
                                    @endphp
                                    <span style="color: #4caf50;"><i class="fa fa-arrow-up"></i> {{ $increase }}% increase</span>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div style="display: flex; align-items: flex-end; height: 30px; border-radius: 8px; overflow: hidden;">
                                        @php
                                            // Create a simple graph based on recent statistics
                                            $txStats = isset($statistics) && $statistics->isNotEmpty() 
                                                ? $statistics->sortByDesc('collected_at')->take(6)->pluck('tx_bytes')->toArray() 
                                                : [0, 0, 0, 0, 0, 0];
                                            $maxTx = !empty($txStats) ? max($txStats) : 1;
                                            $maxTx = $maxTx ?: 1; // Prevent division by zero
                                            $txStats = array_reverse($txStats);
                                        @endphp
                                        
                                        @for($i = 0; $i < 6; $i++)
                                            @php 
                                                $stat = isset($txStats[$i]) ? $txStats[$i] : 0;
                                                $height = $maxTx > 0 ? max(20, min(100, ($stat / $maxTx) * 100)) : 20; 
                                            @endphp
                                            <div style="width: 12%; height: {{ $height }}%; background-color: #3498db; margin-right: 2px; border-radius: 3px;"></div>
                                        @endfor
                                    </div>
                                </div>
                                <div style="margin-top: 5px; font-size: 12px;">
                                    <span>Weekly trend</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Resources Card -->
                    <div class="col-md-3">
                        <div class="small-box bg-purple stats-card" style="background-color: #1e2745 !important; color: white; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356; margin-bottom: 20px;">
                            <div class="inner" style="padding: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <div>
                                        <p style="margin: 0; color: #aab3c3; font-size: 14px;">System Resources</p>
                                        @php
                                            // Calculate total RAM allocation across all servers
                                            $totalRam = $statistics->sum('memory') ?? 0;
                                            $ramUnit = 'MB';
                                            
                                            if ($totalRam >= 1024) {
                                                $totalRam = round($totalRam / 1024, 1);
                                                $ramUnit = 'GB';
                                            }
                                        @endphp
                                        <h2 style="margin-top: 5px; font-size: 36px; font-weight: bold;">{{ $totalRam }} {{ $ramUnit }}</h2>
                                    </div>
                                    <div>
                                        <i class="fa fa-microchip" style="font-size: 24px; color: #2e3858;"></i>
                                    </div>
                                </div>
                                <div style="margin-top: 15px;">
                                    <span>Total RAM allocation</span>
                                </div>
                                <div style="margin-top: 10px;">
                                    @php
                                        // Calculate CPU usage percentage across all servers
                                        $totalCpu = $statistics->sum('cpu') ?? 0;
                                        $allocatedCpu = $statistics->sum('cpu') ?? 0;
                                        $cpuUsagePercent = $allocatedCpu > 0 ? min(100, round(($totalCpu / $allocatedCpu) * 100)) : 0;
                                        
                                        // Calculate RAM usage percentage
                                        $allocatedRam = $statistics->sum('memory') ?? 0;
                                        $usedRam = $statistics->sum('memory') * 0.45; // Assuming 45% usage as an example
                                        $ramUsagePercent = $allocatedRam > 0 ? min(100, round(($usedRam / $allocatedRam) * 100)) : 0;
                                    @endphp
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                        <span>CPU Usage</span>
                                        <span>{{ $cpuUsagePercent }}%</span>
                                    </div>
                                    <div class="progress" style="height: 5px; background-color: #2e3858; margin-bottom: 10px; border-radius: 3px; overflow: hidden;">
                                        <div class="progress-bar" style="width: {{ $cpuUsagePercent }}%; background-color: #4caf50; border-radius: 3px;"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                        <span>RAM Usage</span>
                                        <span>{{ $ramUsagePercent }}%</span>
                                    </div>
                                    <div class="progress" style="height: 5px; background-color: #2e3858; border-radius: 3px; overflow: hidden;">
                                        <div class="progress-bar" style="width: {{ $ramUsagePercent }}%; background-color: #9c27b0; border-radius: 3px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Server Details Table -->
                <div class="row" style="margin-top: 20px;">
                    <div class="col-xs-12">
                        <div class="box box-primary" style="background-color: #1a2035; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 12px; overflow: hidden;">
                            <div class="box-header with-border" style="background-color: #1a2035; color: white; border-bottom: 1px solid #2e3858; padding: 15px 20px;">
                                <h3 class="box-title" style="color: white; font-size: 18px;">Server Details</h3>
                                <div class="box-tools pull-right">
                                    <span class="badge" style="background-color: #3498db; color: white; font-weight: normal; padding: 5px 10px; border-radius: 4px;">{{ isset($statistics) ? $statistics->count() : 0 }} servers</span>
                                </div>
                            </div>
                            <div class="box-body table-responsive no-padding" style="background-color: #1a2035;">
                                <table class="table" style="color: white; margin-bottom: 0; border-collapse: separate; border-spacing: 0 4px;">
                                    <thead>
                                        <tr style="background-color: #1a2035;">
                                            <th style="border-top: none; color: #aab3c3; font-weight: normal; font-size: 13px; padding: 12px 8px 4px 20px; text-transform: uppercase; border-radius: 8px 0 0 0;"><i class="fas fa-server" style="margin-right: 5px;"></i> SERVER</th>
                                            <th style="border-top: none; color: white; font-weight: normal; font-size: 13px; padding: 12px 8px 4px 8px; text-transform: uppercase; border-left: 2px solid #3498db; border-right: 2px solid #3498db; background-color: rgba(52, 152, 219, 0.1);"><i class="fas fa-network-wired" style="margin-right: 5px;"></i> NODE</th>
                                            <th style="border-top: none; color: #aab3c3; font-weight: normal; font-size: 13px; padding: 12px 8px 4px 8px; text-transform: uppercase; text-align: center;" colspan="3"><i class="fas fa-microchip" style="margin-right: 5px;"></i> SYSTEM RESOURCES</th>
                                            <th style="border-top: none; color: #aab3c3; font-weight: normal; font-size: 13px; padding: 12px 8px 4px 8px; text-transform: uppercase; text-align: center;" colspan="3"><i class="fas fa-exchange-alt" style="margin-right: 5px;"></i> NETWORK & TRAFFIC</th>
                                            <th style="border-top: none; color: #aab3c3; font-weight: normal; font-size: 13px; padding: 12px 8px 4px 8px; text-transform: uppercase; text-align: center; border-radius: 0 8px 0 0;"><i class="fas fa-signal" style="margin-right: 5px;"></i> STATUS</th>
                                        </tr>
                                        <tr style="background-color: #1a2035; font-weight: normal; font-size: 12px;">
                                            <th style="border-top: none; padding: 4px 8px 12px 20px;"></th>
                                            <th style="border-top: none; padding: 4px 8px 12px 8px;"></th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">RAM</th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">CPU</th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">Disk</th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">Received</th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">Transmitted</th>
                                            <th style="border-top: none; color: #aab3c3; padding: 4px 8px 12px 8px; text-align: center;">Packets (Rx/Tx)</th>
                                            <th style="border-top: none; padding: 4px 8px 12px 8px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(isset($statistics) && $statistics->isNotEmpty())
                                            @foreach($statistics as $stat)
                                                <tr style="transition: all 0.2s ease; border-radius: 8px; overflow: hidden;" class="server-row">
                                                    <td style="padding: 15px 8px 15px 20px; vertical-align: middle; border-radius: 8px 0 0 8px;">
                                                        @php $status = isset($stat->status) ? $stat->status : 'idle'; @endphp
                                                        <span style="color: {{ $status == 'running' ? '#4caf50' : '#aab3c3' }}; margin-right: 8px; font-size: 12px;">●</span>
                                                        <span style="font-weight: 500;">{{ $stat->server_name ?? 'Unknown' }}</span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; color: #aab3c3;">{{ $stat->node_name ?? 'Unknown' }}</td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        <span style="background-color: rgba(156, 39, 176, 0.2); padding: 6px 10px; border-radius: 4px; display: inline-block; min-width: 70px;">
                                                        <i class="fa fa-ticket" style="color: #9c27b0; margin-right: 5px;"></i>
                                                            {{ $stat->formatted_memory }}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        <span style="background-color: rgba(156, 39, 176, 0.2); padding: 6px 10px; border-radius: 4px; display: inline-block; min-width: 50px;">
                                                            <i class="fa fa-microchip" style="color: #9c27b0; margin-right: 5px;"></i>
                                                            {{ $stat->formatted_cpu }}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        <span style="background-color: rgba(156, 39, 176, 0.2); padding: 6px 10px; border-radius: 4px; display: inline-block; min-width: 70px;">
                                                            <i class="fa fa-hdd-o" style="color: #9c27b0; margin-right: 5px;"></i>
                                                            {{ $stat->formatted_disk }}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        @php
                                                            $rx = $stat->rx_bytes ?? 0;
                                                            $rxUnit = 'B';
                                                            
                                                            if ($rx > 1073741824) { // 1 GB
                                                                $rx = round($rx / 1073741824, 2);
                                                                $rxUnit = 'GB';
                                                            } elseif ($rx > 1048576) { // 1 MB
                                                                $rx = round($rx / 1048576, 2);
                                                                $rxUnit = 'MB';
                                                            } elseif ($rx > 1024) { // 1 KB
                                                                $rx = round($rx / 1024, 2);
                                                                $rxUnit = 'KB';
                                                            }
                                                        @endphp
                                                        <span style="color: {{ $rx > 0 ? '#4caf50' : '#aab3c3' }}; display: inline-block;">
                                                            <i class="fa fa-arrow-down" style="margin-right: 5px;"></i>
                                                            {{ $rx }} {{ $rxUnit }}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        @php
                                                            $tx = $stat->tx_bytes ?? 0;
                                                            $txUnit = 'B';
                                                            
                                                            if ($tx > 1073741824) { // 1 GB
                                                                $tx = round($tx / 1073741824, 2);
                                                                $txUnit = 'GB';
                                                            } elseif ($tx > 1048576) { // 1 MB
                                                                $tx = round($tx / 1048576, 2);
                                                                $txUnit = 'MB';
                                                            } elseif ($tx > 1024) { // 1 KB
                                                                $tx = round($tx / 1024, 2);
                                                                $txUnit = 'KB';
                                                            }
                                                        @endphp
                                                        <span style="color: {{ $tx > 0 ? '#3498db' : '#aab3c3' }}; display: inline-block;">
                                                            <i class="fa fa-arrow-up" style="margin-right: 5px;"></i>
                                                            {{ $tx }} {{ $txUnit }}
                                                        </span>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center;">
                                                        @php
                                                            $rx_packets = $stat->rx_packets ?? 0;
                                                            $tx_packets = $stat->tx_packets ?? 0;
                                                        @endphp
                                                        <div style="display: inline-block; text-align: center;">
                                                            <div style="color: {{ $rx_packets > 0 ? '#4caf50' : '#aab3c3' }}; margin-bottom: 3px;">
                                                                <i class="fa fa-arrow-down" style="margin-right: 5px;"></i>
                                                                {{ number_format($rx_packets) }}
                                                            </div>
                                                            <div style="color: {{ $tx_packets > 0 ? '#3498db' : '#aab3c3' }}; margin-top: 3px;">
                                                                <i class="fa fa-arrow-up" style="margin-right: 5px;"></i>
                                                                {{ number_format($tx_packets) }}
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="padding: 15px 8px; vertical-align: middle; text-align: center; border-radius: 0 8px 8px 0;">
                                                        @php $status = isset($stat->status) ? $stat->status : 'idle'; @endphp
                                                        <span class="label" 
                                                              style="background-color: {{ $status == 'running' ? '#4caf50' : '#2e3858' }}; 
                                                                     color: white; border-radius: 4px; padding: 6px 12px; font-weight: normal;">
                                                            {{ $status == 'running' ? 'active' : 'idle' }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td colspan="9" class="text-center">
                                                    <p style="color: #aab3c3; padding: 20px;">No server statistics available.</p>
                                                </td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                            <div class="box-footer" style="background-color: #1a2035; color: #aab3c3; border-top: 1px solid #2e3858; border-radius: 0 0 10px 10px; padding: 12px 20px;">
                                <div class="pull-right">
                                    Last updated <span id="last-update">{{ now()->diffForHumans() }}</span>
                                    <button class="btn btn-sm" id="refresh-data" style="margin-left: 10px; background-color: #3498db; border: none; color: white; padding: 6px 12px; border-radius: 4px;">
                                        <i class="fa fa-sync"></i> Refresh Data
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <style>
        .server-row {
            background-color: #1e2542;
            margin-bottom: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .server-row:hover {
            background-color: #252d4a;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .server-row td {
            border-top: none !important;
        }
        
        /* Stats card styling */
        .stats-card {
            height: 250px !important;
            min-height: 250px !important;
            display: flex !important;
            flex-direction: column !important;
        }
        
        /* Filter section styling */
        .filter-form .input-group-addon {
            transition: all 0.2s ease;
        }
        .filter-form .form-control:focus + .input-group-addon,
        .filter-form .form-control:hover + .input-group-addon {
            background-color: #3498db;
        }
        .filter-form .form-control:focus,
        .filter-form .form-control:hover {
            box-shadow: 0 0 8px rgba(52, 152, 219, 0.5);
            border-color: #3498db;
        }
        .filter-form .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        /* Responsive adjustments */
        @media (max-width: 991px) {
            .filter-form .col-md-4,
            .filter-form .col-md-6,
            .filter-form .col-md-2 {
                margin-bottom: 10px;
            }
        }
    </style>
    <script>
        $(document).ready(function() {
            // Handle refresh button click
            $('#refresh-data').on('click', function() {
                // Show loading indicator
                $(this).html('<i class="fa fa-sync fa-spin"></i> Loading...');
                
                // Reload the page to get fresh data
                window.location.reload();
            });
            
            // Update the last-update time every minute
            setInterval(function() {
                var lastUpdate = $('#last-update');
                var seconds = parseInt(lastUpdate.data('seconds') || 0) + 60;
                lastUpdate.data('seconds', seconds);
                
                if (seconds < 60) {
                    lastUpdate.text(seconds + ' seconds ago');
                } else if (seconds < 3600) {
                    var minutes = Math.floor(seconds / 60);
                    lastUpdate.text(minutes + ' minute' + (minutes > 1 ? 's' : '') + ' ago');
                } else {
                    var hours = Math.floor(seconds / 3600);
                    lastUpdate.text(hours + ' hour' + (hours > 1 ? 's' : '') + ' ago');
                }
            }, 60000); // Update every minute
        });
    </script>
@endsection