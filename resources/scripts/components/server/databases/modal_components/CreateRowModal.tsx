import React, { useState, useContext } from 'react';
import { Formik, Form } from 'formik';
import * as yup from 'yup';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus, faDatabase, faKey, faHashtag, faCalendarAlt, faFont, faToggleOn, faExclamationTriangle, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';
import { insertTableRow } from '@/api/server/databases/tableDataOperations';
import type { InsertRowRequest, TableColumn } from '@/api/server/databases/tableDataOperations';
import GradientModal from './GradientModal';
import Field from '@/components/elements/Field';
import { Button } from '@/components/elements/button/index';
import FlashMessageRender from '@/components/FlashMessageRender';
import { useFlashKey } from '@/plugins/useFlash';
import Spinner from '@/components/elements/Spinner';
import styled from 'styled-components';
import tw from 'twin.macro';

interface CreateRowModalProps {
    databaseId: string;
    tableName: string;
    columns: TableColumn[];
    visible: boolean;
    onDismissed: () => void;
    onRowCreated?: () => void;
}

interface FormValues {
    [key: string]: string;
}

const ModalHeader = styled.div`
    ${tw`p-6 bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 border-b border-gray-700`};
    background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 50%, #1a1a1a 100%);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
`;

const HeaderIcon = styled.div`
    ${tw`w-12 h-12 bg-gradient-to-br from-blue-600 to-purple-700 rounded-xl flex items-center justify-center mr-4 shadow-lg`};
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
    transition: all 0.3s ease;
    
    &:hover {
        transform: scale(1.05) rotate(5deg);
        box-shadow: 0 12px 35px rgba(59, 130, 246, 0.4);
    }
`;

const ModalBody = styled.div`
    ${tw`p-6`};
    background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 50%, #0f0f0f 100%);
    min-height: 400px;
`;

const FieldGrid = styled.div`
    ${tw`grid gap-6 mb-8`};
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    max-height: 60vh;
    overflow-y: auto;
    padding-right: 8px;
    
    &::-webkit-scrollbar {
        width: 6px;
    }
    
    &::-webkit-scrollbar-track {
        background: #1a1a1a;
        border-radius: 3px;
    }
    
    &::-webkit-scrollbar-thumb {
        background: linear-gradient(to bottom, #4f46e5, #7c3aed);
        border-radius: 3px;
    }
    
    &::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(to bottom, #6366f1, #8b5cf6);
    }
`;

const FieldContainer = styled.div`
    ${tw`bg-gradient-to-br from-gray-800 to-gray-900 p-5 rounded-xl border border-gray-700 transition-all duration-300`};
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    
    &:hover {
        ${tw`border-gray-600 shadow-2xl`};
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
    }
`;

const FieldHeader = styled.div`
    ${tw`flex items-center mb-3 pb-2 border-b border-gray-700`};
`;

const FieldIcon = styled.div<{ type: string }>`
    ${tw`w-8 h-8 rounded-lg flex items-center justify-center mr-3 text-white text-sm transition-all duration-300`};
    background: ${props => getFieldIconColor(props.type)};
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    
    &:hover {
        transform: scale(1.1) rotate(10deg);
    }
`;

const FieldLabel = styled.label`
    ${tw`text-sm font-semibold text-gray-200 flex-1`};
`;

const FieldMeta = styled.div`
    ${tw`flex items-center space-x-2 mt-2`};
`;

const MetaBadge = styled.span<{ variant: 'type' | 'nullable' | 'auto' | 'primary' }>`
    ${tw`px-2 py-1 rounded-md text-xs font-medium transition-all duration-200`};
    ${props => {
        switch (props.variant) {
            case 'type':
                return tw`bg-blue-900 text-blue-200 border border-blue-700`;
            case 'nullable':
                return tw`bg-green-900 text-green-200 border border-green-700`;
            case 'auto':
                return tw`bg-purple-900 text-purple-200 border border-purple-700`;
            case 'primary':
                return tw`bg-yellow-900 text-yellow-200 border border-yellow-700`;
            default:
                return tw`bg-gray-700 text-gray-300 border border-gray-600`;
        }
    }}
    
    &:hover {
        transform: scale(1.05);
    }
`;

