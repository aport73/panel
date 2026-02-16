import React, { useState, useRef, useEffect } from 'react';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTerminal, faPlay, faExclamationTriangle, faCopy, faTimes, faHistory, faCode } from '@fortawesome/free-solid-svg-icons';
import { executeSQL, ExecuteSQLRequest, ExecuteSQLResponse } from '@/api/server/databases/tableDataOperations';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import { sanitizeSqlForDisplay, escapeHtml } from '@/utils/xssProtection';

interface SQLConsoleProps {
    databaseId: string;
}

const ConsoleWrapper = styled.div`
    ${tw`bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 border border-gray-600 rounded-xl overflow-hidden shadow-2xl`};
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`border-gray-500 shadow-2xl`};
        transform: translateY(-2px);
    }
`;

const WarningBanner = styled.div`
    ${tw`bg-gradient-to-r from-red-900 via-red-800 to-red-900 border-b border-red-600 text-red-100 p-4 flex items-start gap-3`};
    background-image: linear-gradient(45deg, rgba(220, 38, 38, 0.1) 25%, transparent 25%), 
                      linear-gradient(-45deg, rgba(220, 38, 38, 0.1) 25%, transparent 25%), 
                      linear-gradient(45deg, transparent 75%, rgba(220, 38, 38, 0.1) 75%), 
                      linear-gradient(-45deg, transparent 75%, rgba(220, 38, 38, 0.1) 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
    animation: warning-pulse 3s ease-in-out infinite;
    
    @media (max-width: 640px) {
        ${tw`p-3 flex-col gap-2`};
    }
    
    @keyframes warning-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.8; }
    }
`;

const ConsoleHeader = styled.div`
    ${tw`bg-gradient-to-r from-gray-800 via-gray-700 to-gray-800 border-b border-gray-600 p-4 flex items-center justify-between`};
    position: relative;
    
    @media (max-width: 640px) {
        ${tw`p-3 flex-col gap-2 items-start`};
    }
    
    &::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.5), transparent);
    }
`;

const TerminalDots = styled.div`
    ${tw`flex items-center gap-2 mr-4`};
    
    &::before, &::after {
        content: '';
        ${tw`w-3 h-3 rounded-full`};
    }
    
    &::before {
        ${tw`bg-red-500`};
        box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
    }
    
    &::after {
        ${tw`bg-yellow-500 ml-1`};
        box-shadow: 0 0 10px rgba(245, 158, 11, 0.5);
    }
    
    & > div {
        ${tw`w-3 h-3 rounded-full bg-green-500 ml-1`};
        box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
    }
`;

const InputSection = styled.div`
    ${tw`p-6 border-b border-gray-600 bg-gradient-to-b from-gray-800 to-gray-900`};
    
    @media (max-width: 640px) {
        ${tw`p-4`};
    }
    
    @media (max-width: 480px) {
        ${tw`p-3`};
    }
`;

const SQLTextArea = styled.textarea`
    ${tw`w-full bg-gray-900 border border-gray-600 rounded-lg p-4 text-gray-100 font-mono text-sm resize-none focus:border-blue-500 focus:outline-none transition-all duration-300`};
    min-height: 140px;
    line-height: 1.6;
    
    @media (max-width: 640px) {
        ${tw`p-3 text-xs`};
        min-height: 120px;
        line-height: 1.5;
    }
    
    @media (max-width: 480px) {
        ${tw`p-2 text-xs`};
        min-height: 100px;
        line-height: 1.4;
    }
    
    &:focus {
        ${tw`bg-gray-800 shadow-lg`};
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), 0 10px 25px rgba(0, 0, 0, 0.2);
        transform: translateY(-1px);
        
        @media (max-width: 640px) {
            transform: none;
        }
    }
    
    &::placeholder {
        ${tw`text-gray-500`};
        font-style: italic;
        
        @media (max-width: 640px) {
            font-size: 0.7rem;
        }
    }
`;

