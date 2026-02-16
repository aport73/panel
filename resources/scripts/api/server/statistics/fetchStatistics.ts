import http from '@/api/http';
import { NetworkStat, StatsResponse, NetworkChartData, ChartDataPoint } from './Statistics';

const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${sizes[i]}`;
};

const formatTimestamp = (timestamp: number): string => {
    return new Date(timestamp * 1000).toLocaleTimeString();
};

const processStatsData = (data: NetworkStat[]): NetworkChartData => {
    const DISPLAY_POINTS = 10000;
    const recentData = data.slice(-DISPLAY_POINTS);
    
    return {
        rx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.rx_bytes,
            label: `RX: ${formatBytes(stat.rx_bytes)}`
        })),
        tx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.tx_bytes,
            label: `TX: ${formatBytes(stat.tx_bytes)}`
        })),
        network_rx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.rx_bytes,
            label: `RX: ${formatBytes(stat.rx_bytes)}`
        })),
        network_tx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.tx_bytes,
            label: `TX: ${formatBytes(stat.tx_bytes)}`
        })),
        packets_rx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.rx_packets,
            label: `RX Packets: ${stat.rx_packets}`
        })),
        packets_tx: recentData.map(stat => ({
            timestamp: stat.timestamp,
            value: stat.tx_packets,
            label: `TX Packets: ${stat.tx_packets}`
        }))
    };
};

export const fetchStatistics = async (uuid: string): Promise<NetworkChartData | undefined> => {
    try {
        const response = await http.get(`/api/client/servers/${uuid}/statistics`);
        const result = processStatsData(response.data.data);
        
        if (response.data.settings) {
            result.settings = response.data.settings;
        }
        
        return result;
    } catch (error) {
        console.error('Failed to fetch server statistics:', error);
        return undefined;
    }
};