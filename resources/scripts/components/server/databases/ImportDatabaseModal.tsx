import React, { useState } from 'react';
import { Form, Formik, FormikHelpers } from 'formik';
import { object, string, mixed, number, array } from 'yup';
import StyledCheckbox from '@/components/elements/StyledCheckbox';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUpload, faFileImport, faExclamationTriangle, faDatabase, faChevronLeft, faChevronRight, faServer, faKey, faToggleOn, faToggleOff, faEye, faEyeSlash, faSpinner, faChevronDown } from '@fortawesome/free-solid-svg-icons';
import GradientModal from './modal_components/GradientModal';
import Button from '@/components/elements/Button';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import uploadDatabase from '@/api/server/databases/uploadDatabase';
import { httpErrorToHuman } from '@/api/http';
import tw, { styled } from 'twin.macro';
import { sanitizeFilename } from '@/utils/xssProtection';
import analyzeFile, { FileAnalysisResult, AnalyzedTable } from '@/api/server/databases/analyzeFile';
import analyzeExternalDatabase, { ExternalDatabaseCredentials, ExternalDatabaseAnalysis } from '@/api/server/databases/analyzeExternalDatabase';

const FileInput = styled.input`
    ${tw`block w-full text-sm text-neutral-300 border-2 border-dashed border-neutral-600 rounded-xl bg-gradient-to-br from-neutral-800 to-neutral-900 p-8 hover:border-blue-500 focus:border-blue-500 focus:outline-none transition-all duration-300 cursor-pointer shadow-inner`}
    
    &:hover {
        ${tw`bg-gradient-to-br from-neutral-700 to-neutral-800`};
        box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3), 0 4px 15px rgba(59, 130, 246, 0.1);
    }
    
    &::file-selector-button {
        ${tw`mr-4 py-3 px-6 rounded-xl border-0 text-sm font-semibold bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-500 hover:to-blue-600 text-white cursor-pointer transition-all duration-300 shadow-lg transform hover:scale-105`}
    }
    
    &::file-selector-button:hover {
        box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    }
`;

interface Props {
    databaseId: string;
    visible: boolean;
    onDismissed: () => void;
    currentIndex?: number;
    totalCount?: number;
    onNext?: () => void;
    onPrevious?: () => void;
}

interface FormValues {
    importType: 'file' | 'credentials';
    file: File | null;
    mode: 'wipe' | 'merge';
    force: boolean;
    selective: boolean;
    selectedTables: string[];
    selectedColumns: Record<string, string[]>;
    sourceCredentials: {
        host: string;
        port: number;
        username: string;
        password: string;
        database: string;
    };
}

const schema = object().shape({
    importType: string().required().oneOf(['file', 'credentials']),
    file: mixed().when('importType', {
        is: 'file',
        then: mixed().required('A database file is required.'),
        otherwise: mixed()
    }),
    mode: string().required('Upload mode is required.').oneOf(['wipe', 'merge']),
    force: mixed(),
    selective: mixed(),
    selectedTables: array().of(string()).default([]),
    selectedColumns: object().default({}),
    sourceCredentials: object().when('importType', {
        is: 'credentials',
        then: object().shape({
            host: string().required('Host is required'),
            port: number().required('Port is required').min(1).max(65535),
            username: string().required('Username is required'),
            password: string().required('Password is required'),
            database: string().required('Database name is required'),
        }),
        otherwise: object()
    }),
});

const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

