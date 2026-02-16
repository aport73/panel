import http from '@/api/http';

export default (uuid: string, databaseId: string): Promise<string> => {
    return new Promise((resolve) => {
        const downloadUrl = `/api/client/servers/${uuid}/databases/${databaseId}/download`;
        resolve(downloadUrl);
    });
};