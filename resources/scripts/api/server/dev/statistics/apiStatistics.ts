import http from '@/api/http';

export interface NetworkStats {
    rx_bytes: number;
    tx_bytes: number;
    rx_packets: number;
    tx_packets: number;
}

export interface ServerNetworkResponse {
    resources: {
        network_rx_bytes: number;
        network_tx_bytes: number;
        network_rx_packets: number;
        network_tx_packets: number;
    };
}

export const getServerStats = async (uuid: string): Promise<ServerNetworkResponse> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/stats`);
    return data.data;
};

export const getNetworkStats = async (uuid: string): Promise<NetworkStats> => {
    const stats = await getServerStats(uuid);
    return {
        rx_bytes: stats.resources.network_rx_bytes,
        tx_bytes: stats.resources.network_tx_bytes,
        rx_packets: stats.resources.network_rx_packets,
        tx_packets: stats.resources.network_tx_packets,
    };
};
