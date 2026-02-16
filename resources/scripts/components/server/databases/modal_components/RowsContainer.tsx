import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTrash, faCheck, faTimes, faPlus, faSync } from '@fortawesome/free-solid-svg-icons';
import Spinner from '@/components/elements/Spinner';
import { sanitizeColumnValue, sanitizeDatabaseName } from '@/utils/xssProtection';

interface TableColumn {
    column_name: string;
    data_type: string;
    is_nullable: string;
    column_key: string;
    extra: string;
}

interface TableRowData {
    [key: string]: any;
}

interface TableDataResponse {
    data: TableRowData[];
    total: number;
    has_primary_key: boolean;
}

interface EditingCell {
    row: number;
    column: string;
    value: string;
}

interface RowsContainerProps {
    selectedTable: {
        name: string;
        columns: TableColumn[];
    };
    tableData: TableDataResponse | null;
    loadingData: boolean;
    currentPage: number;
    editingCell: EditingCell | null;
    editValue: string;
    showAddRowForm: boolean;
    newRowData: Record<string, string>;
    databaseId: string;
    onLoadTableData: (tableName: string, page: number) => void;
    onPageChange: (page: number) => void;
    onCellEdit: (row: number, column: string, value: any) => void;
    onCellSave: (row: number, column: string) => void;
    onCellCancel: () => void;
    onDeleteRow: (rowIndex: number) => void;
    onAddRow: () => void;
    onSaveNewRow: () => void;
    onSetShowAddRowForm: (show: boolean) => void;
    onSetNewRowData: (data: Record<string, string>) => void;
    onSetEditValue: (value: string) => void;
    onColumnsChanged?: () => void;
}

