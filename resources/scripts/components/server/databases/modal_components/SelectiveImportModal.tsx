import React, { useState, useEffect } from 'react';
import { Form, Formik, FormikHelpers } from 'formik';
import { object, string, array, boolean } from 'yup';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faUpload,
    faTable,
    faColumns,
    faCheckCircle,
    faSpinner,
    faFile,
    faDatabase,
    faEye,
    faChevronDown,
    faChevronRight,
    faInfoCircle
} from '@fortawesome/free-solid-svg-icons';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { httpErrorToHuman } from '@/api/http';
import tw, { styled } from 'twin.macro';
import GradientModal from './GradientModal';
import analyzeFile, { FileAnalysisResult, AnalyzedTable } from '@/api/server/databases/analyzeFile';
import getImportPreview, { ImportPreviewResult } from '@/api/server/databases/getImportPreview';
import uploadDatabase from '@/api/server/databases/uploadDatabase';

interface Props {
    databaseId: string;
    visible: boolean;
    onDismissed: () => void;
}

interface FormValues {
    file: File | null;
    mode: 'wipe' | 'merge';
    selective: boolean;
    selectedTables: string[];
    selectedColumns: Record<string, string[]>;
}

const schema = object().shape({
    file: object().nullable().required('File is required'),
    mode: string().required().oneOf(['wipe', 'merge']),
    selective: boolean(),
    selectedTables: array().of(string()).default([]),
    selectedColumns: object().default({}),
});

const FileInput = styled.input`
    ${tw`sr-only`}
`;

const FileDropZone = styled.div<{ isDragOver: boolean }>`
    ${tw`relative border-2 border-dashed rounded-lg p-8 text-center transition-all duration-200`}
    ${({ isDragOver }) => isDragOver ? 
        tw`border-blue-400 bg-blue-50 bg-opacity-10` : 
        tw`border-neutral-600 hover:border-neutral-500`
    }
`;

const TableCard = styled.div<{ selected: boolean }>`
    ${tw`p-4 rounded-lg border transition-all duration-200 cursor-pointer`}
    ${({ selected }) => selected ? 
        tw`border-blue-500 bg-blue-50 bg-opacity-10 shadow-md` : 
        tw`border-neutral-600 hover:border-neutral-500 hover:bg-neutral-800`
    }
`;

const ColumnTag = styled.span<{ selected: boolean }>`
    ${tw`inline-flex items-center px-2 py-1 rounded-md text-xs font-medium mr-2 mb-2 transition-all duration-200 cursor-pointer`}
    ${({ selected }) => selected ? 
        tw`bg-blue-600 text-white` : 
        tw`bg-neutral-700 text-neutral-300 hover:bg-neutral-600`
    }
`;

