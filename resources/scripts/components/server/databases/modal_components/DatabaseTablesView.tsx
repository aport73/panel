import React, { useState, useEffect, useRef } from 'react';
import styled from 'styled-components';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTable, faSearch, faPlus, faEdit, faTrash, faSyncAlt, faEye, faColumns, faList, faDownload, faBars, faTimes, faExclamationTriangle, faChevronLeft, faChevronRight, faSave, faBan } from '@fortawesome/free-solid-svg-icons';
import Spinner from '@/components/elements/Spinner';
import CreateTableModal from './CreateTableModal';
import DeleteTableModal from './DeleteTableModal';
import CreateRowModal from './CreateRowModal';
import DeleteRowModal from './DeleteRowModal';
import DeleteColumnModal from './DeleteColumnModal';
import AddColumnModal from './AddColumnModal';
import ForeignKeyConstraintModal from './ForeignKeyConstraintModal';
import RowsContainer from './RowsContainer';
import GradientModal from './GradientModal';
import { ServerContext } from '@/state/server';
import { getTableData, updateTableRow, insertTableRow, deleteTableRow, TableColumn } from '@/api/server/databases/tableDataOperations';
import { DatabaseContents, DatabaseTable, DatabaseColumn } from '@/api/server/databases/getDatabaseContents';
import { sanitizeDatabaseName, escapeHtml } from '@/utils/xssProtection';

interface TableDataResponse {
    data: Record<string, any>[];
    total: number;
    has_primary_key: boolean;
}

interface DatabaseTablesViewProps {
    databaseId: string;
    databaseContents: DatabaseContents;
    loading?: boolean;
    onRefresh?: () => void;
}

const Container = styled.div`
    ${tw`flex bg-gray-900 rounded-lg overflow-hidden relative`};
    min-height: 600px;
    max-height: 90vh;
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`shadow-2xl`};
    }
`;

const Sidebar = styled.div<{ isCollapsed?: boolean }>`
    ${tw`bg-gradient-to-b from-gray-800 to-gray-900 border-r border-gray-700 flex flex-col transition-all duration-300 ease-in-out`};
    width: ${props => props.isCollapsed ? '0' : '320px'};
    min-width: ${props => props.isCollapsed ? '0' : '320px'};
    overflow: hidden;
    
    &:hover {
        ${tw`border-gray-600`};
    }
    
    @media (max-width: 768px) {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 50;
        width: ${props => props.isCollapsed ? '0' : '280px'};
        min-width: ${props => props.isCollapsed ? '0' : '280px'};
        box-shadow: ${props => props.isCollapsed ? 'none' : '4px 0 20px rgba(0, 0, 0, 0.3)'};
    }
    
    @media (max-width: 640px) {
        width: ${props => props.isCollapsed ? '0' : '100%'};
        min-width: ${props => props.isCollapsed ? '0' : '100%'};
    }
`;

const SidebarHeader = styled.div`
    ${tw`p-4 border-b border-gray-700 bg-gradient-to-r from-gray-700 to-gray-800`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`bg-gradient-to-r from-gray-600 to-gray-700 border-gray-600`};
        transform: translateY(-1px);
    }
`;

const SidebarTitle = styled.div`
    ${tw`text-lg font-semibold text-gray-100 flex items-center gap-2`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`text-white`};
        text-shadow: 0 0 8px rgba(59, 130, 246, 0.5);
    }
`;

const SearchContainer = styled.div`
    ${tw`p-3 border-b border-gray-700`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`border-gray-600`};
    }
`;

const SearchInput = styled.input`
    ${tw`w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-gray-100 placeholder-gray-400 focus:border-blue-500 focus:outline-none transition-all duration-300`};
    
    &:hover {
        ${tw`bg-gray-700 border-gray-500 shadow-md`};
        transform: translateY(-1px);
    }
    
    &:focus {
        ${tw`bg-gray-700 shadow-lg`};
        transform: translateY(-2px);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
`;

const ButtonContainer = styled.div`
    ${tw`p-4 border-b border-gray-700 flex flex-col gap-3`};
    transition: all 0.3s ease;
    
    @media (max-width: 640px) {
        ${tw`p-3 gap-2`};
    }
    
    @media (max-width: 480px) {
        ${tw`p-2 gap-1`};
    }
    
    &:hover {
        ${tw`border-gray-600`};
    }
`;

const ButtonRow = styled.div`
    ${tw`grid gap-3 mb-3`};
    grid-template-columns: 1fr 1fr;
    
    @media (max-width: 640px) {
        grid-template-columns: 1fr;
        gap: 2px;
    }
    
    @media (max-width: 480px) {
        gap: 1px;
    }
`;

const TablesList = styled.div<{ hasScrolling?: boolean }>`
    ${tw`flex-1 p-2 relative`};
    ${props => props.hasScrolling ? tw`overflow-y-auto` : tw`overflow-y-visible`};
    
    /* Enhanced scrollbar with hover effects */
    &::-webkit-scrollbar {
        width: 8px;
        transition: all 0.3s ease;
    }
    
    &::-webkit-scrollbar-track {
        ${tw`bg-gray-800 rounded`};
        transition: all 0.3s ease;
    }
    
    &::-webkit-scrollbar-thumb {
        ${tw`bg-gray-600 rounded`};
        transition: all 0.3s ease;
    }
    
    &:hover::-webkit-scrollbar-thumb {
        ${tw`bg-gray-500`};
    }
    
    /* Set max height when scrolling is enabled */
    ${props => props.hasScrolling && `
        max-height: 1050px;
    `}
    
`;

const TableItem = styled.div<{ isSelected?: boolean }>`
    ${tw`p-3 mb-2 rounded-lg cursor-pointer transition-all duration-300 border`};
    ${props => props.isSelected
        ? tw`bg-gradient-to-r from-blue-600 to-blue-700 border-blue-500`
        : tw`bg-gradient-to-r from-gray-700 to-gray-800 border-gray-600`
    };
    
    &:hover {
        ${props => props.isSelected
            ? tw`bg-gradient-to-r from-blue-500 to-blue-600 border-blue-400`
            : tw`bg-gradient-to-r from-gray-600 to-gray-700 border-gray-500`
        };
        transform: translateX(4px) translateY(-2px);
    }
    
    &:active {
        transform: translateX(2px) translateY(-1px);
        transition: all 0.1s ease;
    }
`;

const TableName = styled.div`
    ${tw`font-medium text-gray-100 mb-1 flex items-center gap-2`};
    transition: all 0.3s ease;
    
    ${TableItem}:hover & {
        ${tw`text-white`};
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
`;

const TableStats = styled.div`
    ${tw`flex justify-between text-xs text-gray-300`};
    transition: all 0.3s ease;
    
    ${TableItem}:hover & {
        ${tw`text-gray-100`};
    }
`;

const StatItem = styled.div`
    ${tw`flex items-center gap-1`};
    transition: all 0.3s ease;
    
    &:hover {
        transform: scale(1.05);
    }
`;

const MainContent = styled.div<{ sidebarCollapsed?: boolean }>`
    ${tw`flex-1 bg-gray-900 flex flex-col transition-all duration-300 ease-in-out`};
    min-width: 0; /* Prevents flex item from overflowing */
    
    @media (max-width: 768px) {
        width: 100%;
        ${props => !props.sidebarCollapsed && tw`filter blur-sm pointer-events-none`};
    }
`;

