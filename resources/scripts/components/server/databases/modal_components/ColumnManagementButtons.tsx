import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus, faTrash, faColumns } from '@fortawesome/free-solid-svg-icons';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import { dropColumn } from '@/api/server/databases/databaseEditingOperations';
import { ServerContext } from '@/state/server';
import AddColumnModal from './AddColumnModal';

const ButtonContainer = styled.div`
    ${tw`flex items-center space-x-2 mb-4`}
`;

const ActionButton = styled.button<{ variant?: 'primary' | 'danger' }>`
    ${tw`px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200 flex items-center space-x-2`}
    ${props => props.variant === 'danger' 
        ? tw`bg-red-600 hover:bg-red-700 text-white` 
        : tw`bg-blue-600 hover:bg-blue-700 text-white`
    }
    
    &:disabled {
        ${tw`opacity-50 cursor-not-allowed`}
    }
`;

const ColumnSelector = styled.select`
    ${tw`px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500`}
`;

const ConfirmDialog = styled.div`
    ${tw`fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50`}
`;

const ConfirmCard = styled.div`
    ${tw`bg-gray-800 rounded-lg p-6 max-w-md mx-4 border border-gray-700`}
`;

interface TableColumn {
    column_name: string;
    data_type: string;
    is_nullable: string;
    column_key: string;
    extra: string;
}

interface ColumnManagementButtonsProps {
    tableName: string;
    databaseId: string;
    columns: TableColumn[];
    onColumnsChanged: () => void;
}

const ColumnManagementButtons: React.FC<ColumnManagementButtonsProps> = ({
    tableName,
    databaseId,
    columns,
    onColumnsChanged
}) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const [showAddModal, setShowAddModal] = useState(false);
    const [selectedColumn, setSelectedColumn] = useState('');
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDeleteColumn = async () => {
        if (!selectedColumn) return;
        
        setIsDeleting(true);
        try {
            await dropColumn(uuid, databaseId, tableName, selectedColumn);
            onColumnsChanged();
            setShowDeleteConfirm(false);
            setSelectedColumn('');
        } catch (error) {
            console.error('Error deleting column:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const canDeleteColumn = columns.length > 1 && selectedColumn;
    const nonPrimaryColumns = columns.filter(col => col.column_key !== 'PRI');

    return (
        <>
            <ButtonContainer>
                <div css={tw`flex items-center space-x-2`}>
                    <FontAwesomeIcon icon={faColumns} css={tw`text-gray-400`} />
                    <span css={tw`text-sm font-medium text-gray-300`}>Column Management:</span>
                </div>
                
                <ActionButton
                    onClick={() => setShowAddModal(true)}
                    variant="primary"
                >
                    <FontAwesomeIcon icon={faPlus} />
                    <span>Add Column</span>
                </ActionButton>
                
                <div css={tw`flex items-center space-x-2`}>
                    <ColumnSelector
                        value={selectedColumn}
                        onChange={(e) => setSelectedColumn(e.target.value)}
                        disabled={nonPrimaryColumns.length === 0}
                    >
                        <option value="">Select column to delete</option>
                        {nonPrimaryColumns.map(column => (
                            <option key={column.column_name} value={column.column_name}>
                                {column.column_name} ({column.data_type})
                            </option>
                        ))}
                    </ColumnSelector>
                    
                    <ActionButton
                        onClick={() => setShowDeleteConfirm(true)}
                        variant="danger"
                        disabled={!canDeleteColumn}
                    >
                        <FontAwesomeIcon icon={faTrash} />
                        <span>Delete</span>
                    </ActionButton>
                </div>
            </ButtonContainer>

            <AddColumnModal
                visible={showAddModal}
                onDismissed={() => setShowAddModal(false)}
                tableName={tableName}
                databaseId={databaseId}
                onColumnAdded={onColumnsChanged}
                existingColumns={columns.map(col => col.column_name)}
            />

            {showDeleteConfirm && (
                <ConfirmDialog>
                    <ConfirmCard>
                        <h3 css={tw`text-lg font-semibold text-white mb-4`}>Confirm Column Deletion</h3>
                        <p css={tw`text-gray-300 mb-6`}>
                            Are you sure you want to delete the column <strong>"{selectedColumn}"</strong>? 
                            This action cannot be undone and will permanently remove all data in this column.
                        </p>
                        <div css={tw`flex justify-end space-x-3`}>
                            <button
                                onClick={() => {
                                    setShowDeleteConfirm(false);
                                    setSelectedColumn('');
                                }}
                                disabled={isDeleting}
                                css={tw`px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors`}
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleDeleteColumn}
                                disabled={isDeleting}
                                css={tw`px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors`}
                            >
                                {isDeleting ? 'Deleting...' : 'Delete Column'}
                            </button>
                        </div>
                    </ConfirmCard>
                </ConfirmDialog>
            )}
        </>
    );
};

export default ColumnManagementButtons;