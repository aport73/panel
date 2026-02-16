import http from '@/api/http';

export interface DatabaseHealthAlert {
    type: string;
    severity: 'low' | 'medium' | 'warning' | 'critical';
    title: string;
    description: string;
    recommendation: string;
}

export interface DatabaseHealthMetrics {
    size?: {
        total_size_mb: number;
        data_size_mb: number;
        index_size_mb: number;
        free_space_mb: number;
        table_count: number;
        storage_efficiency: number;
    };
    tables?: {
        total_tables: number;
        largest_tables: any[];
        empty_tables: number;
        fragmented_tables: number;
        engines_used: string[];
        total_rows: number;
    };
    indexes?: {
        total_indexes: number;
        duplicate_indexes: any[];
        potentially_unused: any[];
        index_types: Record<string, number>;
    };
    connections?: {
        max_connections: number;
        current_connections: number;
        connection_usage_percent: number;
    };
}

export interface DatabaseHealthAnalysis {
    database_id: string;
    database_name: string;
    server_uuid: string;
    analyzed_at: string;
    from_cache: boolean;
    cache_expires_at?: string;
    alerts: DatabaseHealthAlert[];
    metrics: DatabaseHealthMetrics;
    recommendations: string[];
    overall_health: 'good' | 'warning' | 'critical' | 'unknown';
    health_score: number;
    error?: string;
}

export default (uuid: string, databaseId: string, forceRefresh = false): Promise<DatabaseHealthAnalysis> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/analyze-health`, {
            force_refresh: forceRefresh,
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
