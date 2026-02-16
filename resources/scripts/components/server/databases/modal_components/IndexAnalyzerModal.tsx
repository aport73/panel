import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { 
    faSearch, 
    faSpinner, 
    faRedo, 
    faExclamationTriangle, 
    faCheckCircle, 
    faInfoCircle,
    faTimes,
    faDatabase,
    faKey,
    faCog,
    faChartBar,
    faLightbulb,
    faCode
} from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { ServerContext } from '@/state/server';
import analyzeIndexes, { IndexAnalysisResult, IndexRecommendation } from '@/api/server/databases/analyzeIndexes';
import GradientModal from './GradientModal';
import useFlash from '@/plugins/useFlash';

interface Props {
    databaseId: string;
    databaseName: string;
    visible: boolean;
    onDismissed: () => void;
}

const AnalysisContainer = styled.div`
    ${tw`p-6 space-y-6 max-h-screen overflow-y-auto`};
`;

const StatsGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6`};
`;

const StatCard = styled.div<{ variant?: 'success' | 'warning' | 'error' | 'info' }>`
    ${tw`p-4 rounded-lg border transition-all duration-300 hover:shadow-lg`};
    
    ${props => {
        switch (props.variant) {
            case 'success': return tw`bg-green-900 bg-opacity-20 border-green-600`;
            case 'warning': return tw`bg-yellow-900 bg-opacity-20 border-yellow-600`;
            case 'error': return tw`bg-red-900 bg-opacity-20 border-red-600`;
            default: return tw`bg-blue-900 bg-opacity-20 border-blue-600`;
        }
    }}
`;

const RecommendationCard = styled.div<{ priority: 'low' | 'medium' | 'high' }>`
    ${tw`p-4 rounded-lg border transition-all duration-300 hover:shadow-lg mb-4`};
    
    ${props => {
        switch (props.priority) {
            case 'high': return tw`bg-red-900 bg-opacity-20 border-red-600`;
            case 'medium': return tw`bg-yellow-900 bg-opacity-20 border-yellow-600`;
            default: return tw`bg-blue-900 bg-opacity-20 border-blue-600`;
        }
    }}
`;

const CodeBlock = styled.pre`
    ${tw`bg-gray-800 border border-gray-700 rounded-lg p-3 text-sm text-gray-300 overflow-x-auto mt-2`};
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
`;

