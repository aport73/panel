import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faHeartbeat,
    faExclamationTriangle,
    faCheckCircle,
    faTimesCircle,
    faRedo,
    faDatabase,
    faTable,
    faSearch,
    faPlug,
    faClock
} from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import GradientModal from './GradientModal';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import analyzeDatabaseHealth, { DatabaseHealthAnalysis } from '@/api/server/databases/analyzeDatabaseHealth';
import FlashMessageRender from '@/components/FlashMessageRender';

interface DatabaseHealthModalProps {
    databaseId: string;
    databaseName: string;
    visible: boolean;
    onDismissed: () => void;
}

const DatabaseHealthModal: React.FC<DatabaseHealthModalProps> = ({
    databaseId,
    databaseName,
    visible,
    onDismissed
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [analysis, setAnalysis] = useState<DatabaseHealthAnalysis | null>(null);
    const [isAnalyzing, setIsAnalyzing] = useState(false);

    useEffect(() => {
        if (visible && uuid) {
            performAnalysis(false);
        }
    }, [visible, uuid]);

    const performAnalysis = async (forceRefresh: boolean) => {
        if (!uuid) return;

        setIsAnalyzing(true);
        clearFlashes('database:health');

        try {
            const result = await analyzeDatabaseHealth(uuid, databaseId, forceRefresh);
            setAnalysis(result);
        } catch (error: any) {
            clearAndAddHttpError({ key: 'database:health', error });
        } finally {
            setIsAnalyzing(false);
        }
    };

    const getHealthColor = (health: string) => {
        switch (health) {
            case 'good': return 'text-green-400';
            case 'warning': return 'text-yellow-400';
            case 'critical': return 'text-red-400';
            default: return 'text-gray-400';
        }
    };

    const getHealthIcon = (health: string) => {
        switch (health) {
            case 'good': return faCheckCircle;
            case 'warning': return faExclamationTriangle;
            case 'critical': return faTimesCircle;
            default: return faHeartbeat;
        }
    };

    const formatBytes = (mb: number | string | null | undefined): string => {
        if (!mb) return '0 MB';
        const numMb = Number(mb);
        if (isNaN(numMb) || numMb === 0) return '0 MB';
        if (numMb < 1024) return `${numMb.toFixed(1)} MB`;
        return `${(numMb / 1024).toFixed(2)} GB`;
    };

    return (
        <GradientModal visible={visible} onDismissed={onDismissed} size="xl" closeOnEscape={true} closeOnBackground={true}>
            <div css={tw`p-6`}>
                <FlashMessageRender byKey={'database:health'} css={tw`mb-4`} />

                <div css={tw`flex items-center justify-between mb-6`}>
                    <div css={tw`flex items-center gap-3`}>
                        <div css={tw`p-2 rounded-lg bg-blue-600 bg-opacity-20`}>
                            <FontAwesomeIcon icon={faHeartbeat} css={tw`text-blue-400 text-xl`} />
                        </div>
                        <div>
                            <h2 css={tw`text-xl font-bold`}>Database Health</h2>
                            <p css={tw`text-sm text-neutral-400`}>{databaseName}</p>
                        </div>
                    </div>
                    <button
                        onClick={() => performAnalysis(true)}
                        disabled={isAnalyzing}
                        css={tw`px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed`}
                    >
                        <FontAwesomeIcon
                            icon={faRedo}
                            className={isAnalyzing ? 'animate-spin' : ''}
                        />
                        {isAnalyzing ? 'Analyzing...' : 'Refresh'}
                    </button>
                </div>

                {analysis && (
                    <div css={tw`space-y-5`}>
                        {/* Overall Health Status */}
                        <div css={tw`bg-neutral-700 rounded-lg p-4 border-l-4`} style={{
                            borderLeftColor: analysis.overall_health === 'good' ? '#10B981' :
                                analysis.overall_health === 'warning' ? '#F59E0B' : '#EF4444'
                        }}>
                            <div css={tw`flex items-center justify-between`}>
                                <div css={tw`flex items-center gap-3`}>
                                    <FontAwesomeIcon
                                        icon={getHealthIcon(analysis.overall_health)}
                                        className={getHealthColor(analysis.overall_health)}
                                        css={tw`text-2xl`}
                                    />
                                    <div>
                                        <h3 css={tw`text-base font-semibold`}>
                                            {analysis.overall_health === 'good' ? 'Healthy' :
                                                analysis.overall_health === 'warning' ? 'Needs Attention' :
                                                    'Critical Issues'}
                                        </h3>
                                        <p css={tw`text-sm text-neutral-400`}>
                                            Score: {analysis.health_score}/100
                                        </p>
                                    </div>
                                </div>
                                {analysis.from_cache && (
                                    <div css={tw`flex items-center gap-2 text-xs text-neutral-400`}>
                                        <FontAwesomeIcon icon={faClock} />
                                        <span>Cached</span>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Alerts */}
                        {analysis.alerts && analysis.alerts.length > 0 && (
                            <div>
                                <h3 css={tw`text-base font-semibold mb-3`}>
                                    Alerts ({analysis.alerts.length})
                                </h3>
                                <div css={tw`space-y-2`}>
                                    {analysis.alerts.map((alert, index) => (
                                        <div
                                            key={index}
                                            css={tw`bg-neutral-700 rounded-lg p-3 border-l-4`}
                                            style={{
                                                borderLeftColor: alert.severity === 'critical' ? '#EF4444' :
                                                    alert.severity === 'warning' || alert.severity === 'medium' ? '#F59E0B' : '#3B82F6'
                                            }}
                                        >
                                            <div css={tw`flex items-start gap-3`}>
                                                <FontAwesomeIcon
                                                    icon={alert.severity === 'critical' ? faTimesCircle : faExclamationTriangle}
                                                    css={tw`mt-0.5`}
                                                    className={
                                                        alert.severity === 'critical' ? 'text-red-400' :
                                                            alert.severity === 'warning' || alert.severity === 'medium' ? 'text-yellow-400' :
                                                                'text-blue-400'
                                                    }
                                                />
                                                <div css={tw`flex-1 min-w-0`}>
                                                    <div css={tw`flex items-center gap-2 mb-1`}>
                                                        <h4 css={tw`font-semibold text-sm`}>{alert.title}</h4>
                                                        <span
                                                            css={tw`px-2 py-0.5 rounded text-xs font-medium uppercase`}
                                                            style={{
                                                                backgroundColor: alert.severity === 'critical' ? '#DC2626' :
                                                                    alert.severity === 'warning' || alert.severity === 'medium' ? '#D97706' : '#2563EB',
                                                                color: 'white'
                                                            }}
                                                        >
                                                            {alert.severity}
                                                        </span>
                                                    </div>
                                                    <p css={tw`text-xs text-neutral-300 mb-2`}>{alert.description}</p>
                                                    <div css={tw`bg-neutral-800 rounded p-2 text-xs text-neutral-400`}>
                                                        💡 {alert.recommendation}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Metrics */}
                        {analysis.metrics && (
                            <div>
                                <h3 css={tw`text-base font-semibold mb-3`}>Metrics</h3>
                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3`}>

                                    {/* Storage */}
                                    {analysis.metrics.size && (
                                        <div css={tw`bg-neutral-700 rounded-lg p-3`}>
                                            <div css={tw`flex items-center gap-2 mb-3`}>
                                                <FontAwesomeIcon icon={faDatabase} css={tw`text-blue-400`} />
                                                <h4 css={tw`font-semibold text-sm`}>Storage</h4>
                                            </div>
                                            <div css={tw`space-y-2 text-sm`}>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Total Size</span>
                                                    <span css={tw`font-mono`}>{formatBytes(analysis.metrics.size.total_size_mb)}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Data</span>
                                                    <span css={tw`font-mono`}>{formatBytes(analysis.metrics.size.data_size_mb)}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Indexes</span>
                                                    <span css={tw`font-mono`}>{formatBytes(analysis.metrics.size.index_size_mb)}</span>
                                                </div>
                                                <div css={tw`flex justify-between pt-2 border-t border-neutral-600`}>
                                                    <span css={tw`text-neutral-400`}>Efficiency</span>
                                                    <span css={tw`font-semibold`}>
                                                        {analysis.metrics.size.storage_efficiency ?
                                                            Number(analysis.metrics.size.storage_efficiency).toFixed(1) : '0'}%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Tables */}
                                    {analysis.metrics.tables && (
                                        <div css={tw`bg-neutral-700 rounded-lg p-3`}>
                                            <div css={tw`flex items-center gap-2 mb-3`}>
                                                <FontAwesomeIcon icon={faTable} css={tw`text-green-400`} />
                                                <h4 css={tw`font-semibold text-sm`}>Tables</h4>
                                            </div>
                                            <div css={tw`space-y-2 text-sm`}>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Total</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.tables.total_tables}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Rows</span>
                                                    <span css={tw`font-mono`}>
                                                        {analysis.metrics.tables.total_rows ?
                                                            Number(analysis.metrics.tables.total_rows).toLocaleString() : '0'}
                                                    </span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Empty</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.tables.empty_tables}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Fragmented</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.tables.fragmented_tables}</span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Indexes */}
                                    {analysis.metrics.indexes && (
                                        <div css={tw`bg-neutral-700 rounded-lg p-3`}>
                                            <div css={tw`flex items-center gap-2 mb-3`}>
                                                <FontAwesomeIcon icon={faSearch} css={tw`text-purple-400`} />
                                                <h4 css={tw`font-semibold text-sm`}>Indexes</h4>
                                            </div>
                                            <div css={tw`space-y-2 text-sm`}>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Total</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.indexes.total_indexes}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Duplicates</span>
                                                    <span css={tw`font-mono text-yellow-400`}>
                                                        {Object.keys(analysis.metrics.indexes.duplicate_indexes || {}).length}
                                                    </span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Unused</span>
                                                    <span css={tw`font-mono text-red-400`}>
                                                        {analysis.metrics.indexes.potentially_unused?.length || 0}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Connections */}
                                    {analysis.metrics.connections && (
                                        <div css={tw`bg-neutral-700 rounded-lg p-3`}>
                                            <div css={tw`flex items-center gap-2 mb-3`}>
                                                <FontAwesomeIcon icon={faPlug} css={tw`text-cyan-400`} />
                                                <h4 css={tw`font-semibold text-sm`}>Connections</h4>
                                            </div>
                                            <div css={tw`space-y-2 text-sm`}>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Current</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.connections.current_connections}</span>
                                                </div>
                                                <div css={tw`flex justify-between`}>
                                                    <span css={tw`text-neutral-400`}>Maximum</span>
                                                    <span css={tw`font-mono`}>{analysis.metrics.connections.max_connections}</span>
                                                </div>
                                                <div css={tw`flex justify-between pt-2 border-t border-neutral-600`}>
                                                    <span css={tw`text-neutral-400`}>Usage</span>
                                                    <span css={tw`font-semibold`}>
                                                        {analysis.metrics.connections.connection_usage_percent ?
                                                            Number(analysis.metrics.connections.connection_usage_percent).toFixed(1) : '0'}%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Recommendations */}
                        {analysis.recommendations && analysis.recommendations.length > 0 && (
                            <div>
                                <h3 css={tw`text-base font-semibold mb-3`}>Recommendations</h3>
                                <div css={tw`bg-neutral-700 rounded-lg p-3`}>
                                    <ul css={tw`space-y-2`}>
                                        {analysis.recommendations.map((rec, index) => (
                                            <li key={index} css={tw`text-sm text-neutral-300 flex items-start gap-2`}>
                                                <span css={tw`text-blue-400 mt-0.5`}>•</span>
                                                <span>{rec}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        )}

                        <div css={tw`text-xs text-neutral-500 text-center pt-4 border-t border-neutral-700`}>
                            Last analyzed: {new Date(analysis.analyzed_at).toLocaleString()}
                        </div>
                    </div>
                )}

                {!analysis && !isAnalyzing && (
                    <div css={tw`text-center py-8 text-neutral-400`}>
                        <FontAwesomeIcon icon={faHeartbeat} css={tw`text-4xl mb-3`} />
                        <p>No analysis data available</p>
                    </div>
                )}

                {isAnalyzing && !analysis && (
                    <div css={tw`text-center py-12`}>
                        <FontAwesomeIcon icon={faRedo} spin css={tw`text-4xl text-blue-400 mb-3`} />
                        <p css={tw`text-neutral-400`}>Analyzing database health...</p>
                    </div>
                )}
            </div>
        </GradientModal>
    );
};

export default DatabaseHealthModal;