const ActionButtons = styled.div`
    ${tw`flex justify-end space-x-4 pt-6 border-t border-gray-700`};
    background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 50%, #1a1a1a 100%);
    margin: 0 -24px -24px -24px;
    padding: 24px;
`;

const StyledButton = styled(Button)`
    ${tw`transition-all duration-300 font-semibold`};
    
    &.primary {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        border: 1px solid #6366f1;
        box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        
        &:hover {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }
    }
    
    &.secondary {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
        border: 1px solid #6b7280;
        
        &:hover {
            background: linear-gradient(135deg, #4b5563 0%, #6b7280 100%);
            transform: translateY(-1px);
        }
    }
`;

function getFieldIconColor(type: string): string {
    const safeType = type || '';
    if (safeType.includes('int') || safeType.includes('decimal') || safeType.includes('float')) {
        return 'linear-gradient(135deg, #059669 0%, #10b981 100%)';
    }
    if (safeType.includes('date') || safeType.includes('time')) {
        return 'linear-gradient(135deg, #dc2626 0%, #ef4444 100%)';
    }
    if (safeType.includes('varchar') || safeType.includes('text')) {
        return 'linear-gradient(135deg, #7c3aed 0%, #a855f7 100%)';
    }
    if (safeType.includes('boolean')) {
        return 'linear-gradient(135deg, #ea580c 0%, #f97316 100%)';
    }
    return 'linear-gradient(135deg, #4f46e5 0%, #6366f1 100%)';
}

function getFieldIcon(type: string, isAutoIncrement: boolean, isPrimaryKey: boolean): any {
    const safeType = type || '';
    if (isPrimaryKey) return faKey;
    if (isAutoIncrement) return faHashtag;
    if (safeType.includes('int') || safeType.includes('decimal') || safeType.includes('float')) return faHashtag;
    if (safeType.includes('date') || safeType.includes('time')) return faCalendarAlt;
    if (safeType.includes('boolean')) return faToggleOn;
    return faFont;
}

