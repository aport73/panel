import React, { useState } from 'react';
import { Form, Formik, FormikHelpers } from 'formik';
import { object, string, mixed, array } from 'yup';
import StyledCheckbox from '@/components/elements/StyledCheckbox';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faDownload,
    faDatabase,
    faFileArchive,
    faCompress,
    faFile,
    faCheckCircle,
    faSpinner,
    faArchive,
    faChevronDown,
    faChevronRight
} from '@fortawesome/free-solid-svg-icons';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { httpErrorToHuman } from '@/api/http';
import tw, { styled } from 'twin.macro';
import { sanitizeFilename, sanitizeDatabaseName } from '@/utils/xssProtection';
import GradientModal from './modal_components/GradientModal';
import downloadSelective from '@/api/server/databases/downloadSelective';
import getDatabaseContents, { DatabaseContents } from '@/api/server/databases/getDatabaseContents';

interface Props {
    databaseId: string;
    databaseName: string;
    visible: boolean;
    onDismissed: () => void;
}

interface FormValues {
    format: 'sql' | 'gz' | 'bz2' | 'zip' | 'tar' | '7z' | 'rar';
    filename: string;
    selective: boolean;
    selectedTables: string[];
    selectedColumns: Record<string, string[]>;
}

const schema = object().shape({
    format: string().required('Export format is required').oneOf(['sql', 'gz', 'bz2', 'zip', 'tar', '7z', 'rar']),
    filename: string().required('Filename is required').min(1, 'Filename cannot be empty'),
    selective: mixed(),
    selectedTables: array().of(string()).default([]),
    selectedColumns: object().default({}),
});


const FilenameInput = styled.input`
    ${tw`w-full px-4 py-3 bg-neutral-800 border border-neutral-600 rounded-lg text-neutral-100 placeholder-neutral-400 focus:border-blue-500 focus:outline-none transition-all duration-200`};
    
    &:hover {
        ${tw`bg-neutral-700 border-neutral-500`};
    }
    
    &:focus {
        ${tw`bg-neutral-700`};
    }
`;

const formatOptions = [
    {
        value: 'sql',
        title: 'SQL File',
        description: 'Standard SQL dump file',
        icon: faFile,
        badge: 'Recommended',
        size: '100%'
    },
    {
        value: 'gz',
        title: 'GZIP Compressed',
        description: 'SQL file with GZIP compression',
        icon: faCompress,
        badge: 'Fast',
        size: '~30%'
    },
    {
        value: 'bz2',
        title: 'BZIP2 Compressed',
        description: 'SQL file with BZIP2 compression',
        icon: faFileArchive,
        badge: 'High Compression',
        size: '~25%'
    },
    {
        value: 'zip',
        title: 'ZIP Archive',
        description: 'SQL file in ZIP archive',
        icon: faFileArchive,
        badge: 'Universal',
        size: '~35%'
    },
    {
        value: 'tar',
        title: 'TAR Archive',
        description: 'SQL file in TAR archive',
        icon: faArchive,
        badge: 'Unix Standard',
        size: '~40%'
    },
    {
        value: '7z',
        title: '7-Zip Compressed',
        description: 'SQL file with 7-Zip compression',
        icon: faFileArchive,
        badge: 'Best Compression',
        size: '~20%'
    },
    {
        value: 'rar',
        title: 'RAR Archive',
        description: 'SQL file in RAR archive',
        icon: faArchive,
        badge: 'WinRAR',
        size: '~22%'
    }
];

