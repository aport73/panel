import http from '@/api/http';

export interface TableColumn {
    name: string;
    type: string;
    nullable: boolean;
    default: string | null;
    auto_increment: boolean;
}

export interface AnalyzedTable {
    name: string;
    columns: TableColumn[];
    estimated_rows: number;
    has_data: boolean;
    primary_keys: string[];
}

export interface FileAnalysisResult {
    success: boolean;
    tables: AnalyzedTable[];
    file_size: number;
    file_name: string;
    estimated_rows: number;
    error?: string;
}

export default (uuid: string, databaseId: string, file: File): Promise<FileAnalysisResult> => {
    const formData = new FormData();
    formData.append('file', file);

    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/analyze-file`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
