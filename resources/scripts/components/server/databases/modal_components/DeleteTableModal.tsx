import React, { useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTrash, faExclamationTriangle, faTable } from '@fortawesome/free-solid-svg-icons';
import GradientModal from './GradientModal';
import { dropTable } from '@/api/server/databases/tableDataOperations';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Button } from '@/components/elements/button';
import InputField from '@/components/elements/inputs/InputField';

interface DeleteTableModalProps {
    databaseId: string;
    tableName: string;
    visible: boolean;
    onDismissed: () => void;
    onTableDeleted?: () => void;
}

const Container = styled.div`
    ${tw`p-6 bg-gradient-to-br from-gray-800 to-gray-900`};
`;

const Header = styled.div`
    ${tw`flex items-center mb-6 pb-4 border-b border-gray-600`};
`;

const IconContainer = styled.div`
    ${tw`bg-red-600 p-3 rounded-full mr-4 shadow-lg`};
`;

const WarningBox = styled.div`
    ${tw`bg-red-900 border border-red-600 rounded-lg p-4 mb-6`};
`;

const TableNameBox = styled.div`
    ${tw`bg-gray-700 border border-gray-600 rounded-lg p-3 mb-4 font-mono text-center`};
`;

const DeleteTableModal: React.FC<DeleteTableModalProps> = ({
    databaseId,
    tableName,
    visible,
    onDismissed,
    onTableDeleted
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { addFlash, clearFlashes } = useFlash();
    const [isDeleting, setIsDeleting] = useState(false);
    const [confirmationText, setConfirmationText] = useState('');
    const [foreignKeyError, setForeignKeyError] = useState<{
        error: string;
        referencingTables: string[];
    } | null>(null);
    const [showForceOption, setShowForceOption] = useState(false);

    const handleDelete = async (force = false) => {

        if (!uuid) {
            console.error('Server UUID not available');
            return;
        }
        
        if (confirmationText !== tableName) {
            return;
        }

        setIsDeleting(true);
        clearFlashes();
        setForeignKeyError(null);

        try {
            await dropTable(uuid, databaseId, tableName, force);
            onTableDeleted?.();
            onDismissed();
        } catch (error: any) {
            console.error('Error deleting table:', error);
            if (error.response?.data?.foreign_key_error) {
                setForeignKeyError({
                    error: error.response.data.error,
                    referencingTables: error.response.data.referencing_tables || []
                });
                setShowForceOption(true);
            } else {
                addFlash({
                    key: 'database:delete-table',
                    type: 'error',
                    message: httpErrorToHuman(error),
                });
            }
        } finally {
            setIsDeleting(false);
        }
    };

    const handleDismiss = () => {
        setConfirmationText('');
        onDismissed();
    };

    const isConfirmationValid = confirmationText === tableName;

    return (
        <GradientModal
            visible={visible}
            onDismissed={handleDismiss}
            size="md"
        >
            <Container>
                <Header>
                    <IconContainer>
                        <FontAwesomeIcon icon={faTrash} css={tw`text-white text-lg`} />
                    </IconContainer>
                    <div>
                        <h2 css={tw`text-2xl font-semibold text-white`}>Delete Table</h2>
                        <p css={tw`text-sm text-gray-400`}>This action cannot be undone</p>
                    </div>
                </Header>

                <FlashMessageRender byKey={'database:delete-table'} css={tw`mb-6`} />

                {foreignKeyError && (
                    <div css={tw`bg-yellow-900 border border-yellow-600 rounded-lg p-4 mb-6`}>
                        <div css={tw`flex items-start`}>
                            <FontAwesomeIcon 
                                icon={faExclamationTriangle} 
                                css={tw`text-yellow-400 text-xl mr-3 mt-1 flex-shrink-0`} 
                            />
                            <div>
                                <h3 css={tw`text-yellow-300 font-semibold mb-2`}>Foreign Key Constraint</h3>
                                <p css={tw`text-yellow-200 text-sm leading-relaxed mb-3`}>
                                    {foreignKeyError.error}
                                </p>
                                <div css={tw`text-yellow-200 text-sm`}>
                                    <strong>Referencing tables:</strong>
                                    <ul css={tw`list-disc list-inside mt-1 ml-4`}>
                                        {foreignKeyError.referencingTables.map(table => (
                                            <li key={table} css={tw`font-mono`}>{table}</li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                <WarningBox>
                    <div css={tw`flex items-start`}>
                        <FontAwesomeIcon 
                            icon={faExclamationTriangle} 
                            css={tw`text-red-400 text-xl mr-3 mt-1 flex-shrink-0`} 
                        />
                        <div>
                            <h3 css={tw`text-red-300 font-semibold mb-2`}>Warning: Permanent Deletion</h3>
                            <p css={tw`text-red-200 text-sm leading-relaxed`}>
                                You are about to permanently delete the table and <strong>all of its data</strong>. 
                                This action cannot be undone and will result in complete data loss.
                            </p>
                        </div>
                    </div>
                </WarningBox>

                <div css={tw`mb-6`}>
                    <h3 css={tw`text-white font-medium mb-3 flex items-center`}>
                        <FontAwesomeIcon icon={faTable} css={tw`mr-2 text-red-400`} />
                        Table to be deleted:
                    </h3>
                    <TableNameBox>
                        <span css={tw`text-red-300 text-lg font-semibold`}>{tableName}</span>
                    </TableNameBox>
                </div>

                <div css={tw`mb-6`}>
                    <label css={tw`block text-sm font-medium text-gray-300 mb-3`}>
                        To confirm deletion, type the table name: <span css={tw`text-red-400 font-semibold`}>{tableName}</span>
                    </label>
                    <InputField
                        type="text"
                        value={confirmationText}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) => setConfirmationText(e.target.value)}
                        placeholder={`Type "${tableName}" to confirm`}
                        css={[
                            tw`font-mono`,
                            confirmationText && !isConfirmationValid && tw`border-red-500`,
                            confirmationText && isConfirmationValid && tw`border-green-500`
                        ]}
                    />
                </div>

                <div css={tw`flex justify-end space-x-4 pt-4 border-t border-gray-600`}>
                    <Button
                        type="button"
                        variant={Button.Variants.Secondary}
                        onClick={handleDismiss}
                        disabled={isDeleting}
                    >
                        Cancel
                    </Button>
                    
                    {showForceOption && foreignKeyError && (
                        <Button.Danger
                            type="button"
                            onClick={() => handleDelete(true)}
                            disabled={!isConfirmationValid || isDeleting}
                            css={[
                                tw`bg-red-700 hover:bg-red-800`,
                                !isConfirmationValid && tw`opacity-50 cursor-not-allowed`
                            ]}
                        >
                            <FontAwesomeIcon 
                                icon={faTrash} 
                                css={[
                                    tw`mr-2`,
                                    isDeleting && tw`animate-spin`
                                ]} 
                            />
                            {isDeleting ? 'Force Deleting...' : 'Force Delete'}
                        </Button.Danger>
                    )}
                    
                    <Button.Danger
                        type="button"
                        onClick={() => handleDelete(false)}
                        disabled={!isConfirmationValid || isDeleting}
                        css={[
                            !isConfirmationValid && tw`opacity-50 cursor-not-allowed`,
                            showForceOption && tw`bg-red-600 hover:bg-red-700`
                        ]}
                    >
                        <FontAwesomeIcon 
                            icon={faTrash} 
                            css={[
                                tw`mr-2`,
                                isDeleting && tw`animate-spin`
                            ]} 
                        />
                        {isDeleting ? 'Deleting...' : 'Delete Table'}
                    </Button.Danger>
                </div>
            </Container>
        </GradientModal>
    );
};

export default DeleteTableModal;