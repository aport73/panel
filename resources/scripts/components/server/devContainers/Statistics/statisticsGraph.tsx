import React, { useEffect, useRef } from 'react';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    ChartData,
    ChartOptions,
} from 'chart.js';
import { bytesToString } from '@/lib/formatters';
import { ServerContext } from '@/state/server';
import { GraphDataPoint } from '@/api/server/statistics/Statistics';
import { fetchStatistics } from '@/api/server/statistics/fetchStatistics';
import { UserIcon, EyeIcon, ViewGridIcon, ColorSwatchIcon, ShieldCheckIcon, CloudDownloadIcon, TableIcon, SparklesIcon, TerminalIcon, ChartPieIcon, FolderOpenIcon, DatabaseIcon, ScissorsIcon, CalendarIcon, UserGroupIcon, ArchiveIcon, GlobeIcon, AdjustmentsIcon, CogIcon, CloudUploadIcon, GiftIcon, UsersIcon, MapIcon, CubeTransparentIcon, PencilAltIcon, BellIcon, TruckIcon, ClockIcon, SelectorIcon } from '@heroicons/react/outline';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend
);

interface IntervalAverage {
    startTime: string;
    endTime: string;
    rx: number;
    tx: number;
}

interface NetworkChartData {
    rx: Array<{ timestamp: number; value: number }>;
    tx: Array<{ timestamp: number; value: number }>;
    packets_rx: Array<{ timestamp: number; value: number }>;
    packets_tx: Array<{ timestamp: number; value: number }>;
    settings?: {
        retention_hours: number;
        collection_interval: number;
        retention_text: string;
        collection_text: string;
        deletion_interval_days?: number;
    };
}

const AVAILABLE_INTERVALS = [15, 30, 45, 60];

const getStoredInterval = (): number => {
    const stored = localStorage.getItem('nsm_interval_minutes');
    const interval = parseInt(stored || '15', 10);
    return AVAILABLE_INTERVALS.includes(interval) ? interval : 15;
};

