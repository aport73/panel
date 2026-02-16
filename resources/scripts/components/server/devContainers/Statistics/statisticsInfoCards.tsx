import React, { useEffect, useRef, useState } from 'react';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { bytesToString } from '@/lib/formatters';
import { CloudDownloadIcon, CloudUploadIcon } from '@heroicons/react/outline';
import { fetchStatistics } from '@/api/server/statistics/fetchStatistics';

interface Stats {
    rx: number;
    tx: number;
    rx_bytes: number;
    tx_bytes: number;
}

export default () => {
    const [stats, setStats] = useState<Stats>({ rx: 0, tx: 0, rx_bytes: 0, tx_bytes: 0 });
    const previous = useRef<Record<'tx' | 'rx', number>>({ tx: -1, rx: -1 });
    const [totalTraffic, setTotalTraffic] = useState({ rx: 0, tx: 0 });
    const [peakTraffic, setPeakTraffic] = useState({ rx: 0, tx: 0 });
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);

    useEffect(() => {
        const fetchTraffic = async () => {
            try {
                const chartData = await fetchStatistics(uuid);
                if (chartData && chartData.rx.length > 0 && chartData.tx.length > 0) {
                    const latestTimestamp = Math.max(
                        ...chartData.rx.map(p => p.timestamp * 1000),
                        ...chartData.tx.map(p => p.timestamp * 1000)
                    );
                    
                    const serverNow = new Date(latestTimestamp);
                    serverNow.setSeconds(0, 0);
                    const fifteenMinutesAgo = new Date(serverNow.getTime() - 15 * 60 * 1000);
                    const rxData = chartData.rx.filter(point => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= fifteenMinutesAgo.getTime() && pointTime < serverNow.getTime();
                    });

                    const txData = chartData.tx.filter(point => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= fifteenMinutesAgo.getTime() && pointTime < serverNow.getTime();
                    });

                    const peakRx = rxData.length > 0 ? Math.max(...rxData.map(point => point.value)) : 0;
                    const peakTx = txData.length > 0 ? Math.max(...txData.map(point => point.value)) : 0;

                    setPeakTraffic({
                        rx: peakRx,
                        tx: peakTx
                    });
                }
            } catch (error) {
                console.error('Failed to fetch traffic statistics:', error);
            }
        };

        fetchTraffic();
        const interval = setInterval(fetchTraffic, 60000);

        return () => clearInterval(interval);
    }, [uuid]);

    useWebsocketEvent(SocketEvent.STATS, (data: string) => {
        let values: any = {};
        try {
            values = JSON.parse(data);
        } catch (e) {
            return;
        }

        const currentRx = previous.current.rx < 0 ? 0 : Math.max(0, values.network.rx_bytes - previous.current.rx);
        const currentTx = previous.current.tx < 0 ? 0 : Math.max(0, values.network.tx_bytes - previous.current.tx);

        setStats({
            rx: currentRx,
            tx: currentTx,
            rx_bytes: values.network.rx_bytes,
            tx_bytes: values.network.tx_bytes
        });

        setTotalTraffic({
            rx: values.network.rx_bytes,
            tx: values.network.tx_bytes
        });

        previous.current = { tx: values.network.tx_bytes, rx: values.network.rx_bytes };
    });

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-8 mb-8">
            <div className="backdrop-blur-md bg-gradient-to-br from-gray-800/95 via-gray-850/95 to-gray-900/90 rounded-2xl p-5 border border-gray-700/40 shadow-[0_10px_40px_rgba(0,0,0,0.15)] transition-all duration-300 hover:shadow-[0_15px_50px_rgba(0,0,0,0.25)] hover:border-cyan-500/30 hover:translate-y-[-2px]">

                <div className="flex items-center mb-5">
                    <div className="bg-gradient-to-br from-cyan-600/40 to-cyan-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-cyan-400/50 shadow-lg shadow-cyan-500/20">
                        <CloudDownloadIcon className="h-5 w-5 text-cyan-300" />
                    </div>
                    <div>
                        <span className="text-xs font-medium text-cyan-400/70 uppercase tracking-wider">Network</span>
                        <h3 className="text-xl font-bold text-gray-100 tracking-tight">Incoming Traffic</h3>
                    </div>
                </div>
                <div className="space-y-5">
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-blue-500/20 shadow-lg hover:shadow-blue-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-blue-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0v10" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Current Usage</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(stats.rx)}</p>
                                    <p className="text-gray-400 text-sm font-medium ml-1">/s</p>
                                </div>
                            </div>
                            <div className="bg-blue-600/20 px-2 py-1 rounded-md flex items-center">
                                <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse mr-1"></span>
                                <span className="text-blue-300 text-xs font-medium">LIVE</span>
                            </div>
                        </div>
                    </div>
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-blue-500/20 shadow-lg hover:shadow-blue-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-blue-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Peak (15m)</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(peakTraffic.rx)}</p>
                                    <p className="text-gray-400 text-sm font-medium ml-1">/s</p>
                                </div>
                            </div>
                            <div className="bg-blue-600/10 px-2 py-1 rounded-md">
                                <span className="text-blue-300 text-xs font-medium">MAX</span>
                            </div>
                        </div>
                    </div>
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-blue-500/20 shadow-lg hover:shadow-blue-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-blue-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Total Traffic</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(totalTraffic.rx)}</p>
                                </div>
                            </div>
                            <div className="bg-blue-600/10 px-2 py-1 rounded-md">
                                <span className="text-blue-300 text-xs font-medium">TOTAL</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="backdrop-blur-md bg-gradient-to-br from-gray-900/90 via-gray-800/90 to-gray-800/80 rounded-2xl p-6 border border-gray-700/50 shadow-[0_8px_30px_rgb(0,0,0,0.12)] transition-all duration-300 hover:shadow-[0_10px_40px_rgb(0,0,0,0.2)] hover:border-gray-600/70 hover:translate-y-[-3px]">

                <div className="flex items-center mb-5">
                    <div className="bg-gradient-to-br from-amber-600/40 to-amber-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-amber-400/50 shadow-lg shadow-amber-500/20">
                        <CloudUploadIcon className="h-5 w-5 text-amber-300" />
                    </div>
                    <div>
                        <span className="text-xs font-medium text-amber-400/70 uppercase tracking-wider">Network</span>
                        <h3 className="text-xl font-bold text-gray-100 tracking-tight">Outgoing Traffic</h3>
                    </div>
                </div>
                <div className="space-y-5">
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-amber-500/20 shadow-lg hover:shadow-amber-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-amber-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 11l3-3m0 0l3 3m-3-3v8m0-13a9 9 0 110 18 9 9 0 010-18z" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Current Usage</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(stats.tx)}</p>
                                    <p className="text-gray-400 text-sm font-medium ml-1">/s</p>
                                </div>
                            </div>
                            <div className="bg-amber-600/20 px-2 py-1 rounded-md flex items-center">
                                <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse mr-1"></span>
                                <span className="text-amber-300 text-xs font-medium">LIVE</span>
                            </div>
                        </div>
                    </div>
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-amber-500/20 shadow-lg hover:shadow-amber-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-amber-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Peak (15m)</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(peakTraffic.tx)}</p>
                                    <p className="text-gray-400 text-sm font-medium ml-1">/s</p>
                                </div>
                            </div>
                            <div className="bg-amber-600/10 px-2 py-1 rounded-md">
                                <span className="text-amber-300 text-xs font-medium">MAX</span>
                            </div>
                        </div>
                    </div>
                    <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-5 border border-amber-500/20 shadow-lg hover:shadow-amber-500/5 transition-all duration-200">
                        <div className="flex items-center mb-3">
                            <div className="bg-amber-500/20 p-2 rounded-lg mr-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                            </div>
                            <div className="flex-grow">
                                <p className="text-gray-400 text-sm font-medium">Total Traffic</p>
                                <div className="flex items-baseline mt-1">
                                    <p className="text-white text-2xl font-bold">{bytesToString(totalTraffic.tx)}</p>
                                </div>
                            </div>
                            <div className="bg-amber-600/10 px-2 py-1 rounded-md">
                                <span className="text-amber-300 text-xs font-medium">TOTAL</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};
