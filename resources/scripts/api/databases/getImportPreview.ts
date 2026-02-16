import http from '@/api/http';

export interface ImportPreviewTable {
    name: string;
    columns: Array<{
        name: string;
        type: string;
        nullable: boolean;
        default: string | null;
        auto_increment: boolean;
    }>;
    estimated_rows: number;
    has_data: boolean;
    primary_keys: string[];
}

export interface ImportPreviewResult {
    success: boolean;
    selected_tables: ImportPreviewTable[];
    total_tables: number;
    total_estimated_rows: number;
    file_info: {
        name: string;
        size: number;
    };
    error?: string;
}

export default (uuid: string, databaseId: string, file: File, selectedTables: string[]): Promise<ImportPreviewResult> => {
    const formData = new FormData();
    formData.append('file', file);
    selectedTables.forEach(table => {
        formData.append('selected_tables[]', table);
    });

    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/import-preview`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
