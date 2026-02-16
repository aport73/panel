import { NetworkChartData, GraphData, GraphDataPoint } from './Statistics';

export const formatBytes = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(Math.abs(bytes)) / Math.log(1024));
    const value = (bytes / Math.pow(1024, i)).toFixed(1);
    const unit = sizes[i];
    return `${value} ${unit}`;
};

export const formatTimestamp = (timestamp: number): { day: string; time: string } => {
    const date = new Date(timestamp * 1000);
    return {
        day: date.toLocaleDateString('en-US', { weekday: 'short' }),
        time: date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })
    };
};

export const formatLabel = (label: string): string => {
    return label.replace(/^(RX|TX):\s*/, '').trim();
};

export const processNetworkStats = (data: NetworkChartData): GraphData => {
    const graphData: GraphDataPoint[] = data.rx.map((rxPoint, index) => {
        const txPoint = data.tx[index];
        const rxPackets = data.packets_rx[index];
        const txPackets = data.packets_tx[index];
        const { day, time } = formatTimestamp(rxPoint.timestamp);

        return {
            timestamp: rxPoint.timestamp,
            rx: parseFloat(rxPoint.value.toFixed(2)),
            tx: parseFloat(txPoint.value.toFixed(2)),
            rx_packets: rxPackets.value,
            tx_packets: txPackets.value,
            day,
            time,
        };
    });

    const maxValue = Math.max(
        ...graphData.map(d => Math.max(d.rx, d.tx))
    );

    return {
        data: graphData,
        maxValue: maxValue * 1.1, 
    };
};
