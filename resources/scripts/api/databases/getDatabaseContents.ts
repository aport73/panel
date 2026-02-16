import http from '@/api/http';

export interface DatabaseColumn {
    column_name: string;
    data_type: string;
    is_nullable: string;
    column_default: string | null;
    column_key: string;
    extra: string;
    column_comment: string;
}

export interface DatabaseTable {
    name: string;
    rows: number;
    size_mb: number;
    comment: string;
    columns: DatabaseColumn[];
    sample_data: Record<string, any>[];
}

export interface DatabaseContents {
    database: string;
    tables: DatabaseTable[];
    total_records: number;
    error?: string;
}

function transformColumnData(apiColumn: any): DatabaseColumn {
    return {
        column_name: apiColumn.COLUMN_NAME || apiColumn.column_name || '',
        data_type: apiColumn.DATA_TYPE || apiColumn.data_type || '',
        is_nullable: apiColumn.IS_NULLABLE || apiColumn.is_nullable || 'NO',
        column_default: apiColumn.COLUMN_DEFAULT || apiColumn.column_default || null,
        column_key: apiColumn.COLUMN_KEY || apiColumn.column_key || '',
        extra: apiColumn.EXTRA || apiColumn.extra || '',
        column_comment: apiColumn.COLUMN_COMMENT || apiColumn.column_comment || ''
    };
}

function transformTableData(apiTable: any): DatabaseTable {
    return {
        name: apiTable.name || '',
        rows: apiTable.rows || 0,
        size_mb: apiTable.size_mb || 0,
        comment: apiTable.comment || '',
        columns: (apiTable.columns || []).map(transformColumnData),
        sample_data: apiTable.sample_data || []
    };
}

export default (uuid: string, databaseId: string): Promise<DatabaseContents> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/databases/${databaseId}/contents`)
            .then(({ data }) => {
                const transformedData: DatabaseContents = {
                    database: data.database || '',
                    tables: (data.tables || []).map(transformTableData),
                    total_records: data.total_records || 0,
                    error: data.error
                };
                resolve(transformedData);
            })
            .catch(reject);
    });
};