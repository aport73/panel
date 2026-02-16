import React, { useState, useEffect } from 'react';
import { ChartPieIcon } from '@heroicons/react/outline';
import { Pie, Bar, Doughnut, PolarArea, Radar } from 'react-chartjs-2';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import { 
    Chart as ChartJS, 
    ArcElement, 
    Tooltip, 
    Legend,
    RadialLinearScale,
    PointElement,
    LineElement,
    CategoryScale,
    LinearScale,
    BarElement
} from 'chart.js';
import getProtocolStats, { ProtocolStatsResponse } from '@/api/server/statistics/getProtocolStats';
import ChartTypeSelector, { ChartType } from './ChartTypeSelector';

ChartJS.register(
    ArcElement,
    Tooltip,
    Legend,
    RadialLinearScale,
    PointElement,
    LineElement,
    CategoryScale,
    LinearScale,
    BarElement
);

interface ProtocolStats {
    total: {
        in_packets: number;
        out_packets: number;
    };
    tcp: {
        in_packets: number;
        out_packets: number;
        in_errors: number;
        out_errors: number;
    };
    udp: {
        in_datagrams: number;
        out_datagrams: number;
        in_errors: number;
        no_ports: number;
    };
    icmp: {
        in_msgs: number;
        out_msgs: number;
        in_errors: number;
        out_errors: number;
    };
    other: {
        in_packets: number;
        out_packets: number;
    };
    timestamp: number | null;
}

const CHART_COLORS = {
    tcp: { bg: 'rgba(59, 130, 246, 0.2)', border: 'rgba(59, 130, 246, 0.9)' },
    udp: { bg: 'rgba(20, 184, 166, 0.2)', border: 'rgba(20, 184, 166, 0.9)' },   
    icmp: { bg: 'rgba(245, 158, 11, 0.2)', border: 'rgba(245, 158, 11, 0.9)' }, 
    other: { bg: 'rgba(139, 92, 246, 0.2)', border: 'rgba(139, 92, 246, 0.9)' },  
    total: { bg: 'rgba(75, 85, 99, 0.2)', border: 'rgba(75, 85, 99, 0.9)' } 
};

const calculateTotals = (stats: ProtocolStats) => {
    const tcpTotal = (stats.tcp?.in_packets || 0) + (stats.tcp?.out_packets || 0);
    const udpTotal = (stats.udp?.in_datagrams || 0) + (stats.udp?.out_datagrams || 0);
    const icmpTotal = (stats.icmp?.in_msgs || 0) + (stats.icmp?.out_msgs || 0);
    const otherTotal = (stats.other?.in_packets || 0) + (stats.other?.out_packets || 0);
    return { tcpTotal, udpTotal, icmpTotal, otherTotal };
};

const createChartData = (stats: ProtocolStats, chartType: ChartType) => {
    const { tcpTotal, udpTotal, icmpTotal, otherTotal } = calculateTotals(stats);
    const protocolLabels = ['TCP', 'UDP', 'ICMP', 'Other'];
    const backgroundColors = [
        CHART_COLORS.tcp.bg,
        CHART_COLORS.udp.bg, 
        CHART_COLORS.icmp.bg,
        CHART_COLORS.other.bg
    ];
    
    const borderColors = [
        CHART_COLORS.tcp.border,
        CHART_COLORS.udp.border,
        CHART_COLORS.icmp.border,
        CHART_COLORS.other.border
    ];
    
    if (chartType === 'line' || chartType === 'bar') {
        return {
            labels: protocolLabels,
            datasets: [
                {
                    label: 'Protocol Distribution',
                    data: [tcpTotal, udpTotal, icmpTotal, otherTotal],
                    backgroundColor: backgroundColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                },
            ],
        };
    } else if (chartType === 'radar' || chartType === 'polar') {
        return {
            labels: protocolLabels,
            datasets: [
                {
                    label: 'Protocol Distribution',
                    data: [tcpTotal, udpTotal, icmpTotal, otherTotal],
                    backgroundColor: backgroundColors,
                    borderColor: borderColors,
                    borderWidth: 2,
                    pointBackgroundColor: borderColors,
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: CHART_COLORS.tcp.border,
                },
            ],
        };
    } else {
        return {
            labels: protocolLabels,
            datasets: [
                {
                    label: 'Protocol Distribution',
                    data: [tcpTotal, udpTotal, icmpTotal, otherTotal],
                    backgroundColor: backgroundColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                },
            ],
        };
    }
};

