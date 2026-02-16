import http from '@/api/http';

export interface IndexColumn {
    name: string;
    position: number;
    sub_part?: number;
    nullable: boolean;
}

export interface DatabaseIndex {
    name: string;
    type: string;
    unique: boolean;
    columns: IndexColumn[];
    cardinality: number;
    size_estimate: number;
}

export interface IndexRecommendation {
    type: string;
    priority: 'low' | 'medium' | 'high';
    title: string;
    description: string;
    recommendation: string;
    sql_example: string;
    impact: string;
}

export interface TableAnalysis {
    name: string;
    engine: string;
    rows: number;
    data_size: number;
    index_size: number;
    auto_increment?: number;
    columns: any[];
    indexes: DatabaseIndex[];
    foreign_keys: any[];
    query_patterns: any;
    index_usage: any[];
    recommendations: IndexRecommendation[];
}

export interface PerformanceImpact {
    overall_score: number;
    categories: {
        indexing: { score: number; issues: number };
        structure: { score: number; issues: number };
        efficiency: { score: number; issues: number };
    };
    critical_issues: number;
    optimization_potential: 'low' | 'medium' | 'high';
}

export interface AnalysisSummary {
    total_tables: number;
    total_indexes: number;
    unused_indexes: number;
    missing_indexes: number;
    redundant_indexes: number;
    performance_score: number;
}

export interface IndexAnalysisResult {
    database_name: string;
    analysis_timestamp: string;
    tables: Record<string, TableAnalysis>;
    recommendations: Record<string, IndexRecommendation[]>;
    performance_impact: PerformanceImpact;
    summary: AnalysisSummary;
    error?: boolean;
    message?: string;
}

export default (uuid: string, databaseId: string, forceRefresh: boolean = false): Promise<IndexAnalysisResult> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/databases/${databaseId}/analyze-indexes`, {
            force_refresh: forceRefresh
        })
            .then(({ data }) => {
                if (data.success) {
                    resolve(data.data);
                } else {
                    reject(new Error(data.message || 'Failed to analyze database indexes'));
                }
            })
            .catch(reject);
    });
};
