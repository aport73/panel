import React, { useState } from 'react';
import { Form, Formik, FormikHelpers } from 'formik';
import { object, string, array, boolean } from 'yup';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTable, faPlus, faTimes, faKey, faDatabase } from '@fortawesome/free-solid-svg-icons';
import GradientModal from './GradientModal';
import { createTable, TableColumn } from '@/api/server/databases/tableDataOperations';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Button } from '@/components/elements/button';
import Field from '@/components/elements/Field';
import Select from '@/components/elements/Select';
import FormikSwitch from '@/components/elements/FormikSwitch';

interface CreateTableModalProps {
    databaseId: string;
    visible: boolean;
    onDismissed: () => void;
    onTableCreated?: () => void;
}

interface FormValues {
    tableName: string;
    columns: ColumnFormData[];
}

interface ColumnFormData {
    name: string;
    type: string;
    length: string;
    precision: string;
    enumValues: string[];
    setValues: string[];
    nullable: boolean;
    default: string;
    autoIncrement: boolean;
    primaryKey: boolean;
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

const ColumnContainer = styled.div`
    ${tw`bg-gray-700 rounded-lg p-4 mb-4 border border-gray-600`};
`;

const ColumnHeader = styled.div`
    ${tw`flex items-center justify-between mb-3`};
`;

const ColumnGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4`};
`;

const AddColumnButton = styled.button`
    ${tw`w-full p-3 border-2 border-dashed border-gray-500 rounded-lg text-gray-400 hover:border-blue-500 hover:text-blue-400 transition-all duration-200 flex items-center justify-center`};
`;

const RemoveColumnButton = styled.button`
    ${tw`text-red-400 hover:text-red-300 p-1 rounded transition-colors duration-200`};
`;
const DATA_TYPES = {
    numeric: [
        { value: 'TINYINT', label: 'TINYINT', description: 'Small integers (-128 to 127)', needsLength: false },
        { value: 'SMALLINT', label: 'SMALLINT', description: 'Larger than TINYINT', needsLength: false },
        { value: 'MEDIUMINT', label: 'MEDIUMINT', description: 'Medium range', needsLength: false },
        { value: 'INT', label: 'INT', description: 'Standard integer', needsLength: false },
        { value: 'BIGINT', label: 'BIGINT', description: 'Very large numbers', needsLength: false },
        { value: 'DECIMAL', label: 'DECIMAL', description: 'Fixed-point for money', needsLength: true, needsPrecision: true },
        { value: 'FLOAT', label: 'FLOAT', description: 'Approximate decimals', needsLength: false },
        { value: 'DOUBLE', label: 'DOUBLE', description: 'Double precision', needsLength: false },
        { value: 'BIT', label: 'BIT', description: 'Binary flags', needsLength: true }
    ],
    datetime: [
        { value: 'DATE', label: 'DATE', description: 'YYYY-MM-DD', needsLength: false },
        { value: 'TIME', label: 'TIME', description: 'HH:MM:SS', needsLength: false },
        { value: 'DATETIME', label: 'DATETIME', description: 'Date + time', needsLength: false },
        { value: 'TIMESTAMP', label: 'TIMESTAMP', description: 'Date + time, auto UTC', needsLength: false },
        { value: 'YEAR', label: 'YEAR', description: '4-digit year', needsLength: false }
    ],
    string: [
        { value: 'CHAR', label: 'CHAR', description: 'Fixed length (n ≤ 255)', needsLength: true },
        { value: 'VARCHAR', label: 'VARCHAR', description: 'Variable (n ≤ 65535)', needsLength: true },
        { value: 'TEXT', label: 'TEXT', description: 'Up to 64KB', needsLength: false },
        { value: 'TINYTEXT', label: 'TINYTEXT', description: 'Up to 255 bytes', needsLength: false },
        { value: 'MEDIUMTEXT', label: 'MEDIUMTEXT', description: 'Up to 16MB', needsLength: false },
        { value: 'LONGTEXT', label: 'LONGTEXT', description: 'Up to 4GB', needsLength: false }
    ],
    binary: [
        { value: 'BLOB', label: 'BLOB', description: 'Binary data', needsLength: false },
        { value: 'TINYBLOB', label: 'TINYBLOB', description: 'Small binary', needsLength: false },
        { value: 'MEDIUMBLOB', label: 'MEDIUMBLOB', description: 'Medium binary', needsLength: false },
        { value: 'LONGBLOB', label: 'LONGBLOB', description: 'Large binary', needsLength: false }
    ],
    special: [
        { value: 'BOOLEAN', label: 'BOOLEAN', description: 'True/False', needsLength: false },
        { value: 'JSON', label: 'JSON', description: 'JSON data', needsLength: false },
        { value: 'ENUM', label: 'ENUM', description: 'Predefined options', needsEnum: true },
        { value: 'SET', label: 'SET', description: 'Multi-choice set', needsSet: true }
    ]
};

const ALL_DATA_TYPES = [
    ...DATA_TYPES.numeric,
    ...DATA_TYPES.datetime,
    ...DATA_TYPES.string,
    ...DATA_TYPES.binary,
    ...DATA_TYPES.special
];
const dataTypes = ALL_DATA_TYPES.map(type => type.value);

const validationSchema = object().shape({
    tableName: string()
        .required('Table name is required')
        .matches(/^[a-zA-Z_][a-zA-Z0-9_]*$/, 'Table name must start with a letter or underscore and contain only letters, numbers, and underscores'),
    columns: array().min(1, 'At least one column is required')
});

const CreateTableModal: React.FC<CreateTableModalProps> = ({
    databaseId,
    visible,
    onDismissed,
    onTableCreated
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { addFlash, clearFlashes } = useFlash();
    const [isCreating, setIsCreating] = useState(false); 

    const initialColumn: ColumnFormData = {
        name: 'id',
        type: 'INT',
        length: '',
        precision: '',
        enumValues: [],
        setValues: [],
        nullable: false,
        default: '',
        autoIncrement: true,
        primaryKey: true
    };

    const initialValues: FormValues = {
        tableName: '',
        columns: [initialColumn]
    };

    const handleSubmit = async (values: FormValues) => {

        if (!uuid) {
            console.error('CreateTableModal: Server UUID not available');
            return;
        }

        setIsCreating(true);
        clearFlashes('database:create-table');

        const requestData = {
            table_name: values.tableName,
            columns: values.columns.map(col => {
                const processedColumn: any = {
                    name: col.name,
                    type: col.type,
                    nullable: Boolean(col.nullable),
                    auto_increment: Boolean(col.autoIncrement),
                    primary_key: Boolean(col.primaryKey)
                };

                if (col.length && col.length.trim() !== '') {
                    const lengthValue = parseInt(col.length);
                    if (!isNaN(lengthValue) && lengthValue > 0) {
                        processedColumn.length = lengthValue;
                    }
                }

                if (col.precision && col.precision.trim() !== '') {
                    const precisionValue = parseInt(col.precision);
                    if (!isNaN(precisionValue) && precisionValue >= 0) {
                        processedColumn.precision = precisionValue;
                    }
                }

                if (col.type === 'ENUM' && col.enumValues && col.enumValues.length > 0) {
                    processedColumn.enum_values = col.enumValues.filter(v => v.trim() !== '');
                }

                if (col.type === 'SET' && col.setValues && col.setValues.length > 0) {
                    processedColumn.set_values = col.setValues.filter(v => v.trim() !== '');
                }

                if (col.default && col.default.trim() !== '') {
                    const defaultValue = col.default.trim();
                    const columnType = (col.type || '').toUpperCase();

                    switch (columnType) {
                        case 'BOOLEAN':
                        case 'BOOL':
                            if (defaultValue.toLowerCase() === 'true' || defaultValue === '1') {
                                processedColumn.default = 'true';
                            } else if (defaultValue.toLowerCase() === 'false' || defaultValue === '0') {
                                processedColumn.default = 'false';
                            } else {
                                processedColumn.default = 'false'; 
                            }
                            break;

                        case 'INT':
                        case 'BIGINT':
                        case 'SMALLINT':
                        case 'TINYINT':
                        case 'MEDIUMINT':
                            if (!isNaN(Number(defaultValue))) {
                                processedColumn.default = defaultValue;
                            }
                            break;

                        case 'DECIMAL':
                        case 'FLOAT':
                        case 'DOUBLE':
                            if (!isNaN(Number(defaultValue))) {
                                processedColumn.default = defaultValue;
                            }
                            break;

                        case 'JSON':
                            try {
                                JSON.parse(defaultValue);
                                processedColumn.default = defaultValue;
                            } catch (e) {
                                console.warn('Invalid JSON default value:', defaultValue);
                            }
                            break;

                        default:
                            processedColumn.default = defaultValue;
                            break;
                    }
                }

                return processedColumn;
            })
        };

        try {
            await createTable(uuid, databaseId, requestData);
            
            onTableCreated?.();
            onDismissed();
        } catch (error: any) {
            console.error('CreateTableModal: Error creating table', {
                error,
                errorMessage: error?.message,
                errorResponse: error?.response?.data,
                errorStatus: error?.response?.status,
                requestData
            });

            addFlash({
                key: 'database:create-table',
                type: 'error',
                message: httpErrorToHuman(error),
            });
        } finally {
            setIsCreating(false);
        }
    };

    const addColumn = (values: FormValues, setFieldValue: any) => {
        const newColumn: ColumnFormData = {
            name: '',
            type: 'VARCHAR',
            length: '255',
            precision: '',
            enumValues: [],
            setValues: [],
            nullable: true,
            default: '',
            autoIncrement: false,
            primaryKey: false
        };
        setFieldValue('columns', [...values.columns, newColumn]);
    };

    const removeColumn = (index: number, values: FormValues, setFieldValue: any) => {
        if (values.columns.length > 1) {
            const newColumns = values.columns.filter((_, i) => i !== index);
            setFieldValue('columns', newColumns);
        }
    };

    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size="xl"
        >
            <Container>
                <Header>
                    <IconContainer>
                        <FontAwesomeIcon icon={faTable} css={tw`text-white text-lg`} />
                    </IconContainer>
                    <div>
                        <h2 css={tw`text-2xl font-semibold text-white`}>Create New Table</h2>
                        <p css={tw`text-sm text-gray-400`}>Define your table structure and columns</p>
                    </div>
                </Header>

                <FlashMessageRender byKey={'database:create-table'} css={tw`mb-6`} />

                <Formik
                    initialValues={initialValues}
                    validationSchema={validationSchema}
                    onSubmit={handleSubmit} 
                >
                    {({ values, setFieldValue, isSubmitting }) => (
                        <Form>
                            <div css={tw`mb-6`}>
                                <Field
                                    type="string"
                                    id="tableName"
                                    name="tableName"
                                    label="Table Name"
                                    description="Enter a unique name for your table"
                                />
                            </div>

                            <div css={tw`mb-6`}>
                                <h3 css={tw`text-lg font-medium text-white mb-4 flex items-center`}>
                                    <FontAwesomeIcon icon={faDatabase} css={tw`mr-2 text-blue-400`} />
                                    Columns
                                </h3>

                                {values.columns.map((column, index) => (
                                    <ColumnContainer key={index}>
                                        <ColumnHeader>
                                            <div css={tw`flex items-center`}>
                                                <span css={tw`text-white font-medium`}>Column {index + 1}</span>
                                                {column.primaryKey && (
                                                    <FontAwesomeIcon
                                                        icon={faKey}
                                                        css={tw`ml-2 text-yellow-400`}
                                                        title="Primary Key"
                                                    />
                                                )}
                                            </div>
                                            {values.columns.length > 1 && (
                                                <RemoveColumnButton
                                                    type="button"
                                                    onClick={() => removeColumn(index, values, setFieldValue)}
                                                >
                                                    <FontAwesomeIcon icon={faTimes} />
                                                </RemoveColumnButton>
                                            )}
                                        </ColumnHeader>

                                        <ColumnGrid>
                                            <Field
                                                type="string"
                                                id={`columns.${index}.name`}
                                                name={`columns.${index}.name`}
                                                label="Column Name"
                                            />

                                            <div>
                                                <label css={tw`block text-sm font-medium text-gray-300 mb-2`}>
                                                    Data Type
                                                </label>
                                                <Select
                                                    name={`columns.${index}.type`}
                                                    value={column.type}
                                                    onChange={(e) => {
                                                        const newType = e.target.value;
                                                        setFieldValue(`columns.${index}.type`, newType);
                                                        if (newType !== 'ENUM') {
                                                            setFieldValue(`columns.${index}.enumValues`, []);
                                                        }
                                                        if (newType !== 'SET') {
                                                            setFieldValue(`columns.${index}.setValues`, []);
                                                        }
                                                        if (newType !== 'DECIMAL') {
                                                            setFieldValue(`columns.${index}.precision`, '');
                                                        }
                                                        if (!['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(newType)) {
                                                            setFieldValue(`columns.${index}.autoIncrement`, false);
                                                        }
                                                    }}
                                                >
                                                    <optgroup label="🔢 Numeric Types">
                                                        {DATA_TYPES.numeric.map(type => (
                                                            <option key={type.value} value={type.value} title={type.description}>
                                                                {type.label}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                    <optgroup label="⏳ Date & Time Types">
                                                        {DATA_TYPES.datetime.map(type => (
                                                            <option key={type.value} value={type.value} title={type.description}>
                                                                {type.label}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                    <optgroup label="🔤 String Types">
                                                        {DATA_TYPES.string.map(type => (
                                                            <option key={type.value} value={type.value} title={type.description}>
                                                                {type.label}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                    <optgroup label="📁 Binary Types">
                                                        {DATA_TYPES.binary.map(type => (
                                                            <option key={type.value} value={type.value} title={type.description}>
                                                                {type.label}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                    <optgroup label="⚡ Special Types">
                                                        {DATA_TYPES.special.map(type => (
                                                            <option key={type.value} value={type.value} title={type.description}>
                                                                {type.label}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                </Select>
                                            </div>
                                            {ALL_DATA_TYPES.find(t => t.value === column.type)?.needsLength && (
                                                <Field
                                                    type="string"
                                                    id={`columns.${index}.length`}
                                                    name={`columns.${index}.length`}
                                                    label="Length"
                                                    placeholder={
                                                        column.type === 'VARCHAR' ? '255' :
                                                            column.type === 'CHAR' ? '50' :
                                                                column.type === 'DECIMAL' ? '10' :
                                                                    column.type === 'BIT' ? '1' : ''
                                                    }
                                                />
                                            )}
                                            {column.type === 'DECIMAL' && (
                                                <Field
                                                    type="string"
                                                    id={`columns.${index}.precision`}
                                                    name={`columns.${index}.precision`}
                                                    label="Precision (Decimal Places)"
                                                    placeholder="2"
                                                />
                                            )}
                                            {column.type === 'ENUM' && (
                                                <div>
                                                    <label css={tw`block text-sm font-medium text-gray-300 mb-2`}>
                                                        ENUM Values (one per line)
                                                    </label>
                                                    <textarea
                                                        css={tw`w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none`}
                                                        rows={4}
                                                        placeholder="option1&#10;option2&#10;option3"
                                                        value={column.enumValues.join('\n')}
                                                        onChange={(e) => {
                                                            const values = e.target.value.split('\n').filter(v => v.trim() !== '');
                                                            setFieldValue(`columns.${index}.enumValues`, values);
                                                        }}
                                                    />
                                                </div>
                                            )}
                                            {column.type === 'SET' && (
                                                <div>
                                                    <label css={tw`block text-sm font-medium text-gray-300 mb-2`}>
                                                        SET Values (one per line)
                                                    </label>
                                                    <textarea
                                                        css={tw`w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none`}
                                                        rows={4}
                                                        placeholder="tag1&#10;tag2&#10;tag3"
                                                        value={column.setValues.join('\n')}
                                                        onChange={(e) => {
                                                            const values = e.target.value.split('\n').filter(v => v.trim() !== '');
                                                            setFieldValue(`columns.${index}.setValues`, values);
                                                        }}
                                                    />
                                                </div>
                                            )}

                                            <Field
                                                type="string"
                                                id={`columns.${index}.default`}
                                                name={`columns.${index}.default`}
                                                label="Default Value"
                                                placeholder={values.columns[index].autoIncrement ? "Not allowed for AUTO_INCREMENT" : "Optional"}
                                                disabled={values.columns[index].autoIncrement}
                                                css={[
                                                    values.columns[index].autoIncrement && tw`opacity-50 cursor-not-allowed`
                                                ]}
                                            />

                                            <div css={tw`flex flex-col space-y-3`}>
                                                <FormikSwitch
                                                    name={`columns.${index}.nullable`}
                                                    label="Nullable"
                                                    description="Allow NULL values"
                                                />

                                                <div css={[
                                                    !['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(column.type) && tw`opacity-50 pointer-events-none`
                                                ]}>
                                                    <FormikSwitch
                                                        name={`columns.${index}.autoIncrement`}
                                                        label="Auto Increment"
                                                        description={
                                                            !['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(column.type)
                                                                ? "Only available for integer types"
                                                                : "Automatically increment value"
                                                        }
                                                        onChange={(e) => {
                                                            if (!['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(column.type)) {
                                                                return;
                                                            }
                                                            
                                                            const isChecked = e.target.checked;
                                                            setFieldValue(`columns.${index}.autoIncrement`, isChecked);
                                                            
                                                            if (isChecked) {
                                                                setFieldValue(`columns.${index}.default`, '');
                                                                setFieldValue(`columns.${index}.primaryKey`, true);
                                                                values.columns.forEach((col, colIndex) => {
                                                                    if (colIndex !== index && col.autoIncrement) {
                                                                        setFieldValue(`columns.${colIndex}.autoIncrement`, false);
                                                                    }
                                                                });
                                                            }
                                                        }}
                                                    />
                                                </div>

                                                <FormikSwitch
                                                    name={`columns.${index}.primaryKey`}
                                                    label="Primary Key"
                                                    description="Set as primary key"
                                                />
                                            </div>
                                        </ColumnGrid>
                                    </ColumnContainer>
                                ))}

                                <AddColumnButton
                                    type="button"
                                    onClick={() => addColumn(values, setFieldValue)}
                                >
                                    <FontAwesomeIcon icon={faPlus} css={tw`mr-2`} />
                                    Add Column
                                </AddColumnButton>
                            </div>

                            <div css={tw`flex justify-end space-x-4 pt-4 border-t border-gray-600`}>
                                <Button
                                    type="button"
                                    variant={Button.Variants.Secondary}
                                    onClick={onDismissed}
                                    disabled={isSubmitting}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant={Button.Variants.Primary}
                                    disabled={isCreating} 
                                >
                                    <FontAwesomeIcon
                                        icon={faTable}
                                        css={[
                                            tw`mr-2`,
                                            isCreating && tw`animate-spin` 
                                        ]}
                                    />
                                    {isCreating ? 'Creating...' : 'Create Table'} 
                                </Button>
                            </div>
                        </Form>
                    )}
                </Formik>
            </Container>
        </GradientModal>
    );
};

export default CreateTableModal;