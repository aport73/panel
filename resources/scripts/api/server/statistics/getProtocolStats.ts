import http from '@/api/http';

export interface ProtocolStatsResponse {
    timestamp: number;
    data: {
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
        timestamp: number;
    };
}

export default async (uuid: string): Promise<ProtocolStatsResponse> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/protocols`);
    return data;
};
