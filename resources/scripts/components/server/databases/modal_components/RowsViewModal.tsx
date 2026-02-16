import React, { useState, useEffect } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { 
    faTable, 
    faTrash, 
    faChevronLeft, 
    faChevronRight, 
    faKey,
    faEdit,
    faDatabase
} from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';
import { getTableData, deleteTableRow } from '@/api/server/databases/tableDataOperations';
import type { TableColumn } from '@/api/server/databases/tableDataOperations';
import GradientModal from './GradientModal';
import Spinner from '@/components/elements/Spinner';
import { sanitizeColumnValue, sanitizeDatabaseName } from '@/utils/xssProtection';
import EditRowModal from './EditRowModal';
import { Button } from '@/components/elements/button';

interface RowsViewModalProps {
    databaseId: string;
    tableName: string;
    columns: TableColumn[];
    visible: boolean;
    onDismissed: () => void;
    onRefresh?: () => void;
}

interface TableDataResponse {
    data: Record<string, any>[];
    total: number;
    has_primary_key: boolean;
}

const Container = styled.div`
    ${tw`p-6 bg-gradient-to-br from-gray-800 to-gray-900`};
`;

const Header = styled.div`
    ${tw`flex items-center mb-6 pb-4 border-b border-gray-600`};
`;

const IconContainer = styled.div`
    ${tw`bg-blue-600 p-3 rounded-full mr-4 shadow-lg`};
`;

const HeaderText = styled.div`
    ${tw`flex flex-col`};
`;

const HeaderTitle = styled.h2`
    ${tw`text-2xl font-semibold text-white`};
`;

const HeaderSubtitle = styled.p`
    ${tw`text-sm text-gray-400`};
`;

const LoadingContainer = styled.div`
    ${tw`flex items-center justify-center py-20`};
`;

const RowsGrid = styled.div`
    ${tw`grid gap-6 mb-8`};
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    
    @media (max-width: 768px) {
        grid-template-columns: 1fr;
        ${tw`gap-4`};
    }
`;

const RowCard = styled.div`
    ${tw`bg-gradient-to-br from-gray-700 to-gray-800 rounded-xl border border-gray-600 p-6 transition-all duration-300 hover:shadow-xl hover:border-gray-500`};
    
    &:hover {
        transform: translateY(-2px);
    }
`;

const RowHeader = styled.div`
    ${tw`flex items-center justify-between mb-4 pb-3 border-b border-gray-600`};
`;

const RowNumber = styled.div`
    ${tw`bg-gradient-to-r from-blue-500 to-purple-600 text-white px-3 py-1 rounded-full text-sm font-semibold flex items-center gap-2`};
`;

const RowActions = styled.div`
    ${tw`flex items-center gap-2`};
`;

const ActionButton = styled.button`
    ${tw`p-2 rounded-lg transition-all duration-200 flex items-center justify-center`};
    
    &.edit {
        ${tw`bg-blue-600 hover:bg-blue-700 text-white`};
    }
    
    &.delete {
        ${tw`bg-red-600 hover:bg-red-700 text-white`};
    }
    
    &:hover {
        transform: scale(1.1);
    }
    
    &:disabled {
        ${tw`opacity-50 cursor-not-allowed`};
        
        &:hover {
            transform: none;
        }
    }
`;

const FieldsContainer = styled.div`
    ${tw`space-y-3`};
`;

const FieldRow = styled.div`
    ${tw`flex flex-col gap-1`};
`;

const FieldLabel = styled.div`
    ${tw`text-sm font-medium text-gray-300 flex items-center gap-2`};
`;

const FieldValue = styled.div`
    ${tw`bg-gray-800 border border-gray-600 rounded-lg p-3 text-gray-100 font-mono text-sm min-h-[2.5rem] flex items-center`};
    word-break: break-all;
`;

const PrimaryKeyBadge = styled.span`
    ${tw`bg-yellow-600 text-yellow-100 px-2 py-0.5 rounded text-xs font-medium flex items-center gap-1`};
`;