const IndexAnalyzerModal: React.FC<Props> = ({ databaseId, databaseName, visible, onDismissed }) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes } = useFlash();
    
    const [loading, setLoading] = useState(false);
    const [analysis, setAnalysis] = useState<IndexAnalysisResult | null>(null);
    const [selectedTable, setSelectedTable] = useState<string | null>(null);
    const [expandedRecommendations, setExpandedRecommendations] = useState<Set<string>>(new Set());

    const analyzeDatabase = async (forceRefresh = false) => {
        if (!visible) return;
        
        setLoading(true);
        clearFlashes();
        
        try {
            const result = await analyzeIndexes(uuid, databaseId, forceRefresh);
            setAnalysis(result);
            
            if (!selectedTable && result.tables && Object.keys(result.tables).length > 0) {
                setSelectedTable(Object.keys(result.tables)[0]);
            }
        } catch (error: any) {
            console.error('Failed to analyze indexes:', error);
            addError({ message: 'Failed to analyze database indexes: ' + (error.message || 'Unknown error') });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (visible) {
            analyzeDatabase();
        }
    }, [visible, uuid, databaseId]);

    const toggleRecommendation = (id: string) => {
        const newExpanded = new Set(expandedRecommendations);
        if (newExpanded.has(id)) {
            newExpanded.delete(id);
        } else {
            newExpanded.add(id);
        }
        setExpandedRecommendations(newExpanded);
    };

    const getPriorityIcon = (priority: string | undefined) => {
        switch (priority) {
            case 'high': return faExclamationTriangle;
            case 'medium': return faInfoCircle;
            default: return faCheckCircle;
        }
    };

    const getPriorityColor = (priority: string | undefined) => {
        switch (priority) {
            case 'high': return 'text-red-400';
            case 'medium': return 'text-yellow-400';
            default: return 'text-blue-400';
        }
    };

    const getScoreColor = (score: number) => {
        if (score >= 80) return 'text-green-400';
        if (score >= 60) return 'text-yellow-400';
        return 'text-red-400';
    };

    if (!visible) return null;

    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size="xxl"
            dismissable={!loading}
        >
            <AnalysisContainer>
                {/* Header */}
                <div css={tw`flex items-center justify-between mb-6`}>
                    <div css={tw`flex items-center`}>
                        <div css={tw`w-12 h-12 bg-gradient-to-br from-blue-600 to-blue-700 rounded-xl flex items-center justify-center shadow-lg mr-4`}>
                            <FontAwesomeIcon icon={faSearch} css={tw`text-2xl text-white`} />
                        </div>
                        <div>
                            <h2 css={tw`text-2xl font-bold text-white`}>Index Analyzer</h2>
                            <p css={tw`text-neutral-400`}>Performance analysis for {databaseName}</p>
                        </div>
                    </div>
                    
                    <button
                        type="button"
                        onClick={() => analyzeDatabase(true)}
                        disabled={loading}
                        css={[
                            tw`px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-all duration-300 flex items-center gap-2`,
                            loading && tw`opacity-50 cursor-not-allowed`
                        ]}
                    >
                        <FontAwesomeIcon icon={loading ? faSpinner : faRedo} spin={loading} />
                        {loading ? 'Analyzing...' : 'Refresh Analysis'}
                    </button>
                </div>

                {loading && !analysis && (
                    <div css={tw`flex items-center justify-center py-12`}>
                        <div css={tw`text-center`}>
                            <FontAwesomeIcon icon={faSpinner} spin css={tw`text-4xl text-blue-400 mb-4`} />
                            <p css={tw`text-lg text-white font-semibold`}>Analyzing Database Indexes...</p>
                            <p css={tw`text-neutral-400`}>This may take a few moments</p>
                        </div>
                    </div>
                )}

                {analysis && !analysis.error && (
                    <>
                        {/* Summary Stats */}
                        <StatsGrid>
                            <StatCard variant="info">
                                <div css={tw`flex items-center justify-between`}>
                                    <div>
                                        <p css={tw`text-sm text-blue-300 font-medium`}>Overall Score</p>
                                        <p css={[tw`text-2xl font-bold`, getScoreColor(analysis.performance_impact?.overall_score || 0)]}>
                                            {analysis.performance_impact?.overall_score || 0}%
                                        </p>
                                    </div>
                                    <FontAwesomeIcon icon={faChartBar} css={tw`text-2xl text-blue-400`} />
                                </div>
                            </StatCard>

                            <StatCard variant={(analysis.performance_impact?.critical_issues || 0) > 0 ? 'error' : 'success'}>
                                <div css={tw`flex items-center justify-between`}>
                                    <div>
                                        <p css={tw`text-sm text-gray-300 font-medium`}>Critical Issues</p>
                                        <p css={tw`text-2xl font-bold text-white`}>{analysis.performance_impact?.critical_issues || 0}</p>
                                    </div>
                                    <FontAwesomeIcon 
                                        icon={(analysis.performance_impact?.critical_issues || 0) > 0 ? faExclamationTriangle : faCheckCircle} 
                                        css={[
                                            tw`text-2xl`,
                                            (analysis.performance_impact?.critical_issues || 0) > 0 ? tw`text-red-400` : tw`text-green-400`
                                        ]} 
                                    />
                                </div>
                            </StatCard>

                            <StatCard variant="warning">
                                <div css={tw`flex items-center justify-between`}>
                                    <div>
                                        <p css={tw`text-sm text-yellow-300 font-medium`}>Unused Indexes</p>
                                        <p css={tw`text-2xl font-bold text-white`}>{analysis.summary?.unused_indexes || 0}</p>
                                    </div>
                                    <FontAwesomeIcon icon={faDatabase} css={tw`text-2xl text-yellow-400`} />
                                </div>
                            </StatCard>

                            <StatCard variant="info">
                                <div css={tw`flex items-center justify-between`}>
                                    <div>
                                        <p css={tw`text-sm text-blue-300 font-medium`}>Total Tables</p>
                                        <p css={tw`text-2xl font-bold text-white`}>{analysis.summary?.total_tables || 0}</p>
                                    </div>
                                    <FontAwesomeIcon icon={faKey} css={tw`text-2xl text-blue-400`} />
                                </div>
                            </StatCard>
                        </StatsGrid>

                        {/* Recommendations */}
                        <div css={tw`space-y-4`}>
                            <h3 css={tw`text-xl font-semibold text-white flex items-center gap-2`}>
                                <FontAwesomeIcon icon={faLightbulb} css={tw`text-yellow-400`} />
                                Optimization Recommendations
                            </h3>
                            
                            {Object.keys(analysis.recommendations || {}).length === 0 ? (
                                <div css={tw`p-6 bg-green-900 bg-opacity-20 border border-green-600 rounded-lg text-center`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`text-4xl text-green-400 mb-4`} />
                                    <p css={tw`text-lg font-semibold text-green-300`}>Great Job!</p>
                                    <p css={tw`text-green-200`}>No optimization recommendations found. Your database indexes are well-optimized.</p>
                                </div>
                            ) : (
                                <div css={tw`max-h-96 overflow-y-auto space-y-4`}>
                                    {analysis.recommendations && Object.entries(analysis.recommendations).map(([tableName, recommendations]) => (
                                        <div key={tableName}>
                                            <h4 css={tw`text-lg font-medium text-blue-300 mb-3 flex items-center gap-2`}>
                                                <FontAwesomeIcon icon={faDatabase} />
                                                {tableName}
                                            </h4>
                                            
                                            {recommendations.map((rec, index) => {
                                                const recId = `${tableName}-${index}`;
                                                const isExpanded = expandedRecommendations.has(recId);
                                                
                                                return (
                                                    <RecommendationCard key={recId} priority={rec.priority || 'low'}>
                                                        <div 
                                                            css={tw`cursor-pointer`}
                                                            onClick={() => toggleRecommendation(recId)}
                                                        >
                                                            <div css={tw`flex items-center justify-between`}>
                                                                <div css={tw`flex items-center gap-3`}>
                                                                    <FontAwesomeIcon 
                                                                        icon={getPriorityIcon(rec.priority)} 
                                                                        css={[tw`text-lg`, getPriorityColor(rec.priority)]}
                                                                    />
                                                                    <div>
                                                                        <h5 css={tw`font-semibold text-white`}>{rec.title || 'Unknown Issue'}</h5>
                                                                        <p css={tw`text-sm text-gray-300`}>{rec.description || 'No description available'}</p>
                                                                    </div>
                                                                </div>
                                                                <span css={[
                                                                    tw`px-2 py-1 rounded-full text-xs font-medium uppercase`,
                                                                    rec.priority === 'high' && tw`bg-red-600 text-red-100`,
                                                                    rec.priority === 'medium' && tw`bg-yellow-600 text-yellow-100`,
                                                                    rec.priority === 'low' && tw`bg-blue-600 text-blue-100`,
                                                                    !rec.priority && tw`bg-gray-600 text-gray-100`
                                                                ]}>
                                                                    {rec.priority || 'unknown'}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        
                                                        {isExpanded && (
                                                            <div css={tw`mt-4 pt-4 border-t border-gray-600`}>
                                                                <div css={tw`mb-3`}>
                                                                    <h6 css={tw`text-sm font-semibold text-gray-300 mb-2`}>Recommendation:</h6>
                                                                    <p css={tw`text-gray-200`}>{rec.recommendation || 'No recommendation available'}</p>
                                                                </div>
                                                                
                                                                <div css={tw`mb-3`}>
                                                                    <h6 css={tw`text-sm font-semibold text-gray-300 mb-2 flex items-center gap-2`}>
                                                                        <FontAwesomeIcon icon={faCode} />
                                                                        SQL Example:
                                                                    </h6>
                                                                    <CodeBlock>{rec.sql_example}</CodeBlock>
                                                                </div>
                                                                
                                                                <div css={tw`p-3 bg-gray-800 rounded-lg`}>
                                                                    <h6 css={tw`text-sm font-semibold text-green-300 mb-1`}>Expected Impact:</h6>
                                                                    <p css={tw`text-sm text-green-200`}>{rec.impact}</p>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </RecommendationCard>
                                                );
                                            })}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </>
                )}

                {analysis?.error && (
                    <div css={tw`p-6 bg-red-900 bg-opacity-20 border border-red-600 rounded-lg text-center`}>
                        <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-4xl text-red-400 mb-4`} />
                        <p css={tw`text-lg font-semibold text-red-300 mb-2`}>Analysis Failed</p>
                        <p css={tw`text-red-200`}>{analysis.message}</p>
                    </div>
                )}
            </AnalysisContainer>
        </GradientModal>
    );
};

export default IndexAnalyzerModal;