const OutputSection = styled.div`
    ${tw`bg-black text-green-400 font-mono text-sm max-h-96 overflow-y-auto relative`};
    background-image: 
        radial-gradient(circle at 1px 1px, rgba(34, 197, 94, 0.15) 1px, transparent 0);
    background-size: 20px 20px;
    
    @media (max-width: 640px) {
        ${tw`text-xs max-h-64`};
        background-size: 15px 15px;
    }
    
    @media (max-width: 480px) {
        ${tw`text-xs max-h-48`};
        background-size: 12px 12px;
    }
    
    &::-webkit-scrollbar {
        width: 8px;
        
        @media (max-width: 640px) {
            width: 6px;
        }
    }
    
    &::-webkit-scrollbar-track {
        ${tw`bg-gray-900`};
    }
    
    &::-webkit-scrollbar-thumb {
        ${tw`bg-gray-600 rounded`};
        
        &:hover {
            ${tw`bg-gray-500`};
        }
    }
`;

const OutputContent = styled.div`
    ${tw`p-6`};
    white-space: pre-wrap;
    line-height: 1.5;
    
    @media (max-width: 640px) {
        ${tw`p-4`};
        line-height: 1.4;
    }
    
    @media (max-width: 480px) {
        ${tw`p-3`};
        line-height: 1.3;
    }
    
    & .query-line {
        ${tw`text-blue-400`};
    }
    
    & .success-line {
        ${tw`text-green-400`};
    }
    
    & .error-line {
        ${tw`text-red-400`};
    }
    
    & .info-line {
        ${tw`text-yellow-400`};
    }
`;

const ActionBar = styled.div`
    ${tw`flex items-center justify-between gap-3 mt-4`};
    
    @media (max-width: 640px) {
        ${tw`flex-col gap-3 items-stretch`};
    }
`;

const ButtonGroup = styled.div`
    ${tw`flex items-center gap-3`};
    
    @media (max-width: 640px) {
        ${tw`flex-col gap-2 w-full`};
    }
    
    @media (max-width: 480px) {
        ${tw`gap-1`};
    }
`;

const PrimaryButton = styled.button`
    ${tw`bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-500 hover:to-blue-600 text-white px-6 py-3 rounded-lg flex items-center justify-center gap-2 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed font-medium shadow-lg`};
    
    @media (max-width: 640px) {
        ${tw`w-full px-4 py-3 text-sm`};
    }
    
    @media (max-width: 480px) {
        ${tw`px-3 py-2 text-xs`};
        gap: 4px;
    }
    
    &:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);
        
        @media (max-width: 640px) {
            transform: translateY(-1px);
        }
    }
    
    &:active {
        transform: translateY(-1px);
        
        @media (max-width: 640px) {
            transform: none;
        }
    }
`;

const SecondaryButton = styled.button`
    ${tw`bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-500 hover:to-gray-600 text-white px-4 py-3 rounded-lg flex items-center justify-center gap-2 transition-all duration-300 font-medium shadow-lg`};
    
    @media (max-width: 640px) {
        ${tw`w-full px-4 py-3 text-sm`};
    }
    
    @media (max-width: 480px) {
        ${tw`px-3 py-2 text-xs`};
        gap: 4px;
    }
    
    &:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        
        @media (max-width: 640px) {
            transform: translateY(-1px);
        }
    }
`;

const StatusIndicator = styled.div<{ isExecuting: boolean }>`
    ${tw`flex items-center gap-2 text-sm`};
    
    & .status-dot {
        ${tw`w-2 h-2 rounded-full`};
        ${props => props.isExecuting ? tw`bg-yellow-400 animate-pulse` : tw`bg-green-400`};
    }
    
    & .status-text {
        ${props => props.isExecuting ? tw`text-yellow-400` : tw`text-gray-400`};
    }
`;