const CreateRowModal: React.FC<CreateRowModalProps> = ({
    databaseId,
    tableName,
    columns,
    visible,
    onDismissed,
    onRowCreated,
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('create-row-modal');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const validationSchema = yup.object().shape(
        columns.reduce((schema, column) => {
            const columnType = column.type || '';
            if (columnType.includes('int') || columnType.includes('decimal') || columnType.includes('float')) {
                let fieldSchema = yup.number().typeError(`${column.name} must be a number`);
                if (column.nullable === false && !column.default && !column.auto_increment) {
                    fieldSchema = fieldSchema.required(`${column.name} is required`);
                }
                
                return {
                    ...schema,
                    [column.name]: fieldSchema,
                };
            } else {
                let fieldSchema = yup.string();
                if (column.nullable === false && !column.default && !column.auto_increment) {
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
        let defaultValue = '';
        if (column.default && column.default !== 'NULL' && column.default !== 'null') {
            defaultValue = column.default;
        }
        
        return {
            ...values,
            [column.name]: defaultValue,
        };
    }, {});

    const submit = async (values: FormValues) => {
        setIsSubmitting(true);
        clearFlashes();

        try {
            const filteredValues = Object.entries(values).reduce((acc, [key, value]) => {
                const column = columns.find(col => col.name === key);
                if (column?.auto_increment && !value) {
                    return acc; 
                }
                return { ...acc, [key]: value };
            }, {});

            const request: InsertRowRequest = {
                row_data: filteredValues,
            };

            await insertTableRow(uuid, databaseId, tableName, request);
            onRowCreated?.();
            onDismissed();
        } catch (error) {
            clearAndAddHttpError(error as Error);
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size="xl"
        >
            {isSubmitting && (
                <div className="absolute inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 rounded-2xl">
                    <div className="flex flex-col items-center space-y-4">
                        <Spinner size="large" />
                        <p className="text-white font-medium">Creating new row...</p>
                    </div>
                </div>
            )}
            
            <ModalHeader>
                <div className="flex items-center">
                    <HeaderIcon>
                        <FontAwesomeIcon icon={faPlus} className="text-white" size="lg" />
                    </HeaderIcon>
                    <div className="flex-1">
                        <h2 className="text-2xl font-bold text-white mb-1">
                            Create New Row
                        </h2>
                        <div className="flex items-center space-x-2">
                            <FontAwesomeIcon icon={faDatabase} className="text-blue-400" />
                            <p className="text-gray-300 font-medium">
                                Adding to table: <span className="text-blue-400 font-semibold">{tableName}</span>
                            </p>
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-sm text-gray-400">{columns.length} columns</p>
                        <p className="text-xs text-gray-500">Fill required fields</p>
                    </div>
                </div>
            </ModalHeader>

            <ModalBody>
                <FlashMessageRender byKey="create-row-modal" className="mb-6" />
                
                <Formik
                    onSubmit={submit}
                    initialValues={initialValues}
                    validationSchema={validationSchema}
                >
                    <Form className="m-0">
                        <FieldGrid>
                            {columns.map((column) => {
                                const isAutoIncrement = column.auto_increment;
                                const isPrimaryKey = column.primary_key;
                                const inputType = getInputType(column.type);
                                const fieldIcon = getFieldIcon(column.type, isAutoIncrement, isPrimaryKey);
                                
                                return (
                                    <FieldContainer key={column.name}>
                                        <FieldHeader>
                                            <FieldIcon type={column.type}>
                                                <FontAwesomeIcon icon={fieldIcon} />
                                            </FieldIcon>
                                            <FieldLabel>
                                                {column.name}
                                                {!column.nullable && !column.default && !isAutoIncrement && (
                                                    <FontAwesomeIcon 
                                                        icon={faExclamationTriangle} 
                                                        className="ml-2 text-red-400" 
                                                        size="xs"
                                                        title="Required field"
                                                    />
                                                )}
                                            </FieldLabel>
                                        </FieldHeader>
                                        <Field
                                            name={column.name}
                                            type={inputType}
                                            placeholder={
                                                isAutoIncrement 
                                                    ? 'Auto-generated' 
                                                    : column.nullable 
                                                        ? `Enter ${column.name} (optional)` 
                                                        : `Enter ${column.name} (required)`
                                            }
                                            disabled={isAutoIncrement}
                                            className={`transition-all duration-300 ${
                                                isAutoIncrement 
                                                    ? 'bg-gray-800 border-gray-600 text-gray-500' 
                                                    : 'bg-gray-900 border-gray-600 text-white hover:border-blue-500 focus:border-blue-400'
                                            }`}
                                        />
                                        
                                        <FieldMeta>
                                            <MetaBadge variant="type">
                                                <FontAwesomeIcon icon={faInfoCircle} className="mr-1" size="xs" />
                                                {column.type?.toUpperCase() || 'UNKNOWN'}
                                            </MetaBadge>
                                            
                                            {column.nullable && (
                                                <MetaBadge variant="nullable">
                                                    NULLABLE
                                                </MetaBadge>
                                            )}
                                            
                                            {isAutoIncrement && (
                                                <MetaBadge variant="auto">
                                                    AUTO INCREMENT
                                                </MetaBadge>
                                            )}
                                            
                                            {isPrimaryKey && (
                                                <MetaBadge variant="primary">
                                                    <FontAwesomeIcon icon={faKey} className="mr-1" size="xs" />
                                                    PRIMARY KEY
                                                </MetaBadge>
                                            )}
                                        </FieldMeta>
                                    </FieldContainer>
                                );
                            })}
                        </FieldGrid>
                        
                        <ActionButtons>
                            <StyledButton 
                                type="button" 
                                onClick={onDismissed}
                                disabled={isSubmitting}
                                variant={Button.Variants.Secondary}
                                className="secondary"
                            >
                                Cancel
                            </StyledButton>
                            <StyledButton 
                                type="submit" 
                                disabled={isSubmitting}
                                variant={Button.Variants.Primary}
                                className="primary"
                            >
                                <FontAwesomeIcon 
                                    icon={faPlus} 
                                    className={`mr-2 ${isSubmitting ? 'animate-spin' : ''}`}
                                />
                                {isSubmitting ? 'Creating...' : 'Create Row'}
                            </StyledButton>
                        </ActionButtons>
                    </Form>
                </Formik>
            </ModalBody>
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

export default CreateRowModal;