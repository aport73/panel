import http from '@/api/http';

export interface TableDataResponse {
    data: Record<string, any>[];
    total: number;
    page: number;
    limit: number;
    primary_keys: string[];
    has_primary_key: boolean;
    error?: string;
}

export interface UpdateRowRequest {
    primary_key_values: Record<string, any>;
    update_data: Record<string, any>;
    force?: boolean;
}

export interface DeleteRowRequest {
    primary_key_values: Record<string, any>;
    force?: boolean;
}

export interface InsertRowRequest {
    row_data: Record<string, any>;
}

export interface OperationResponse {
    success: boolean;
    affected_rows?: number;
    message?: string;
    error?: string;
    foreign_key_error?: boolean;
    error_type?: string;
    referencing_tables?: string[];
}

const convertBooleanValues = (data: Record<string, any>, columns: TableColumn[]): Record<string, any> => {
    const converted = { ...data };

    columns.forEach(column => {
        if (column.type && ['BOOLEAN', 'BOOL', 'TINYINT'].includes(column.type?.toUpperCase() || '') &&
            typeof converted[column.name] === 'string' &&
            converted[column.name] !== '') {
            const value = converted[column.name].toLowerCase().trim();
            if (value === 'true') {
                converted[column.name] = '1';
            } else if (value === 'false') {
                converted[column.name] = '0';
            }
        }
    });

    return converted;
};

export const getTableData = (uuid: string, databaseId: string, tableName: string, page = 1, limit = 50): Promise<TableDataResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/data`, {
            params: { page, limit }
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const updateTableRow = (uuid: string, databaseId: string, tableName: string, request: UpdateRowRequest, columns?: TableColumn[]): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        const processedRequest = columns ? {
            ...request,
            update_data: convertBooleanValues(request.update_data, columns)
        } : request;

        http.put(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/row`, processedRequest)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const deleteTableRow = (uuid: string, databaseId: string, tableName: string, request: DeleteRowRequest): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/row`, {
            data: request
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export interface CreateTableRequest {
    table_name: string;
    columns: TableColumn[];
}

export interface TableColumn {
    name: string;
    type: string;
    length?: number;
    precision?: number;
    enum_values?: string[];
    set_values?: string[];
    nullable: boolean;
    default?: string;
    auto_increment: boolean;
    primary_key: boolean;
}

export interface AddColumnRequest {
    name: string;
    type: string;
    length?: number;
    precision?: number;
    enum_values?: string[];
    set_values?: string[];
    nullable: boolean;
    default?: string;
    auto_increment: boolean;
    primary_key?: boolean;
    after?: string;
}

export const createTable = (uuid: string, databaseId: string, request: CreateTableRequest): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/tables`, request)
            .then(({ data }) => {
                resolve(data);
            })
            .catch((error) => {
                console.error('API: createTable failed', {
                    error,
                    errorMessage: error?.message,
                    errorResponse: error?.response?.data,
                    errorStatus: error?.response?.status,
                    request
                });
                reject(error);
            });
    });
};

export const addColumn = (uuid: string, databaseId: string, tableName: string, request: AddColumnRequest): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/columns`, request)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const insertTableRow = (uuid: string, databaseId: string, tableName: string, request: InsertRowRequest, columns?: TableColumn[]): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        const processedRequest = columns ? {
            ...request,
            row_data: convertBooleanValues(request.row_data, columns)
        } : request;

        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/row`, processedRequest)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const dropTable = (uuid: string, databaseId: string, tableName: string, force = false): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}`, {
            data: { force }
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const dropColumn = (uuid: string, databaseId: string, tableName: string, columnName: string): Promise<OperationResponse> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/column/${columnName}`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export interface SearchDatabaseRequest {
    search_term: string;
    tables?: string[];
    limit?: number;
}

export interface SearchDatabaseResponse {
    success: boolean;
    results: SearchTableResult[];
    total_matches: number;
    search_term: string;
    error?: string;
}

export interface SearchTableResult {
    table: string;
    matches: number;
    rows: Record<string, any>[];
    columns: string[];
}

export interface ExecuteSQLRequest {
    sql: string;
    is_select?: boolean;
    confirm_risky?: boolean;
}

export interface ExecuteSQLResponse {
    success: boolean;
    results?: any[];
    columns?: string[];
    row_count?: number;
    affected_rows?: number;
    execution_time_ms?: number;
    last_insert_id?: number;
    query?: string;
    error?: string;
    terminal_output?: string; 
    executed_at?: string; 
}

export interface SearchTableRequest {
    search_term?: string;
    search_columns?: string[];
    page?: number;
    limit?: number;
}

export const searchDatabase = (uuid: string, databaseId: string, request: SearchDatabaseRequest): Promise<SearchDatabaseResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/search`, request)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const executeSQL = (uuid: string, databaseId: string, request: ExecuteSQLRequest): Promise<ExecuteSQLResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/execute-sql`, request)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const searchTableData = (uuid: string, databaseId: string, tableName: string, request: SearchTableRequest): Promise<TableDataResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/search`, request)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};