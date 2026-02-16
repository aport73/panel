import React, { useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus, faColumns } from '@fortawesome/free-solid-svg-icons';
import { Formik, Form } from 'formik';
import { object, string } from 'yup';
import GradientModal from './GradientModal';
import { addColumn, AddColumnRequest } from '@/api/server/databases/tableDataOperations';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Button } from '@/components/elements/button';
import Field from '@/components/elements/Field';
import Select from '@/components/elements/Select';
import FormikSwitch from '@/components/elements/FormikSwitch';

interface AddColumnModalProps {
    databaseId: string;
    tableName: string;
    visible: boolean;
    onDismissed: () => void;
    onColumnAdded?: () => void;
    existingColumns: string[];
}

interface FormValues {
    name: string;
    type: string;
    length: string;
    precision: string;
    enumValues: string[];
    setValues: string[];
    nullable: boolean;
    default: string;
    auto_increment: boolean;
    primary_key: boolean;
    after: string;
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

const FormGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-6`};
`;

const FormGroup = styled.div`
    ${tw`space-y-2`};
`;

const Label = styled.label`
    ${tw`block text-sm font-medium text-gray-300`};
`;

const CheckboxGroup = styled.div`
    ${tw`flex items-center space-x-2`};
`;

const dataTypes = ALL_DATA_TYPES.map(type => type.value);

const validationSchema = object().shape({
    name: string()
        .required('Column name is required')
        .matches(/^[a-zA-Z_][a-zA-Z0-9_]*$/, 'Column name must start with a letter or underscore and contain only letters, numbers, and underscores'),
    type: string().required('Data type is required')
});

const AddColumnModal: React.FC<AddColumnModalProps> = ({
    databaseId,
    tableName,
    visible,
    onDismissed,
    onColumnAdded,
    existingColumns
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { addFlash, clearFlashes } = useFlash();
    const [isAdding, setIsAdding] = useState(false);
    const [hasAutoIncrement, setHasAutoIncrement] = useState(false);

    const initialValues: FormValues = {
        name: '',
        type: 'VARCHAR',
        length: '',
        precision: '',
        enumValues: [],
        setValues: [],
        nullable: true,
        default: '',
        auto_increment: false,
        primary_key: false,
        after: ''
    };

    const handleSubmit = async (values: FormValues) => {
        if (!uuid) {
            console.error('Server UUID not available');
            return;
        }

        setIsAdding(true);
        clearFlashes('database:add-column');

        try {
            const request: AddColumnRequest = {
                name: values.name,
                type: values.type,
                length: values.length ? parseInt(values.length, 10) : undefined,
                precision: values.precision ? parseInt(values.precision, 10) : undefined,
                enum_values: values.type === 'ENUM' ? values.enumValues.filter(v => v.trim() !== '') : undefined,
                set_values: values.type === 'SET' ? values.setValues.filter(v => v.trim() !== '') : undefined,
                nullable: values.nullable,
                default: values.auto_increment ? undefined : (values.default || undefined),
                auto_increment: values.auto_increment,
                primary_key: values.auto_increment || values.primary_key,
                after: values.after || undefined
            };

            await addColumn(uuid, databaseId, tableName, request);
            onColumnAdded?.();
            addFlash({
                key: 'database:add-column',
                type: 'success',
                message: `Column '${values.name}' added successfully!`,
            });
            
        } catch (error) {
            console.error('Error adding column:', error);
            addFlash({
                key: 'database:add-column',
                type: 'error',
                message: httpErrorToHuman(error),
            });
        } finally {
            setIsAdding(false);
        }
    };

    const handleDismiss = () => {
        clearFlashes('database:add-column');
        onDismissed();
    };

    return (
        <GradientModal
            visible={visible}
            onDismissed={handleDismiss}
            size="lg"
        >
            <Container>
                <Header>
                    <IconContainer>
                        <FontAwesomeIcon icon={faPlus} css={tw`text-white text-lg`} />
                    </IconContainer>
                    <div>
                        <h2 css={tw`text-2xl font-semibold text-white`}>Add Column</h2>
                        <p css={tw`text-sm text-gray-400`}>Add a new column to {tableName}</p>
                    </div>
                </Header>

                <FlashMessageRender byKey={'database:add-column'} css={tw`mb-6`} />

                <Formik
                    initialValues={initialValues}
                    validationSchema={validationSchema}
                    onSubmit={handleSubmit}
                >
                    {({ values, setFieldValue, isSubmitting }) => {
                        const currentType = ALL_DATA_TYPES.find(t => t.value === values.type);
                        const requiresLength = currentType?.needsLength || false;
                        const canAutoIncrement = ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(values.type);
                        const needsPrecision = values.type === 'DECIMAL';
                        const needsEnum = values.type === 'ENUM';
                        const needsSet = values.type === 'SET';

                        return (
                            <Form>
                                <FormGrid>
                                    <FormGroup>
                                        <Field
                                            type="string"
                                            id="name"
                                            name="name"
                                            label="Column Name"
                                            description="Enter a unique name for the column"
                                        />
                                    </FormGroup>

                                    <FormGroup>
                                        <Label>Data Type *</Label>
                                        <Select
                                            name="type"
                                            value={values.type}
                                            onChange={(e) => {
                                                const newType = e.target.value;
                                                setFieldValue('type', newType);
                                                if (newType !== 'ENUM') {
                                                    setFieldValue('enumValues', []);
                                                }
                                                if (newType !== 'SET') {
                                                    setFieldValue('setValues', []);
                                                }
                                                if (newType !== 'DECIMAL') {
                                                    setFieldValue('precision', '');
                                                }
                                                if (!['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(newType)) {
                                                    setFieldValue('auto_increment', false);
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
                                    </FormGroup>

                                    {requiresLength && (
                                        <FormGroup>
                                            <Field
                                                type="string"
                                                id="length"
                                                name="length"
                                                label="Length"
                                                placeholder={
                                                    values.type === 'VARCHAR' ? '255' :
                                                        values.type === 'CHAR' ? '50' :
                                                            values.type === 'DECIMAL' ? '10' :
                                                                values.type === 'BIT' ? '1' : ''
                                                }
                                            />
                                        </FormGroup>
                                    )}

                                    {needsPrecision && (
                                        <FormGroup>
                                            <Field
                                                type="string"
                                                id="precision"
                                                name="precision"
                                                label="Precision (Decimal Places)"
                                                placeholder="2"
                                            />
                                        </FormGroup>
                                    )}

                                    {needsEnum && (
                                        <FormGroup>
                                            <label css={tw`block text-sm font-medium text-gray-300 mb-2`}>
                                                ENUM Values (one per line)
                                            </label>
                                            <textarea
                                                css={tw`w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none`}
                                                rows={4}
                                                placeholder="option1&#10;option2&#10;option3"
                                                value={values.enumValues.join('\n')}
                                                onChange={(e) => {
                                                    const enumValues = e.target.value.split('\n').filter(v => v.trim() !== '');
                                                    setFieldValue('enumValues', enumValues);
                                                }}
                                            />
                                        </FormGroup>
                                    )}

                                    {needsSet && (
                                        <FormGroup>
                                            <label css={tw`block text-sm font-medium text-gray-300 mb-2`}>
                                                SET Values (one per line)
                                            </label>
                                            <textarea
                                                css={tw`w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none`}
                                                rows={4}
                                                placeholder="tag1&#10;tag2&#10;tag3"
                                                value={values.setValues.join('\n')}
                                                onChange={(e) => {
                                                    const setValues = e.target.value.split('\n').filter(v => v.trim() !== '');
                                                    setFieldValue('setValues', setValues);
                                                }}
                                            />
                                        </FormGroup>
                                    )}

                                    <FormGroup>
                                        <Field
                                            type="string"
                                            id="default"
                                            name="default"
                                            label="Default Value"
                                            placeholder={values.auto_increment ? "Not allowed for AUTO_INCREMENT" : "Leave empty for no default"}
                                            disabled={values.auto_increment}
                                            css={[
                                                values.auto_increment && tw`opacity-50 cursor-not-allowed`
                                            ]}
                                        />
                                    </FormGroup>

                                    <FormGroup>
                                        <Label>Position After</Label>
                                        <Select
                                            name="after"
                                            value={values.after}
                                            onChange={(e) => setFieldValue('after', e.target.value)}
                                        >
                                            <option value="">At the end</option>
                                            <option value="FIRST">At the top</option>
                                            {existingColumns.map(column => (
                                                <option key={column} value={column}>After {column}</option>
                                            ))}
                                        </Select>
                                    </FormGroup>
                                </FormGrid>

                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-6`}>
                                    <FormikSwitch
                                        name="nullable"
                                        label="Allow NULL values"
                                        description="Allow this column to contain NULL values"
                                    />

                                    <FormikSwitch
                                        name="primary_key"
                                        label="Primary Key"
                                        description="Make this column a primary key"
                                        readOnly={values.auto_increment}
                                    />
                                    {canAutoIncrement && (
                                        <div css={[
                                            !canAutoIncrement && tw`opacity-50 pointer-events-none`
                                        ]}>
                                            <FormikSwitch
                                                name="auto_increment"
                                                label="Auto Increment"
                                                description={
                                                    !canAutoIncrement
                                                        ? "Only available for integer types"
                                                        : "Automatically increment this column's value (sets as primary key)"
                                                }
                                                onChange={(e) => {
                                                    if (!canAutoIncrement) {
                                                        return;
                                                    }

                                                    const isChecked = e.target.checked;
                                                    setFieldValue('auto_increment', isChecked);

                                                    if (isChecked) {
                                                        setFieldValue('default', '');
                                                        setFieldValue('primary_key', true);
                                                    }
                                                }}
                                            />
                                        </div>
                                    )}

                                    {canAutoIncrement && hasAutoIncrement && (
                                        <div css={tw`text-sm text-gray-400`}>
                                            Auto increment is disabled because the table already has an AUTO_INCREMENT column.
                                        </div>
                                    )}
                                </div>

                                <div css={tw`flex justify-end space-x-4 pt-4 border-t border-gray-600`}>
                                    <Button
                                        type="button"
                                        variant={Button.Variants.Secondary}
                                        onClick={handleDismiss}
                                        disabled={isSubmitting || isAdding}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={isSubmitting || isAdding}
                                        css={tw`bg-blue-600 hover:bg-blue-700`}
                                    >
                                        <FontAwesomeIcon
                                            icon={faPlus}
                                            css={[
                                                tw`mr-2`,
                                                (isSubmitting || isAdding) && tw`animate-spin`
                                            ]}
                                        />
                                        {(isSubmitting || isAdding) ? 'Adding...' : 'Add Column'}
                                    </Button>
                                </div>
                            </Form>
                        );
                    }}
                </Formik>
            </Container>
        </GradientModal>
    );
};

export default AddColumnModal;