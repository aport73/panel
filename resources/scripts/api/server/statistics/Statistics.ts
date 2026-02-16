export interface NetworkStat {
    timestamp: number;
    rx_bytes: number;
    tx_bytes: number;
    rx_packets: number;
    tx_packets: number;
}

export interface StatsResponse {
    data: NetworkStat[];
    meta: {
        total: number;
        days: number;
    };
}

export interface ChartDataPoint {
    timestamp: number;
    value: number;
    label: string;
}

export interface RetentionSettings {
    retention_hours: number;
    collection_interval: number;
    retention_text: string;
    collection_text: string;
    deletion_interval_days?: number;
}

export interface NetworkChartData {
    rx: ChartDataPoint[];
    tx: ChartDataPoint[];
    network_rx: ChartDataPoint[];
    network_tx: ChartDataPoint[];
    packets_rx: ChartDataPoint[];
    packets_tx: ChartDataPoint[];
    settings?: RetentionSettings;
}

export interface GraphDataPoint {
    timestamp: number;
    rx: number;
    tx: number;
    rx_packets: number;
    tx_packets: number;
    day: string;
    time: string;
}

export interface GraphData {
    data: GraphDataPoint[];
    maxValue: number;
}