const SQLConsole: React.FC<SQLConsoleProps> = ({ databaseId }) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { addFlash } = useFlash();
    const [sqlQuery, setSqlQuery] = useState('');
    const [output, setOutput] = useState('🚀 SQL Console Ready\n\n> Welcome to the advanced SQL console. Type your commands above and execute them safely.\n> Press Ctrl+Enter for quick execution, ↑/↓ arrows for history.\n\n⚠️  WARNING: Direct database access - use with caution!');
    const [isExecuting, setIsExecuting] = useState(false);
    const [history, setHistory] = useState<string[]>([]);
    const [historyIndex, setHistoryIndex] = useState(-1);
    const [currentQuery, setCurrentQuery] = useState(''); 
    const outputRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const historyKey = `sql_history_${uuid}_${databaseId}`;
        const savedHistory = localStorage.getItem(historyKey);
        if (savedHistory) {
            try {
                const parsedHistory = JSON.parse(savedHistory);
                if (Array.isArray(parsedHistory)) {
                    setHistory(parsedHistory);
                }
            } catch (e) {
                console.warn('Failed to parse SQL history from localStorage:', e);
            }
        }
    }, [uuid, databaseId]);

    useEffect(() => {
        if (outputRef.current) {
            outputRef.current.scrollTop = outputRef.current.scrollHeight;
        }
    }, [output]);

    useEffect(() => {
        if (uuid && databaseId && history.length > 0) {
            const historyKey = `sql_history_${uuid}_${databaseId}`;
            const trimmedHistory = history.slice(-50);
            localStorage.setItem(historyKey, JSON.stringify(trimmedHistory));
        }
    }, [history, uuid, databaseId]);

    const handleExecute = async () => {
        if (!uuid || !sqlQuery.trim()) return;

        setIsExecuting(true);
        let trimmedQuery = sqlQuery.trim();

        if (!trimmedQuery.endsWith(';')) {
            trimmedQuery += ';';
        }

        try {
            const isSelect = /^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s+/i.test(trimmedQuery);

            const request: ExecuteSQLRequest = {
                sql: trimmedQuery,
                is_select: isSelect,
                confirm_risky: !isSelect
            };

            const response: ExecuteSQLResponse = await executeSQL(uuid, databaseId, request);

            setHistory(prev => {
                if (prev.length === 0 || prev[prev.length - 1] !== trimmedQuery) {
                    return [...prev, trimmedQuery];
                }
                return prev;
            });

            setHistoryIndex(-1);
            setCurrentQuery('');

            let consoleOutput = '';
            const timestamp = new Date().toLocaleTimeString();

            if (response.success) {
                consoleOutput = `\n[${timestamp}] mysql> ${trimmedQuery}\n`;
                if (response.results && response.results.length > 0 && response.columns && response.columns.length > 0) {
                    const maxWidths = response.columns.map(col =>
                        Math.max(
                            col.length + 2,
                            ...response.results!.map(row =>
                                String(row[col] || 'NULL').length + 2
                            )
                        )
                    );

                    const totalWidth = maxWidths.reduce((sum, width) => sum + width, 0) + (response.columns.length - 1) * 3;

                    consoleOutput += `╔${'═'.repeat(totalWidth + 2)}╗\n`;
                    const header = response.columns.map((col, i) =>
                        ` ${col.toUpperCase().padEnd(maxWidths[i] - 1)}`
                    ).join('║');
                    consoleOutput += `║${header}║\n`;
                    consoleOutput += `╠${'═'.repeat(totalWidth + 2)}╣\n`;

                    response.results.forEach((row, rowIndex) => {
                        const rowStr = response.columns!.map((col, i) => {
                            const value = row[col];
                            const displayValue = value === null || value === undefined ? '(NULL)' : String(value);
                            return ` ${displayValue.padEnd(maxWidths[i] - 1)}`;
                        }).join('║');
                        consoleOutput += `║${rowStr}║\n`;
                    });
                    consoleOutput += `╚${'═'.repeat(totalWidth + 2)}╝\n`;

                    const rowCount = response.row_count || response.results.length;
                    consoleOutput += `\n🎯 Query Results: ${rowCount} row${rowCount !== 1 ? 's' : ''} returned`;

                    if (response.execution_time_ms !== undefined) {
                        const timeColor = response.execution_time_ms < 1 ? '🟢' : response.execution_time_ms < 100 ? '🟡' : '🔴';
                        consoleOutput += ` ${timeColor} Execution time: ${response.execution_time_ms}ms`;
                    }

                    if (response.executed_at) {
                        consoleOutput += `\n📅 Executed at: ${response.executed_at}`;
                    }

                } else if (response.results && response.results.length === 0 && isSelect) {
                    consoleOutput += `\n📋 Empty result set\n`;
                    consoleOutput += `╔═══════════════════════════════════╗\n`;
                    consoleOutput += `║           NO ROWS FOUND           ║\n`;
                    consoleOutput += `╚═══════════════════════════════════╝\n`;
                    consoleOutput += `\n🎯 Query completed: 0 rows returned`;

                    if (response.execution_time_ms !== undefined) {
                        consoleOutput += ` 🟢 ${response.execution_time_ms}ms`;
                    }
                } else {
                    const affectedRows = response.affected_rows || 0;
                    consoleOutput += `\n✅ Query executed successfully\n`;
                    consoleOutput += `📊 ${affectedRows} row${affectedRows !== 1 ? 's' : ''} affected`;

                    if (response.last_insert_id) {
                        consoleOutput += `\n🆔 Last insert ID: ${response.last_insert_id}`;
                    }

                    if (response.execution_time_ms !== undefined) {
                        const timeColor = response.execution_time_ms < 10 ? '🟢' : response.execution_time_ms < 100 ? '🟡' : '🔴';
                        consoleOutput += ` ${timeColor} ${response.execution_time_ms}ms`;
                    }
                }

                consoleOutput += '\n';
            } else {
                consoleOutput = `\n[${timestamp}] mysql> ${trimmedQuery}\n`;
                consoleOutput += `\n❌ ERROR OCCURRED\n`;
                consoleOutput += `╔═══════════════════════════════════╗\n`;
                consoleOutput += `║              ERROR                ║\n`;
                consoleOutput += `╚═══════════════════════════════════╝\n`;
                consoleOutput += `💥 ${response.error}\n`;
            }

            setOutput(prev => prev + consoleOutput);

            if (response.success) {
                setSqlQuery('');
            }

        } catch (error) {
            const timestamp = new Date().toLocaleTimeString();
            const errorOutput = `\n[${timestamp}] mysql> ${trimmedQuery}\n❌ NETWORK/CONNECTION ERROR: ${httpErrorToHuman(error)}\n`;
            setOutput(prev => prev + errorOutput);

            addFlash({
                key: 'sql-console',
                type: 'error',
                message: httpErrorToHuman(error),
            });
        } finally {
            setIsExecuting(false);
        }
    };

    const handleClear = () => {
        setOutput('🚀 SQL Console Ready\n\n> Console cleared. Ready for new commands.\n\n⚠️  WARNING: Direct database access - use with caution!');
        setSqlQuery('');
        setHistoryIndex(-1);
        setCurrentQuery('');
    };

    const handleClearHistory = () => {
        setHistory([]);
        setHistoryIndex(-1);
        setCurrentQuery('');
        if (uuid && databaseId) {
            const historyKey = `sql_history_${uuid}_${databaseId}`;
            localStorage.removeItem(historyKey);
        }
        setOutput(prev => prev + '\n\n📝 Query history cleared.');
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            handleExecute();
            return;
        }

        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            
            if (history.length === 0) return;

            if (e.key === 'ArrowUp') {
                if (historyIndex === -1) {
                    setCurrentQuery(sqlQuery);
                    setHistoryIndex(history.length - 1);
                    setSqlQuery(history[history.length - 1]);
                } else if (historyIndex > 0) {
                    setHistoryIndex(historyIndex - 1);
                    setSqlQuery(history[historyIndex - 1]);
                }
            } else if (e.key === 'ArrowDown') {
                if (historyIndex !== -1) {
                    if (historyIndex < history.length - 1) {
                        setHistoryIndex(historyIndex + 1);
                        setSqlQuery(history[historyIndex + 1]);
                    } else {
                        setHistoryIndex(-1);
                        setSqlQuery(currentQuery);
                        setCurrentQuery('');
                    }
                }
            }
        }
    };

    return (
        <ConsoleWrapper>
            <WarningBanner>
                <FontAwesomeIcon icon={faExclamationTriangle} className="text-red-300 text-lg" />
                <div>
                    <div className="font-bold text-red-100 text-sm sm:text-base">⚠️ Danger Zone - Raw SQL Execution</div>
                    <div className="text-xs sm:text-sm text-red-200 mt-1">
                        Direct database access can permanently damage your data. Only use if you understand SQL and have backups.
                    </div>
                </div>
            </WarningBanner>

            <ConsoleHeader>
                <div className="flex items-center gap-3">
                    <TerminalDots>
                        <div></div>
                    </TerminalDots>
                    <FontAwesomeIcon icon={faTerminal} className="text-blue-400 text-lg" />
                    <span className="text-gray-100 font-semibold text-sm sm:text-lg">Advanced SQL Console</span>
                </div>
                <StatusIndicator isExecuting={isExecuting}>
                    <div className="status-dot"></div>
                    <span className="status-text font-medium">
                        {isExecuting ? 'Executing...' : 'Ready'}
                    </span>
                </StatusIndicator>
            </ConsoleHeader>

            <InputSection>
                <div className="flex items-center gap-2 mb-3">
                    <FontAwesomeIcon icon={faCode} className="text-gray-400" />
                    <span className="text-gray-300 font-medium">SQL Query Editor</span>
                </div>
                <SQLTextArea
                    value={sqlQuery}
                    onChange={(e) => setSqlQuery(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder="-- Enter your SQL query here\n-- Examples:\n--   SELECT * FROM users LIMIT 10;\n--   UPDATE users SET status = 'active' WHERE id = 1;\n--   INSERT INTO logs (message) VALUES ('Hello World');"
                />

                <ActionBar>
                    <ButtonGroup>
                        <PrimaryButton
                            onClick={handleExecute}
                            disabled={isExecuting || !sqlQuery.trim()}
                        >
                            <FontAwesomeIcon
                                icon={faPlay}
                                className={isExecuting ? 'animate-spin' : ''}
                            />
                            {isExecuting ? 'Executing...' : 'Execute Query'}
                        </PrimaryButton>

                        <SecondaryButton onClick={handleClear}>
                            <FontAwesomeIcon icon={faTimes} />
                            Clear
                        </SecondaryButton>
                        
                        {history.length > 0 && (
                            <SecondaryButton onClick={handleClearHistory} title="Clear query history">
                                <FontAwesomeIcon icon={faHistory} />
                                Clear History
                            </SecondaryButton>
                        )}
                    </ButtonGroup>

                    <div className="text-xs text-gray-500 flex items-center gap-3">
                        <div className="flex items-center gap-1">
                            <FontAwesomeIcon icon={faPlay} />
                            <span>Ctrl+Enter to execute</span>
                        </div>
                        <div className="flex items-center gap-1">
                            <FontAwesomeIcon icon={faHistory} />
                            <span>↑/↓ for history ({history.length})</span>
                        </div>
                    </div>
                </ActionBar>
            </InputSection>

            <OutputSection ref={outputRef}>
                <OutputContent>
                    <pre dangerouslySetInnerHTML={{ __html: sanitizeSqlForDisplay(output) }} />
                </OutputContent>
            </OutputSection>
        </ConsoleWrapper>
    );
};

export default SQLConsole;