const createChartOptions = (chartType: ChartType = 'pie') => ({
    aspectRatio: 1,
    responsive: true,
    plugins: {
        legend: {
            position: 'bottom' as const,
            labels: {
                color: 'rgb(229, 231, 235)',
                font: {
                    size: 12
                }
            }
        },
        tooltip: {
            callbacks: {
                label: (context: any) => {
                    const value = context.raw;
                    const total = context.dataset.data.reduce((a: number, b: number) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${context.label}: ${value.toLocaleString()} (${percentage}%)`;
                }
            }
        }
    },
    animation: {
        duration: 750,
        easing: 'easeOutQuart' as const
    },
    maintainAspectRatio: false
});

export default () => {
    const [chartType, setChartType] = useState<ChartType>('pie');
    useEffect(() => {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @keyframes pulse {
                0%, 100% { transform: translateY(0); opacity: 0.2; }
                50% { transform: translateY(100%); opacity: 0.8; }
            }
        `;
        document.head.appendChild(style);
        return () => style.remove();
    }, []);
    const [stats, setStats] = useState<ProtocolStats>({
        total: { in_packets: 0, out_packets: 0 },
        tcp: { in_packets: 0, out_packets: 0, in_errors: 0, out_errors: 0 },
        udp: { in_datagrams: 0, out_datagrams: 0, in_errors: 0, no_ports: 0 },
        icmp: { in_msgs: 0, out_msgs: 0, in_errors: 0, out_errors: 0 },
        other: { in_packets: 0, out_packets: 0 },
        timestamp: null,
    });
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);


    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);

    const getStats = async () => {
        try {
            const response = await getProtocolStats(uuid);
            setStats({
                ...response.data,
                timestamp: response.timestamp
            });
            

        } catch (error) {
            console.error('Failed to fetch protocol statistics:', error);
        }
    };

    useEffect(() => {
        getStats();
        const interval = setInterval(getStats, 60000);

        return () => clearInterval(interval);
    }, [uuid]);

    useEffect(() => {
        if (!connected || !instance) return;

        const handleStats = async (data: string) => {
            try {
                const values = JSON.parse(data);
                if (values.data?.protocols) {
                    const protocolData = values.data.protocols;
                    console.log('Websocket timestamp:', protocolData.timestamp);
                    setStats({
                        total: protocolData.total || { in_packets: 0, out_packets: 0 },
                        tcp: protocolData.tcp || { in_packets: 0, out_packets: 0, in_errors: 0, out_errors: 0 },
                        udp: protocolData.udp || { in_datagrams: 0, out_datagrams: 0, in_errors: 0, no_ports: 0 },
                        icmp: protocolData.icmp || { in_msgs: 0, out_msgs: 0, in_errors: 0, out_errors: 0 },
                        other: protocolData.other || { in_packets: 0, out_packets: 0 },
                        timestamp: protocolData.timestamp
                    });
                    

                }
            } catch (error) {
                console.error('Failed to parse protocol statistics:', error);
            }
        };

        instance.addListener(SocketEvent.STATS, handleStats);
        return () => {
            instance.removeListener(SocketEvent.STATS, handleStats);
        };
    }, [connected, instance]);

    const { tcpTotal, udpTotal, icmpTotal, otherTotal } = calculateTotals(stats);
    const data = createChartData(stats, chartType);
    const options = createChartOptions(chartType);

    const total = tcpTotal + udpTotal + icmpTotal + otherTotal;
    const getPercent = (value: number) => ((value / total) * 100).toFixed(1);

    return (
        <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-lg p-4 md:p-6 border border-gray-600 shadow-lg">
            <div className="flex items-center justify-between mb-6">
                <h3 className="text-base md:text-lg font-medium text-gray-50 flex items-center gap-2">
                    <div className="bg-gradient-to-br from-purple-500/20 to-violet-600/20 p-2.5 rounded-lg backdrop-blur-sm ring-1 ring-purple-500/30 shadow-lg shadow-purple-500/10">
                        <ChartPieIcon className="h-5 w-5 text-purple-400" />
                    </div>
                    Protocol Distribution
                </h3>
                <div className="text-sm text-gray-400">
                    Last Update: {stats.timestamp ? new Date(stats.timestamp * 1000).toLocaleString() : 'Never'}
                </div>
            </div>
            <div className="mb-5">
                <div className="flex items-center">
                    <span className="text-sm text-gray-400 mr-2">Chart Type:</span>
                    <ChartTypeSelector 
                        selectedType={chartType} 
                        onChange={setChartType} 
                        className="ml-1"
                    />
                </div>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="h-96">
                    <div className="h-full w-full max-w-[400px] mx-auto">
                        {chartType === 'pie' && <Pie data={data} options={options} />}
                        {chartType === 'bar' && <Bar data={data} options={options} />}
                        {chartType === 'radar' && <Radar data={data} options={options} />}
                        {chartType === 'line' && <Bar data={data} options={options} />}
                        {chartType === 'polar' && <PolarArea data={data} options={options} />}
                    </div>
                </div>
                <div className="flex flex-col justify-center space-y-4">
                    <div className="group relative h-[72px] transition-all duration-300 hover:h-[120px] overflow-hidden rounded-xl backdrop-blur-sm ring-1 ring-opacity-30" 
                         style={{ backgroundColor: CHART_COLORS.tcp.bg, borderRight: `4px solid ${CHART_COLORS.tcp.border}` }}>
                        <div className="absolute inset-0 overflow-hidden">
                            <div 
                                className="absolute w-[200%] h-[200%] top-[-50%] left-[-50%] bg-gradient-to-br from-blue-500/[0.05] to-transparent" 
                                style={{ animation: 'spin 15s linear infinite' }}
                            />
                            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                {[10, 30, 50, 70, 90].map((left, i) => (
                                    <div 
                                        key={left}
                                        className={`absolute top-0 left-[${left}%] w-1 h-${i === 2 ? 16 : i % 2 === 0 ? 8 : 12} bg-blue-500/20`}
                                        style={{ animation: `pulse 2s ${i * 0.15}s ease-in-out infinite` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="absolute inset-0 p-4 flex items-center justify-between transition-all duration-300 group-hover:translate-y-[-120px] group-hover:opacity-0">
                            <div>
                                <div className="text-sm font-semibold text-gray-100">TCP</div>
                                <div className="text-xs font-medium text-gray-400">In: {stats.tcp.in_packets.toLocaleString()} | Out: {stats.tcp.out_packets.toLocaleString()}</div>
                            </div>
                            <div className="text-lg font-bold text-gray-100 tracking-tight">{getPercent(tcpTotal)}%</div>
                        </div>
                        <div className="absolute inset-0 p-4 flex flex-col items-center justify-center translate-y-[120px] opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                             style={{ background: `linear-gradient(135deg, ${CHART_COLORS.tcp.border}40, ${CHART_COLORS.tcp.bg} 70%)` }}>
                            <div className="text-2xl font-bold text-blue-300 mb-2">{getPercent(tcpTotal)}%</div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div className="text-emerald-400 text-lg font-semibold">▲ In</div>
                                    <div className="text-gray-200">{stats.tcp.in_packets.toLocaleString()}</div>
                                </div>
                                <div>
                                    <div className="text-blue-400 text-lg font-semibold">▼ Out</div>
                                    <div className="text-gray-200">{stats.tcp.out_packets.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="group relative h-[72px] transition-all duration-300 hover:h-[120px] overflow-hidden rounded-xl backdrop-blur-sm ring-1 ring-opacity-30" 
                         style={{ backgroundColor: CHART_COLORS.udp.bg, borderRight: `4px solid ${CHART_COLORS.udp.border}` }}>
                        <div className="absolute inset-0 overflow-hidden">
                            <div 
                                className="absolute w-[200%] h-[200%] top-[-50%] left-[-50%] bg-gradient-to-br from-emerald-500/[0.05] to-transparent" 
                                style={{ animation: 'spin 15s linear infinite' }}
                            />
                            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                {[10, 30, 50, 70, 90].map((left, i) => (
                                    <div 
                                        key={left}
                                        className={`absolute top-0 left-[${left}%] w-1 h-${i === 2 ? 16 : i % 2 === 0 ? 8 : 12} bg-emerald-500/20`}
                                        style={{ animation: `pulse 2s ${i * 0.15}s ease-in-out infinite` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="absolute inset-0 p-4 flex items-center justify-between transition-all duration-300 group-hover:translate-y-[-120px] group-hover:opacity-0">
                            <div>
                                <div className="text-sm font-semibold text-gray-100">UDP</div>
                                <div className="text-xs font-medium text-gray-400">In: {stats.udp.in_datagrams.toLocaleString()} | Out: {stats.udp.out_datagrams.toLocaleString()}</div>
                            </div>
                            <div className="text-lg font-bold text-gray-100 tracking-tight">{getPercent(udpTotal)}%</div>
                        </div>
                        <div className="absolute inset-0 p-4 flex flex-col items-center justify-center translate-y-[120px] opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                             style={{ background: `linear-gradient(135deg, ${CHART_COLORS.udp.border}40, ${CHART_COLORS.udp.bg} 70%)` }}>
                            <div className="text-2xl font-bold text-emerald-300 mb-2">{getPercent(udpTotal)}%</div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div className="text-emerald-400 text-lg font-semibold">▲ In</div>
                                    <div className="text-gray-200">{stats.udp.in_datagrams.toLocaleString()}</div>
                                </div>
                                <div>
                                    <div className="text-blue-400 text-lg font-semibold">▼ Out</div>
                                    <div className="text-gray-200">{stats.udp.out_datagrams.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="group relative h-[72px] transition-all duration-300 hover:h-[120px] overflow-hidden rounded-xl backdrop-blur-sm ring-1 ring-opacity-30" 
                         style={{ backgroundColor: CHART_COLORS.icmp.bg, borderRight: `4px solid ${CHART_COLORS.icmp.border}` }}>
                        <div className="absolute inset-0 overflow-hidden">
                            <div 
                                className="absolute w-[200%] h-[200%] top-[-50%] left-[-50%] bg-gradient-to-br from-yellow-500/[0.05] to-transparent" 
                                style={{ animation: 'spin 15s linear infinite' }}
                            />
                            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                {[10, 30, 50, 70, 90].map((left, i) => (
                                    <div 
                                        key={left}
                                        className={`absolute top-0 left-[${left}%] w-1 h-${i === 2 ? 16 : i % 2 === 0 ? 8 : 12} bg-yellow-500/20`}
                                        style={{ animation: `pulse 2s ${i * 0.15}s ease-in-out infinite` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="absolute inset-0 p-4 flex items-center justify-between transition-all duration-300 group-hover:translate-y-[-120px] group-hover:opacity-0">
                            <div>
                                <div className="text-sm font-semibold text-gray-100">ICMP</div>
                                <div className="text-xs font-medium text-gray-400">In: {stats.icmp.in_msgs.toLocaleString()} | Out: {stats.icmp.out_msgs.toLocaleString()}</div>
                            </div>
                            <div className="text-lg font-bold text-gray-100 tracking-tight">{getPercent(icmpTotal)}%</div>
                        </div>
                        <div className="absolute inset-0 p-4 flex flex-col items-center justify-center translate-y-[120px] opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                             style={{ background: `linear-gradient(135deg, ${CHART_COLORS.icmp.border}40, ${CHART_COLORS.icmp.bg} 70%)` }}>
                            <div className="text-2xl font-bold text-yellow-300 mb-2">{getPercent(icmpTotal)}%</div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div className="text-emerald-400 text-lg font-semibold">▲ In</div>
                                    <div className="text-gray-200">{stats.icmp.in_msgs.toLocaleString()}</div>
                                </div>
                                <div>
                                    <div className="text-blue-400 text-lg font-semibold">▼ Out</div>
                                    <div className="text-gray-200">{stats.icmp.out_msgs.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="group relative h-[72px] transition-all duration-300 hover:h-[120px] overflow-hidden rounded-xl backdrop-blur-sm ring-1 ring-opacity-30" 
                         style={{ backgroundColor: CHART_COLORS.other.bg, borderRight: `4px solid ${CHART_COLORS.other.border}` }}>
                        <div className="absolute inset-0 overflow-hidden">
                            <div 
                                className="absolute w-[200%] h-[200%] top-[-50%] left-[-50%] bg-gradient-to-br from-purple-500/[0.05] to-transparent" 
                                style={{ animation: 'spin 15s linear infinite' }}
                            />
                            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                {[10, 30, 50, 70, 90].map((left, i) => (
                                    <div 
                                        key={left}
                                        className={`absolute top-0 left-[${left}%] w-1 h-${i === 2 ? 16 : i % 2 === 0 ? 8 : 12} bg-purple-500/20`}
                                        style={{ animation: `pulse 2s ${i * 0.15}s ease-in-out infinite` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="absolute inset-0 p-4 flex items-center justify-between transition-all duration-300 group-hover:translate-y-[-120px] group-hover:opacity-0">
                            <div>
                                <div className="text-sm font-semibold text-gray-100">Other</div>
                                <div className="text-xs font-medium text-gray-400">In: {stats.other.in_packets.toLocaleString()} | Out: {stats.other.out_packets.toLocaleString()}</div>
                            </div>
                            <div className="text-lg font-bold text-gray-100 tracking-tight">{getPercent(otherTotal)}%</div>
                        </div>
                        <div className="absolute inset-0 p-4 flex flex-col items-center justify-center translate-y-[120px] opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                             style={{ background: `linear-gradient(135deg, ${CHART_COLORS.other.border}40, ${CHART_COLORS.other.bg} 70%)` }}>
                            <div className="text-2xl font-bold text-purple-300 mb-2">{getPercent(otherTotal)}%</div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div className="text-emerald-400 text-lg font-semibold">▲ In</div>
                                    <div className="text-gray-200">{stats.other.in_packets.toLocaleString()}</div>
                                </div>
                                <div>
                                    <div className="text-blue-400 text-lg font-semibold">▼ Out</div>
                                    <div className="text-gray-200">{stats.other.out_packets.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="group relative h-[72px] transition-all duration-300 hover:h-[120px] overflow-hidden rounded-lg mt-4" 
                         style={{ backgroundColor: CHART_COLORS.total.bg, borderRight: `4px solid ${CHART_COLORS.total.border}` }}>
                        <div className="absolute inset-0 overflow-hidden">
                            <div 
                                className="absolute w-[200%] h-[200%] top-[-50%] left-[-50%] bg-gradient-to-br from-gray-500/[0.05] to-transparent" 
                                style={{ animation: 'spin 15s linear infinite' }}
                            />
                            <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                                {[10, 30, 50, 70, 90].map((left, i) => (
                                    <div 
                                        key={left}
                                        className="absolute top-0 bg-gray-500/20"
                                        style={{ 
                                            left: `${left}%`, 
                                            width: '1px',
                                            height: i === 2 ? '4rem' : i % 2 === 0 ? '2rem' : '3rem',
                                            animation: `pulse 2s ${i * 0.15}s ease-in-out infinite` 
                                        }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="absolute inset-0 p-4 flex items-center justify-between transition-all duration-300 group-hover:translate-y-[-120px] group-hover:opacity-0">
                            <div>
                                <div className="text-sm font-semibold text-gray-100">Total Packets</div>
                                <div className="text-xs font-medium text-gray-400">In: {stats.total.in_packets.toLocaleString()} | Out: {stats.total.out_packets.toLocaleString()}</div>
                            </div>
                            <div className="text-lg font-bold text-gray-100 tracking-tight">100%</div>
                        </div>
                        <div className="absolute inset-0 p-4 flex flex-col items-center justify-center translate-y-[120px] opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100"
                             style={{ background: `linear-gradient(135deg, ${CHART_COLORS.total.border}40, ${CHART_COLORS.total.bg} 70%)` }}>
                            <div className="text-2xl font-bold text-gray-100 mb-3 tracking-tight">Total</div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div className="text-emerald-400 text-lg font-semibold">▲ In</div>
                                    <div className="text-gray-200">{stats.total.in_packets.toLocaleString()}</div>
                                </div>
                                <div>
                                    <div className="text-blue-400 text-lg font-semibold">▼ Out</div>
                                    <div className="text-gray-200">{stats.total.out_packets.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="mt-6 bg-purple-500/5 rounded-lg p-2 border border-violet-500/10">
                    <div className="flex items-center">
                        <div className="bg-violet-500/10 p-1 rounded mr-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <p className="text-xs text-gray-400">Showing network protocol distribution by packet count</p>
                    </div>
                </div>
            </div>
        </div>
    );
};