export default ({ databaseId, databaseName, visible, onDismissed }: Props) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes, addFlash } = useFlash();
    const [isExporting, setIsExporting] = useState(false);
    const [loadingContents, setLoadingContents] = useState(false);
    const [databaseContents, setDatabaseContents] = useState<DatabaseContents | null>(null);
    const [expandedTables, setExpandedTables] = useState<Set<string>>(new Set());

    const generateFilename = (format: string) => {
        const timestamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '_');
        const safeDatabaseName = databaseName.replace(/[<>"'&]/g, '');
        return `${safeDatabaseName}_${timestamp}.${format}`;
    };

    const loadDatabaseContents = async () => {
        if (databaseContents) return; 

        setLoadingContents(true);
        try {
            const contents = await getDatabaseContents(uuid, databaseId);
            setDatabaseContents(contents);
        } catch (error: any) {
            console.error('Failed to load database contents:', error);
            addError({ key: 'database-contents', message: httpErrorToHuman(error) });
        } finally {
            setLoadingContents(false);
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

    const sanitizeFilename = (filename: string) => {
        return filename.replace(/[<>"'&]/g, '').replace(/[\/\\:*?"<>|]/g, '_');
    };


    const submit = async (values: FormValues, { setSubmitting, resetForm }: FormikHelpers<FormValues>) => {
        clearFlashes('database:export');
        setIsExporting(true);
        setSubmitting(true);

        try {
            if (values.selective && values.selectedTables.length > 0) {
                downloadSelective(uuid, databaseId, {
                    format: values.format,
                    filename: values.filename,
                    selected_tables: values.selectedTables,
                    selected_columns: values.selectedColumns,
                });
            } else {
                const downloadUrl = `/api/client/servers/${uuid}/databases/${databaseId}/download?format=${values.format}&filename=${encodeURIComponent(values.filename)}`;
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = values.filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            addFlash({
                key: 'database:export',
                type: 'success',
                message: `Database export started. Your ${values.format.toUpperCase()} file will download shortly.`,
            });

            resetForm();
            onDismissed();
        } catch (error) {
            console.error(error);
            addError({ key: 'database:export', message: httpErrorToHuman(error) });
        } finally {
            setIsExporting(false);
            setSubmitting(false);
        }
    };

    return (
        <Formik<FormValues>
            onSubmit={submit}
            initialValues={{
                format: 'sql',
                filename: generateFilename('sql'),
                selective: false,
                selectedTables: [],
                selectedColumns: {}
            }}
            validationSchema={schema}
        >
            {({ isSubmitting, setFieldValue, values }) => (
                <GradientModal
                    visible={visible}
                    onDismissed={onDismissed}
                    size="xl"
                    dismissable={!isSubmitting}
                    closeOnEscape={!isSubmitting}
                    closeOnBackground={!isSubmitting}
                >
                        {isExporting && (
                            <div css={tw`absolute inset-0 bg-neutral-900 bg-opacity-75 flex items-center justify-center z-10 rounded-lg`}>
                                <div css={tw`flex flex-col items-center`}>
                                    <FontAwesomeIcon
                                        icon={faSpinner}
                                        css={tw`text-4xl text-blue-400 animate-spin mb-4`}
                                    />
                                    <p css={tw`text-lg font-medium text-white`}>Exporting Database...</p>
                                    <p css={tw`text-sm text-neutral-400 mt-2`}>Please wait while we prepare your download</p>
                                </div>
                            </div>
                        )}
                        <div css={tw`p-4 sm:p-6`}>
                            <FlashMessageRender byKey={'database:export'} css={tw`mb-6`} />
                            <div css={tw`flex flex-col sm:flex-row items-center mb-6`}>
                                <div css={tw`bg-green-600 p-3 rounded-full mr-0 sm:mr-4 mb-3 sm:mb-0`}>
                                    <FontAwesomeIcon icon={faDatabase} css={tw`text-white text-lg`} />
                                </div>
                                <div css={tw`text-center sm:text-left`}>
                                    <h2 css={tw`text-xl sm:text-2xl font-semibold text-white`}>Export Database</h2>
                                    <p css={tw`text-sm text-neutral-400`}>Choose format and download {databaseName}</p>
                                </div>
                            </div>

                            <Form css={tw`m-0`}>
                                <div css={tw`mb-6`}>
                                    <label css={tw`block text-sm font-medium text-neutral-300 mb-4`}>
                                        <FontAwesomeIcon icon={faFileArchive} css={tw`mr-2`} />
                                        Export Format
                                    </label>

                                    <div css={tw`grid grid-cols-2 lg:grid-cols-3 gap-3`}>
                                        {formatOptions.map((option) => (
                                            <div
                                                key={option.value}
                                                className="group"
                                                css={[
                                                    tw`relative border-2 rounded-xl p-3 cursor-pointer transition-all duration-300 transform hover:scale-105`,
                                                    values.format === option.value
                                                        ? tw`border-blue-500 bg-gradient-to-br from-blue-900 to-blue-800 shadow-lg`
                                                        : tw`border-neutral-600 bg-gradient-to-br from-neutral-800 to-neutral-900 hover:border-neutral-500 hover:shadow-lg`
                                                ]}
                                                onClick={() => {
                                                    setFieldValue('format', option.value);
                                                    setFieldValue('filename', generateFilename(option.value));
                                                }}
                                            >
                                                {values.format === option.value && (
                                                    <div css={tw`absolute -top-2 -right-2 w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center shadow-lg`}>
                                                        <FontAwesomeIcon icon={faCheckCircle} css={tw`text-white text-sm`} />
                                                    </div>
                                                )}
                                                <div css={[
                                                    tw`absolute top-2 left-2 px-2 py-1 rounded-full text-xs font-medium transition-all duration-300`,
                                                    values.format === option.value
                                                        ? tw`bg-blue-600 text-blue-100`
                                                        : tw`bg-neutral-700 text-neutral-300 group-hover:bg-neutral-600`
                                                ]}>
                                                    {option.badge}
                                                </div>

                                                <div css={tw`flex flex-col items-center text-center pt-6 pb-2`}>
                                                    <div css={[
                                                        tw`w-12 h-12 rounded-full flex items-center justify-center mb-3 transition-all duration-300`,
                                                        values.format === option.value
                                                            ? tw`bg-blue-600 text-white shadow-lg`
                                                            : tw`bg-neutral-700 text-neutral-300 group-hover:bg-neutral-600`
                                                    ]}>
                                                        <FontAwesomeIcon icon={option.icon} css={tw`text-lg`} />
                                                    </div>
                                                    <h3 css={[
                                                        tw`font-semibold text-sm mb-1 transition-colors duration-300`,
                                                        values.format === option.value ? tw`text-blue-100` : tw`text-white group-hover:text-neutral-100`
                                                    ]}>
                                                        {option.title}
                                                    </h3>
                                                    <div css={[
                                                        tw`text-xs font-medium px-2 py-1 rounded transition-colors duration-300`,
                                                        values.format === option.value
                                                            ? tw`text-blue-200 bg-blue-800`
                                                            : tw`text-neutral-400 group-hover:text-neutral-300`
                                                    ]}>
                                                        {option.size}
                                                    </div>
                                                </div>
                                                <input
                                                    type="radio"
                                                    name="format"
                                                    value={option.value}
                                                    checked={values.format === option.value}
                                                    onChange={() => {
                                                        setFieldValue('format', option.value);
                                                        setFieldValue('filename', generateFilename(option.value));
                                                    }}
                                                    css={tw`sr-only`}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    <div css={tw`mt-4 p-3 bg-neutral-800 rounded-lg border border-neutral-700`}>
                                        <div css={tw`flex items-center text-sm`}>
                                            <FontAwesomeIcon
                                                icon={formatOptions.find(f => f.value === values.format)?.icon || faFile}
                                                css={tw`mr-2 text-blue-400`}
                                            />
                                            <span css={tw`text-neutral-300`}>
                                                {formatOptions.find(f => f.value === values.format)?.description}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div css={tw`mb-6`}>
                                    <label css={tw`block text-sm font-medium text-neutral-300 mb-3`}>
                                        <FontAwesomeIcon icon={faFile} css={tw`mr-2`} />
                                        Filename
                                    </label>
                                    <FilenameInput
                                        type="text"
                                        value={values.filename}
                                        onChange={(e) => setFieldValue('filename', sanitizeFilename(e.target.value))}
                                        placeholder={`${sanitizeDatabaseName(databaseName)}_backup.${values.format}`}
                                    />
                                    <p css={tw`text-xs text-neutral-500 mt-2`}>
                                        The file extension will be automatically set based on the selected format
                                    </p>
                                </div>

                                {/* Selective Export Toggle */}
                                <div css={tw`mb-6 p-4 bg-blue-900 bg-opacity-20 border border-blue-600 rounded-lg`}>
                                    <div css={tw`flex items-center justify-between`}>
                                        <div css={tw`flex items-center`}>
                                            <StyledCheckbox
                                                checked={values.selective}
                                                onChange={async (checked) => {
                                                    setFieldValue('selective', checked);
                                                    if (checked) {
                                                        await loadDatabaseContents();
                                                    }
                                                }}
                                                size="md"
                                                color="blue"
                                            />
                                            <span css={tw`flex items-center ml-2`}>
                                                <FontAwesomeIcon icon={faDatabase} css={tw`mr-2 text-blue-400`} />
                                                Enable selective export (choose specific tables and columns)
                                            </span>
                                        </div>
                                        {values.selective && loadingContents && (
                                            <FontAwesomeIcon icon={faSpinner} spin css={tw`text-blue-400`} />
                                        )}
                                    </div>

                                    {/* Database Contents and Table Selection */}
                                    {values.selective && databaseContents && !loadingContents && (
                                        <div css={tw`mt-4 space-y-4`}>
                                            {/* Database Overview */}
                                            <div css={tw`grid grid-cols-2 md:grid-cols-3 gap-4 mb-4`}>
                                                <div css={tw`bg-gradient-to-br from-green-900 to-green-800 p-3 rounded-lg text-center border border-green-700`}>
                                                    <div css={tw`text-xl font-bold text-green-300 mb-1`}>{databaseContents.tables.length}</div>
                                                    <div css={tw`text-xs text-green-400 font-medium`}>Total Tables</div>
                                                </div>
                                                <div css={tw`bg-gradient-to-br from-blue-900 to-blue-800 p-3 rounded-lg text-center border border-blue-700`}>
                                                    <div css={tw`text-xl font-bold text-blue-300 mb-1`}>{databaseContents.total_records}</div>
                                                    <div css={tw`text-xs text-blue-400 font-medium`}>Total Records</div>
                                                </div>
                                                <div css={tw`bg-gradient-to-br from-purple-900 to-purple-800 p-3 rounded-lg text-center border border-purple-700`}>
                                                    <div css={tw`text-xl font-bold text-purple-300 mb-1`}>{values.selectedTables.length}</div>
                                                    <div css={tw`text-xs text-purple-400 font-medium`}>Selected</div>
                                                </div>
                                            </div>

                                            {/* Table Selection */}
                                            <div>
                                                <h4 css={tw`text-base font-semibold text-neutral-200 mb-3`}>
                                                    Select Tables to Export
                                                </h4>
                                                
                                                <div css={tw`max-h-80 overflow-y-auto space-y-2`}>
                                                    {databaseContents.tables.map((table) => (
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
                                        </div>
                                    )}
                                </div>

                                <div css={tw`mt-8 flex flex-col sm:flex-row justify-between items-center bg-neutral-800 p-4 rounded-lg border border-neutral-700 space-y-4 sm:space-y-0`}>
                                    <div css={tw`text-sm text-neutral-400 text-center sm:text-left w-full sm:w-auto`}>
                                        {values.format && (
                                            <span css={tw`flex items-center`}>
                                                <FontAwesomeIcon
                                                    icon={formatOptions.find(f => f.value === values.format)?.icon || faFile}
                                                    css={tw`mr-2 text-blue-400`}
                                                />
                                                Ready to export as {values.format?.toUpperCase() || 'UNKNOWN'} format
                                            </span>
                                        )}
                                    </div>
                                    <div css={tw`flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4 w-full sm:w-auto`}>
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
                                            disabled={isSubmitting || !values.filename}
                                            className="group relative px-8 py-3 bg-gradient-to-r from-green-600 via-green-700 to-green-600 hover:from-green-500 hover:via-green-600 hover:to-green-500 disabled:from-neutral-600 disabled:via-neutral-700 disabled:to-neutral-600 text-white rounded-xl font-semibold transition-all duration-300 flex items-center justify-center gap-3 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 disabled:transform-none border border-green-500 hover:border-green-400 disabled:border-neutral-500 w-full sm:w-auto"
                                            style={{
                                                boxShadow: (isSubmitting || !values.filename)
                                                    ? '0 4px 15px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                                                    : '0 4px 15px rgba(34, 197, 94, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                                            }}
                                        >
                                            <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-10 transition-opacity duration-300 rounded-xl"></div>
                                            <FontAwesomeIcon
                                                icon={isSubmitting ? faSpinner : faDownload}
                                                className={`relative z-10 transition-transform duration-300 ${isSubmitting ? 'animate-spin' : 'group-hover:translate-y-[-2px]'
                                                    }`}
                                            />
                                            <span className="relative z-10">
                                                {isSubmitting ? 'Exporting Database...' : 'Export Database'}
                                            </span>
                                            {!isSubmitting && (
                                                <div className="absolute inset-0 bg-gradient-to-r from-green-400 to-green-600 opacity-0 group-hover:opacity-20 transition-opacity duration-300 rounded-xl"></div>
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