const SidebarOverlay = styled.div<{ isVisible?: boolean }>`
    ${tw`fixed inset-0 bg-black bg-opacity-50 z-40 transition-opacity duration-300`};
    opacity: ${props => props.isVisible ? '1' : '0'};
    pointer-events: ${props => props.isVisible ? 'auto' : 'none'};
    
    @media (min-width: 769px) {
        display: none;
    }
`;

const ToggleButton = styled.button`
    ${tw`p-2 bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white rounded-lg transition-all duration-200 flex items-center justify-center`};
    
    &:hover {
        transform: scale(1.05);
    }
    
    &:active {
        transform: scale(0.95);
    }
`;

const ContentHeader = styled.div`
    ${tw`p-4 border-b border-gray-700 bg-gradient-to-r from-gray-800 to-gray-900 flex items-center justify-between`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`bg-gradient-to-r from-gray-700 to-gray-800 border-gray-600`};
        transform: translateY(-1px);
    }
`;

const ContentTitle = styled.h2`
    ${tw`text-xl font-semibold text-gray-100`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`text-white`};
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
`;

const ContentArea = styled.div`
    ${tw`flex-1 p-6`};
    overflow-y: auto;
    overflow-x: hidden;
    max-height: calc(90vh - 120px);
    
    @media (max-width: 640px) {
        ${tw`p-4`};
    }
    
    @media (max-width: 480px) {
        ${tw`p-3`};
    }
`;

const StatsCard = styled.div`
    ${tw`bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-lg border border-gray-700 transition-all duration-300`};
    min-height: 120p
    
    @media (max-width: 640px) {
        ${tw`p-4`};translateY(-4px) scale(1.02);
    }
        min-height: 100px;
    }
    
    @media (max-width: 480px) {
        ${tw`p-3`};
        min-height: 90px;
    }
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-700 to-gray-800 border-gray-600 shadow-xl`};
        transform: translateY(-4px) scale(1.02);
    }
    
    &:active {ale(1.01);
        transition: all 0.1s ease;
    }
`;

const StatsHeader = styled.div`
    ${tw`flex items-center gap-2 mb-2`};
    transition: all 0.3s ease;
    
    ${StatsCard}:hover & {
        transform: translateX(2px);
    }
`;

const StatsValue = styled.div`
    ${tw`text-3xl font-bold text-white`};
    transition: all 0.3s ease;
    
    @media (max-width: 640px) {
        ${tw`text-2xl`};
    }
    
    @media (max-width: 480px) {
        ${tw`text-xl`};
    }
    
    ${StatsCard}:hover & {
        ${tw`text-4xl`};
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        
        @media (max-width: 640px) {
            ${tw`text-3xl`};
        }
        
        @media (max-width: 480px) {
            ${tw`text-2xl`};
        }
    }
`;

const TableStructureCard = styled.div`
    ${tw`bg-gradient-to-br from-gray-800 to-gray-900 p-6 rounded-lg border border-gray-700 transition-all duration-300 mb-6`};
    min-height: fit-content;
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-700 to-gray-800 border-gray-600 shadow-xl`};
        transform: translateY(-2px);
    }
`;

const PlaceholderContent = styled.div`
    ${tw`flex flex-col items-center justify-center h-full text-center transition-all duration-300`};
    
    &:hover {
        transform: scale(1.02);
    }
`;

const PlaceholderIcon = styled.div`
    ${tw`w-16 h-16 rounded-full bg-gradient-to-br from-gray-700 to-gray-800 flex items-center justify-center mb-4 transition-all duration-300`};
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-600 to-gray-700 shadow-lg`};
        transform: scale(1.1) rotate(5deg);
    }
`;

const PlaceholderText = styled.p`
    ${tw`text-gray-400 text-lg mb-2 transition-all duration-300`};
    
    ${PlaceholderContent}:hover & {
        ${tw`text-gray-300`};
    }
`;

const PlaceholderSubtext = styled.p`
    ${tw`text-gray-500 text-sm transition-all duration-300`};
    
    ${PlaceholderContent}:hover & {
        ${tw`text-gray-400`};
    }
`;

const ModalContainer = styled.div`
    ${tw`p-6 bg-gradient-to-br from-gray-800 to-gray-900`};
`;

const ModalHeader = styled.div`
    ${tw`flex items-center mb-6 pb-4 border-b border-gray-600`};
`;

const ModalIconContainer = styled.div`
    ${tw`bg-blue-600 p-3 rounded-full mr-4 shadow-lg`};
`;

const ModalContent = styled.div`
    ${tw`overflow-y-auto`};
    max-height: calc(90vh - 200px);
`;

const RowCard = styled.div`
    ${tw`bg-gradient-to-br from-gray-700 to-gray-800 rounded-lg p-4 mb-4 border border-gray-600 transition-all duration-300`};
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-600 to-gray-700 border-gray-500 shadow-lg`};
        transform: translateY(-2px);
    }
`;

const RowHeader = styled.div`
    ${tw`flex items-center justify-between mb-4 pb-3 border-b border-gray-600`};
`;

const RowNumber = styled.div`
    ${tw`flex items-center gap-3`};
`;

const RowBadge = styled.div`
    ${tw`w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center shadow-lg`};
`;

const RowActions = styled.div`
    ${tw`flex items-center gap-2`};
`;

const FieldGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 gap-4`};
`;

const FieldContainer = styled.div`
    ${tw`space-y-2`};
`;

const FieldLabel = styled.div`
    ${tw`flex items-center gap-2`};
`;

const FieldValue = styled.div`
    ${tw`relative`};
`;

const EditableField = styled.div`
    ${tw`p-3 bg-gray-800 rounded-lg border border-gray-700 hover:border-blue-500 transition-all duration-300 cursor-pointer`};
`;

const NullField = styled.div`
    ${tw`flex items-center justify-center p-3 bg-gray-800 rounded-lg border border-gray-700`};
`;

const EditInput = styled.input`
    ${tw`w-full p-3 bg-gray-700 border border-blue-500 rounded-lg text-white font-mono text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/25 transition-all duration-300`};
`;

const ActionButton = styled.button`
    ${tw`flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-all duration-300 transform hover:scale-105 shadow-lg`};
`;

const SaveButton = styled(ActionButton)`
    ${tw`bg-green-600 hover:bg-green-700 text-white hover:shadow-lg`};
`;

const CancelButton = styled(ActionButton)`
    ${tw`bg-gray-600 hover:bg-gray-700 text-white`};
`;

const DeleteButton = styled.button`
    ${tw`p-2 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-all duration-300 transform hover:scale-110 disabled:opacity-50`};
`;

const EditButton = styled.button`
    ${tw`absolute -top-2 -right-2 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 transform hover:scale-110 shadow-lg`};
`;

const PaginationFooter = styled.div`
    ${tw`flex items-center justify-between pt-4 border-t border-gray-600 mt-6`};
`;

const PaginationButton = styled.button`
    ${tw`flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed transform hover:scale-105 shadow-lg`};
`;

const PaginationInfo = styled.div`
    ${tw`flex flex-col items-center gap-1`};
`;

const EmptyState = styled.div`
    ${tw`text-center py-16`};
`;

const EmptyIcon = styled.div`
    ${tw`inline-flex items-center justify-center w-16 h-16 bg-gray-700 rounded-full mb-4 shadow-lg`};
`;

