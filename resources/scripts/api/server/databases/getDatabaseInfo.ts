import http from '@/api/http';

export interface DatabaseInfo {
    table_count: number;
    size_mb: number;
    error?: string;
}

export default (uuid: string, databaseId: string): Promise<DatabaseInfo> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/databases/${databaseId}/info`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};