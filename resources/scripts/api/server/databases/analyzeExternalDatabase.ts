import http from '@/api/http';

export interface ExternalDatabaseCredentials {
    host: string;
    port: number;
    username: string;
    password: string;
    database: string;
}

export interface ExternalDatabaseAnalysis {
    success: boolean;
    error?: string;
    database_name?: string;
    host?: string;
    port?: number;
    tables?: Array<{
        name: string;
        rows: number;
        size_mb: number;
        has_data: boolean;
        columns: Array<{
            column_name: string;
            data_type: string;
            is_nullable: boolean;
            column_default: string | null;
            column_key: string;
            extra: string;
        }>;
    }>;
    total_tables?: number;
    total_records?: number;
    total_size_mb?: number;
}

export default (uuid: string, databaseId: string, credentials: ExternalDatabaseCredentials): Promise<ExternalDatabaseAnalysis> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/analyze-external-database`, credentials)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