interface ViewRowsModalProps {
    visible: boolean;
    onDismissed: () => void;
    selectedTable: DatabaseTable | null;
    tableData: TableDataResponse | null;
    currentPage: number;
    modalEditingCell: { rowIndex: number, columnName: string } | null;
    modalEditValue: string;
    isDesktopViewport: boolean;
    loadingData: boolean;
    onModalCellEdit: (rowIndex: number, columnName: string, value: any) => void;
    onModalCellSave: (rowIndex: number, columnName: string) => void;
    onModalCellCancel: () => void;
    onSetModalEditValue: (value: string) => void;
    onDeleteRow: (rowIndex: number) => void;
    onPageChange: (page: number) => void;
}

const ViewRowsModal: React.FC<ViewRowsModalProps> = ({
    visible,
    onDismissed,
    selectedTable,
    tableData,
    currentPage,
    modalEditingCell,
    modalEditValue,
    isDesktopViewport,
    loadingData,
    onModalCellEdit,
    onModalCellSave,
    onModalCellCancel,
    onSetModalEditValue,
    onDeleteRow,
    onPageChange,
}) => {
    if (!selectedTable || !tableData) return null;

    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size={isDesktopViewport ? "xxl" : "lg"}
        >
            <ModalContainer>
                <ModalHeader>
                    <ModalIconContainer>
                        <FontAwesomeIcon icon={faEye} css={tw`text-white text-lg`} />
                    </ModalIconContainer>
                    <div>
                        <h2 css={tw`text-2xl font-semibold text-white`}>Table Rows</h2>
                        <p css={tw`text-sm text-gray-400`}>
                            Viewing {selectedTable.name} - Page {currentPage} of {Math.ceil(tableData.total / 6)} ({tableData.total} total rows)
                        </p>
                    </div>
                </ModalHeader>

                <ModalContent>
                    {tableData.data.length > 0 ? (
                        <div css={tw`space-y-4`}>
                            {tableData.data.map((row, rowIndex) => (
                                <RowCard key={rowIndex} className="group">
                                    <RowHeader>
                                        <RowNumber>
                                            <RowBadge>
                                                <span css={tw`text-white font-bold text-sm`}>
                                                    {((currentPage - 1) * 6) + rowIndex + 1}
                                                </span>
                                            </RowBadge>
                                            <div>
                                                <span css={tw`text-white font-semibold`}>
                                                    Row {((currentPage - 1) * 6) + rowIndex + 1}
                                                </span>
                                                <p css={tw`text-xs text-gray-400`}>
                                                    {selectedTable.columns.length} columns
                                                </p>
                                            </div>
                                        </RowNumber>
                                        <RowActions>
                                            {modalEditingCell?.rowIndex === rowIndex && (
                                                <div css={tw`flex items-center gap-2 px-3 py-1 bg-blue-600/20 border border-blue-500/30 rounded-full`}>
                                                    <span css={tw`w-2 h-2 bg-blue-400 rounded-full animate-pulse`}></span>
                                                    <span css={tw`text-xs text-blue-200 font-medium`}>Editing</span>
                                                </div>
                                            )}
                                            <DeleteButton
                                                onClick={() => onDeleteRow(rowIndex)}
                                                disabled={!tableData.has_primary_key}
                                                title="Delete row"
                                            >
                                                <FontAwesomeIcon icon={faTrash} css={tw`text-sm`} />
                                            </DeleteButton>
                                        </RowActions>
                                    </RowHeader>

                                    <FieldGrid>
                                        {selectedTable.columns.map((column) => (
                                            <FieldContainer key={column.column_name} className="group/field">
                                                <FieldLabel>
                                                    <span css={tw`font-medium text-gray-300 text-sm`}>
                                                        {column.column_name}
                                                    </span>
                                                    {column.column_key === 'PRI' && (
                                                        <span css={tw`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-600 text-white border border-yellow-500/30 shadow-lg`}>
                                                            🔑 PK
                                                        </span>
                                                    )}
                                                    <span css={tw`text-xs text-gray-500 bg-gray-800 px-2 py-0.5 rounded border border-gray-700`}>
                                                        {column.data_type}
                                                    </span>
                                                </FieldLabel>

                                                <FieldValue>
                                                    {modalEditingCell?.rowIndex === rowIndex && modalEditingCell?.columnName === column.column_name ? (
                                                        <div css={tw`space-y-3`}>
                                                            <EditInput
                                                                type="text"
                                                                value={modalEditValue}
                                                                onChange={(e) => onSetModalEditValue(e.target.value)}
                                                                placeholder="Enter value..."
                                                                autoFocus
                                                                onKeyPress={(e) => {
                                                                    if (e.key === 'Enter') {
                                                                        onModalCellSave(rowIndex, column.column_name);
                                                                    } else if (e.key === 'Escape') {
                                                                        onModalCellCancel();
                                                                    }
                                                                }}
                                                            />
                                                            <div css={tw`flex items-center gap-2`}>
                                                                <SaveButton
                                                                    onClick={() => onModalCellSave(rowIndex, column.column_name)}
                                                                >
                                                                    <FontAwesomeIcon icon={faSave} css={tw`text-xs`} />
                                                                    Save
                                                                </SaveButton>
                                                                <CancelButton onClick={onModalCellCancel}>
                                                                    <FontAwesomeIcon icon={faBan} css={tw`text-xs`} />
                                                                    Cancel
                                                                </CancelButton>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="group/value relative">
                                                            {row[column.column_name] === null ? (
                                                                <NullField>
                                                                    <span css={tw`text-gray-500 italic text-sm flex items-center gap-2`}>
                                                                        <span css={tw`w-2 h-2 bg-gray-600 rounded-full`}></span>
                                                                        NULL
                                                                    </span>
                                                                </NullField>
                                                            ) : (
                                                                <EditableField
                                                                    onClick={() => onModalCellEdit(rowIndex, column.column_name, row[column.column_name])}
                                                                    title="Click to edit"
                                                                >
                                                                    <span css={tw`text-gray-100 font-mono text-sm break-all`}>
                                                                        {String(row[column.column_name])}
                                                                    </span>
                                                                </EditableField>
                                                            )}
                                                            <EditButton
                                                                onClick={() => onModalCellEdit(rowIndex, column.column_name, row[column.column_name])}
                                                                title="Edit this field"
                                                            >
                                                                <FontAwesomeIcon icon={faEdit} css={tw`text-xs`} />
                                                            </EditButton>
                                                        </div>
                                                    )}
                                                </FieldValue>
                                            </FieldContainer>
                                        ))}
                                    </FieldGrid>
                                </RowCard>
                            ))}
                        </div>
                    ) : (
                        <EmptyState>
                            <EmptyIcon>
                                <FontAwesomeIcon icon={faTable} css={tw`text-gray-400 text-2xl`} />
                            </EmptyIcon>
                            <h4 css={tw`text-xl font-semibold text-gray-300 mb-2`}>No Data Found</h4>
                            <p css={tw`text-gray-500`}>This table appears to be empty.</p>
                        </EmptyState>
                    )}
                </ModalContent>

                <PaginationFooter>
                    <PaginationButton
                        onClick={() => onPageChange(currentPage - 1)}
                        disabled={currentPage <= 1 || loadingData}
                    >
                        <FontAwesomeIcon icon={faChevronLeft} css={tw`text-sm`} />
                        Previous
                    </PaginationButton>

                    <PaginationInfo>
                        <div css={tw`flex items-center gap-3`}>
                            <span css={tw`text-sm font-semibold text-white bg-blue-600 px-3 py-1 rounded-full`}>
                                Page {currentPage}
                            </span>
                            <span css={tw`text-gray-400 text-sm`}>of</span>
                            <span css={tw`text-sm font-semibold text-gray-300 bg-gray-700 px-3 py-1 rounded-full`}>
                                {Math.ceil(tableData.total / 6)}
                            </span>
                        </div>
                        <span css={tw`text-xs text-gray-400`}>
                            Showing {((currentPage - 1) * 6) + 1}-{Math.min(currentPage * 6, tableData.total)} of {tableData.total}
                        </span>
                    </PaginationInfo>

                    <PaginationButton
                        onClick={() => onPageChange(currentPage + 1)}
                        disabled={currentPage * 6 >= tableData.total || loadingData}
                    >
                        Next
                        <FontAwesomeIcon icon={faChevronRight} css={tw`text-sm`} />
                    </PaginationButton>
                </PaginationFooter>
            </ModalContainer>
        </GradientModal>
    );
};

