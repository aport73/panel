import React, { useState } from 'react';
import { deleteTableRow } from '@/api/server/databases/tableDataOperations';
import type { OperationResponse } from '@/api/server/databases/tableDataOperations';
import { useFlashKey } from '@/plugins/useFlash';
import GradientModal from './GradientModal';
import Button from '@/components/elements/Button';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle, faSpinner, faTrash } from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';

interface DeleteRowModalProps {
    databaseId: string;
    tableName: string;
    rowData: Record<string, any>;
    primaryKeys: string[];
    visible: boolean;
    onDismissed: () => void;
    onRowDeleted?: () => void;
}

const WarningContainer = styled.div`
    ${tw`bg-red-900 bg-opacity-50 border border-red-700 rounded-lg p-4 mb-6`}
`;

const RowDataContainer = styled.div`
    ${tw`bg-gray-800 rounded-lg p-4 mb-6 max-h-64 overflow-y-auto`}
`;

const DataItem = styled.div`
    ${tw`flex justify-between items-center py-2 border-b border-gray-700 last:border-b-0`}
`;

const DataKey = styled.span`
    ${tw`font-medium text-gray-300`}
`;

const DataValue = styled.span`
    ${tw`text-gray-100 font-mono text-sm`}
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

const DeleteRowModal: React.FC<DeleteRowModalProps> = ({
    databaseId,
    tableName,
    rowData,
    primaryKeys,
    visible,
    onDismissed,
    onRowDeleted
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('database:delete-row');
    const [isDeleting, setIsDeleting] = useState(false);
    const [foreignKeyError, setForeignKeyError] = useState<{
        error: string;
        referencingTables?: string[];
    } | null>(null);
    const [showForceOption, setShowForceOption] = useState(false);

    const handleDelete = async (force = false) => {
        if (!uuid) {
            console.error('Server UUID not available');
            return;
        }
        
        setIsDeleting(true);
        clearFlashes();

        try {
            const primaryKeyValues: Record<string, any> = {};
            primaryKeys.forEach(key => {
                primaryKeyValues[key] = rowData[key];
            });

            await deleteTableRow(uuid, databaseId, tableName, {
                primary_key_values: primaryKeyValues,
                force
            });
            
            onRowDeleted?.();
            onDismissed();
            setShowForceOption(false);
            setForeignKeyError(null);
        } catch (error: any) {
            if (error.response?.data?.foreign_key_error && !force) {
                setForeignKeyError({
                    error: error.response.data.error,
                    referencingTables: error.response.data.referencing_tables || []
                });
                setShowForceOption(true);
            } else {
                clearAndAddHttpError(error as Error);
            }
        } finally {
            setIsDeleting(false);
        }
    };

    const handleForceDelete = () => {
        handleDelete(true);
    };

    return (
        <>
            <GradientModal visible={visible} onDismissed={onDismissed} size="md">
                <div css={tw`p-6`}>
                    <SpinnerOverlay visible={isDeleting} />
                    
                    <h2 css={tw`text-2xl font-bold text-white mb-6 flex items-center`}>
                        <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-red-400 mr-3`} />
                        Delete Row
                    </h2>
                    
                    {foreignKeyError && (
                        <div css={tw`bg-yellow-900 border border-yellow-600 rounded-lg p-4 mb-6`}>
                            <div css={tw`flex items-start`}>
                                <FontAwesomeIcon 
                                    icon={faExclamationTriangle} 
                                    css={tw`text-yellow-400 text-xl mr-3 mt-1 flex-shrink-0`} 
                                />
                                <div>
                                    <h3 css={tw`text-yellow-300 font-semibold mb-2`}>Konstantin's Foreign Key Constraint Issue</h3>
                                    <p css={tw`text-yellow-200 text-sm leading-relaxed mb-3`}>
                                        {foreignKeyError.error}
                                    </p>
                                    {foreignKeyError.referencingTables && foreignKeyError.referencingTables.length > 0 && (
                                        <div css={tw`text-yellow-200 text-sm`}>
                                            <strong>Referencing tables:</strong>
                                            <ul css={tw`list-disc list-inside mt-1 ml-4`}>
                                                {foreignKeyError.referencingTables.map(table => (
                                                    <li key={table} css={tw`font-mono`}>{table}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                    
                    <WarningContainer>
                        <p css={tw`text-red-200 font-medium mb-2`}>
                            ⚠️ This action cannot be undone!
                        </p>
                        <p css={tw`text-red-300 text-sm`}>
                            You are about to permanently delete this row from the <strong>{tableName}</strong> table.
                        </p>
                    </WarningContainer>
                    
                    <div css={tw`mb-6`}>
                        <h3 css={tw`text-lg font-semibold text-gray-200 mb-3`}>Row Data:</h3>
                        <RowDataContainer>
                            {Object.entries(rowData).map(([key, value]) => (
                                <DataItem key={key}>
                                    <DataKey>
                                        {key}
                                        {primaryKeys.includes(key) && (
                                            <span css={tw`text-yellow-400 text-xs ml-2`}>(PK)</span>
                                        )}
                                    </DataKey>
                                    <DataValue>
                                        {value !== null && value !== undefined ? value.toString() : 'NULL'}
                                    </DataValue>
                                </DataItem>
                            ))}
                        </RowDataContainer>
                    </div>
                    
                    <div css={tw`flex justify-end space-x-4`}>
                        <ButtonContainer>
                            <CancelButton type="button" onClick={onDismissed}>
                                Cancel
                            </CancelButton>
                            
                            {showForceOption && foreignKeyError && (
                                <DeleteButton 
                                    type="button" 
                                    onClick={handleForceDelete} 
                                    disabled={isDeleting}
                                    css={tw`bg-red-700 hover:bg-red-800`}
                                >
                                    {isDeleting ? (
                                        <>
                                            <FontAwesomeIcon icon={faSpinner} className="animate-spin mr-2" />
                                            Force Deleting...
                                        </>
                                    ) : (
                                        <>
                                            <FontAwesomeIcon icon={faTrash} className="mr-2" />
                                            Force Delete
                                        </>
                                    )}
                                </DeleteButton>
                            )}
                            
                            <DeleteButton 
                                type="button" 
                                onClick={() => handleDelete(false)} 
                                disabled={isDeleting}
                            >
                                {isDeleting ? (
                                    <>
                                        <FontAwesomeIcon icon={faSpinner} className="animate-spin mr-2" />
                                        Deleting...
                                    </>
                                ) : (
                                    <>
                                        <FontAwesomeIcon icon={faTrash} className="mr-2" />
                                        Delete Row
                                    </>
                                )}
                            </DeleteButton>
                        </ButtonContainer>
                    </div>
                </div>
            </GradientModal>
        </>
    );
};

export default DeleteRowModal;