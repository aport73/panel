import http from '@/api/http';

interface SourceCredentials {
    host: string;
    port: number;
    username: string;
    password: string;
    database: string;
}

interface UploadDatabaseData {
    file?: File;
    mode: 'wipe' | 'merge';
    force?: boolean;
    import_type: 'file' | 'credentials';
    selective?: boolean;
    selected_tables?: string[];
    selected_columns?: Record<string, string[]>;
    source_credentials?: SourceCredentials;
}

export default (uuid: string, databaseId: string, data: UploadDatabaseData): Promise<void> => {
    const formData = new FormData();
    
    formData.append('import_type', data.import_type);
    formData.append('mode', data.mode);
    
    if (data.force) {
        formData.append('force', '1');
    }

    if (data.selective) {
        formData.append('selective', '1');
        
        if (data.selected_tables) {
            data.selected_tables.forEach(table => {
                formData.append('selected_tables[]', table);
            });
        }
        
        if (data.selected_columns) {
            Object.entries(data.selected_columns).forEach(([table, columns]) => {
                columns.forEach(column => {
                    formData.append(`selected_columns[${table}][]`, column);
                });
            });
        }
    }

    if (data.import_type === 'file' && data.file) {
        formData.append('file', data.file);
    } else if (data.import_type === 'credentials' && data.source_credentials) {
        formData.append('source_credentials[host]', data.source_credentials.host);
        formData.append('source_credentials[port]', data.source_credentials.port.toString());
        formData.append('source_credentials[username]', data.source_credentials.username);
        formData.append('source_credentials[password]', data.source_credentials.password);
        formData.append('source_credentials[database]', data.source_credentials.database);
    }

    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/upload`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        })
            .then(() => resolve())
            .catch(reject);
    });
};