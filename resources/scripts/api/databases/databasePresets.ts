// @ts-ignore - Pterodactyl http module
import http from '@/api/http';

export interface DatabasePresetTable {
    description: string;
    columns: Record<string, {
        type: string;
        length?: string | number;
        nullable?: boolean;
        auto_increment?: boolean;
        primary?: boolean;
        index?: boolean;
        default?: string;
        comment?: string;
    }>;
    sample_data?: Record<string, any>[];
}

export interface DatabasePreset {
    name: string;
    description: string;
    category: string;
    icon: string;
    tables: Record<string, DatabasePresetTable>;
}

export interface DatabasePresets {
    presets: Record<string, DatabasePreset>;
}

export interface ApplyPresetsRequest {
    presets: string[];
    selected_tables?: Record<string, string[]>;
}

export interface ApplyPresetsResponse {
    message: string;
    results: Record<string, Record<string, {
        success: boolean;
        message?: string;
        error?: string;
    }>>;
}

export const getDatabasePresets = (uuid: string, databaseId: string): Promise<DatabasePresets> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/databases/${databaseId}/presets`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const applyDatabasePresets = (uuid: string, databaseId: string, data: ApplyPresetsRequest): Promise<ApplyPresetsResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/presets/apply`, data)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