const NullValue = styled.span`
    ${tw`text-gray-500 italic`};
`;

const PaginationContainer = styled.div`
    ${tw`flex justify-between items-center pt-4 border-t border-gray-600`};
`;

const PaginationInfo = styled.div`
    ${tw`text-sm text-gray-400`};
`;

const PaginationControls = styled.div`
    ${tw`flex items-center gap-4`};
`;

const PageIndicator = styled.div`
    ${tw`bg-gray-700 px-4 py-2 rounded-lg font-medium text-white`};
`;

const EmptyState = styled.div`
    ${tw`text-center py-20 text-gray-400`};
`;

const RowsViewModal: React.FC<RowsViewModalProps> = ({
    databaseId,
    tableName,
    columns,
    visible,
    onDismissed,
    onRefresh
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [tableData, setTableData] = useState<TableDataResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [editingRow, setEditingRow] = useState<Record<string, any> | null>(null);
    const [showEditModal, setShowEditModal] = useState(false);
    
    const rowsPerPage = 6;
    const primaryKeys = columns.filter(col => col.primary_key).map(col => col.name);

    useEffect(() => {
        if (visible && uuid) {
            loadTableData();
        }
    }, [visible, uuid, currentPage]);

    const loadTableData = async () => {
        setLoading(true);
        try {
            const response = await getTableData(uuid, databaseId, tableName, currentPage, rowsPerPage);
            setTableData(response);
        } catch (error) {
            console.error('Error loading table data:', error);
            setTableData({ data: [], total: 0, has_primary_key: false });
        } finally {
            setLoading(false);
        }
    };

    const handleEditRow = (rowData: Record<string, any>) => {
        setEditingRow(rowData);
        setShowEditModal(true);
    };

    const handleDeleteRow = async (rowIndex: number) => {
        if (!tableData || !tableData.has_primary_key) return;
        
        const rowData = tableData.data[rowIndex];
        const primaryKeyValues = primaryKeys.reduce((acc, key) => {
            acc[key] = rowData[key];
            return acc;
        }, {} as Record<string, any>);

        try {
            await deleteTableRow(uuid, databaseId, tableName, { primary_key_values: primaryKeyValues });
            await loadTableData();
            onRefresh?.();
        } catch (error) {
            console.error('Error deleting row:', error);
        }
    };

    const handlePageChange = (newPage: number) => {
        setCurrentPage(newPage);
    };

    const handleEditModalClose = () => {
        setShowEditModal(false);
        setEditingRow(null);
        loadTableData(); 
        onRefresh?.();
    };

    const totalPages = tableData ? Math.ceil(tableData.total / rowsPerPage) : 0;
    const startRow = (currentPage - 1) * rowsPerPage + 1;
    const endRow = Math.min(currentPage * rowsPerPage, tableData?.total || 0);

    return (
        <>
            <GradientModal
                visible={visible}
                onDismissed={onDismissed}
                size="xxl"
            >
                <Container>
                    <Header>
                        <IconContainer>
                            <FontAwesomeIcon icon={faTable} css={tw`text-white text-lg`} />
                        </IconContainer>
                        <HeaderText>
                            <HeaderTitle>{sanitizeDatabaseName(tableName)} Rows</HeaderTitle>
                            <HeaderSubtitle>
                                Page {currentPage} of {totalPages} • {tableData?.total || 0} total rows
                            </HeaderSubtitle>
                        </HeaderText>
                    </Header>

                    {loading ? (
                        <LoadingContainer>
                            <div css={tw`text-center`}>
                                <Spinner size="large" />
                                <p css={tw`text-gray-400 mt-4`}>Loading table data...</p>
                            </div>
                        </LoadingContainer>
                    ) : tableData && tableData.data.length > 0 ? (
                        <>
                            <RowsGrid>
                                {tableData.data.map((row, index) => (
                                    <RowCard key={index}>
                                        <RowHeader>
                                            <RowNumber>
                                                #{startRow + index}
                                                <span css={tw`text-xs opacity-75`}>
                                                    {columns.length} columns
                                                </span>
                                            </RowNumber>
                                            <RowActions>
                                                <ActionButton
                                                    className="edit"
                                                    onClick={() => handleEditRow(row)}
                                                    disabled={!tableData.has_primary_key}
                                                    title={tableData.has_primary_key ? "Edit row" : "Cannot edit - no primary key"}
                                                >
                                                    <FontAwesomeIcon icon={faEdit} />
                                                </ActionButton>
                                                <ActionButton
                                                    className="delete"
                                                    onClick={() => handleDeleteRow(index)}
                                                    disabled={!tableData.has_primary_key}
                                                    title={tableData.has_primary_key ? "Delete row" : "Cannot delete - no primary key"}
                                                >
                                                    <FontAwesomeIcon icon={faTrash} />
                                                </ActionButton>
                                            </RowActions>
                                        </RowHeader>

                                        <FieldsContainer>
                                            {columns.map((column) => (
                                                <FieldRow key={column.name}>
                                                    <FieldLabel>
                                                        {column.name}
                                                        {column.primary_key && (
                                                            <PrimaryKeyBadge>
                                                                <FontAwesomeIcon icon={faKey} />
                                                                PK
                                                            </PrimaryKeyBadge>
                                                        )}
                                                    </FieldLabel>
                                                    <FieldValue>
                                                        {row[column.name] === null || row[column.name] === undefined ? (
                                                            <NullValue>NULL</NullValue>
                                                        ) : (
                                                            <span dangerouslySetInnerHTML={{ 
                                                                __html: sanitizeColumnValue(row[column.name]) 
                                                            }} />
                                                        )}
                                                    </FieldValue>
                                                </FieldRow>
                                            ))}
                                        </FieldsContainer>
                                    </RowCard>
                                ))}
                            </RowsGrid>

                            <PaginationContainer>
                                <PaginationInfo>
                                    Showing {startRow} to {endRow} of {tableData.total} rows
                                </PaginationInfo>
                                <PaginationControls>
                                    <Button
                                        type="button"
                                        variant={Button.Variants.Secondary}
                                        onClick={() => handlePageChange(currentPage - 1)}
                                        disabled={currentPage <= 1}
                                    >
                                        <FontAwesomeIcon icon={faChevronLeft} css={tw`mr-2`} />
                                        Previous
                                    </Button>
                                    <PageIndicator>
                                        Page {currentPage} of {totalPages}
                                    </PageIndicator>
                                    <Button
                                        type="button"
                                        variant={Button.Variants.Secondary}
                                        onClick={() => handlePageChange(currentPage + 1)}
                                        disabled={currentPage >= totalPages}
                                    >
                                        Next
                                        <FontAwesomeIcon icon={faChevronRight} css={tw`ml-2`} />
                                    </Button>
                                </PaginationControls>
                            </PaginationContainer>
                        </>
                    ) : (
                        <EmptyState>
                            <FontAwesomeIcon icon={faDatabase} size="3x" css={tw`text-gray-600 mb-4`} />
                            <h3 css={tw`text-lg font-medium text-gray-400 mb-2`}>No Data Found</h3>
                            <p css={tw`text-gray-500`}>This table doesn't contain any rows yet.</p>
                        </EmptyState>
                    )}
                </Container>
            </GradientModal>
            {showEditModal && editingRow && (
                <EditRowModal
                    databaseId={databaseId}
                    tableName={tableName}
                    columns={columns}
                    rowData={editingRow}
                    primaryKeys={primaryKeys}
                    visible={showEditModal}
                    onDismissed={handleEditModalClose}
                    onRowUpdated={handleEditModalClose}
                />
            )}
        </>
    );
};

export default RowsViewModal;