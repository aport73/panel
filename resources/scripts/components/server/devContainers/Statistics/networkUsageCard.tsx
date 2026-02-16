import React, { useState, useEffect, useRef } from 'react';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import { bytesToString } from '@/lib/formatters';
import { ChartBarIcon, ArrowUpIcon, ArrowDownIcon, RefreshIcon, TrendingUpIcon, TrendingDownIcon } from '@heroicons/react/outline';

interface NetworkStats {
    currentRx: number;
    currentTx: number;
    peakRx: number;
    peakTx: number;
    rxTrend: 'up' | 'down' | 'stable';
    txTrend: 'up' | 'down' | 'stable';
}

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const [stats, setStats] = useState<NetworkStats>(() => {
        const stored = localStorage.getItem(`network-peaks-${uuid}`);
        return stored ? JSON.parse(stored) : {
            currentRx: 0,
            currentTx: 0,
            peakRx: 0,
            peakTx: 0,
            rxTrend: 'stable',
            txTrend: 'stable'
        };
    });

    const previous = useRef<Record<'tx' | 'rx', number>>({ tx: -1, rx: -1 });
    const totalTraffic = useRef<Record<'tx' | 'rx', number>>({ tx: 0, rx: 0 });
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);
    const [isRefreshing, setIsRefreshing] = useState(false);
    
    const resetStats = () => {
        localStorage.removeItem(`network-peaks-${uuid}`);
        setStats({
            currentRx: 0,
            currentTx: 0,
            peakRx: 0,
            peakTx: 0,
            rxTrend: 'stable',
            txTrend: 'stable'
        });
        totalTraffic.current = { tx: 0, rx: 0 };
        previous.current = { tx: -1, rx: -1 };
    };
    
    const handleRefresh = () => {
        setIsRefreshing(true);
        resetStats();
        setTimeout(() => {
            setIsRefreshing(false);
        }, 1000);
    };

    useEffect(() => {
        if (!connected || !instance) return;

        const handleStats = (data: string) => {
            try {
                const values = JSON.parse(data);
                if (values.network) {
                    const currentRx = previous.current.rx < 0 ? 0 : Math.max(0, values.network.rx_bytes - previous.current.rx);
                    const currentTx = previous.current.tx < 0 ? 0 : Math.max(0, values.network.tx_bytes - previous.current.tx);

                    totalTraffic.current = {
                        rx: values.network.rx_bytes,
                        tx: values.network.tx_bytes
                    };

                    setStats(prevStats => {
                        let rxTrend: 'up' | 'down' | 'stable' = 'stable';
                        let txTrend: 'up' | 'down' | 'stable' = 'stable';
                        
                        if (currentRx > prevStats.currentRx * 1.1) { 
                            rxTrend = 'up';
                        } else if (currentRx < prevStats.currentRx * 0.9) { 
                            rxTrend = 'down';
                        }
                        
                        if (currentTx > prevStats.currentTx * 1.1) {
                            txTrend = 'up';
                        } else if (currentTx < prevStats.currentTx * 0.9) {
                            txTrend = 'down';
                        }
                        
                        const newStats = {
                            currentRx,
                            currentTx,
                            peakRx: Math.max(prevStats.peakRx, currentRx),
                            peakTx: Math.max(prevStats.peakTx, currentTx),
                            rxTrend,
                            txTrend
                        };
                        
                        localStorage.setItem(`network-peaks-${uuid}`, JSON.stringify(newStats));
                        return newStats;
                    });

                    previous.current = { 
                        tx: values.network.tx_bytes, 
                        rx: values.network.rx_bytes 
                    };
                }
            } catch (error) {
                console.error('Failed to parse network statistics:', error);
            }
        };

        const handlePowerState = (state: string) => {
            if (state === 'starting') {
                resetStats();
            }
        };

        instance.addListener(SocketEvent.STATS, handleStats);
        instance.addListener(SocketEvent.STATUS, handlePowerState);
        
        return () => {
            instance.removeListener(SocketEvent.STATS, handleStats);
            instance.removeListener(SocketEvent.STATUS, handlePowerState);
        };
    }, [connected, instance, uuid]);

    const rxPercentage = stats.peakRx ? Math.round((stats.currentRx / stats.peakRx) * 100) : 0;
    const txPercentage = stats.peakTx ? Math.round((stats.currentTx / stats.peakTx) * 100) : 0;
    const maxPercentage = Math.max(rxPercentage, txPercentage);

    return (
        <div className="backdrop-blur-md bg-gradient-to-br from-gray-900/90 to-gray-800/90 rounded-xl border border-gray-700/50 shadow-[0_0_15px_rgba(0,0,0,0.2)] transition-all duration-300 overflow-hidden">
            {/* Card Header with Glass Effect */}
            <div className="relative bg-gray-800/70 backdrop-blur-lg px-6 py-4 border-b border-gray-700/30 flex items-center justify-between">
                <div className="flex items-center space-x-3">
                    <div className="bg-gradient-to-br from-emerald-500/20 to-blue-600/20 p-2.5 rounded-lg backdrop-blur-sm ring-1 ring-emerald-500/30 shadow-lg shadow-emerald-500/10">
                        <ChartBarIcon className="h-5 w-5 text-emerald-400" />
                    </div>
                    <div>
                        <h3 className="text-lg font-bold bg-gradient-to-r from-emerald-300 to-blue-300 bg-clip-text text-transparent">Network Usage</h3>
                        <p className="text-xs text-gray-400">Real-time traffic monitoring</p>
                    </div>
                </div>
                <div className="flex items-center space-x-2">
                    <span className="px-2.5 py-1 text-xs font-semibold bg-gradient-to-r from-emerald-500/10 to-blue-600/10 text-emerald-300 rounded-md border border-emerald-500/20 shadow-inner">ALPHA</span>
                    <button 
                        onClick={handleRefresh} 
                        disabled={isRefreshing}
                        className={`p-1.5 rounded-md ${isRefreshing ? 'bg-gray-700/50' : 'hover:bg-gray-700/50'} transition-colors`}
                    >
                        <RefreshIcon className={`h-4 w-4 ${isRefreshing ? 'text-emerald-300 animate-spin' : 'text-gray-400 hover:text-white'} transition-colors`} />
                    </button>
                </div>
            </div>
            
            {/* Card Body */}
            <div className="p-5 space-y-6">
                {/* Usage Bar */}
                <div className="relative">
                    <div className="flex items-center justify-between mb-2">
                        <div>
                            <span className="text-sm font-medium text-gray-300">
                                Current vs Peak Usage
                            </span>
                        </div>
                        <div className="text-right">
                            <span className="text-sm font-bold bg-gradient-to-r from-emerald-300 to-blue-300 bg-clip-text text-transparent">
                                {maxPercentage}%
                            </span>
                        </div>
                    </div>
                    
                    {/* Progress Bar with Glow */}
                    <div className="overflow-hidden h-2.5 rounded-full bg-gray-800 shadow-inner relative">
                        <div 
                            style={{ width: `${maxPercentage}%` }}
                            className="h-full bg-gradient-to-r from-emerald-500 to-blue-500 rounded-full shadow-[0_0_8px_rgba(52,211,153,0.6)] transition-all duration-500 ease-out"
                        />
                    </div>
                </div>

                {/* Stats Cards with Glassmorphism */}
                <div className="grid grid-cols-2 gap-4">
                    {/* Incoming Traffic Card */}
                    <div className="bg-gray-800/50 backdrop-blur-sm rounded-lg p-4 border border-gray-700/40 shadow-lg relative overflow-hidden group transition-all duration-300 hover:bg-gray-700/40">
                        {/* Background Glow Effect */}
                        <div className="absolute inset-0 bg-gradient-to-br from-emerald-500/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center">
                                <div className="p-1.5 rounded-md bg-emerald-500/10 mr-2">
                                    <ArrowDownIcon className="h-4 w-4 text-emerald-400" />
                                </div>
                                <span className="text-sm font-medium text-emerald-300">Incoming</span>
                            </div>
                            <span className="px-2 py-0.5 text-xs rounded-full bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">
                                {rxPercentage}%
                            </span>
                        </div>
                        
                        <div className="relative z-10">
                            <div className="flex items-baseline mb-1">
                                <span className="text-2xl font-bold text-white tracking-tight">{bytesToString(stats.currentRx)}</span>
                                <span className="text-sm text-gray-400 ml-1">/s</span>
                                {stats.rxTrend !== 'stable' && (
                                    <div className={`ml-2 p-0.5 rounded ${stats.rxTrend === 'up' ? 'bg-emerald-500/20' : 'bg-red-500/20'}`}>
                                        {stats.rxTrend === 'up' ? 
                                            <TrendingUpIcon className="h-3 w-3 text-emerald-400" /> : 
                                            <TrendingDownIcon className="h-3 w-3 text-red-400" />
                                        }
                                    </div>
                                )}
                            </div>
                            
                            <div className="grid grid-cols-2 gap-2 mt-2">
                                <div className="bg-gray-900/50 rounded-md px-2 py-1.5">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-500 mb-0.5">Peak</p>
                                    <p className="text-xs font-medium text-emerald-300">{bytesToString(stats.peakRx)}/s</p>
                                </div>
                                <div className="bg-gray-900/50 rounded-md px-2 py-1.5">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-500 mb-0.5">Total</p>
                                    <p className="text-xs font-medium text-emerald-300">{bytesToString(totalTraffic.current.rx)}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    {/* Outgoing Traffic Card */}
                    <div className="bg-gray-800/50 backdrop-blur-sm rounded-lg p-4 border border-gray-700/40 shadow-lg relative overflow-hidden group transition-all duration-300 hover:bg-gray-700/40">
                        {/* Background Glow Effect */}
                        <div className="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center">
                                <div className="p-1.5 rounded-md bg-blue-500/10 mr-2">
                                    <ArrowUpIcon className="h-4 w-4 text-blue-400" />
                                </div>
                                <span className="text-sm font-medium text-blue-300">Outgoing</span>
                            </div>
                            <span className="px-2 py-0.5 text-xs rounded-full bg-blue-500/10 text-blue-300 border border-blue-500/20">
                                {txPercentage}%
                            </span>
                        </div>
                        
                        <div className="relative z-10">
                            <div className="flex items-baseline mb-1">
                                <span className="text-2xl font-bold text-white tracking-tight">{bytesToString(stats.currentTx)}</span>
                                <span className="text-sm text-gray-400 ml-1">/s</span>
                                {stats.txTrend !== 'stable' && (
                                    <div className={`ml-2 p-0.5 rounded ${stats.txTrend === 'up' ? 'bg-blue-500/20' : 'bg-red-500/20'}`}>
                                        {stats.txTrend === 'up' ? 
                                            <TrendingUpIcon className="h-3 w-3 text-blue-400" /> : 
                                            <TrendingDownIcon className="h-3 w-3 text-red-400" />
                                        }
                                    </div>
                                )}
                            </div>
                            
                            <div className="grid grid-cols-2 gap-2 mt-2">
                                <div className="bg-gray-900/50 rounded-md px-2 py-1.5">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-500 mb-0.5">Peak</p>
                                    <p className="text-xs font-medium text-blue-300">{bytesToString(stats.peakTx)}/s</p>
                                </div>
                                <div className="bg-gray-900/50 rounded-md px-2 py-1.5">
                                    <p className="text-[10px] uppercase tracking-wider text-gray-500 mb-0.5">Total</p>
                                    <p className="text-xs font-medium text-blue-300">{bytesToString(totalTraffic.current.tx)}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};
