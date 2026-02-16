import React, { useState, useEffect } from 'react';
import { Pie, PolarArea, Radar, Doughnut, Bar } from 'react-chartjs-2';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import { Chart as ChartJS, ArcElement, Tooltip, Legend, RadialLinearScale, PointElement, LineElement, LinearScale, BarElement, CategoryScale } from 'chart.js';
import { bytesToString } from '@/lib/formatters';
import { ChartPieIcon } from '@heroicons/react/outline';
import ChartTypeSelector, { ChartType } from './ChartTypeSelector';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    ArcElement,
    RadialLinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Legend
);

interface TrafficStats {
    rx: number;
    tx: number;
}

const createChartData = (rx: number, tx: number, labels: string[], chartType: ChartType, formatValue?: (value: number) => string) => {
    if (chartType === 'radar') {
        return {
            labels: ['Current Traffic', 'Max Capacity', 'Average Traffic', 'Minimum Traffic', 'Baseline'],
            datasets: [
                {
                    label: 'Incoming',
                    data: [rx, rx * 1.5, rx * 0.8, rx * 0.3, rx * 0.5],
                    backgroundColor: 'rgba(34, 211, 238, 0.3)',
                    borderColor: 'rgba(34, 211, 238, 0.8)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(34, 211, 238, 1)',
                    pointHoverRadius: 6,
                    fill: true,
                },
                {
                    label: 'Outgoing',
                    data: [tx, tx * 1.5, tx * 0.8, tx * 0.3, tx * 0.5],
                    backgroundColor: 'rgba(234, 179, 8, 0.3)',
                    borderColor: 'rgba(234, 179, 8, 0.8)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(234, 179, 8, 1)',
                    pointHoverRadius: 6,
                    fill: true,
                }
            ],
        };
    } else if (chartType === 'bar') {
        return {
            labels: ['Traffic'],
            datasets: [
                {
                    label: 'Incoming',
                    data: [rx],
                    backgroundColor: 'rgba(34, 211, 238, 0.5)',
                    borderColor: 'rgba(34, 211, 238, 0.8)',
                    borderWidth: 2,
                },
                {
                    label: 'Outgoing',
                    data: [tx],
                    backgroundColor: 'rgba(234, 179, 8, 0.5)',
                    borderColor: 'rgba(234, 179, 8, 0.8)',
                    borderWidth: 2,
                }
            ],
        };
    } else {
        return {
            labels,
            datasets: [
                {
                    data: [rx, tx],
                    backgroundColor: ['rgba(34, 211, 238, 0.5)', 'rgba(234, 179, 8, 0.5)'],
                    borderColor: ['rgba(34, 211, 238, 0.8)', 'rgba(234, 179, 8, 0.8)'],
                    borderWidth: 2,
                },
            ],
        };
    }
};

const createChartOptions = (formatValue?: (value: number) => string, chartType?: ChartType) => ({
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
                    if (chartType === 'radar' || chartType === 'bar') {
                        const formattedValue = formatValue ? formatValue(value) : value.toLocaleString();
                        return `${context.dataset.label}: ${formattedValue}`;
                    }
                    const total = context.dataset.data.reduce((a: number, b: number) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    const formattedValue = formatValue ? formatValue(value) : value.toLocaleString();
                    return `${context.label}: ${formattedValue} (${percentage}%)`;
                }
            }
        }
    },
    animation: {
        duration: 750,
        easing: 'easeOutQuart' as const
    },
    scales: chartType === 'radar' ? {
        r: {
            ticks: {
                callback: (value: number) => {
                    if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                    if (value >= 1000) return (value / 1000).toFixed(1) + 'K';
                    return value;
                },
                color: 'rgb(180, 180, 180)',
                font: {
                    size: 10
                },
                backdropColor: 'transparent'
            },
            angleLines: {
                color: 'rgba(100, 100, 100, 0.3)'
            },
            grid: {
                color: 'rgba(100, 100, 100, 0.3)'
            },
            pointLabels: {
                color: 'rgb(200, 200, 200)',
                font: {
                    size: 11
                }
            }
        }
    } : undefined,
    maintainAspectRatio: false
});