const StatisticsGraph: React.FC = () => {
    const [networkData, setNetworkData] = React.useState<GraphDataPoint[]>([]);
    const [selectedInterval, setSelectedInterval] = React.useState<number>(getStoredInterval());
    const [retentionSettings, setRetentionSettings] = React.useState<{
        retention_hours: number;
        collection_interval: number;
        retention_text: string;
        collection_text: string;
        deletion_interval_days?: number;
        interval_minutes?: number;
        available_intervals?: number[];
    }>({ retention_hours: 12, collection_interval: 60, retention_text: '12 hours', collection_text: '1 minute' });
    const uuid = ServerContext.useStoreState(state => state.server.data?.uuid);

    useEffect(() => {
        if (!uuid) return;

        const populateNetworkData = async () => {
            try {
                const data = await fetchStatistics(uuid);
                if (!data) {
                    console.warn('No statistics data available');
                    return;
                }
                
                const chartData: NetworkChartData = data;
                if (chartData.settings) {
                    setRetentionSettings(chartData.settings);
                }
                const serverNow = new Date();
                const minutes = serverNow.getMinutes();
                const roundedMinutes = Math.floor(minutes / 15) * 15;
                serverNow.setMinutes(roundedMinutes);
                serverNow.setSeconds(0, 0);
                serverNow.setMilliseconds(0);

                const points: GraphDataPoint[] = [];
                const entriesPerHour = 4; 
                const numEntries = Math.max(12, Math.floor(retentionSettings.retention_hours * entriesPerHour));
                console.log(`Using ${numEntries} data points based on ${retentionSettings.retention_hours}h retention period`);
                for (let i = 0; i < numEntries; i++) {
                    const intervalEnd = new Date(serverNow.getTime() - i * 15 * 60 * 1000);
                    const intervalStart = new Date(intervalEnd.getTime() - 15 * 60 * 1000);
                    const intervalData = chartData.rx.filter((point: { timestamp: number; value: number }) => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= (intervalStart.getTime() - 30000) && 
                               pointTime < (intervalEnd.getTime() + 30000);
                    });
                    intervalData.sort((a, b) => b.value - a.value);

                    const txData = chartData.tx.filter((point: { timestamp: number; value: number }) => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= (intervalStart.getTime() - 30000) && 
                               pointTime < (intervalEnd.getTime() + 30000);
                    });
                    txData.sort((a, b) => b.value - a.value);

                    const rxPacketsData = chartData.packets_rx?.filter((point: { timestamp: number; value: number }) => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= (intervalStart.getTime() - 30000) && 
                               pointTime < (intervalEnd.getTime() + 30000);
                    }).sort((a, b) => b.value - a.value) || [];

                    const txPacketsData = chartData.packets_tx?.filter((point: { timestamp: number; value: number }) => {
                        const pointTime = point.timestamp * 1000;
                        return pointTime >= (intervalStart.getTime() - 30000) && 
                               pointTime < (intervalEnd.getTime() + 30000);
                    }).sort((a, b) => b.value - a.value) || [];
                    const maxRx = intervalData.length > 0 
                        ? Math.max(1, ...intervalData.map(p => isFinite(p.value) ? p.value : 0))
                        : 0;
                    const maxTx = txData.length > 0 
                        ? Math.max(1, ...txData.map(p => isFinite(p.value) ? p.value : 0))
                        : 0;
                    const maxRxPackets = rxPacketsData.length > 0
                        ? Math.max(0, ...rxPacketsData.map(p => isFinite(p.value) ? p.value : 0))
                        : 0;
                    const maxTxPackets = txPacketsData.length > 0
                        ? Math.max(0, ...txPacketsData.map(p => isFinite(p.value) ? p.value : 0))
                        : 0;

                    if (!isFinite(maxRx) || !isFinite(maxTx) || !isFinite(maxRxPackets) || !isFinite(maxTxPackets)) {
                        console.warn('Invalid values detected:', { maxRx, maxTx, maxRxPackets, maxTxPackets });
                        continue;
                    }

                    points.push({
                        timestamp: Math.floor(intervalEnd.getTime() / 1000),
                        rx: maxRx,
                        tx: maxTx,
                        rx_packets: maxRxPackets,
                        tx_packets: maxTxPackets,
                        time: intervalEnd.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                        day: intervalEnd.toLocaleDateString('en-US', { weekday: 'short' })
                    });
                }
                setNetworkData(points.reverse());
            } catch (error) {
                console.error('Failed to fetch statistics:', error);
            }
        };

        populateNetworkData();
        const interval = setInterval(populateNetworkData, 15000); 

        return () => {
            clearInterval(interval);
        };
    }, [uuid]);

    const options: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 600,
            easing: 'easeOutQuart',
        },
        elements: {
            line: {
                tension: 0.35,
                borderWidth: 3,
                borderCapStyle: 'round',
                borderJoinStyle: 'round',
                fill: true,
                cubicInterpolationMode: 'monotone',
                spanGaps: true,
                stepped: false
            },
            point: {
                radius: 0,
                hoverRadius: 6,
                hitRadius: 30,
                hoverBorderWidth: 2
            },
        },
        scales: {
            x: {
                type: 'category' as const,
                display: true,
                grid: {
                    color: 'rgb(55, 65, 81, 0.15)', 
                    drawBorder: false,
                    drawOnChartArea: true,
                    drawTicks: true,
                    tickLength: 8,
                    lineWidth: 0.6,
                    z: 0,
                    borderDash: [4, 5],
                    borderColor: 'transparent',
                    borderWidth: 0
                },
                ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 8,
                    color: 'rgb(156, 163, 175)',
                    padding: 8,
                    font: {
                        size: 10
                    }
                }
            },
            y: {
                type: 'linear' as const,
                display: true,
                min: 0, 
                grid: {
                    color: 'rgb(55, 65, 81, 0.08)', 
                    drawBorder: false,
                    lineWidth: 0.5,
                    z: 0,
                    borderColor: 'transparent',
                    borderWidth: 0
                },
                ticks: {
                    callback: function(value) {
                        return bytesToString(value as number) + '/s';
                    },
                    color: 'rgb(156, 163, 175)', 
                    padding: 10,
                    font: {
                        size: 11
                    }
                }
            },
        },
        plugins: {
            legend: {
                labels: {
                    color: 'rgb(156, 163, 175)', 
                    usePointStyle: true,
                    pointStyle: 'rectRounded',
                    boxWidth: 8,
                    boxHeight: 8,
                    padding: 15,
                    font: {
                        size: 12
                    }
                },
                reverse: true, 
            },
            tooltip: {
                callbacks: {
                    title: function(context) {
                        const point = networkData[context[0].dataIndex];
                        return `${point.day}, ${point.time}`;
                    },
                    label: function(context) {
                        const value = context.raw as number;
                        const point = networkData[context.dataIndex];
                        const label = context.dataset.label || '';
                        const isIncoming = label.includes('Incoming');
                        const dot = isIncoming ? '🟢' : '🔵';
                        const arrow = isIncoming ? '⬇' : '⬆';
                        const packets = isIncoming ? point.rx_packets : point.tx_packets;
                        const formattedPackets = packets ? packets.toLocaleString() : '0';
                        const rate = value ? bytesToString(value) : '0 B';
                        return [
                            `${dot} ${arrow} ${label}: ${rate}`,
                            `   Packets: ${formattedPackets}`
                        ];
                    },
                    labelTextColor: function(context) {
                        return context.dataset.label?.includes('Incoming') 
                            ? 'rgb(34, 197, 94)' 
                            : 'rgb(59, 130, 246)'; 
                    }
                },
                titleFont: {
                    size: 14,
                    weight: 'bold',
                    family: "'Inter', -apple-system, system-ui, sans-serif"
                },
                bodyFont: {
                    size: 13,
                    family: "'Inter', -apple-system, system-ui, sans-serif"
                },
                backgroundColor: 'rgb(17, 24, 39)', 
                titleColor: 'rgb(243, 244, 246)',
                borderColor: 'rgb(75, 85, 99)',
                borderWidth: 1,
                padding: {
                    top: 12,
                    right: 15,
                    bottom: 12,
                    left: 15
                },
                cornerRadius: 6,
                displayColors: false,
                bodySpacing: 4,
                titleSpacing: 8,
                titleMarginBottom: 8,
                caretSize: 7,
                caretPadding: 5,
                boxPadding: 3,
            },
        },
        interaction: {
            intersect: false,
            mode: 'index',
        },
    };

    const chartData: ChartData<'line'> = {
        labels: networkData.map(d => d.time),
        datasets: [
            {
                label: 'Incoming Traffic',
                data: networkData.map(d => d.rx),
                borderColor: 'rgba(34, 197, 94, 0.9)', 
                backgroundColor: 'rgba(34, 197, 94, 0.25)',
                borderWidth: 4,
                pointRadius: 0, 
                pointBackgroundColor: 'rgb(34, 197, 94)',
                pointBorderColor: 'rgb(17, 24, 39)', 
                pointBorderWidth: 1.5,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: 'rgb(34, 197, 94)',
                pointHoverBorderColor: 'rgb(255, 255, 255)',
                pointHoverBorderWidth: 2,
                tension: 0.7,
                fill: true,
                order: 1,
                cubicInterpolationMode: 'monotone',
            },
            {
                label: 'Outgoing Traffic',
                data: networkData.map(d => d.tx),
                borderColor: 'rgba(59, 130, 246, 0.9)', 
                backgroundColor: 'rgba(59, 130, 246, 0.25)',
                borderWidth: 4,
                pointRadius: 0,
                pointBackgroundColor: 'rgb(59, 130, 246)',
                pointBorderColor: 'rgb(17, 24, 39)', 
                pointBorderWidth: 1.5,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: 'rgb(59, 130, 246)',
                pointHoverBorderColor: 'rgb(255, 255, 255)',
                pointHoverBorderWidth: 2,
                tension: 0.7, 
                fill: true,
                order: 2,
                cubicInterpolationMode: 'monotone',
            },
        ],
    };

    return (
        <div className="backdrop-blur-md bg-gray-800/80 rounded-xl shadow-lg p-6 w-full border border-gray-600/50 transition-all duration-300 hover:shadow-2xl hover:border-gray-500/70">
            <div className="mb-6">
                <div className="flex justify-between items-center mb-5">
                    <div className="flex items-center">
                        <div className="p-2 bg-gradient-to-br from-emerald-500/20 to-blue-500/20 rounded-lg mr-3">
                            <ChartPieIcon className="w-5 h-5 text-emerald-400" />
                        </div>
                        <h3 className="text-xl font-semibold text-gray-100">Latest 15-Minute Intervals</h3>
                    </div>
                    <div className="flex space-x-2 text-xs text-gray-300">
                        <span className="inline-flex items-center px-3 py-1.5 rounded-full bg-gray-700/70 border border-gray-600/30 backdrop-blur-sm shadow-inner">
                            <CalendarIcon className="w-3.5 h-3.5 mr-1.5 text-emerald-400" />
                            <span>Retention: <span className="font-semibold text-emerald-400">{retentionSettings.retention_text}</span></span>
                        </span>
                        <span className="inline-flex items-center px-3 py-1.5 rounded-full bg-gray-700/70 border border-gray-600/30 backdrop-blur-sm shadow-inner">
                            <ClockIcon className="w-3.5 h-3.5 mr-1.5 text-blue-400" />
                            <span>Interval: <span className="font-semibold text-blue-400">{retentionSettings.collection_text}</span></span>
                        </span>
                    </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {networkData.slice(-4).reverse().map((point, index) => (
                        <div key={index} className="bg-gray-700/60 backdrop-blur-lg rounded-xl p-4 border border-gray-600/40 shadow-inner transition-all duration-300 hover:bg-gray-700/80 hover:border-gray-500/50 group">
                            <div className="flex items-center justify-between mb-3.5">
                                <div className="bg-gray-800/60 px-3 py-1 rounded-md border border-gray-700/50">
                                    <span className="text-sm font-semibold text-gray-200">{point.time}</span>
                                </div>
                                <span className="text-xs font-medium text-gray-400">{point.day}</span>
                            </div>
                            <div className="space-y-3.5 mt-1">
                                <div className="relative overflow-hidden rounded-lg bg-gradient-to-r from-emerald-900/20 to-emerald-700/10 p-3 group-hover:from-emerald-900/30 group-hover:to-emerald-700/20 transition-all duration-300 border border-emerald-600/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <div className="w-2 h-6 rounded-sm bg-gradient-to-b from-emerald-400 to-emerald-600 mr-3"></div>
                                            <CloudDownloadIcon className="w-4 h-4 text-emerald-400" />
                                            <span className="ml-1 text-xs uppercase tracking-wider font-medium text-emerald-300">In</span>
                                        </div>
                                        <span className="text-sm font-bold text-gray-100 tracking-tight">{bytesToString(point.rx)}/s</span>
                                    </div>
                                </div>
                                <div className="relative overflow-hidden rounded-lg bg-gradient-to-r from-blue-900/20 to-blue-700/10 p-3 group-hover:from-blue-900/30 group-hover:to-blue-700/20 transition-all duration-300 border border-blue-600/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <div className="w-2 h-6 rounded-sm bg-gradient-to-b from-blue-400 to-blue-600 mr-3"></div>
                                            <CloudUploadIcon className="w-4 h-4 text-blue-400" />
                                            <span className="ml-1 text-xs uppercase tracking-wider font-medium text-blue-300">Out</span>
                                        </div>
                                        <span className="text-sm font-bold text-gray-100 tracking-tight">{bytesToString(point.tx)}/s</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            <div className="relative h-[300px] sm:h-[400px] mt-4 bg-gray-800/40 rounded-xl backdrop-blur-sm p-4 border border-gray-600/30 shadow-inner overflow-hidden" style={{ boxShadow: 'inset 0 0 20px rgba(34, 197, 94, 0.05), inset 0 0 30px rgba(59, 130, 246, 0.05), 0 0 30px rgba(34, 197, 94, 0.1), 0 0 20px rgba(59, 130, 246, 0.1)' }}>
                <div className="absolute top-4 left-4 flex items-center space-x-4">
                    <div className="flex items-center space-x-2">
                        <div className="w-3 h-3 rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500 ring-2 ring-emerald-400/30"></div>
                        <span className="text-xs font-medium text-emerald-300">Incoming</span>
                    </div>
                    <div className="flex items-center space-x-2">
                        <div className="w-3 h-3 rounded-full bg-gradient-to-r from-blue-400 to-blue-500 ring-2 ring-blue-400/30"></div>
                        <span className="text-xs font-medium text-blue-300">Outgoing</span>
                    </div>
                </div>
                <Line options={options} data={chartData} />
            </div>
        </div>
    );
};

export default StatisticsGraph;