const DatabaseTablesView: React.FC<DatabaseTablesViewProps> = ({ databaseId, databaseContents, loading, onRefresh }) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [selectedTable, setSelectedTable] = useState<DatabaseTable | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [filteredTables, setFilteredTables] = useState<DatabaseTable[]>([]);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [showCreateTableModal, setShowCreateTableModal] = useState(false);
    const [showDeleteTableModal, setShowDeleteTableModal] = useState(false);
    const [showAlterTableModal, setShowAlterTableModal] = useState(false);
    const [showCreateRowModal, setShowCreateRowModal] = useState(false);
    const [showDeleteRowModal, setShowDeleteRowModal] = useState(false);
    const [rowToDelete, setRowToDelete] = useState<{ index: number, data: Record<string, any> } | null>(null);
    const [selectedTableForDeletion, setSelectedTableForDeletion] = useState<string>('');
    const [showDeleteColumnModal, setShowDeleteColumnModal] = useState(false);
    const [columnToDelete, setColumnToDelete] = useState<string>('');
    const [showAddColumnModal, setShowAddColumnModal] = useState(false);
    const [showColumnsModal, setShowColumnsModal] = useState(false);
    const [showRowsModal, setShowRowsModal] = useState(false);
    const [modalEditingCell, setModalEditingCell] = useState<{ rowIndex: number, columnName: string } | null>(null);
    const [modalEditValue, setModalEditValue] = useState('');
    const [isSmallViewport, setIsSmallViewport] = useState(false);
    const [isDesktopViewport, setIsDesktopViewport] = useState(false);
    const [tableData, setTableData] = useState<TableDataResponse | null>(null);
    const [loadingData, setLoadingData] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [editingCell, setEditingCell] = useState<{ row: number, column: string, value: string } | null>(null);
    const [editValue, setEditValue] = useState('');
    const [showAddRowForm, setShowAddRowForm] = useState(false);
    const [newRowData, setNewRowData] = useState<Record<string, any>>({});
    const [foreignKeyError, setForeignKeyError] = useState<{
        error: string;
        referencingTables?: string[];
        actionType: 'edit' | 'delete';
        row?: number;
        column?: string;
        value?: any;
    } | null>(null);
    const [showForceOption, setShowForceOption] = useState(false);

    const convertToTableColumns = (databaseColumns: DatabaseColumn[]): TableColumn[] => {
        return databaseColumns.map(col => ({
            name: col.column_name,
            type: col.data_type,
            nullable: col.is_nullable === 'YES',
            default: col.column_default || undefined, 
            auto_increment: col.extra ? col.extra.includes('auto_increment') : false,
            primary_key: col.column_key === 'PRI'
        }));
    };
    const canAddRows = tableData?.has_primary_key || false;

    useEffect(() => {
        const handleResize = () => {
            if (window.innerWidth <= 768) {
                setSidebarCollapsed(true);
            } else {
                setSidebarCollapsed(false);
            }
        };

        handleResize();

        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    useEffect(() => {
        const handleViewportCheck = () => {
            setIsSmallViewport(window.innerWidth <= 480);
            setIsDesktopViewport(window.innerWidth >= 1024);
        };

        handleViewportCheck();

        window.addEventListener('resize', handleViewportCheck);
        return () => window.removeEventListener('resize', handleViewportCheck);
    }, []);

    const toggleSidebar = () => {
        setSidebarCollapsed(!sidebarCollapsed);
    };

    const closeSidebar = () => {
        if (window.innerWidth <= 768) {
            setSidebarCollapsed(true);
        }
    };

    useEffect(() => {

        if (databaseContents?.tables) {
            const filtered = databaseContents.tables.filter(table =>
                table.name.toLowerCase().includes(searchTerm.toLowerCase())
            );


            setFilteredTables(filtered);

            if (!selectedTable && filtered.length > 0) {
                setSelectedTable(filtered[0]);
            }
        } else {
            setFilteredTables([]);
        }
    }, [databaseContents, searchTerm, selectedTable]);

    const handleCreateTable = () => {
        setShowCreateTableModal(true);
    };

    const handleDeleteTable = () => {
        if (selectedTable) {
            setSelectedTableForDeletion(selectedTable.name);
            setShowDeleteTableModal(true);
        }
    };

    const handleAlterTable = () => {
        if (selectedTable) {
            setShowAlterTableModal(true);
        }
    };

    const handleExportTable = () => {
        if (selectedTable) {
        }
    };

    const handleTableAltered = () => {
        onRefresh?.();
        setShowAlterTableModal(false);
    };

    const handleTableCreated = () => {
        onRefresh?.();
        setShowCreateTableModal(false);
    };

    const handleTableDeleted = () => {
        onRefresh?.();
        setShowDeleteTableModal(false);
        setSelectedTableForDeletion('');
        setSelectedTable(null);
    };

    const handleLoadTableData = async (tableName: string, page: number = 1) => {
        setLoadingData(true);
        try {
            const response = await getTableData(uuid, databaseId, tableName, page, 6);

            setTableData({
                data: response.data,
                total: response.total,
                has_primary_key: response.has_primary_key
            });
        } catch (error) {
            console.error('Error loading table data:', error);
            const hasPrimaryKey = selectedTable?.columns.some(column => column.column_key === 'PRI') || false;

            setTableData({
                data: [],
                total: 0,
                has_primary_key: hasPrimaryKey
            });
        } finally {
            setLoadingData(false);
        }
    };

    const handlePageChange = (page: number) => {
        setCurrentPage(page);
        if (selectedTable) {
            handleLoadTableData(selectedTable.name, page);
        }
    };

    const handleCellEdit = (row: number, column: string, value: any) => {
        const editValueToSet = value !== null && value !== undefined ? String(value) : '';
        setEditingCell({ row, column, value: editValueToSet });
        setEditValue(editValueToSet);
    };

    const handleCellSave = async (row: number, column: string, force = false) => {
        try {

            if (!tableData || !selectedTable) return;
            const rowData = tableData.data[row];
            const primaryKeys = selectedTable.columns
                .filter(col => col.column_key === 'PRI')
                .map(col => col.column_name);

            if (primaryKeys.length === 0) {
                console.error('No primary key found for table');
                return;
            }
            const primaryKeyValues: Record<string, any> = {};
            primaryKeys.forEach(key => {
                primaryKeyValues[key] = rowData[key];
            });

            await updateTableRow(uuid, databaseId, selectedTable.name, {
                primary_key_values: primaryKeyValues,
                update_data: { [column]: editValue },
                force
            });

            const updatedData = { ...tableData };
            updatedData.data[row] = { ...updatedData.data[row], [column]: editValue };
            setTableData(updatedData);

            setEditingCell(null);
            setEditValue('');
            setForeignKeyError(null);
            setShowForceOption(false);

        } catch (error: any) {
            console.error('Error saving cell:', error);
            if (error.response?.data?.foreign_key_error && !force) {
                setForeignKeyError({
                    error: error.response.data.error,
                    referencingTables: error.response.data.referencing_tables || [],
                    actionType: 'edit',
                    row,
                    column,
                    value: editValue
                });
                setShowForceOption(true);
            } else {
                alert(`Error saving cell: ${error.response?.data?.error || error.message}`);
                setEditingCell(null);
                setEditValue('');
            }
        }
    };

    const handleCellCancel = () => {
        setEditingCell(null);
        setEditValue('');
        setForeignKeyError(null);
        setShowForceOption(false);
    };

    const handleForceCellSave = async () => {
        if (!foreignKeyError || foreignKeyError.row === undefined || !foreignKeyError.column || !foreignKeyError.value) {
            return;
        }

        try {

            if (!tableData || !selectedTable) return;

            const rowData = tableData.data[foreignKeyError.row];
            const primaryKeys = selectedTable.columns
                .filter(col => col.column_key === 'PRI')
                .map(col => col.column_name);

            if (primaryKeys.length === 0) {
                console.error('No primary key found for table');
                return;
            }

            const primaryKeyValues: Record<string, any> = {};
            primaryKeys.forEach(key => {
                primaryKeyValues[key] = rowData[key];
            });

            await updateTableRow(uuid, databaseId, selectedTable.name, {
                primary_key_values: primaryKeyValues,
                update_data: { [foreignKeyError.column]: foreignKeyError.value },
                force: true
            });

            const updatedData = { ...tableData };
            updatedData.data[foreignKeyError.row] = {
                ...updatedData.data[foreignKeyError.row],
                [foreignKeyError.column]: foreignKeyError.value
            };
            setTableData(updatedData);

            setEditingCell(null);
            setEditValue('');
            setModalEditingCell(null);
            setModalEditValue('');
            setForeignKeyError(null);
            setShowForceOption(false);

        } catch (error: any) {
            console.error('Error force saving cell:', error);
            alert(`Error force saving cell: ${error.response?.data?.error || error.message}`);
        }
    };



    const handleForceModalCellSave = () => {
        if (foreignKeyError && foreignKeyError.row !== undefined && foreignKeyError.column) {
            handleModalCellSave(foreignKeyError.row, foreignKeyError.column, true);
        }
    };

    const handleModalCellEdit = (rowIndex: number, columnName: string, value: any) => {
        setModalEditingCell({ rowIndex, columnName });
        setModalEditValue(value !== null && value !== undefined ? String(value) : '');
    };

    const handleModalCellSave = async (rowIndex: number, columnName: string, force = false) => {
        try {

            if (!tableData || !selectedTable) return;

            const rowData = tableData.data[rowIndex];
            const primaryKeys = selectedTable.columns
                .filter(col => col.column_key === 'PRI')
                .map(col => col.column_name);

            if (primaryKeys.length === 0) {
                console.error('No primary key found for table');
                return;
            }

            const primaryKeyValues: Record<string, any> = {};
            primaryKeys.forEach(key => {
                primaryKeyValues[key] = rowData[key];
            });

            await updateTableRow(uuid, databaseId, selectedTable.name, {
                primary_key_values: primaryKeyValues,
                update_data: { [columnName]: modalEditValue },
                force
            });

            const updatedData = { ...tableData };
            updatedData.data[rowIndex] = { ...updatedData.data[rowIndex], [columnName]: modalEditValue };
            setTableData(updatedData);

            setModalEditingCell(null);
            setModalEditValue('');
            setForeignKeyError(null);
            setShowForceOption(false);

        } catch (error: any) {
            console.error('Error saving modal cell:', error);
            if (error.response?.data?.foreign_key_error && !force) {
                setForeignKeyError({
                    error: error.response.data.error,
                    referencingTables: error.response.data.referencing_tables || [],
                    actionType: 'edit',
                    row: rowIndex,
                    column: columnName,
                    value: modalEditValue
                });
                setShowForceOption(true);
            } else {
                alert(`Error saving cell: ${error.response?.data?.error || error.message}`);
                setModalEditingCell(null);
                setModalEditValue('');
            }
        }
    };

    const handleModalCellCancel = () => {
        setModalEditingCell(null);
        setModalEditValue('');
        setForeignKeyError(null);
        setShowForceOption(false);
    };

    const handleAddRow = () => {
        setShowCreateRowModal(true);  
    };

    const handleRefreshData = () => {
        if (selectedTable) {
            handleLoadTableData(selectedTable.name, currentPage);
        }
    };

    const handleToggleAddRowForm = () => {
        setShowAddRowForm(!showAddRowForm);
        if (!showAddRowForm) {
            const initialData: Record<string, any> = {};
            selectedTable?.columns.forEach(column => {
                initialData[column.column_name] = '';
            });
            setNewRowData(initialData);
        }
    };

    const handleNewRowDataChange = (columnName: string, value: any) => {
        setNewRowData(prev => ({
            ...prev,
            [columnName]: value
        }));
    };

    const handleSaveNewRow = async () => {
        try {

            if (!selectedTable) return;

            await insertTableRow(uuid, databaseId, selectedTable.name, {
                row_data: newRowData
            });

            await handleLoadTableData(selectedTable.name, currentPage);

            setShowAddRowForm(false);
            setNewRowData({});

        } catch (error) {
            console.error('Error saving new row:', error);
        }
    };

    const handleDeleteRow = async (rowIndex: number) => {
        if (!tableData || !selectedTable) return;

        const rowData = tableData.data[rowIndex];
        setRowToDelete({ index: rowIndex, data: rowData });
        setShowDeleteRowModal(true);
    };

    const handleDeleteColumn = (columnName: string) => {
        setColumnToDelete(columnName);
        setShowDeleteColumnModal(true);
    };

    const handleColumnsChanged = async () => {
        if (selectedTable) {
            onRefresh?.();
            await handleLoadTableData(selectedTable.name, currentPage);
        }
    };

    const handleConfirmDeleteRow = async (force = false) => {
        if (!rowToDelete || !tableData || !selectedTable) return;

        try {

            const primaryKeys = selectedTable.columns
                .filter(col => col.column_key === 'PRI')
                .map(col => col.column_name);

            if (primaryKeys.length === 0) {
                console.error('No primary key found for table');
                return;
            }

            const primaryKeyValues: Record<string, any> = {};
            primaryKeys.forEach(key => {
                primaryKeyValues[key] = rowToDelete.data[key];
            });

            await deleteTableRow(uuid, databaseId, selectedTable.name, {
                primary_key_values: primaryKeyValues,
                force
            });

            await handleLoadTableData(selectedTable.name, currentPage);

            setShowDeleteRowModal(false);
            setRowToDelete(null);
            setForeignKeyError(null);
            setShowForceOption(false);

        } catch (error: any) {
            console.error('Error deleting row:', error);
            alert(`Error deleting row: ${error.response?.data?.error || error.message}`);
            setShowDeleteRowModal(false);
            setRowToDelete(null);
        }
    };

    useEffect(() => {
        if (selectedTable) {
            handleLoadTableData(selectedTable.name, 1);
            setCurrentPage(1);
        }
    }, [selectedTable]);

    if (loading) {
        return (
            <Container>
                <div className="flex items-center justify-center w-full h-64">
                    <div className="text-center">
                        <Spinner size="large" />
                        <p className="text-gray-400 mt-4">Loading database tables...</p>
                    </div>
                </div>
            </Container>
        );
    }

    return (
        <>
            {isSmallViewport && (
                <div className="bg-gradient-to-r from-orange-900 via-orange-800 to-orange-900 border-b border-orange-600 text-orange-100 p-3 flex items-start gap-3 mb-4 rounded-lg">
                    <FontAwesomeIcon icon={faExclamationTriangle} className="text-orange-300 text-lg mt-0.5 flex-shrink-0" />
                    <div>
                        <div className="font-bold text-orange-100 text-sm">⚠️ Small Screen Detected</div>
                        <div className="text-xs text-orange-200 mt-1">
                            Device with viewport less than 480px detected. Please use this on PC - many views on phones can be buggy due to how hard it is to display data on small widths.
                        </div>
                    </div>
                </div>
            )}

            <SidebarOverlay isVisible={!sidebarCollapsed && window.innerWidth <= 768} onClick={closeSidebar} />
            <Container>
                <Sidebar isCollapsed={sidebarCollapsed}>
                    <SidebarHeader>
                        <SidebarTitle>
                            <FontAwesomeIcon icon={faTable} className="text-blue-400" />
                            Database Tables
                        </SidebarTitle>
                        <ToggleButton
                            onClick={toggleSidebar}
                            className="md:hidden"
                            title="Close sidebar"
                        >
                            <FontAwesomeIcon icon={faTimes} size="sm" />
                        </ToggleButton>
                    </SidebarHeader>

                    <SearchContainer>
                        <div className="relative">
                            <FontAwesomeIcon
                                icon={faSearch}
                                className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 transition-colors duration-300"
                                size="sm"
                            />
                            <SearchInput
                                type="text"
                                placeholder="Search tables..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="pl-10"
                            />
                        </div>
                    </SearchContainer>

                    <ButtonContainer>
                        <ButtonRow>
                            <ActionButton
                                className="bg-green-600 hover:bg-green-700 text-white border border-green-500 hover:border-green-400 hover:shadow-lg hover:-translate-y-0.5 active:-translate-y-0 transition-all duration-200"
                                onClick={handleCreateTable}
                                title="Create a new table"
                            >
                                <FontAwesomeIcon icon={faPlus} size="sm" />
                                Create
                            </ActionButton>
                            <ActionButton
                                className="bg-red-600 hover:bg-red-700 text-white border border-red-500 hover:border-red-400 hover:shadow-lg hover:-translate-y-0.5 active:-translate-y-0 disabled:bg-gray-600 disabled:border-gray-500 disabled:cursor-not-allowed disabled:opacity-50 disabled:transform-none transition-all duration-200"
                                onClick={handleDeleteTable}
                                disabled={!selectedTable}
                                title={selectedTable ? `Delete table "${selectedTable.name}"` : "Select a table to delete"}
                            >
                                <FontAwesomeIcon icon={faTrash} size="sm" />
                                Delete Table
                            </ActionButton>

                        </ButtonRow>

                    </ButtonContainer>

                    <TablesList hasScrolling={filteredTables.length > 4}>
                        {filteredTables.length > 0 ? (
                            filteredTables.map((table) => (
                                <TableItem
                                    key={table.name}
                                    isSelected={selectedTable?.name === table.name}
                                    onClick={() => {
                                        setSelectedTable(table);
                                        closeSidebar();
                                    }}
                                >
                                    <TableName>
                                        <FontAwesomeIcon icon={faTable} size="sm" className="text-blue-400" />
                                        {sanitizeDatabaseName(table.name)}
                                    </TableName>
                                    <TableStats>
                                        <StatItem>
                                            <FontAwesomeIcon icon={faList} size="xs" className="text-green-400" />
                                            {table.rows.toLocaleString()} rows
                                        </StatItem>
                                        <StatItem>
                                            <FontAwesomeIcon icon={faColumns} size="xs" className="text-amber-400" />
                                            {table.columns.length} cols
                                        </StatItem>
                                    </TableStats>
                                </TableItem>
                            ))
                        ) : (
                            <div className="p-4 text-center text-gray-400">
                                {searchTerm ? 'No tables found matching your search.' : 'No tables found in this database.'}
                            </div>
                        )}
                    </TablesList>
                </Sidebar>

                <MainContent sidebarCollapsed={sidebarCollapsed}>
                    <ContentHeader>
                        <div className="flex items-center gap-3">
                            <ToggleButton
                                onClick={toggleSidebar}
                                title={sidebarCollapsed ? "Show tables sidebar" : "Hide tables sidebar"}
                            >
                                <FontAwesomeIcon icon={sidebarCollapsed ? faBars : faTimes} size="sm" />
                            </ToggleButton>
                            <ContentTitle>
                                {selectedTable ? `Table: ${sanitizeDatabaseName(selectedTable.name)}` : 'Select a Table'}
                            </ContentTitle>
                        </div>
                    </ContentHeader>

                    <ContentArea>
                        {selectedTable ? (
                            <div>
                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                                    <StatsCard>
                                        <StatsHeader>
                                            <FontAwesomeIcon icon={faList} className="text-green-400" />
                                            <span className="text-gray-300 font-medium">Total Rows</span>
                                        </StatsHeader>
                                        <StatsValue>
                                            {selectedTable.rows.toLocaleString()}
                                        </StatsValue>
                                    </StatsCard>

                                    <StatsCard>
                                        <StatsHeader>
                                            <FontAwesomeIcon icon={faColumns} className="text-amber-400" />
                                            <span className="text-gray-300 font-medium">Total Columns</span>
                                        </StatsHeader>
                                        <StatsValue>
                                            {selectedTable.columns.length}
                                        </StatsValue>
                                    </StatsCard>
                                </div>

                                <TableStructureCard>
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                                        <h3 className="text-lg font-semibold text-gray-100 transition-all duration-300 hover:text-white hover:text-xl">Table Structure</h3>
                                        <div className="flex gap-2 w-full sm:w-auto">
                                            <button
                                                className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium text-green-200 bg-green-900/50 border border-green-700/50 rounded-md hover:bg-green-800/60 hover:border-green-600 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-green-500/50 flex-1 sm:flex-none"
                                                onClick={() => setShowAddColumnModal(true)}
                                                title="Add a new column to this table"
                                            >
                                                <FontAwesomeIcon icon={faPlus} className="w-3 h-3" />
                                                <span className="hidden xs:inline">Add Column</span>
                                                <span className="xs:hidden">Add</span>
                                            </button>                      
                                            <button
                                                className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-200 bg-blue-900/50 border border-blue-700/50 rounded-md hover:bg-blue-800/60 hover:border-blue-600 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50 flex-1 sm:flex-none"
                                                onClick={() => setShowColumnsModal(true)}
                                                title="View columns in modal"
                                                style={{ display: 'none' }}
                                            >
                                                <FontAwesomeIcon icon={faEye} className="w-3 h-3" />
                                                <span>View</span>
                                            </button>
                                            <button
                                                className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium text-purple-200 bg-purple-900/50 border border-purple-700/50 rounded-md hover:bg-purple-800/60 hover:border-purple-600 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-purple-500/50 flex-1 sm:flex-none transform hover:scale-105 shadow-lg hover:shadow-purple-500/25"
                                                onClick={() => setShowRowsModal(true)}
                                                title="View table rows in beautiful modal"
                                            >
                                                <FontAwesomeIcon icon={faEye} className="w-3 h-3" />
                                                <span className="hidden xs:inline">View Rows</span>
                                                <span className="xs:hidden">Rows</span>
                                            </button>
                                        </div>
                                    </div>
                                    <style dangerouslySetInnerHTML={{
                                        __html: `
                                            @media (max-width: 360px) {
                                                button[title="View columns in modal"] {
                                                    display: inline-flex !important;
                                                }
                                            }
                                        `
                                    }} />

                                    {selectedTable.columns.length > 0 ? (
                                        <div className="overflow-auto" style={{ maxHeight: '400px' }}>
                                            <div className="inline-block min-w-full align-middle">
                                                <table className="w-full divide-y divide-gray-800" style={{ minWidth: 'max-content' }}>
                                                    <thead className="bg-gray-800 sticky top-0 z-10">
                                                        <tr>
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '150px' }}>Column</th>
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '120px' }}>Type</th>
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '100px' }}>Null</th>
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '100px' }}>Key</th>
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '120px' }}>Default</th>
                                                            {selectedTable.columns.some(col => col.extra) && (
                                                                <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '120px' }}>Extra</th>
                                                            )}
                                                            <th className="text-left py-3 px-6 text-gray-300 font-medium whitespace-nowrap" style={{ minWidth: '100px' }}>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {selectedTable.columns.map((column, index) => (
                                                            <tr key={column.column_name} className={`border-b border-gray-800 hover:bg-gray-800 transition-colors duration-200 ${index % 2 === 0 ? 'bg-gray-900' : 'bg-gray-850'
                                                                }`}>
                                                                <td className="py-3 px-6 whitespace-nowrap">
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-gray-100 font-medium">
                                                                            {column.column_name}
                                                                        </span>
                                                                        {column.column_key === 'PRI' && (
                                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900 text-yellow-200 border border-yellow-700 flex-shrink-0">
                                                                                PK
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </td>
                                                                <td className="py-3 px-6 text-gray-300 whitespace-nowrap">
                                                                    <span className="inline-flex items-center px-2 py-1 rounded text-xs font-mono bg-blue-900 text-blue-200 border border-blue-700">
                                                                        {column.data_type?.toUpperCase() || 'UNKNOWN'}
                                                                    </span>
                                                                </td>
                                                                <td className="py-3 px-6 whitespace-nowrap">
                                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${column.is_nullable === 'YES'
                                                                        ? 'bg-green-900 text-green-200 border border-green-700'
                                                                        : 'bg-red-900 text-red-200 border border-red-700'
                                                                        }`}>
                                                                        {column.is_nullable === 'YES' ? 'YES' : 'NO'}
                                                                    </span>
                                                                </td>
                                                                <td className="py-3 px-6 text-gray-300 whitespace-nowrap">
                                                                    {column.column_key && (
                                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-900 text-purple-200 border border-purple-700">
                                                                            {column.column_key}
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="py-3 px-6 text-gray-400 whitespace-nowrap">
                                                                    {column.column_default !== null ? (
                                                                        <span className="font-mono text-xs bg-gray-800 px-2 py-1 rounded border border-gray-600" title={column.column_default}>
                                                                            {column.column_default}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="text-gray-500 italic">NULL</span>
                                                                    )}
                                                                </td>
                                                                {selectedTable.columns.some(col => col.extra) && (
                                                                    <td className="py-3 px-6 text-gray-400 whitespace-nowrap">
                                                                        {column.extra && (
                                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-900 text-orange-200 border border-orange-700">
                                                                                {column.extra}
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                )}
                                                                <td className="py-3 px-6 whitespace-nowrap">
                                                                    <button
                                                                        onClick={() => handleDeleteColumn(column.column_name)}
                                                                        className="text-gray-400 hover:text-red-400 transition-colors duration-200 p-1 rounded hover:bg-gray-700"
                                                                        title={`Delete column ${column.column_name}`}
                                                                    >
                                                                        <FontAwesomeIcon icon={faTrash} size="sm" />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-gray-400 transition-all duration-300 hover:text-gray-300">No columns found in this table.</p>
                                    )}
                                </TableStructureCard>

                                {foreignKeyError && (
                                    <div css={tw`bg-yellow-900 border border-yellow-600 rounded-lg p-4 mb-6`}>
                                        <div css={tw`flex items-start`}>
                                            <FontAwesomeIcon
                                                icon={faExclamationTriangle}
                                                css={tw`text-yellow-400 text-xl mr-3 mt-1 flex-shrink-0`}
                                            />
                                            <div css={tw`flex-1`}>
                                                <h3 css={tw`text-yellow-300 font-semibold mb-2`}>Konstantin's Foreign Key Constraint Issue</h3>
                                                <p css={tw`text-yellow-200 text-sm leading-relaxed mb-3`}>
                                                    {foreignKeyError.error}
                                                </p>
                                                {foreignKeyError.referencingTables && foreignKeyError.referencingTables.length > 0 && (
                                                    <div css={tw`text-yellow-200 text-sm mb-3`}>
                                                        <strong>Referencing tables:</strong>
                                                        <ul css={tw`list-disc list-inside mt-1 ml-4`}>
                                                            {foreignKeyError.referencingTables.map(table => (
                                                                <li key={table} css={tw`font-mono`}>{table}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                                <div css={tw`flex gap-3`}>
                                                    <button
                                                        onClick={() => {
                                                            setForeignKeyError(null);
                                                            setShowForceOption(false);
                                                        }}
                                                        css={tw`px-3 py-1.5 text-sm bg-gray-600 hover:bg-gray-700 text-white rounded transition-colors`}
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        onClick={modalEditingCell ? handleForceModalCellSave : handleForceCellSave}
                                                        css={tw`px-3 py-1.5 text-sm bg-red-600 hover:bg-red-700 text-white rounded transition-colors`}
                                                    >
                                                        Force Save
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                <RowsContainer
                                    databaseId={databaseId}
                                    selectedTable={{
                                        name: selectedTable.name,
                                        columns: selectedTable.columns
                                    }}
                                    tableData={tableData}
                                    loadingData={loadingData}
                                    currentPage={currentPage}
                                    editingCell={editingCell}
                                    editValue={editValue}
                                    showAddRowForm={showAddRowForm}
                                    newRowData={newRowData}
                                    onLoadTableData={handleLoadTableData}
                                    onPageChange={handlePageChange}
                                    onCellEdit={handleCellEdit}
                                    onCellSave={handleCellSave}
                                    onCellCancel={handleCellCancel}
                                    onDeleteRow={handleDeleteRow}
                                    onAddRow={handleAddRow}
                                    onSaveNewRow={handleSaveNewRow}
                                    onSetShowAddRowForm={setShowAddRowForm}
                                    onSetNewRowData={setNewRowData}
                                    onSetEditValue={setEditValue}
                                    onColumnsChanged={handleColumnsChanged}
                                />
                                <div className="mt-4 flex justify-center">
                                    <button
                                        className="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-medium text-blue-200 bg-blue-900/50 border border-blue-700/50 rounded-md hover:bg-blue-800/60 hover:border-blue-600 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                                        onClick={() => setShowRowsModal(true)}
                                        title="View table rows in modal"
                                        style={{ display: 'none' }}
                                    >
                                        <FontAwesomeIcon icon={faEye} className="w-4 h-4" />
                                        <span>View Rows in Modal</span>
                                    </button>
                                </div>
                                <style dangerouslySetInnerHTML={{
                                    __html: `
                                        @media (max-width: 360px) {
                                            button[title="View table rows in modal"] {
                                                display: inline-flex !important;
                                            }
                                        }
                                    `
                                }} />
                            </div>
                        ) : (
                            <PlaceholderContent>
                                <PlaceholderIcon>
                                    <FontAwesomeIcon icon={faTable} size="2x" className="text-gray-500" />
                                </PlaceholderIcon>
                                <PlaceholderText>Select a table to view details</PlaceholderText>
                                <PlaceholderSubtext>
                                    Choose a table from the sidebar to view its structure and data
                                </PlaceholderSubtext>
                            </PlaceholderContent>
                        )}
                    </ContentArea>
                </MainContent>
            </Container>
            <CreateTableModal
                databaseId={databaseId}
                visible={showCreateTableModal}
                onDismissed={() => setShowCreateTableModal(false)}
                onTableCreated={() => {
                    setShowCreateTableModal(false);
                    onRefresh?.();
                }}
            />

            <DeleteTableModal
                databaseId={databaseId}
                tableName={selectedTable?.name || ''}
                visible={showDeleteTableModal}
                onDismissed={() => setShowDeleteTableModal(false)}
                onTableDeleted={() => {
                    setShowDeleteTableModal(false);
                    setSelectedTable(null);
                    onRefresh?.();
                }}
            />

            <CreateRowModal
                databaseId={databaseId}
                tableName={selectedTable?.name || ''}
                columns={selectedTable ? convertToTableColumns(selectedTable.columns) : []}
                visible={showCreateRowModal}
                onDismissed={() => setShowCreateRowModal(false)}
                onRowCreated={() => {
                    setShowCreateRowModal(false);
                    handleLoadTableData(selectedTable!.name, currentPage); 
                }}
            />

            <DeleteRowModal
                databaseId={databaseId}
                tableName={selectedTable?.name || ''}
                rowData={rowToDelete?.data || {}}
                primaryKeys={selectedTable?.columns
                    .filter(col => col.column_key === 'PRI')
                    .map(col => col.column_name) || []}
                visible={showDeleteRowModal}
                onDismissed={() => {
                    setShowDeleteRowModal(false);
                    setRowToDelete(null);
                }}
                onRowDeleted={() => {
                    if (selectedTable) {
                        handleLoadTableData(selectedTable.name, currentPage);
                    }
                }}
            />

            <DeleteColumnModal
                databaseId={databaseId}
                tableName={selectedTable?.name || ''}
                columnName={columnToDelete}
                visible={showDeleteColumnModal}
                onDismissed={() => {
                    setShowDeleteColumnModal(false);
                    setColumnToDelete('');
                }}
                onColumnDeleted={() => {
                    setShowDeleteColumnModal(false);
                    setColumnToDelete('');
                    handleColumnsChanged();
                }}
            />

            <AddColumnModal
                visible={showAddColumnModal}
                onDismissed={() => setShowAddColumnModal(false)}
                tableName={selectedTable?.name || ''}
                databaseId={databaseId}
                onColumnAdded={() => {
                    setShowAddColumnModal(false);
                    handleColumnsChanged();
                }}
                existingColumns={selectedTable?.columns.map(col => col.column_name) || []}
            />
            {showColumnsModal && selectedTable && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                    <div className="bg-gray-800 rounded-lg max-w-sm w-full max-h-[80vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b border-gray-700">
                            <h3 className="text-lg font-semibold text-white">Table Columns</h3>
                            <button
                                onClick={() => setShowColumnsModal(false)}
                                className="text-gray-400 hover:text-white transition-colors"
                            >
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[60vh]">
                            <div className="space-y-3">
                                {selectedTable.columns.map((column) => (
                                    <div key={column.column_name} className="bg-gray-700 rounded-lg p-3 border border-gray-600">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-white font-medium">{column.column_name}</span>
                                            {column.column_key === 'PRI' && (
                                                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900 text-yellow-200 border border-yellow-700">
                                                    PK
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-sm space-y-1">
                                            <div className="flex justify-between">
                                                <span className="text-gray-400">Type:</span>
                                                <span className="text-blue-200 font-mono">{column.data_type?.toUpperCase() || 'UNKNOWN'}</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-gray-400">Nullable:</span>
                                                <span className={column.is_nullable === 'YES' ? 'text-green-200' : 'text-red-200'}>
                                                    {column.is_nullable === 'YES' ? 'YES' : 'NO'}
                                                </span>
                                            </div>
                                            {column.column_key && (
                                                <div className="flex justify-between">
                                                    <span className="text-gray-400">Key:</span>
                                                    <span className="text-purple-200">{column.column_key}</span>
                                                </div>
                                            )}
                                            <div className="flex justify-between">
                                                <span className="text-gray-400">Default:</span>
                                                <span className="text-gray-200 font-mono text-xs">
                                                    {column.column_default !== null ? column.column_default : 'NULL'}
                                                </span>
                                            </div>
                                            {column.extra && (
                                                <div className="flex justify-between">
                                                    <span className="text-gray-400">Extra:</span>
                                                    <span className="text-orange-200">{column.extra}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}
            <ViewRowsModal
                visible={showRowsModal}
                onDismissed={() => setShowRowsModal(false)}
                selectedTable={selectedTable}
                tableData={tableData}
                currentPage={currentPage}
                modalEditingCell={modalEditingCell}
                modalEditValue={modalEditValue}
                isDesktopViewport={isDesktopViewport}
                loadingData={loadingData}
                onModalCellEdit={handleModalCellEdit}
                onModalCellSave={handleModalCellSave}
                onModalCellCancel={handleModalCellCancel}
                onSetModalEditValue={setModalEditValue}
                onDeleteRow={handleDeleteRow}
                onPageChange={handlePageChange}
            />
            <ForeignKeyConstraintModal
                visible={!!foreignKeyError && foreignKeyError.actionType === 'edit'}
                onDismissed={() => {
                    setForeignKeyError(null);
                    setShowForceOption(false);
                    setEditingCell(null);
                    setEditValue('');
                    setModalEditingCell(null);
                    setModalEditValue('');
                }}
                onForceConfirm={() => {
                    handleForceCellSave();
                }}
                errorMessage={foreignKeyError?.error || ''}
                actionType={'edit'}
            />
        </>
    );
};

export default DatabaseTablesView;

