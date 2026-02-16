import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle, faSpinner, faTrash, faKey } from '@fortawesome/free-solid-svg-icons';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { dropColumn } from '@/api/server/databases/tableDataOperations';
import { useFlashKey } from '@/plugins/useFlash';
import GradientModal from './GradientModal';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import Button from '@/components/elements/Button';

interface DeleteColumnModalProps {
    databaseId: string;
    tableName: string;
    columnName: string;
    visible: boolean;
    onDismissed: () => void;
    onColumnDeleted?: () => void;
}

const WarningContainer = styled.div`
    ${tw`bg-red-900 bg-opacity-50 border border-red-700 rounded-lg p-4 mb-6`}
`;

const ColumnInfoContainer = styled.div`
    ${tw`bg-gray-800 rounded-lg p-4 mb-6`}
`;

const ModalContent = styled.div`
    ${tw`p-6`}
`;

const ModalHeader = styled.h2`
    ${tw`text-2xl font-bold text-white mb-6 flex items-center`}
`;

const WarningText = styled.p`
    ${tw`text-red-200 font-medium mb-2`}
`;

const WarningSubtext = styled.p`
    ${tw`text-red-300 text-sm`}
`;

const ColumnTitle = styled.h3`
    ${tw`text-lg font-semibold text-gray-200 mb-2`}
`;

const ColumnInfo = styled.div`
    ${tw`flex items-center`}
`;

const ColumnName = styled.span`
    ${tw`font-mono text-gray-100`}
`;

const ButtonContainer = styled.div`
    ${tw`flex justify-end gap-3 mt-6`}
`;

const CancelButton = styled.button`
    ${tw`px-4 py-2.5 text-sm font-medium text-gray-300 bg-gray-700/50 border border-gray-600/50 rounded-lg transition-all duration-200 hover:bg-gray-600/60 hover:border-gray-500 hover:text-white focus:outline-none focus:ring-2 focus:ring-gray-500/50 focus:ring-offset-2 focus:ring-offset-gray-800`}
`;

const DeleteButton = styled.button`
    ${tw`px-4 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-red-600 to-red-700 border border-red-500/50 rounded-lg transition-all duration-200 hover:from-red-700 hover:to-red-800 hover:border-red-400 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-red-500/50 focus:ring-offset-2 focus:ring-offset-gray-800 disabled:opacity-60 disabled:cursor-not-allowed disabled:hover:from-red-600 disabled:hover:to-red-700`}
    
    &:active:not(:disabled) {
        ${tw`transform scale-95`}
    }
`;

const DeleteColumnModal: React.FC<DeleteColumnModalProps> = ({
    databaseId,
    tableName,
    columnName,
    visible,
    onDismissed,
    onColumnDeleted
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('database:delete-column');
    const [isDeleting, setIsDeleting] = useState(false);
    const [isPrimaryKey, setIsPrimaryKey] = useState(false);
    const [columnInfo, setColumnInfo] = useState<any>(null);

    useEffect(() => {
        const checkColumnInfo = async () => {
            if (!uuid || !visible || !columnName) return;
            
            try {
                const response = await fetch(`/api/client/servers/${uuid}/databases/${databaseId}/table/${tableName}/columns`);
                const data = await response.json();
                const column = data.columns?.find((col: any) => col.column_name === columnName);
                
                if (column) {
                    setColumnInfo(column);
                    setIsPrimaryKey(column.column_key === 'PRI');
                }
            } catch (error) {
                console.error('Error fetching column info:', error);
            }
        };

        checkColumnInfo();
    }, [uuid, databaseId, tableName, columnName, visible]);

    const handleDelete = async () => {
        if (!uuid) return;
        
        setIsDeleting(true);
        clearFlashes();
        
        try {
            await dropColumn(uuid, databaseId, tableName, columnName);
            onColumnDeleted?.();
            onDismissed();
        } catch (error) {
            clearAndAddHttpError(error as Error);
        } finally {
            setIsDeleting(false);
        }
    };

    return (
        <GradientModal visible={visible} onDismissed={onDismissed}>
            <ModalContent>
                <ModalHeader>
                    <FontAwesomeIcon icon={faTrash} className="mr-3 text-red-400" />
                    Delete Column
                </ModalHeader>

                {isPrimaryKey && (
                    <WarningContainer>
                        <div className="flex items-start">
                            <FontAwesomeIcon icon={faKey} className="text-yellow-400 mr-3 mt-1" />
                            <div>
                                <WarningText className="text-yellow-200">
                                    ⚠️ Primary Key Column Warning
                                </WarningText>
                                <WarningSubtext className="text-yellow-300">
                                    You are about to delete a primary key column. After deletion, you will no longer have the option to create a primary key column for this table unless you recreate the table or add a new column and set it as primary key.
                                </WarningSubtext>
                            </div>
                        </div>
                    </WarningContainer>
                )}

                <WarningContainer>
                    <div className="flex items-start">
                        <FontAwesomeIcon icon={faExclamationTriangle} className="text-red-400 mr-3 mt-1" />
                        <div>
                            <WarningText>
                                This action cannot be undone!
                            </WarningText>
                            <WarningSubtext>
                                Deleting this column will permanently remove all data stored in it.
                            </WarningSubtext>
                        </div>
                    </div>
                </WarningContainer>

                <ColumnInfoContainer>
                    <ColumnTitle>Column to Delete:</ColumnTitle>
                    <ColumnInfo>
                        <ColumnName>{columnName}</ColumnName>
                        {columnInfo && (
                            <span className="ml-2 text-gray-400">
                                ({columnInfo.data_type}
                                {isPrimaryKey && ', PRIMARY KEY'}
                                {columnInfo.extra?.includes('auto_increment') && ', AUTO_INCREMENT'})
                            </span>
                        )}
                    </ColumnInfo>
                </ColumnInfoContainer>

                <ButtonContainer>
                    <CancelButton onClick={onDismissed} disabled={isDeleting}>
                        Cancel
                    </CancelButton>
                    <DeleteButton onClick={handleDelete} disabled={isDeleting}>
                        {isDeleting ? (
                            <>
                                <FontAwesomeIcon icon={faSpinner} className="animate-spin mr-2" />
                                Deleting...
                            </>
                        ) : (
                            <>
                                <FontAwesomeIcon icon={faTrash} className="mr-2" />
                                Delete Column
                            </>
                        )}
                    </DeleteButton>
                </ButtonContainer>
            </ModalContent>
        </GradientModal>
    );
};

export default DeleteColumnModal;