export default () => {
    const [bytesStats, setBytesStats] = useState<TrafficStats>({ rx: 0, tx: 0 });
    const [packetStats, setPacketStats] = useState<TrafficStats>({ rx: 0, tx: 0 });
    const [trafficChartType, setTrafficChartType] = useState<ChartType>('pie');
    const [packetsChartType, setPacketsChartType] = useState<ChartType>('pie');
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);

    useEffect(() => {
        if (!connected || !instance) return;

        const handleStats = (data: string) => {
            try {
                const values = JSON.parse(data);
                if (values.network) {
                    const minValue = 0; 
                    setBytesStats({
                        rx: Math.max(values.network.rx_bytes || 0, minValue),
                        tx: Math.max(values.network.tx_bytes || 0, minValue)
                    });
                    setPacketStats({
                        rx: Math.max(values.network.rx_packets || 0, minValue),
                        tx: Math.max(values.network.tx_packets || 0, minValue)
                    });
                }
            } catch (error) {
                console.error('Failed to parse network statistics:', error);
            }
        };

        instance.addListener(SocketEvent.STATS, handleStats);
        return () => {
            instance.removeListener(SocketEvent.STATS, handleStats);
        };
    }, [connected, instance]);

    const bytesData = createChartData(
        bytesStats.rx,
        bytesStats.tx,
        ['Incoming', 'Outgoing'],
        trafficChartType,
        bytesToString
    );

    const packetsData = createChartData(
        packetStats.rx,
        packetStats.tx,
        ['Incoming', 'Outgoing'],
        packetsChartType,
        (value: number) => value.toLocaleString()
    );

    const bytesOptions = createChartOptions(bytesToString, trafficChartType);
    const packetsOptions = createChartOptions((value: number) => value.toLocaleString(), packetsChartType);
    const renderChart = (chartType: ChartType, data: any, options: any) => {
        switch (chartType) {
            case 'pie':
                return <Pie data={data} options={options} />;
            case 'polar':
                return <PolarArea data={data} options={options} />;
            case 'radar':
                return <Radar data={data} options={options} />;
            case 'bar':
                return <Bar data={data} options={options} />;
            default:
                return <Doughnut data={data} options={options} />;
        }
    };

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8 mb-8">
            <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-6 border border-amber-500/20 shadow-lg hover:shadow-amber-500/5 transition-all duration-200">
                <div className="flex justify-between items-center mb-6">
                    <div className="flex items-center">
                        <div className="bg-gradient-to-br from-amber-600/40 to-amber-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-amber-400/50 shadow-lg shadow-amber-500/20">
                            <ChartPieIcon className="h-6 w-6 text-amber-300" />
                        </div>
                        <div>
                            <h3 className="text-amber-400 font-medium text-lg">Traffic Visualization</h3>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-400">Chart Type:</span>
                        <ChartTypeSelector 
                            selectedType={trafficChartType} 
                            onChange={setTrafficChartType}
                            className="ml-2"
                        />
                    </div>
                </div>
                <div className="bg-amber-500/5 rounded-lg p-4 border border-amber-500/10 mb-4">
                    <div className="h-60">
                        {renderChart(trafficChartType, bytesData, bytesOptions)}
                    </div>
                </div>
                <div className="bg-amber-500/5 rounded-lg p-2 border border-amber-500/10">
                    <div className="flex items-center">
                        <div className="bg-amber-500/10 p-1 rounded mr-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <p className="text-xs text-gray-400">Showing network traffic volume in bytes</p>
                    </div>
                </div>
            </div>
            <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-6 border border-blue-500/20 shadow-lg hover:shadow-blue-500/5 transition-all duration-200">
                <div className="flex justify-between items-center mb-6">
                    <div className="flex items-center">
                        <div className="bg-gradient-to-br from-blue-600/40 to-cyan-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-blue-400/50 shadow-lg shadow-blue-500/20">
                            <ChartPieIcon className="h-6 w-6 text-blue-300" />
                        </div>
                        <div>
                            <h3 className="text-blue-400 font-medium text-lg">Packets Visualization</h3>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-400">Chart Type:</span>
                        <ChartTypeSelector 
                            selectedType={packetsChartType} 
                            onChange={setPacketsChartType}
                            className="ml-2"
                        />
                    </div>
                </div>
                <div className="bg-blue-500/5 rounded-lg p-4 border border-blue-500/10 mb-4">
                    <div className="h-60">
                        {renderChart(packetsChartType, packetsData, packetsOptions)}
                    </div>
                </div>
                <div className="bg-blue-500/5 rounded-lg p-2 border border-blue-500/10">
                    <div className="flex items-center">
                        <div className="bg-blue-500/10 p-1 rounded mr-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <p className="text-xs text-gray-400">Showing network packet counts</p>
                    </div>
                </div>
            </div>
        </div>
    );
};
