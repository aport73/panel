import React, { useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEdit, faExclamationTriangle, faDatabase, faKey } from '@fortawesome/free-solid-svg-icons';
import { Formik, Form } from 'formik';
import * as yup from 'yup';
import { ServerContext } from '@/state/server';
import { updateTableRow } from '@/api/server/databases/tableDataOperations';
import type { UpdateRowRequest, TableColumn } from '@/api/server/databases/tableDataOperations';
import GradientModal from './GradientModal';
import Field from '@/components/elements/Field';
import { Button } from '@/components/elements/button/index';
import FlashMessageRender from '@/components/FlashMessageRender';
import { useFlashKey } from '@/plugins/useFlash';
import Spinner from '@/components/elements/Spinner';
import { sanitizeColumnValue, sanitizeDatabaseName } from '@/utils/xssProtection';

interface EditRowModalProps {
    databaseId: string;
    tableName: string;
    columns: TableColumn[];
    rowData: Record<string, any>;
    primaryKeys: string[];
    visible: boolean;
    onDismissed: () => void;
    onRowUpdated?: () => void;
}

interface FormValues {
    [key: string]: string;
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

const FormGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-6`};
`;

const FormGroup = styled.div`
    ${tw`space-y-2`};
`;

const FieldContainer = styled.div`
    ${tw`relative`};
`;

const FieldLabel = styled.label`
    ${tw`block text-sm font-medium text-gray-300 mb-2`};
`;

const FieldDescription = styled.p`
    ${tw`text-xs text-gray-500 mt-1`};
`;

const PrimaryKeyBadge = styled.span`
    ${tw`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-900 text-yellow-200 border border-yellow-700 ml-2`};
`;

const RequiredBadge = styled.span`
    ${tw`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-900 text-red-200 border border-red-700 ml-2`};
`;

const NullableBadge = styled.span`
    ${tw`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-900 text-green-200 border border-green-700 ml-2`};
`;

const ButtonGroup = styled.div`
    ${tw`flex justify-end space-x-4 mt-8 pt-4 border-t border-gray-600`};
`;

const ForeignKeyWarning = styled.div`
    ${tw`bg-gradient-to-r from-yellow-900 to-orange-900 border border-yellow-600 rounded-lg p-4 mb-6 shadow-lg`};
`;

const LoadingOverlay = styled.div`
    ${tw`absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 rounded-lg`};
`;

const EditRowModal: React.FC<EditRowModalProps> = ({
    databaseId,
    tableName,
    columns,
    rowData,
    primaryKeys,
    visible,
    onDismissed,
    onRowUpdated,
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('edit-row-modal');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [foreignKeyError, setForeignKeyError] = useState<{
        error: string;
        referencingTables?: string[];
    } | null>(null);
    const [showForceOption, setShowForceOption] = useState(false);
    const [pendingValues, setPendingValues] = useState<FormValues | null>(null);

    const validationSchema = yup.object().shape(
        columns.reduce((schema, column) => {
            if (column.type.includes('int') || column.type.includes('decimal') || column.type.includes('float')) {
                let fieldSchema = yup.number().typeError(`${column.name} must be a number`);
                if (column.nullable === false) {
                    fieldSchema = fieldSchema.required(`${column.name} is required`);
                }

                return {
                    ...schema,
                    [column.name]: fieldSchema,
                };
            } else {
                let fieldSchema = yup.string();
                if (column.nullable === false) {
                    fieldSchema = fieldSchema.required(`${column.name} is required`);
                }

                return {
                    ...schema,
                    [column.name]: fieldSchema,
                };
            }
        }, {})
    );

    const initialValues: FormValues = columns.reduce((values, column) => {
        return {
            ...values,
            [column.name]: sanitizeColumnValue(rowData[column.name]) || '',
        };
    }, {});

    const submit = async (values: FormValues, force = false) => {
        setIsSubmitting(true);
        clearFlashes();

        try {
            const primaryKeyValues = primaryKeys.reduce((acc, key) => {
                return { ...acc, [key]: rowData[key] };
            }, {});

            const changedValues = Object.entries(values).reduce((acc, [key, value]) => {
                if (value !== (rowData[key] || '')) {
                    return { ...acc, [key]: value };
                }
                return acc;
            }, {});

            const request: UpdateRowRequest = {
                primary_key_values: primaryKeyValues,
                update_data: changedValues,
                force,
            };

            await updateTableRow(uuid, databaseId, tableName, request);
            onRowUpdated?.();
            onDismissed();
            setShowForceOption(false);
            setForeignKeyError(null);
            setPendingValues(null);
        } catch (error: any) {
            if (error.response?.data?.foreign_key_error && !force) {
                setForeignKeyError({
                    error: error.response.data.error,
                    referencingTables: error.response.data.referencing_tables || []
                });
                setPendingValues(values);
                setShowForceOption(true);
            } else {
                clearAndAddHttpError(error as Error);
            }
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleForceUpdate = () => {
        if (pendingValues) {
            submit(pendingValues, true);
        }
    };

    const handleDismiss = () => {
        clearFlashes();
        setForeignKeyError(null);
        setShowForceOption(false);
        setPendingValues(null);
        onDismissed();
    };

    return (
        <GradientModal
            visible={visible}
            onDismissed={handleDismiss}
            size="lg"
        >
            <Container>
                {isSubmitting && (
                    <LoadingOverlay>
                        <div css={tw`flex flex-col items-center`}>
                            <Spinner size="large" />
                            <p css={tw`text-white mt-4 text-lg font-medium`}>Updating Row...</p>
                            <p css={tw`text-gray-300 text-sm mt-1`}>Please wait while we save your changes</p>
                        </div>
                    </LoadingOverlay>
                )}

                <Header>
                    <IconContainer>
                        <FontAwesomeIcon icon={faEdit} css={tw`text-white text-lg`} />
                    </IconContainer>
                    <div>
                        <h2 css={tw`text-2xl font-semibold text-white`}>Edit Row</h2>
                        <p css={tw`text-sm text-gray-400`}>Modify data in {sanitizeDatabaseName(tableName)} table</p>
                    </div>
                </Header>

                <FlashMessageRender byKey="edit-row-modal" css={tw`mb-6`} />

                {foreignKeyError && (
                    <ForeignKeyWarning>
                        <div css={tw`flex items-start`}>
                            <FontAwesomeIcon
                                icon={faExclamationTriangle}
                                css={tw`text-yellow-400 text-xl mr-3 mt-1 flex-shrink-0`}
                            />
                            <div css={tw`flex-1`}>
                                <h3 css={tw`text-yellow-300 font-semibold mb-2`}>Foreign Key Constraint Issue</h3>
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
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setForeignKeyError(null);
                                            setShowForceOption(false);
                                            setPendingValues(null);
                                        }}
                                        css={tw`px-3 py-1.5 text-sm bg-gray-600 hover:bg-gray-700 text-white rounded transition-colors`}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={handleForceUpdate}
                                        disabled={isSubmitting}
                                        css={tw`px-3 py-1.5 text-sm bg-red-600 hover:bg-red-700 text-white rounded transition-colors`}
                                    >
                                        Force Update
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </ForeignKeyWarning>
                )}

                <Formik
                    onSubmit={(values) => submit(values, false)}
                    initialValues={initialValues}
                    validationSchema={validationSchema}
                >
                    {({ values, isSubmitting: formSubmitting }) => (
                        <Form>
                            <FormGrid>
                                {columns.map((column) => {
                                    const isPrimaryKey = primaryKeys.includes(column.name);
                                    const inputType = getInputType(column.type);

                                    return (
                                        <FormGroup key={column.name}>
                                            <FieldContainer>
                                                <FieldLabel>
                                                    {column.name}
                                                    {isPrimaryKey && (
                                                        <PrimaryKeyBadge>
                                                            <FontAwesomeIcon icon={faKey} css={tw`mr-1`} />
                                                            PK
                                                        </PrimaryKeyBadge>
                                                    )}
                                                    {!column.nullable && !isPrimaryKey && (
                                                        <RequiredBadge>Required</RequiredBadge>
                                                    )}
                                                    {column.nullable && !isPrimaryKey && (
                                                        <NullableBadge>Nullable</NullableBadge>
                                                    )}
                                                </FieldLabel>
                                                <Field
                                                    name={column.name}
                                                    type={inputType}
                                                    placeholder={isPrimaryKey ? 'Primary key (read-only)' : `Enter ${column.name}`}
                                                    disabled={isPrimaryKey}
                                                />
                                                <FieldDescription>
                                                    Type: {column.type.toUpperCase()}
                                                    {isPrimaryKey && ' • Primary Key (cannot be modified)'}
                                                </FieldDescription>
                                            </FieldContainer>
                                        </FormGroup>
                                    );
                                })}
                            </FormGrid>

                            <ButtonGroup>
                                <Button
                                    type="button"
                                    onClick={handleDismiss}
                                    css={tw`px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors`}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={isSubmitting || formSubmitting}
                                    css={tw`px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed`}
                                >
                                    <FontAwesomeIcon icon={faDatabase} css={tw`mr-2`} />
                                    {isSubmitting || formSubmitting ? 'Updating...' : 'Update Row'}
                                </Button>
                            </ButtonGroup>
                        </Form>
                    )}
                </Formik>
            </Container>
        </GradientModal>
    );
};

function getInputType(dataType: string): string {
    const safeDataType = dataType || '';
    if (safeDataType.includes('int') || safeDataType.includes('decimal') || safeDataType.includes('float')) {
        return 'number';
    }
    if (safeDataType.includes('date')) {
        return 'datetime-local';
    }
    if (safeDataType.includes('enum') || safeDataType.includes('set')) {
        return 'select';
    }
    return 'text';
}

export default EditRowModal;