export default ({ databaseId, visible, onDismissed, currentIndex, totalCount, onNext, onPrevious }: Props) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes, addFlash } = useFlash();
    const [isUploading, setIsUploading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [analyzing, setAnalyzing] = useState(false);
    const [analysis, setAnalysis] = useState<FileAnalysisResult | null>(null);
    const [expandedTables, setExpandedTables] = useState<Set<string>>(new Set());
    const [analyzingExternal, setAnalyzingExternal] = useState(false);
    const [externalAnalysis, setExternalAnalysis] = useState<ExternalDatabaseAnalysis | null>(null);

    const handleFileAnalysis = async (file: File) => {
        if (!file) return;

        setAnalyzing(true);
        try {
            const result = await analyzeFile(uuid, databaseId, file);
            setAnalysis(result);
            
            if (!result.success) {
                addError({ key: 'file-analysis', message: result.error || 'Failed to analyze file' });
            }
        } catch (error: any) {
            console.error('File analysis error:', error);
            addError({ key: 'file-analysis', message: httpErrorToHuman(error) });
        } finally {
            setAnalyzing(false);
        }
    };

    const handleExternalDatabaseAnalysis = async (credentials: ExternalDatabaseCredentials) => {
        setAnalyzingExternal(true);
        setExternalAnalysis(null);
        
        try {
            const result = await analyzeExternalDatabase(uuid, databaseId, credentials);
            setExternalAnalysis(result);
            
            if (!result.success) {
                addError({ key: 'external-analysis', message: result.error || 'Failed to analyze external database' });
            }
        } catch (error: any) {
            console.error('External database analysis error:', error);
            addError({ key: 'external-analysis', message: httpErrorToHuman(error) });
        } finally {
            setAnalyzingExternal(false);
        }
    };

    const toggleTable = (tableName: string, selectedTables: string[], setFieldValue: any) => {
        const newSelection = selectedTables.includes(tableName)
            ? selectedTables.filter(t => t !== tableName)
            : [...selectedTables, tableName];
        
        setFieldValue('selectedTables', newSelection);
    };

    const toggleColumn = (tableName: string, columnName: string, selectedColumns: Record<string, string[]>, setFieldValue: any) => {
        const tableColumns = selectedColumns[tableName] || [];
        const newColumns = tableColumns.includes(columnName)
            ? tableColumns.filter(c => c !== columnName)
            : [...tableColumns, columnName];
        
        setFieldValue('selectedColumns', {
            ...selectedColumns,
            [tableName]: newColumns
        });
    };

    const toggleTableExpansion = (tableName: string) => {
        const newExpanded = new Set(expandedTables);
        if (newExpanded.has(tableName)) {
            newExpanded.delete(tableName);
        } else {
            newExpanded.add(tableName);
        }
        setExpandedTables(newExpanded);
    };

    const submit = async (values: FormValues, { setSubmitting, resetForm }: FormikHelpers<FormValues>) => {
        if (values.importType === 'file' && !values.file) return;
        if (values.importType === 'credentials' && (!values.sourceCredentials.host || !values.sourceCredentials.username)) return;

        clearFlashes('database:upload');
        setIsUploading(true);
        setSubmitting(true);

        try {
            const payload: any = {
                mode: values.mode,
                force: values.force,
                import_type: values.importType,
                selective: values.selective,
                selected_tables: values.selective ? values.selectedTables : undefined,
                selected_columns: values.selective ? values.selectedColumns : undefined,
            };

            if (values.importType === 'file') {
                payload.file = values.file;
            } else {
                payload.source_credentials = values.sourceCredentials;
            }

            await uploadDatabase(uuid, databaseId, payload);

            addFlash({
                key: 'database:upload',
                type: 'success',
                message: values.importType === 'file' 
                    ? 'Database imported successfully from file.' 
                    : 'Database imported successfully from external source.',
            });

            resetForm();
            onDismissed();
        } catch (error) {
            console.error(error);
            addError({ key: 'database:upload', message: httpErrorToHuman(error) });
        } finally {
            setIsUploading(false);
            setSubmitting(false);
        }
    };

    return (
        <Formik<FormValues>
            onSubmit={submit}
            initialValues={{ 
                importType: 'file', 
                file: null, 
                mode: 'wipe', 
                force: false,
                selective: false,
                selectedTables: [],
                selectedColumns: {},
                sourceCredentials: {
                    host: '',
                    port: 3306,
                    username: '',
                    password: '',
                    database: ''
                }
            }}
            validationSchema={schema}
        >
            {({ isSubmitting, setFieldValue, values }) => (
                <GradientModal
                    visible={visible}
                    dismissable={!isSubmitting}
                    onDismissed={onDismissed}
                    size="xl"
                >
                    <div css={tw`p-6 max-h-[80vh] overflow-y-auto`}>
                        <FlashMessageRender byKey={'database:upload'} css={tw`mb-6`} />
                        <div css={tw`flex flex-col mb-6`}>
                            <div css={tw`flex flex-col sm:flex-row items-center mb-4 sm:mb-0`}>
                                <div css={tw`bg-blue-600 p-3 rounded-full mr-0 sm:mr-4 mb-3 sm:mb-0`}>
                                    <FontAwesomeIcon icon={faDatabase} css={tw`text-white text-lg`} />
                                </div>
                                <div css={tw`text-center sm:text-left flex-1`}>
                                    <h2 css={tw`text-xl sm:text-2xl font-semibold text-white`}>Import Database</h2>
                                    <p css={tw`text-sm text-neutral-400`}>
                                        Restore your database from a SQL backup file
                                        {currentIndex !== undefined && totalCount !== undefined && (
                                            <span css={tw`block sm:inline ml-0 sm:ml-2 text-xs text-neutral-500 mt-1 sm:mt-0`}>
                                                ({currentIndex + 1} of {totalCount})
                                            </span>
                                        )}
                                    </p>
                                </div>
                            </div>
                            {(onPrevious || onNext) && (
                                <div css={tw`flex items-center justify-center sm:justify-end space-x-3 mt-4 sm:mt-0`}>
                                    <Button
                                        type="button"
                                        isSecondary
                                        size="small"
                                        css={tw`w-12 h-10 p-0 flex items-center justify-center rounded-lg transition-all duration-200 hover:bg-neutral-600`}
                                        onClick={onPrevious}
                                        disabled={!onPrevious || (currentIndex !== undefined && currentIndex <= 0)}
                                    >
                                        <FontAwesomeIcon icon={faChevronLeft} css={tw`text-sm`} />
                                    </Button>
                                    <div css={tw`text-xs text-neutral-400 px-2 py-1 bg-neutral-800 rounded`}>
                                        {currentIndex !== undefined && totalCount !== undefined && (
                                            `${currentIndex + 1} / ${totalCount}`
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        isSecondary
                                        size="small"
                                        css={tw`w-12 h-10 p-0 flex items-center justify-center rounded-lg transition-all duration-200 hover:bg-neutral-600`}
                                        onClick={onNext}
                                        disabled={!onNext || (currentIndex !== undefined && totalCount !== undefined && currentIndex >= totalCount - 1)}
                                    >
                                        <FontAwesomeIcon icon={faChevronRight} css={tw`text-sm`} />
                                    </Button>
                                </div>
                            )}
                        </div>

                        <Form css={tw`m-0`}>
                            {/* Import Type Selector */}
                            <div css={tw`mb-6`}>
                                <label css={tw`block text-sm font-medium text-neutral-300 mb-4`}>
                                    Import Source
                                </label>
                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                    <div
                                        css={[
                                            tw`border-2 rounded-xl p-4 cursor-pointer transition-all duration-300 bg-gradient-to-br`,
                                            values.importType === 'file'
                                                ? tw`border-blue-500 from-blue-900 to-blue-800 shadow-lg`
                                                : tw`border-neutral-600 from-neutral-800 to-neutral-900 hover:border-neutral-500 hover:from-neutral-700 hover:to-neutral-800`
                                        ]}
                                        onClick={() => setFieldValue('importType', 'file')}
                                    >
                                        <div css={tw`flex items-center mb-2`}>
                                            <input
                                                type="radio"
                                                name="importType"
                                                value="file"
                                                checked={values.importType === 'file'}
                                                onChange={() => setFieldValue('importType', 'file')}
                                                css={tw`mr-3 text-blue-600`}
                                            />
                                            <FontAwesomeIcon icon={faFileImport} css={tw`text-blue-400 mr-2`} />
                                            <span css={tw`font-semibold text-white`}>Import from File</span>
                                        </div>
                                        <p css={tw`text-sm text-neutral-400 ml-6`}>
                                            Upload a SQL backup file (.sql, .gz, .bz2, .zip, .tar, .7z, .rar)
                                        </p>
                                    </div>
                                    
                                    <div
                                        css={[
                                            tw`border-2 rounded-xl p-4 cursor-pointer transition-all duration-300 bg-gradient-to-br`,
                                            values.importType === 'credentials'
                                                ? tw`border-green-500 from-green-900 to-green-800 shadow-lg`
                                                : tw`border-neutral-600 from-neutral-800 to-neutral-900 hover:border-neutral-500 hover:from-neutral-700 hover:to-neutral-800`
                                        ]}
                                        onClick={() => setFieldValue('importType', 'credentials')}
                                    >
                                        <div css={tw`flex items-center mb-2`}>
                                            <input
                                                type="radio"
                                                name="importType"
                                                value="credentials"
                                                checked={values.importType === 'credentials'}
                                                onChange={() => setFieldValue('importType', 'credentials')}
                                                css={tw`mr-3 text-green-600`}
                                            />
                                            <FontAwesomeIcon icon={faServer} css={tw`text-green-400 mr-2`} />
                                            <span css={tw`font-semibold text-white`}>Import from Database</span>
                                        </div>
                                        <p css={tw`text-sm text-neutral-400 ml-6`}>
                                            Connect to an external MySQL database and import directly
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* File Upload Section */}
                            {values.importType === 'file' && (
                                <div css={tw`mb-6`}>
                                    <label css={tw`block text-sm font-medium text-neutral-300 mb-3`}>
                                        <FontAwesomeIcon icon={faFileImport} css={tw`mr-2`} />
                                        Database File
                                    </label>
                                <div css={tw`relative`} style={{ minHeight: '14rem' }}>
                                    <FileInput
                                        type="file"
                                        accept=".sql,.txt,.gz,.bz2,.zip,.tar,.7z,.rar"
                                        onChange={(event) => {
                                            const file = event.currentTarget.files?.[0] || null;
                                            setFieldValue('file', file);
                                            if (file) {
                                                handleFileAnalysis(file);
                                            }
                                        }}
                                    />
                                    {!values.file && (
                                        <div css={tw`absolute top-20 left-0 right-0 flex items-center justify-center pointer-events-none`}>
                                            <div css={tw`text-center max-w-md mx-auto px-4`}>
                                            
                                            </div>
                                        </div>
                                    )}
                                    {values.file && (
                                        <div css={tw`mt-3 p-3 bg-neutral-700 rounded-lg border border-neutral-600`}>
                                            <div css={tw`flex items-center`}>
                                                <FontAwesomeIcon icon={faFileImport} css={tw`text-green-500 mr-2`} />
                                                <span css={tw`text-sm text-neutral-200 font-medium`}>
                                                    {values.file.name}
                                                </span>
                                                <span css={tw`text-xs text-neutral-400 ml-2`}>
                                                    ({formatFileSize(values.file.size)})
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* File Analysis and Selective Import */}
                                {values.file && analyzing && (
                                    <div css={tw`mt-4 text-center py-8`}>
                                        <FontAwesomeIcon icon={faSpinner} spin css={tw`text-2xl text-blue-400 mb-2`} />
                                        <p css={tw`text-neutral-400`}>Analyzing file...</p>
                                    </div>
                                )}

                                {values.file && analysis && analysis.success && (
                                    <div css={tw`mt-4 bg-neutral-800 rounded-lg p-4 border border-neutral-600`}>
                                        <div css={tw`flex items-center mb-4`}>
                                            <FontAwesomeIcon icon={faDatabase} css={tw`text-blue-400 mr-2`} />
                                            <h3 css={tw`text-lg font-semibold text-neutral-100`}>File Analysis</h3>
                                        </div>
                                        
                                        <div css={tw`grid grid-cols-2 md:grid-cols-4 gap-4 mb-6`}>
                                            <div css={tw`bg-gradient-to-br from-blue-900 to-blue-800 p-4 rounded-lg text-center border border-blue-700`}>
                                                <div css={tw`text-2xl font-bold text-blue-300 mb-1`}>{analysis.tables.length}</div>
                                                <div css={tw`text-xs text-blue-400 font-medium`}>Tables</div>
                                            </div>
                                            <div css={tw`bg-gradient-to-br from-green-900 to-green-800 p-4 rounded-lg text-center border border-green-700`}>
                                                <div css={tw`text-2xl font-bold text-green-300 mb-1`}>{analysis.estimated_rows}</div>
                                                <div css={tw`text-xs text-green-400 font-medium`}>Est. Rows</div>
                                            </div>
                                            <div css={tw`bg-gradient-to-br from-purple-900 to-purple-800 p-4 rounded-lg text-center border border-purple-700`}>
                                                <div css={tw`text-2xl font-bold text-purple-300 mb-1`}>
                                                    {(analysis.file_size / 1024 / 1024).toFixed(1)}MB
                                                </div>
                                                <div css={tw`text-xs text-purple-400 font-medium`}>File Size</div>
                                            </div>
                                            <div css={tw`bg-gradient-to-br from-yellow-900 to-yellow-800 p-4 rounded-lg text-center border border-yellow-700`}>
                                                <div css={tw`text-2xl font-bold text-yellow-300 mb-1`}>
                                                    {analysis.tables.filter(t => t.has_data).length}
                                                </div>
                                                <div css={tw`text-xs text-yellow-400 font-medium`}>With Data</div>
                                            </div>
                                        </div>

                                        {/* Selective Import Toggle */}
                                        <div css={tw`mb-4`}>
                                            <StyledCheckbox
                                                checked={values.selective}
                                                onChange={(checked) => setFieldValue('selective', checked)}
                                                size="md"
                                                color="blue"
                                                label="Enable selective import (choose specific tables and columns)"
                                            />
                                        </div>

                                        {/* Table Selection */}
                                        {values.selective && (
                                            <div css={tw`space-y-3`}>
                                                <h4 css={tw`text-base font-semibold text-neutral-200 mb-3`}>
                                                    Select Tables to Import
                                                </h4>
                                                
                                                <div css={tw`max-h-96 overflow-y-auto space-y-2`}>
                                                    {analysis.tables.map((table) => (
                                                        <div
                                                            key={table.name}
                                                            css={[
                                                                tw`p-4 rounded-lg border transition-all duration-300 cursor-pointer transform hover:scale-[1.02]`,
                                                                values.selectedTables.includes(table.name) ? 
                                                                    tw`border-blue-500 bg-gradient-to-r from-blue-900 to-blue-800 bg-opacity-50 shadow-lg ring-2 ring-blue-500 ring-opacity-50` : 
                                                                    tw`border-neutral-600 hover:border-blue-400 hover:bg-gradient-to-r hover:from-neutral-800 hover:to-neutral-700 hover:shadow-md`
                                                            ]}
                                                        >
                                                            <div css={tw`flex items-center justify-between`}>
                                                                <div css={tw`flex items-center`}>
                                                                    <StyledCheckbox
                                                                        checked={values.selectedTables.includes(table.name)}
                                                                        onChange={() => toggleTable(table.name, values.selectedTables, setFieldValue)}
                                                                        size="md"
                                                                        color="blue"
                                                                    />
                                                                    <FontAwesomeIcon icon={faFileImport} css={tw`text-blue-400 mx-3`} />
                                                                    <div>
                                                                        <div css={tw`font-medium text-neutral-200`}>{table.name}</div>
                                                                        <div css={tw`text-xs text-neutral-400`}>
                                                                            {table.columns.length} columns, ~{table.estimated_rows} rows
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                
                                                                {values.selectedTables.includes(table.name) && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            toggleTableExpansion(table.name);
                                                                        }}
                                                                        css={tw`p-1 text-neutral-400 hover:text-neutral-200`}
                                                                    >
                                                                        <FontAwesomeIcon 
                                                                            icon={expandedTables.has(table.name) ? faChevronDown : faChevronRight} 
                                                                        />
                                                                    </button>
                                                                )}
                                                            </div>

                                                            {/* Column Selection */}
                                                            {values.selectedTables.includes(table.name) && expandedTables.has(table.name) && (
                                                                <div css={tw`mt-3 pt-3 border-t border-neutral-600`}>
                                                                    <div css={tw`text-sm font-medium text-neutral-300 mb-2`}>
                                                                        Select Columns:
                                                                    </div>
                                                                    <div css={tw`flex flex-wrap`}>
                                                                        {table.columns.map((column) => (
                                                                            <span
                                                                                key={column.name}
                                                                                css={[
                                                                                    tw`inline-flex items-center px-2 py-1 rounded-md text-xs font-medium mr-2 mb-2 transition-all duration-200 cursor-pointer`,
                                                                                    (values.selectedColumns[table.name] || []).includes(column.name) ? 
                                                                                        tw`bg-blue-600 text-white` : 
                                                                                        tw`bg-neutral-700 text-neutral-300 hover:bg-neutral-600`
                                                                                ]}
                                                                                onClick={() => toggleColumn(table.name, column.name, values.selectedColumns, setFieldValue)}
                                                                            >
                                                                                {column.name}
                                                                                <span css={tw`ml-1 text-xs opacity-75`}>
                                                                                    ({column.type})
                                                                                </span>
                                                                            </span>
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                            )}

                            {/* Credentials Form Section */}
                            {values.importType === 'credentials' && (
                                <div css={tw`mb-6`}>
                                    <label css={tw`block text-sm font-medium text-neutral-300 mb-4`}>
                                        <FontAwesomeIcon icon={faServer} css={tw`mr-2`} />
                                        MySQL Database Credentials
                                    </label>
                                    <div css={tw`bg-gradient-to-br from-neutral-800 to-neutral-900 p-6 rounded-xl border border-neutral-600 space-y-4`}>
                                        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                            <div>
                                                <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                                    Host/IP Address
                                                </label>
                                                <input
                                                    type="text"
                                                    placeholder="localhost or 192.168.1.100"
                                                    value={values.sourceCredentials.host}
                                                    onChange={(e) => setFieldValue('sourceCredentials.host', e.target.value)}
                                                    css={tw`w-full px-4 py-3 bg-neutral-700 border border-neutral-600 rounded-lg text-white placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-colors`}
                                                />
                                            </div>
                                            <div>
                                                <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                                    Port
                                                </label>
                                                <input
                                                    type="number"
                                                    placeholder="3306"
                                                    value={values.sourceCredentials.port}
                                                    onChange={(e) => setFieldValue('sourceCredentials.port', parseInt(e.target.value) || 3306)}
                                                    css={tw`w-full px-4 py-3 bg-neutral-700 border border-neutral-600 rounded-lg text-white placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-colors`}
                                                />
                                            </div>
                                        </div>
                                        
                                        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                            <div>
                                                <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                                    Username
                                                </label>
                                                <input
                                                    type="text"
                                                    placeholder="root"
                                                    value={values.sourceCredentials.username}
                                                    onChange={(e) => setFieldValue('sourceCredentials.username', e.target.value)}
                                                    css={tw`w-full px-4 py-3 bg-neutral-700 border border-neutral-600 rounded-lg text-white placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-colors`}
                                                />
                                            </div>
                                            <div>
                                                <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                                    Password
                                                </label>
                                                <div css={tw`relative`}>
                                                    <input
                                                        type={showPassword ? 'text' : 'password'}
                                                        placeholder="Enter password"
                                                        value={values.sourceCredentials.password}
                                                        onChange={(e) => setFieldValue('sourceCredentials.password', e.target.value)}
                                                        css={tw`w-full px-4 py-3 pr-12 bg-neutral-700 border border-neutral-600 rounded-lg text-white placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-colors`}
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowPassword(!showPassword)}
                                                        css={tw`absolute right-3 top-1/2 transform -translate-y-1/2 text-neutral-400 hover:text-white transition-colors`}
                                                    >
                                                        <FontAwesomeIcon icon={showPassword ? faEyeSlash : faEye} />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                                Database Name
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="database_name"
                                                value={values.sourceCredentials.database}
                                                onChange={(e) => setFieldValue('sourceCredentials.database', e.target.value)}
                                                css={tw`w-full px-4 py-3 bg-neutral-700 border border-neutral-600 rounded-lg text-white placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-colors`}
                                            />
                                        </div>
                                        
                                        <div css={tw`bg-blue-900 bg-opacity-30 border border-blue-700 border-opacity-30 rounded-lg p-4`}>
                                            <div css={tw`flex items-center text-blue-300 mb-2`}>
                                                <FontAwesomeIcon icon={faKey} css={tw`mr-2`} />
                                                <span css={tw`font-semibold`}>Connection Security</span>
                                            </div>
                                            <p css={tw`text-sm text-blue-200`}>
                                                Your credentials are encrypted and used only for the import process. 
                                                They are not stored on our servers.
                                            </p>
                                        </div>

                                        {/* Analyze Database Button */}
                                        <div css={tw`flex items-center justify-center mt-4`}>
                                            <button
                                                type="button"
                                                onClick={() => handleExternalDatabaseAnalysis(values.sourceCredentials)}
                                                disabled={analyzingExternal || !values.sourceCredentials.host || !values.sourceCredentials.username || !values.sourceCredentials.password || !values.sourceCredentials.database}
                                                css={[
                                                    tw`px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-500 hover:to-green-600 text-white font-semibold rounded-lg shadow-lg transition-all duration-300 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none`,
                                                    analyzingExternal && tw`animate-pulse`
                                                ]}
                                            >
                                                {analyzingExternal ? (
                                                    <>
                                                        <FontAwesomeIcon icon={faSpinner} spin css={tw`mr-2`} />
                                                        Analyzing Database...
                                                    </>
                                                ) : (
                                                    <>
                                                        <FontAwesomeIcon icon={faDatabase} css={tw`mr-2`} />
                                                        Analyze Database Structure
                                                    </>
                                                )}
                                            </button>
                                        </div>

                                        {/* External Database Analysis Results */}
                                        {externalAnalysis && externalAnalysis.success && (
                                            <div css={tw`mt-4 p-4 bg-gradient-to-br from-green-900 to-green-800 border border-green-700 rounded-lg`}>
                                                <div css={tw`flex items-center mb-4`}>
                                                    <FontAwesomeIcon icon={faDatabase} css={tw`text-green-400 mr-2`} />
                                                    <h3 css={tw`text-lg font-semibold text-green-100`}>Database Analysis</h3>
                                                </div>
                                                
                                                <div css={tw`grid grid-cols-2 md:grid-cols-4 gap-4 mb-6`}>
                                                    <div css={tw`bg-gradient-to-br from-blue-900 to-blue-800 p-3 rounded-lg text-center border border-blue-700`}>
                                                        <div css={tw`text-xl font-bold text-blue-300 mb-1`}>{externalAnalysis.total_tables}</div>
                                                        <div css={tw`text-xs text-blue-400 font-medium`}>Tables</div>
                                                    </div>
                                                    <div css={tw`bg-gradient-to-br from-green-900 to-green-800 p-3 rounded-lg text-center border border-green-700`}>
                                                        <div css={tw`text-xl font-bold text-green-300 mb-1`}>{externalAnalysis.total_records}</div>
                                                        <div css={tw`text-xs text-green-400 font-medium`}>Records</div>
                                                    </div>
                                                    <div css={tw`bg-gradient-to-br from-purple-900 to-purple-800 p-3 rounded-lg text-center border border-purple-700`}>
                                                        <div css={tw`text-xl font-bold text-purple-300 mb-1`}>{externalAnalysis.total_size_mb?.toFixed(1)} MB</div>
                                                        <div css={tw`text-xs text-purple-400 font-medium`}>Size</div>
                                                    </div>
                                                    <div css={tw`bg-gradient-to-br from-yellow-900 to-yellow-800 p-3 rounded-lg text-center border border-yellow-700`}>
                                                        <div css={tw`text-xl font-bold text-yellow-300 mb-1`}>
                                                            {externalAnalysis.tables?.filter(t => t.has_data).length || 0}
                                                        </div>
                                                        <div css={tw`text-xs text-yellow-400 font-medium`}>With Data</div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* Selective Import for Credentials */}
                                        <div css={tw`mt-4 p-4 bg-blue-900 bg-opacity-20 border border-blue-600 rounded-lg`}>
                                            <div css={tw`flex items-center`}>
                                                <StyledCheckbox
                                                    checked={values.selective}
                                                    onChange={(checked) => setFieldValue('selective', checked)}
                                                    size="md"
                                                    color="blue"
                                                />
                                                <span css={tw`flex items-center ml-2`}>
                                                    <FontAwesomeIcon icon={faDatabase} css={tw`mr-2 text-blue-400`} />
                                                    Enable selective import (choose specific tables and columns)
                                                </span>
                                            </div>
                                            {values.selective && !externalAnalysis?.success && (
                                                <div css={tw`mt-3 p-3 bg-yellow-900 bg-opacity-20 border border-yellow-600 rounded-md`}>
                                                    <div css={tw`flex items-start`}>
                                                        <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-yellow-400 mr-2 mt-0.5`} />
                                                        <p css={tw`text-xs text-yellow-200`}>
                                                            Please analyze the database structure first to enable table and column selection.
                                                        </p>
                                                    </div>
                                                </div>
                                            )}

                                            {/* External Database Table Selection */}
                                            {values.selective && externalAnalysis?.success && externalAnalysis.tables && (
                                                <div css={tw`mt-4 space-y-4`}>
                                                    <h4 css={tw`text-base font-semibold text-neutral-200 mb-3`}>
                                                        Select Tables to Import
                                                    </h4>
                                                    
                                                    <div css={tw`max-h-80 overflow-y-auto space-y-2`}>
                                                        {externalAnalysis.tables.map((table) => (
                                                            <div
                                                                key={table.name}
                                                                css={[
                                                                    tw`p-4 rounded-lg border transition-all duration-300 cursor-pointer transform hover:scale-[1.01]`,
                                                                    values.selectedTables.includes(table.name) ? 
                                                                        tw`border-blue-500 bg-gradient-to-r from-blue-900 to-blue-800 bg-opacity-50 shadow-lg ring-2 ring-blue-500 ring-opacity-50` : 
                                                                        tw`border-neutral-600 hover:border-blue-400 hover:bg-gradient-to-r hover:from-neutral-800 hover:to-neutral-700 hover:shadow-md`
                                                                ]}
                                                            >
                                                                <div css={tw`flex items-center justify-between`}>
                                                                    <div css={tw`flex items-center`}>
                                                                        <StyledCheckbox
                                                                            checked={values.selectedTables.includes(table.name)}
                                                                            onChange={() => toggleTable(table.name, values.selectedTables, setFieldValue)}
                                                                            size="md"
                                                                            color="blue"
                                                                        />
                                                                        <FontAwesomeIcon icon={faDatabase} css={tw`text-blue-400 mx-3`} />
                                                                        <div>
                                                                            <div css={tw`font-medium text-neutral-200`}>{table.name}</div>
                                                                            <div css={tw`text-xs text-neutral-400`}>
                                                                                {table.columns.length} columns, {table.rows} rows, {table.size_mb.toFixed(2)} MB
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    {values.selectedTables.includes(table.name) && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={(e) => {
                                                                                e.stopPropagation();
                                                                                toggleTableExpansion(table.name);
                                                                            }}
                                                                            css={tw`p-1 text-neutral-400 hover:text-neutral-200`}
                                                                        >
                                                                            <FontAwesomeIcon 
                                                                                icon={expandedTables.has(table.name) ? faChevronDown : faChevronRight} 
                                                                            />
                                                                        </button>
                                                                    )}
                                                                </div>

                                                                {/* Column Selection */}
                                                                {values.selectedTables.includes(table.name) && expandedTables.has(table.name) && (
                                                                    <div css={tw`mt-3 pt-3 border-t border-neutral-600`}>
                                                                        <div css={tw`text-sm font-medium text-neutral-300 mb-2`}>
                                                                            Select Columns (leave empty for all):
                                                                        </div>
                                                                        <div css={tw`flex flex-wrap`}>
                                                                            {table.columns.map((column) => (
                                                                                <span
                                                                                    key={column.column_name}
                                                                                    css={[
                                                                                        tw`inline-flex items-center px-2 py-1 rounded-md text-xs font-medium mr-2 mb-2 transition-all duration-200 cursor-pointer`,
                                                                                        (values.selectedColumns[table.name] || []).includes(column.column_name) ? 
                                                                                            tw`bg-blue-600 text-white` : 
                                                                                            tw`bg-neutral-700 text-neutral-300 hover:bg-neutral-600`
                                                                                    ]}
                                                                                    onClick={() => toggleColumn(table.name, column.column_name, values.selectedColumns, setFieldValue)}
                                                                                >
                                                                                    {column.column_name}
                                                                                    <span css={tw`ml-1 text-xs opacity-75`}>
                                                                                        ({column.data_type})
                                                                                    </span>
                                                                                </span>
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                            <div css={tw`mb-6`}>
                                <label css={tw`block text-sm font-medium text-neutral-300 mb-4`}>
                                    Import Strategy
                                </label>

                                <div css={tw`space-y-4 lg:space-y-0 lg:grid lg:grid-cols-2 lg:gap-4`}>
                                    <div
                                        css={[
                                            tw`border-2 rounded-lg p-4 cursor-pointer transition-all duration-200`,
                                            values.mode === 'wipe'
                                                ? tw`border-red-500 bg-red-900 bg-opacity-20`
                                                : tw`border-neutral-600 bg-neutral-800 hover:border-neutral-500`
                                        ]}
                                        onClick={() => setFieldValue('mode', 'wipe')}
                                    >
                                        <div css={tw`flex flex-col sm:flex-row items-start sm:items-center justify-between`}>
                                            <div css={tw`flex items-center mb-2 sm:mb-0`}>
                                                <input
                                                    type="radio"
                                                    name="mode"
                                                    value="wipe"
                                                    checked={values.mode === 'wipe'}
                                                    onChange={() => setFieldValue('mode', 'wipe')}
                                                    css={tw`mr-3 text-red-600`}
                                                />
                                                <div>
                                                    <p css={tw`font-medium text-white`}>Replace Database</p>
                                                    <p css={tw`text-sm text-neutral-400`}>Completely wipe and replace all data</p>
                                                </div>
                                            </div>
                                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-red-500 mt-2 sm:mt-0`} />
                                        </div>
                                        {values.mode === 'wipe' && (
                                            <div css={tw`mt-4 p-4 bg-red-900 bg-opacity-50 border border-red-700 border-opacity-50 rounded-lg`}>
                                                <p css={tw`text-sm text-red-200 flex items-start`}>
                                                    <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2 mt-0.5 flex-shrink-0`} />
                                                    <span><strong>Warning:</strong> All existing data will be permanently deleted!</span>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    <div
                                        css={[
                                            tw`border-2 rounded-lg p-4 cursor-pointer transition-all duration-200`,
                                            values.mode === 'merge'
                                                ? tw`border-yellow-500 bg-yellow-900 bg-opacity-20`
                                                : tw`border-neutral-600 bg-neutral-800 hover:border-neutral-500`
                                        ]}
                                        onClick={() => setFieldValue('mode', 'merge')}
                                    >
                                        <div css={tw`flex flex-col sm:flex-row items-start sm:items-center justify-between`}>
                                            <div css={tw`flex items-center mb-2 sm:mb-0`}>
                                                <input
                                                    type="radio"
                                                    name="mode"
                                                    value="merge"
                                                    checked={values.mode === 'merge'}
                                                    onChange={() => setFieldValue('mode', 'merge')}
                                                    css={tw`mr-3 text-yellow-600`}
                                                />
                                                <div>
                                                    <p css={tw`font-medium text-white`}>Merge with Existing</p>
                                                    <p css={tw`text-sm text-neutral-400`}>Add to existing data without deletion</p>
                                                </div>
                                            </div>
                                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-yellow-500 mt-2 sm:mt-0`} />
                                        </div>
                                        {values.mode === 'merge' && (
                                            <div css={tw`mt-4 p-4 bg-yellow-900 bg-opacity-50 border border-yellow-700 border-opacity-50 rounded-lg`}>
                                                <p css={tw`text-sm text-yellow-200 flex items-start`}>
                                                    <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2 mt-0.5 flex-shrink-0`} />
                                                    <span><strong>Caution:</strong> Possible data conflicts or corruption may occur</span>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div css={tw`mb-8`}>
                                <div css={tw`flex items-start p-4 bg-neutral-800 border border-neutral-700 rounded-lg`}>
                                    <StyledCheckbox
                                        checked={values.force}
                                        onChange={(checked) => setFieldValue('force', checked)}
                                        size="md"
                                        color="yellow"
                                        className="mt-1 flex-shrink-0"
                                    />
                                    <div css={tw`flex-1 ml-3`}>
                                        <div css={tw`flex flex-col sm:flex-row items-start sm:items-center justify-between`}>
                                            <div css={tw`flex-1`}>
                                                <p css={tw`font-medium text-white mb-1`}>Force Import</p>
                                                <p css={tw`text-sm text-neutral-400 leading-relaxed`}>
                                                    Skip security validation for trusted files
                                                </p>
                                            </div>
                                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-yellow-500 mt-2 sm:mt-0 flex-shrink-0`} />
                                        </div>
                                        {values.force && (
                                            <div css={tw`mt-4 p-4 bg-yellow-900 bg-opacity-50 border border-yellow-700 border-opacity-50 rounded-lg`}>
                                                <p css={tw`text-sm text-yellow-200 flex items-start`}>
                                                    <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2 mt-0.5 flex-shrink-0`} />
                                                    <span><strong>Note:</strong> This bypasses security checks. Only use with trusted SQL files.</span>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div css={tw`mt-8 bg-gradient-to-r from-neutral-800 via-neutral-700 to-neutral-800 p-6 rounded-xl border border-neutral-600 shadow-lg`}>
                                <div css={tw`flex items-center justify-center mb-4 p-3 rounded-lg transition-all duration-300`} style={{
                                    background: values.file
                                        ? 'linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05))'
                                        : 'linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(107, 114, 128, 0.05))',
                                    border: values.file
                                        ? '1px solid rgba(34, 197, 94, 0.3)'
                                        : '1px solid rgba(107, 114, 128, 0.3)'
                                }}>
                                    {values.file ? (
                                        <div css={tw`flex items-center text-green-400`}>
                                            <div css={tw`w-8 h-8 bg-green-600 rounded-full flex items-center justify-center mr-3 shadow-lg`}>
                                                <FontAwesomeIcon icon={faFileImport} css={tw`text-white text-sm`} />
                                            </div>
                                            <div css={tw`text-left`}>
                                                <p css={tw`text-sm font-semibold text-green-300`}>
                                                    Ready to import: {sanitizeFilename(values.file.name)}
                                                </p>
                                                <p css={tw`text-xs text-green-400 opacity-80`}>
                                                    File size: {formatFileSize(values.file.size)} • Mode: {values.mode === 'wipe' ? 'Replace Database' : 'Merge with Existing'}
                                                </p>
                                            </div>
                                        </div>
                                    ) : values.importType === 'credentials' ? (
                                        <div css={tw`flex items-center text-green-400`}>
                                            <div css={tw`w-8 h-8 bg-green-600 rounded-full flex items-center justify-center mr-3 shadow-lg`}>
                                                <FontAwesomeIcon icon={faServer} css={tw`text-white text-sm`} />
                                            </div>
                                            <div css={tw`text-left`}>
                                                <p css={tw`text-sm font-semibold text-green-300`}>
                                                    Ready to import from: {values.sourceCredentials.host}:{values.sourceCredentials.port}
                                                </p>
                                                <p css={tw`text-xs text-green-400 opacity-80`}>
                                                    Database: {values.sourceCredentials.database} • Mode: {values.mode === 'wipe' ? 'Replace Database' : 'Merge with Existing'}
                                                </p>
                                            </div>
                                        </div>
                                    ) : (
                                        <div css={tw`flex items-center text-neutral-400`}>
                                            <div css={tw`w-8 h-8 bg-neutral-600 rounded-full flex items-center justify-center mr-3`}>
                                                <FontAwesomeIcon icon={faUpload} css={tw`text-neutral-400 text-sm`} />
                                            </div>
                                            <p css={tw`text-sm font-medium`}>
                                                {values.importType === 'file' 
                                                    ? 'Please select a SQL file to continue'
                                                    : 'Please fill in the database credentials to continue'
                                                }
                                            </p>
                                        </div>
                                    )}
                                </div>
                                <div css={tw`flex flex-col sm:flex-row gap-3 justify-end`}>
                                    <button
                                        type="button"
                                        onClick={onDismissed}
                                        disabled={isSubmitting}
                                        className="group relative px-6 py-3 bg-gradient-to-r from-neutral-600 via-neutral-700 to-neutral-600 hover:from-neutral-500 hover:via-neutral-600 hover:to-neutral-500 text-white rounded-xl font-medium transition-all duration-300 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 border border-neutral-500 hover:border-neutral-400 w-full sm:w-auto"
                                        style={{
                                            boxShadow: '0 4px 15px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                                        }}
                                    >
                                        <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-10 transition-opacity duration-300 rounded-xl"></div>
                                        <span className="relative z-10">Cancel</span>
                                    </button>

                                    <button
                                        type="submit"
                                        disabled={
                                            (values.importType === 'file' && !values.file) ||
                                            (values.importType === 'credentials' && (!values.sourceCredentials.host || !values.sourceCredentials.username || !values.sourceCredentials.password || !values.sourceCredentials.database)) ||
                                            isSubmitting
                                        }
                                        className="group relative px-8 py-3 bg-gradient-to-r from-blue-600 via-blue-700 to-blue-600 hover:from-blue-500 hover:via-blue-600 hover:to-blue-500 disabled:from-neutral-600 disabled:via-neutral-700 disabled:to-neutral-600 text-white rounded-xl font-semibold transition-all duration-300 flex items-center justify-center gap-3 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 disabled:transform-none border border-blue-500 hover:border-blue-400 disabled:border-neutral-500 w-full sm:w-auto"
                                        style={{
                                            boxShadow: (
                                                (values.importType === 'file' && !values.file) ||
                                                (values.importType === 'credentials' && (!values.sourceCredentials.host || !values.sourceCredentials.username || !values.sourceCredentials.password || !values.sourceCredentials.database)) ||
                                                isSubmitting
                                            )
                                                ? '0 4px 15px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                                                : '0 4px 15px rgba(59, 130, 246, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                                        }}
                                    >
                                        <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-10 transition-opacity duration-300 rounded-xl"></div>
                                        <FontAwesomeIcon
                                            icon={values.importType === 'credentials' ? faServer : faUpload}
                                            className={`relative z-10 transition-transform duration-300 ${isSubmitting ? 'animate-spin' : 'group-hover:translate-y-[-2px]'
                                                }`}
                                        />
                                        <span className="relative z-10">
                                            {isSubmitting 
                                                ? (values.importType === 'credentials' ? 'Importing from Database...' : 'Importing File...')
                                                : (values.importType === 'credentials' ? 'Import from Database' : 'Import from File')
                                            }
                                        </span>
                                        {!isSubmitting && (
                                            <div className="absolute inset-0 bg-gradient-to-r from-blue-400 to-blue-600 opacity-0 group-hover:opacity-20 transition-opacity duration-300 rounded-xl"></div>
                                        )}
                                    </button>
                                </div>
                            </div>
                        </Form>
                    </div>
                </GradientModal>
            )}
        </Formik>
    );
};