const RowsContainer: React.FC<RowsContainerProps> = ({
    selectedTable,
    tableData,
    loadingData,
    currentPage,
    editingCell,
    editValue,
    showAddRowForm,
    newRowData,
    onLoadTableData,
    onPageChange,
    onCellEdit,
    onCellSave,
    onCellCancel,
    onDeleteRow,
    onAddRow,
    onSaveNewRow,
    onSetShowAddRowForm,
    onSetNewRowData,
    onSetEditValue,
}) => {
    const [localEditValue, setLocalEditValue] = useState('');
    useEffect(() => {
        setLocalEditValue(editValue);
    }, [editValue]);

    const handleCellClick = (rowIndex: number, columnName: string, value: any) => {
        if (!tableData?.has_primary_key) return;
        
        const displayValue = value !== null && value !== undefined ? String(value) : '';
        setLocalEditValue(displayValue);
        onSetEditValue(displayValue);
        onCellEdit(rowIndex, columnName, value);
    };

    const handleInputChange = (value: string) => {
        setLocalEditValue(value);
        onSetEditValue(value);
    };

    const handleSave = (rowIndex: number, columnName: string) => {
        onCellSave(rowIndex, columnName);
    };

    const handleCancel = () => {
        setLocalEditValue('');
        onCellCancel();
    };

    const handleKeyDown = (e: React.KeyboardEvent, rowIndex: number, columnName: string) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleSave(rowIndex, columnName);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            handleCancel();
        }
    };

    const renderCellContent = (row: TableRowData, rowIndex: number, column: TableColumn) => {
        const isEditing = editingCell?.row === rowIndex && editingCell?.column === column.column_name;
        const value = row[column.column_name];

        if (isEditing) {
            return (
                <div className="flex items-center gap-2">
                    <input
                        key={`${rowIndex}-${column.column_name}`}
                        type="text"
                        defaultValue={localEditValue}
                        onChange={(e) => handleInputChange(e.target.value)}
                        onKeyDown={(e) => handleKeyDown(e, rowIndex, column.column_name)}
                        className="flex-1 bg-gray-600 border border-gray-500 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        autoFocus
                        placeholder="Enter value..."
                        onFocus={(e) => e.target.select()}
                    />
                    <button
                        onClick={() => handleSave(rowIndex, column.column_name)}
                        className="px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-xs rounded transition-colors duration-200 whitespace-nowrap"
                        title="Save (or press Enter)"
                    >
                        Save
                    </button>
                </div>
            );
        }

        return (
            <div
                className="cursor-pointer hover:bg-gray-700 hover:bg-opacity-50 rounded px-2 py-1 transition-colors duration-200"
                onClick={() => handleCellClick(rowIndex, column.column_name, value)}
                title={tableData?.has_primary_key ? "Click to edit (Enter to save, Escape to cancel)" : "Cannot edit - no primary key"}
                style={{ minHeight: '1.5rem' }}
            >
                {value === null ? (
                    <span className="text-gray-500 italic text-xs">NULL</span>
                ) : (
                    <span className="text-gray-100" dangerouslySetInnerHTML={{ __html: sanitizeColumnValue(value) }} />
                )}
            </div>
        );
    };

    if (loadingData) {
        return (
            <div className="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                <div className="flex items-center justify-center py-12">
                    <Spinner size="large" />
                    <span className="ml-3 text-gray-400">Loading table data...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
            <div className="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4 border-b border-gray-600">
                <div className="flex items-center justify-between gap-4 flex-col sm:flex-row">
                    <div>
                        <h3 className="text-xl font-semibold text-white mb-2">Table Data</h3>
                        {tableData && (
                            <div className="text-sm text-gray-400">
                                {tableData.total} total rows • Page {currentPage}
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => onLoadTableData(selectedTable.name, currentPage)}
                            disabled={loadingData}
                            className="group relative px-6 py-3 bg-gradient-to-r from-gray-600 via-gray-700 to-gray-600 hover:from-gray-500 hover:via-gray-600 hover:to-gray-500 text-white rounded-xl font-medium transition-all duration-300 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 border border-gray-500 hover:border-gray-400"
                            style={{
                                background: loadingData ? 'linear-gradient(45deg, #4b5563, #6b7280, #4b5563)' : undefined,
                                boxShadow: '0 4px 15px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                            }}
                        >
                            <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-10 transition-opacity duration-300 rounded-xl"></div>
                            <FontAwesomeIcon icon={faSync} className={`transition-transform duration-300 ${loadingData ? 'animate-spin' : 'group-hover:rotate-180'}`} />
                            <span className="relative z-10">Refresh</span>
                        </button>
                        <button
                            onClick={onAddRow}
                            disabled={!tableData?.has_primary_key}
                            className="group relative px-6 py-3 bg-gradient-to-r from-green-600 via-green-700 to-green-600 hover:from-green-500 hover:via-green-600 hover:to-green-500 text-white rounded-xl font-medium transition-all duration-300 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 border border-green-500 hover:border-green-400"
                            title={tableData?.has_primary_key ? "Add new row" : "Cannot add rows - no primary key"}
                            style={{
                                boxShadow: '0 4px 15px rgba(34, 197, 94, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1)'
                            }}
                        >
                            <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-10 transition-opacity duration-300 rounded-xl"></div>
                            <FontAwesomeIcon icon={faPlus} className="transition-transform duration-300 group-hover:rotate-90" />
                            <span className="relative z-10">Add Row</span>
                        </button>
                    </div>
                </div>
            </div>
            {showAddRowForm && (
                <div className="bg-gray-800 border-t border-gray-700 p-6">
                    <h4 className="text-lg font-medium text-gray-200 mb-4">Add New Row</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                        {selectedTable.columns.map((column) => (
                            <div key={column.column_name} className="flex flex-col">
                                <label className="text-sm font-medium text-gray-300 mb-1">
                                    {sanitizeDatabaseName(column.column_name)}
                                    {column.is_nullable === 'NO' && <span className="text-red-400 ml-1">*</span>}
                                    {column.column_key === 'PRI' && (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900 text-yellow-200 border border-yellow-700 ml-2">
                                            PK
                                        </span>
                                    )}
                                </label>
                                <input
                                    type="text"
                                    placeholder={column.data_type}
                                    value={newRowData[column.column_name] || ''}
                                    onChange={(e) => onSetNewRowData({
                                        ...newRowData,
                                        [column.column_name]: e.target.value
                                    })}
                                    disabled={column.extra === 'auto_increment'}
                                    className="bg-gray-600 border border-gray-500 rounded px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:bg-gray-700"
                                />
                            </div>
                        ))}
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={onSaveNewRow}
                            className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-all duration-200 flex items-center gap-2"
                        >
                            <FontAwesomeIcon icon={faCheck} />
                            Save Row
                        </button>
                        <button
                            onClick={() => {
                                onSetShowAddRowForm(false);
                                onSetNewRowData({});
                            }}
                            className="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition-all duration-200 flex items-center gap-2"
                        >
                            <FontAwesomeIcon icon={faTimes} />
                            Cancel
                        </button>
                    </div>
                </div>
            )}
            {tableData && tableData.data.length > 0 ? (
                <>
                    <div className="overflow-x-auto" style={{ maxHeight: '500px' }}>
                        <table className="w-full text-sm" style={{ minWidth: '600px' }}>
                            <thead className="bg-gray-900 sticky top-0 z-10">
                                <tr className="border-b border-gray-700">
                                    {selectedTable.columns.map((column) => (
                                        <th key={column.column_name} className="text-left py-4 px-4 text-gray-300 font-semibold border-b border-gray-700" style={{ whiteSpace: 'nowrap' }}>
                                            <div className="flex items-center">
                                                {column.column_name}
                                                {column.column_key === 'PRI' && (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900 text-yellow-200 border border-yellow-700 ml-2">
                                                        PK
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-gray-500 mt-1">
                                                {column.data_type?.toUpperCase() || 'UNKNOWN'}
                                            </div>
                                        </th>
                                    ))}
                                    <th className="text-left py-4 px-4 text-gray-300 font-semibold border-b border-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tableData.data.map((row, rowIndex) => (
                                    <tr key={rowIndex} className="border-b border-gray-700 hover:bg-gray-800 transition-colors duration-200 last:border-b-0">
                                        {selectedTable.columns.map((column) => (
                                            <td
                                                key={column.column_name}
                                                className={`py-3 px-4 text-gray-100 ${
                                                    editingCell?.row === rowIndex && editingCell?.column === column.column_name 
                                                        ? 'bg-gray-700' 
                                                        : ''
                                                }`}
                                            >
                                                {renderCellContent(row, rowIndex, column)}
                                            </td>
                                        ))}
                                        <td className="py-3 px-4 text-center" style={{ width: '80px' }}>
                                            <button
                                                onClick={() => onDeleteRow(rowIndex)}
                                                disabled={!tableData.has_primary_key}
                                                className="p-2 text-red-400 hover:text-red-300 hover:bg-red-900 hover:bg-opacity-20 rounded transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                                title={tableData.has_primary_key ? "Delete row" : "Cannot delete - no primary key"}
                                            >
                                                <FontAwesomeIcon icon={faTrash} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="bg-gray-800 px-6 py-4 border-t border-gray-700 flex items-center justify-between flex-col sm:flex-row gap-3">
                        <div className="text-sm text-gray-400">
                            Showing {((currentPage - 1) * 6) + 1} to {Math.min(currentPage * 6, tableData.total)} of {tableData.total} rows
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => onPageChange(currentPage - 1)}
                                disabled={currentPage <= 1 || loadingData}
                                className="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-gray-600"
                            >
                                Previous
                            </button>
                            <span className="px-4 py-2 text-gray-300">
                                Page {currentPage} of {Math.ceil(tableData.total / 6)}
                            </span>
                            <button
                                onClick={() => onPageChange(currentPage + 1)}
                                disabled={currentPage * 6 >= tableData.total || loadingData}
                                className="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-gray-600"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </>
            ) : (
                <div className="text-center py-12 text-gray-400">
                    {tableData ? 'No data found in this table.' : 'Select a table to view its data.'}
                </div>
            )}
        </div>
    );
};

export default RowsContainer;