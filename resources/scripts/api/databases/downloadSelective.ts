export interface SelectiveExportOptions {
    format: 'sql' | 'gz' | 'bz2' | 'zip' | 'tar' | '7z' | 'rar';
    filename?: string;
    selected_tables: string[];
    selected_columns?: Record<string, string[]>;
    row_filters?: Record<string, string>;
    row_limits?: Record<string, number>;
}

export default (uuid: string, databaseId: string, options: SelectiveExportOptions): void => {
    const params = new URLSearchParams();
    
    params.append('format', options.format);
    if (options.filename) {
        params.append('filename', options.filename);
    }
    
    options.selected_tables.forEach(table => {
        params.append('selected_tables[]', table);
    });
    
    if (options.selected_columns) {
        Object.entries(options.selected_columns).forEach(([table, columns]) => {
            columns.forEach(column => {
                params.append(`selected_columns[${table}][]`, column);
            });
        });
    }
    
    if (options.row_filters) {
        Object.entries(options.row_filters).forEach(([table, filter]) => {
            params.append(`row_filters[${table}]`, filter);
        });
    }
    
    if (options.row_limits) {
        Object.entries(options.row_limits).forEach(([table, limit]) => {
            params.append(`row_limits[${table}]`, limit.toString());
        });
    }

    const url = `/api/client/servers/${uuid}/databases/${databaseId}/download-selective?${params.toString()}`;
    
    // Create a temporary anchor element to trigger download
    const link = document.createElement('a');
    link.href = url;
    link.download = options.filename || `database_selective_${Date.now()}.${options.format}`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};