const SelectiveImportModal: React.FC<Props> = ({ databaseId, visible, onDismissed }) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes } = useFlash();
    
    const [analyzing, setAnalyzing] = useState(false);
    const [analysis, setAnalysis] = useState<FileAnalysisResult | null>(null);
    const [preview, setPreview] = useState<ImportPreviewResult | null>(null);
    const [expandedTables, setExpandedTables] = useState<Set<string>>(new Set());
    const [isDragOver, setIsDragOver] = useState(false);

    const resetState = () => {
        setAnalyzing(false);
        setAnalysis(null);
        setPreview(null);
        setExpandedTables(new Set());
        clearFlashes();
    };

    useEffect(() => {
        if (!visible) {
            resetState();
        }
    }, [visible]);

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

    const generatePreview = async (file: File, selectedTables: string[]) => {
        if (!file || selectedTables.length === 0) return;

        try {
            const result = await getImportPreview(uuid, databaseId, file, selectedTables);
            setPreview(result);
            
            if (!result.success) {
                addError({ key: 'preview', message: result.error || 'Failed to generate preview' });
            }
        } catch (error: any) {
            console.error('Preview generation error:', error);
            addError({ key: 'preview', message: httpErrorToHuman(error) });
        }
    };

    const toggleTable = (tableName: string, selectedTables: string[], setFieldValue: any) => {
        const newSelection = selectedTables.includes(tableName)
            ? selectedTables.filter(t => t !== tableName)
            : [...selectedTables, tableName];
        
        setFieldValue('selectedTables', newSelection);

        if (newSelection.length > 0) {
            const currentFile = document.querySelector('input[type="file"]') as HTMLInputElement;
            if (currentFile?.files?.[0]) {
                generatePreview(currentFile.files[0], newSelection);
            }
        }
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

    const submit = async (values: FormValues, { setSubmitting }: FormikHelpers<FormValues>) => {
        if (!values.file) {
            addError({ key: 'import', message: 'Please select a file to import' });
            setSubmitting(false);
            return;
        }

        clearFlashes();

        try {
            await uploadDatabase(uuid, databaseId, {
                import_type: 'file',
                file: values.file,
                mode: values.mode,
                selective: values.selective,
                selected_tables: values.selective ? values.selectedTables : undefined,
                selected_columns: values.selective ? values.selectedColumns : undefined,
            });

            onDismissed();
        } catch (error: any) {
            console.error('Import error:', error);
            addError({ key: 'import', message: httpErrorToHuman(error) });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <GradientModal visible={visible} onDismissed={onDismissed}>
            <FlashMessageRender byKey={'import'} css={tw`mb-6`} />
            <FlashMessageRender byKey={'file-analysis'} css={tw`mb-6`} />
            <FlashMessageRender byKey={'preview'} css={tw`mb-6`} />

            <div css={tw`flex items-center mb-6`}>
                <FontAwesomeIcon icon={faUpload} css={tw`text-2xl text-blue-400 mr-3`} />
                <h2 css={tw`text-2xl font-bold text-neutral-100`}>Selective Database Import</h2>
            </div>

            <Formik<FormValues>
                onSubmit={submit}
                initialValues={{
                    file: null,
                    mode: 'merge',
                    selective: false,
                    selectedTables: [],
                    selectedColumns: {},
                }}
                validationSchema={schema}
            >
                {({ isSubmitting, values, setFieldValue, errors, touched }) => (
                    <Form css={tw`space-y-6`}>
                        {/* File Upload */}
                        <div>
                            <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                SQL File
                            </label>
                            <FileDropZone
                                isDragOver={isDragOver}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setIsDragOver(true);
                                }}
                                onDragLeave={() => setIsDragOver(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setIsDragOver(false);
                                    const files = e.dataTransfer.files;
                                    if (files.length > 0) {
                                        const file = files[0];
                                        setFieldValue('file', file);
                                        handleFileAnalysis(file);
                                    }
                                }}
                            >
                                <FontAwesomeIcon icon={faFile} css={tw`text-4xl text-neutral-500 mb-4`} />
                                <p css={tw`text-neutral-400 mb-2`}>
                                    Drop your SQL file here, or{' '}
                                    <label css={tw`text-blue-400 hover:text-blue-300 cursor-pointer underline`}>
                                        browse
                                        <FileInput
                                            type="file"
                                            accept=".sql,.txt,.gz,.bz2,.zip,.tar,.7z,.rar"
                                            onChange={(e) => {
                                                const file = e.target.files?.[0];
                                                if (file) {
                                                    setFieldValue('file', file);
                                                    handleFileAnalysis(file);
                                                }
                                            }}
                                        />
                                    </label>
                                </p>
                                <p css={tw`text-xs text-neutral-500`}>
                                    Supports: .sql, .gz, .bz2, .zip, .tar, .7z, .rar (max 100MB)
                                </p>
                            </FileDropZone>
                        </div>

                        {/* File Analysis Results */}
                        {analyzing && (
                            <div css={tw`text-center py-8`}>
                                <FontAwesomeIcon icon={faSpinner} spin css={tw`text-2xl text-blue-400 mb-2`} />
                                <p css={tw`text-neutral-400`}>Analyzing file...</p>
                            </div>
                        )}

                        {analysis && analysis.success && (
                            <div css={tw`bg-neutral-800 rounded-lg p-4 border border-neutral-600`}>
                                <div css={tw`flex items-center mb-4`}>
                                    <FontAwesomeIcon icon={faInfoCircle} css={tw`text-blue-400 mr-2`} />
                                    <h3 css={tw`text-lg font-semibold text-neutral-100`}>File Analysis</h3>
                                </div>
                                
                                <div css={tw`grid grid-cols-2 md:grid-cols-4 gap-4 mb-4`}>
                                    <div css={tw`text-center`}>
                                        <div css={tw`text-2xl font-bold text-blue-400`}>{analysis.tables.length}</div>
                                        <div css={tw`text-xs text-neutral-400`}>Tables</div>
                                    </div>
                                    <div css={tw`text-center`}>
                                        <div css={tw`text-2xl font-bold text-green-400`}>{analysis.estimated_rows}</div>
                                        <div css={tw`text-xs text-neutral-400`}>Est. Rows</div>
                                    </div>
                                    <div css={tw`text-center`}>
                                        <div css={tw`text-2xl font-bold text-purple-400`}>
                                            {(analysis.file_size / 1024 / 1024).toFixed(1)}MB
                                        </div>
                                        <div css={tw`text-xs text-neutral-400`}>File Size</div>
                                    </div>
                                    <div css={tw`text-center`}>
                                        <div css={tw`text-2xl font-bold text-orange-400`}>
                                            {analysis.tables.filter(t => t.has_data).length}
                                        </div>
                                        <div css={tw`text-xs text-neutral-400`}>With Data</div>
                                    </div>
                                </div>

                                {/* Selective Import Toggle */}
                                <div css={tw`flex items-center mb-4`}>
                                    <input
                                        type="checkbox"
                                        id="selective"
                                        checked={values.selective}
                                        onChange={(e) => setFieldValue('selective', e.target.checked)}
                                        css={tw`mr-2 h-4 w-4 text-blue-600 border-neutral-600 rounded focus:ring-blue-500`}
                                    />
                                    <label htmlFor="selective" css={tw`text-sm font-medium text-neutral-300`}>
                                        Enable selective import (choose specific tables and columns)
                                    </label>
                                </div>

                                {/* Table Selection */}
                                {values.selective && (
                                    <div css={tw`space-y-3`}>
                                        <h4 css={tw`text-md font-semibold text-neutral-200 mb-3`}>
                                            Select Tables to Import
                                        </h4>
                                        
                                        <div css={tw`max-h-96 overflow-y-auto space-y-2`}>
                                            {analysis.tables.map((table) => (
                                                <TableCard
                                                    key={table.name}
                                                    selected={values.selectedTables.includes(table.name)}
                                                >
                                                    <div
                                                        css={tw`flex items-center justify-between cursor-pointer`}
                                                        onClick={() => toggleTable(table.name, values.selectedTables, setFieldValue)}
                                                    >
                                                        <div css={tw`flex items-center`}>
                                                            <input
                                                                type="checkbox"
                                                                checked={values.selectedTables.includes(table.name)}
                                                                onChange={() => {}}
                                                                css={tw`mr-3 h-4 w-4 text-blue-600 border-neutral-600 rounded focus:ring-blue-500`}
                                                            />
                                                            <FontAwesomeIcon icon={faTable} css={tw`text-blue-400 mr-2`} />
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
                                                                    <ColumnTag
                                                                        key={column.name}
                                                                        selected={(values.selectedColumns[table.name] || []).includes(column.name)}
                                                                        onClick={() => toggleColumn(table.name, column.name, values.selectedColumns, setFieldValue)}
                                                                    >
                                                                        {column.name}
                                                                        <span css={tw`ml-1 text-xs opacity-75`}>
                                                                            ({column.type})
                                                                        </span>
                                                                    </ColumnTag>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    )}
                                                </TableCard>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Import Preview */}
                        {preview && preview.success && (
                            <div css={tw`bg-green-900 bg-opacity-20 border border-green-600 rounded-lg p-4`}>
                                <div css={tw`flex items-center mb-2`}>
                                    <FontAwesomeIcon icon={faEye} css={tw`text-green-400 mr-2`} />
                                    <h4 css={tw`text-md font-semibold text-green-400`}>Import Preview</h4>
                                </div>
                                <p css={tw`text-sm text-neutral-300`}>
                                    Ready to import {preview.total_tables} tables with approximately {preview.total_estimated_rows} rows
                                </p>
                            </div>
                        )}

                        {/* Import Mode */}
                        <div>
                            <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                Import Mode
                            </label>
                            <div css={tw`space-y-2`}>
                                <label css={tw`flex items-center`}>
                                    <input
                                        type="radio"
                                        name="mode"
                                        value="merge"
                                        checked={values.mode === 'merge'}
                                        onChange={() => setFieldValue('mode', 'merge' as 'merge')}
                                        css={tw`mr-2 h-4 w-4 text-blue-600 border-neutral-600 focus:ring-blue-500`}
                                    />
                                    <span css={tw`text-sm text-neutral-300`}>
                                        Merge (add to existing data)
                                    </span>
                                </label>
                                <label css={tw`flex items-center`}>
                                    <input
                                        type="radio"
                                        name="mode"
                                        value="wipe"
                                        checked={values.mode === 'wipe'}
                                        onChange={() => setFieldValue('mode', 'wipe' as 'wipe')}
                                        css={tw`mr-2 h-4 w-4 text-blue-600 border-neutral-600 focus:ring-blue-500`}
                                    />
                                    <span css={tw`text-sm text-neutral-300`}>
                                        Wipe (replace existing data)
                                    </span>
                                </label>
                            </div>
                        </div>

                        {/* Submit Buttons */}
                        <div css={tw`flex justify-end space-x-3 pt-6 border-t border-neutral-600`}>
                            <button
                                type="button"
                                onClick={onDismissed}
                                css={tw`px-6 py-2 text-sm font-medium text-neutral-300 bg-neutral-700 rounded-lg hover:bg-neutral-600 transition-colors`}
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={isSubmitting || !values.file || (values.selective && values.selectedTables.length === 0)}
                                css={tw`px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center`}
                            >
                                {isSubmitting ? (
                                    <>
                                        <FontAwesomeIcon icon={faSpinner} spin css={tw`mr-2`} />
                                        Importing...
                                    </>
                                ) : (
                                    <>
                                        <FontAwesomeIcon icon={faUpload} css={tw`mr-2`} />
                                        Import Database
                                    </>
                                )}
                            </button>
                        </div>
                    </Form>
                )}
            </Formik>
        </GradientModal>
    );
};

export default SelectiveImportModal;
