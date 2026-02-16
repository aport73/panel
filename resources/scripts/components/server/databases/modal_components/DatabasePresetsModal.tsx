import React, { useState, useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import StyledCheckbox from '@/components/elements/StyledCheckbox';
import {
    faMagic,
    faUsers,
    faShoppingCart,
    faBlog,
    faBoxes,
    faCreditCard,
    faFileAlt,
    faDatabase,
    faTable,
    faColumns,
    faSpinner,
    faCheckCircle,
    faExclamationTriangle,
    faChevronDown,
    faChevronRight,
    faTimes,
    faPlus
} from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import { httpErrorToHuman } from '@/api/http';
import tw from 'twin.macro';
import GradientModal from './GradientModal';
import { getDatabasePresets, applyDatabasePresets, DatabasePreset, DatabasePresets } from '@/api/server/databases/databasePresets';

interface Props {
    databaseId: string;
    databaseName: string;
    visible: boolean;
    onDismissed: () => void;
    onSuccess?: () => void;
}

const getIconForPreset = (iconName: string) => {
    const iconMap: Record<string, any> = {
        'users': faUsers,
        'shopping-cart': faShoppingCart,
        'blog': faBlog,
        'boxes': faBoxes,
        'credit-card': faCreditCard,
        'file-alt': faFileAlt,
    };
    return iconMap[iconName] || faDatabase;
};

const getCategoryStyles = (category: string, isSelected: boolean) => {
    const baseStyles = tw`p-4 rounded-lg border transition-all duration-300 cursor-pointer`;
    
    if (isSelected) {
        switch (category) {
            case 'Authentication':
                return [baseStyles, tw`border-purple-500 bg-gradient-to-br from-blue-900 to-blue-800 shadow-lg ring-2 ring-purple-500 ring-opacity-50`];
            case 'E-commerce':
                return [baseStyles, tw`border-purple-500 bg-gradient-to-br from-green-900 to-green-800 shadow-lg ring-2 ring-purple-500 ring-opacity-50`];
            case 'Content':
                return [baseStyles, tw`border-purple-500 bg-gradient-to-br from-purple-900 to-purple-800 shadow-lg ring-2 ring-purple-500 ring-opacity-50`];
            case 'Business':
                return [baseStyles, tw`border-purple-500 bg-gradient-to-br from-yellow-900 to-yellow-800 shadow-lg ring-2 ring-purple-500 ring-opacity-50`];
            default:
                return [baseStyles, tw`border-purple-500 bg-gradient-to-br from-neutral-900 to-neutral-800 shadow-lg ring-2 ring-purple-500 ring-opacity-50`];
        }
    } else {
        switch (category) {
            case 'Authentication':
                return [baseStyles, tw`border-neutral-600 hover:border-purple-400 hover:bg-gradient-to-br hover:from-blue-900 hover:to-blue-800 hover:shadow-md`];
            case 'E-commerce':
                return [baseStyles, tw`border-neutral-600 hover:border-purple-400 hover:bg-gradient-to-br hover:from-green-900 hover:to-green-800 hover:shadow-md`];
            case 'Content':
                return [baseStyles, tw`border-neutral-600 hover:border-purple-400 hover:bg-gradient-to-br hover:from-purple-900 hover:to-purple-800 hover:shadow-md`];
            case 'Business':
                return [baseStyles, tw`border-neutral-600 hover:border-purple-400 hover:bg-gradient-to-br hover:from-yellow-900 hover:to-yellow-800 hover:shadow-md`];
            default:
                return [baseStyles, tw`border-neutral-600 hover:border-purple-400 hover:bg-gradient-to-br hover:from-neutral-900 hover:to-neutral-800 hover:shadow-md`];
        }
    }
};

const DatabasePresetsModal: React.FC<Props> = ({ databaseId, databaseName, visible, onDismissed, onSuccess }) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes, addFlash } = useFlash();
    
    const [loading, setLoading] = useState(false);
    const [applying, setApplying] = useState(false);
    const [presets, setPresets] = useState<DatabasePresets | null>(null);
    const [selectedPresets, setSelectedPresets] = useState<Set<string>>(new Set());
    const [selectedTables, setSelectedTables] = useState<Record<string, Set<string>>>({});
    const [expandedPresets, setExpandedPresets] = useState<Set<string>>(new Set());
    const [results, setResults] = useState<any>(null);

    useEffect(() => {
        if (visible) {
            loadPresets();
        } else {
            resetState();
        }
    }, [visible]);

    const resetState = () => {
        setLoading(false);
        setApplying(false);
        setPresets(null);
        setSelectedPresets(new Set());
        setSelectedTables({});
        setExpandedPresets(new Set());
        setResults(null);
        clearFlashes();
    };

    const loadPresets = async () => {
        setLoading(true);
        try {
            const data = await getDatabasePresets(uuid, databaseId);
            setPresets(data);
        } catch (error: any) {
            console.error('Failed to load presets:', error);
            addError({ key: 'presets-load', message: httpErrorToHuman(error) });
        } finally {
            setLoading(false);
        }
    };

    const togglePreset = (presetId: string) => {
        const newSelected = new Set(selectedPresets);
        if (newSelected.has(presetId)) {
            newSelected.delete(presetId);
            const newSelectedTables = { ...selectedTables };
            delete newSelectedTables[presetId];
            setSelectedTables(newSelectedTables);
        } else {
            newSelected.add(presetId);
        }
        setSelectedPresets(newSelected);
    };

    const toggleTable = (presetId: string, tableName: string) => {
        const presetTables = selectedTables[presetId] || new Set();
        const newTables = new Set(presetTables);
        
        if (newTables.has(tableName)) {
            newTables.delete(tableName);
        } else {
            newTables.add(tableName);
        }
        
        setSelectedTables({
            ...selectedTables,
            [presetId]: newTables
        });
    };

    const togglePresetExpansion = (presetId: string) => {
        const newExpanded = new Set(expandedPresets);
        if (newExpanded.has(presetId)) {
            newExpanded.delete(presetId);
        } else {
            newExpanded.add(presetId);
        }
        setExpandedPresets(newExpanded);
    };

    const selectAllTables = (presetId: string) => {
        if (!presets?.presets[presetId]) return;
        
        const allTables = Object.keys(presets.presets[presetId].tables);
        setSelectedTables({
            ...selectedTables,
            [presetId]: new Set(allTables)
        });
    };

    const deselectAllTables = (presetId: string) => {
        setSelectedTables({
            ...selectedTables,
            [presetId]: new Set()
        });
    };

    const applyPresets = async () => {
        if (selectedPresets.size === 0) {
            addError({ key: 'presets-apply', message: 'Please select at least one preset to apply.' });
            return;
        }

        setApplying(true);
        clearFlashes();

        try {
            const selectedTablesForAPI: Record<string, string[]> = {};
            Object.entries(selectedTables).forEach(([presetId, tables]) => {
                selectedTablesForAPI[presetId] = Array.from(tables);
            });

            const result = await applyDatabasePresets(uuid, databaseId, {
                presets: Array.from(selectedPresets),
                selected_tables: selectedTablesForAPI
            });

            setResults(result.results);
            addFlash({
                key: 'presets-apply',
                type: 'success',
                message: result.message
            });

            if (onSuccess) {
                onSuccess();
            }

        } catch (error: any) {
            console.error('Failed to apply presets:', error);
            addError({ key: 'presets-apply', message: httpErrorToHuman(error) });
        } finally {
            setApplying(false);
        }
    };

    const groupedPresets = presets ? Object.entries(presets.presets).reduce((acc, [id, preset]) => {
        const category = preset.category;
        if (!acc[category]) acc[category] = [];
        acc[category].push({ id, ...preset });
        return acc;
    }, {} as Record<string, Array<{ id: string } & DatabasePreset>>) : {};

    return (
        <GradientModal
            visible={visible}
            onDismissed={applying ? () => {} : onDismissed}
            dismissable={!applying}
            size="xl"
        >
            {/* Loading Overlay */}
            {applying && (
                <div css={tw`absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 rounded-2xl`}>
                    <div css={tw`text-center`}>
                        <FontAwesomeIcon icon={faSpinner} spin css={tw`text-4xl text-white mb-4`} />
                        <p css={tw`text-white text-lg font-semibold`}>Applying Presets...</p>
                        <p css={tw`text-neutral-300 text-sm`}>Creating database tables</p>
                    </div>
                </div>
            )}
            
            <div css={tw`p-6 space-y-6`}>
                {/* Header */}
                <div css={tw`flex items-center justify-between`}>
                    <div css={tw`flex items-center`}>
                        <div css={tw`w-12 h-12 bg-gradient-to-br from-purple-600 to-purple-700 rounded-xl flex items-center justify-center shadow-lg mr-4`}>
                            <FontAwesomeIcon icon={faMagic} css={tw`text-2xl text-white`} />
                        </div>
                        <div>
                            <h2 css={tw`text-2xl font-bold text-white mb-1`}>Database Presets</h2>
                            <p css={tw`text-neutral-400 text-sm`}>
                                Quick setup with common database structures for {databaseName}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={applying ? () => {} : onDismissed}
                        disabled={applying}
                        css={[
                            tw`text-neutral-400 hover:text-white transition-colors p-2`,
                            applying && tw`opacity-50 cursor-not-allowed`
                        ]}
                    >
                        <FontAwesomeIcon icon={faTimes} css={tw`text-xl`} />
                    </button>
                </div>

                <hr css={tw`border-neutral-600`} />

                {/* Flash Messages */}
                <div css={tw`space-y-2`}>
                    {/* Add flash message rendering here if needed */}
                </div>

                {/* Content */}
                {loading ? (
                    <div css={tw`flex items-center justify-center py-12`}>
                        <FontAwesomeIcon icon={faSpinner} spin css={tw`text-2xl text-blue-400 mr-3`} />
                        <span css={tw`text-neutral-300`}>Loading database presets...</span>
                    </div>
                ) : results ? (
                    /* Results Display */
                    <div css={tw`space-y-4`}>
                        <h3 css={tw`text-lg font-semibold text-white mb-4`}>Application Results</h3>
                        {Object.entries(results).map(([presetId, presetResults]: [string, any]) => (
                            <div key={presetId} css={tw`p-4 bg-neutral-800 border border-neutral-700 rounded-lg`}>
                                <h4 css={tw`font-medium text-white mb-2`}>
                                    {presets?.presets[presetId]?.name || presetId}
                                </h4>
                                <div css={tw`space-y-2`}>
                                    {Object.entries(presetResults).map(([tableName, result]: [string, any]) => (
                                        <div key={tableName} css={tw`flex items-center text-sm`}>
                                            <FontAwesomeIcon 
                                                icon={result.success ? faCheckCircle : faExclamationTriangle} 
                                                css={[
                                                    tw`mr-2`,
                                                    result.success ? tw`text-green-400` : tw`text-red-400`
                                                ]}
                                            />
                                            <span css={tw`text-neutral-300 mr-2`}>{tableName}:</span>
                                            <span css={[
                                                result.success ? tw`text-green-400` : tw`text-red-400`
                                            ]}>
                                                {result.message || result.error}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                        <div css={tw`flex justify-end space-x-3 pt-4`}>
                            <button
                                type="button"
                                onClick={onDismissed}
                                css={tw`px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 hover:from-green-500 hover:to-green-600 text-white font-semibold rounded-lg shadow-lg transition-all duration-300 transform hover:scale-105`}
                            >
                                Done
                            </button>
                        </div>
                    </div>
                ) : (
                    /* Preset Selection */
                    <div css={tw`space-y-6 max-h-96 overflow-y-auto pr-2`}>
                        {Object.entries(groupedPresets).map(([category, categoryPresets]) => (
                            <div key={category}>
                                <h3 css={tw`text-lg font-semibold text-white mb-4`}>{category}</h3>
                                <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-4`}>
                                    {categoryPresets.map((preset) => (
                                        <div
                                            key={preset.id}
                                            css={getCategoryStyles(preset.category, selectedPresets.has(preset.id))}
                                        >
                                            <div css={tw`flex items-center justify-between`}>
                                                <div css={tw`flex items-center flex-1`}>
                                                    <StyledCheckbox
                                                        checked={selectedPresets.has(preset.id)}
                                                        onChange={() => togglePreset(preset.id)}
                                                        size="md"
                                                        color="purple"
                                                    />
                                                    <FontAwesomeIcon 
                                                        icon={getIconForPreset(preset.icon)} 
                                                        css={tw`text-purple-400 mx-3 text-lg`} 
                                                    />
                                                    <div css={tw`flex-1`}>
                                                        <div css={tw`font-semibold text-white`}>{preset.name}</div>
                                                        <div css={tw`text-sm text-gray-200 mt-1`}>{preset.description}</div>
                                                        <div css={tw`text-xs text-gray-300 mt-1`}>
                                                            {Object.keys(preset.tables).length} tables
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                {selectedPresets.has(preset.id) && (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            togglePresetExpansion(preset.id);
                                                        }}
                                                        css={tw`p-1 text-neutral-400 hover:text-neutral-200 ml-2`}
                                                    >
                                                        <FontAwesomeIcon 
                                                            icon={expandedPresets.has(preset.id) ? faChevronDown : faChevronRight} 
                                                        />
                                                    </button>
                                                )}
                                            </div>

                                            {/* Table Selection */}
                                            {selectedPresets.has(preset.id) && expandedPresets.has(preset.id) && (
                                                <div css={tw`mt-4 pt-4 border-t border-neutral-600`}>
                                                    <div css={tw`flex items-center justify-between mb-3`}>
                                                        <span css={tw`text-sm font-medium text-white`}>
                                                            Select Tables:
                                                        </span>
                                                        <div css={tw`space-x-2`}>
                                                            <button
                                                                type="button"
                                                                onClick={() => selectAllTables(preset.id)}
                                                                css={tw`text-xs text-purple-400 hover:text-purple-300`}
                                                            >
                                                                Select All
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => deselectAllTables(preset.id)}
                                                                css={tw`text-xs text-neutral-400 hover:text-neutral-300`}
                                                            >
                                                                Clear
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div css={tw`space-y-2 max-h-40 overflow-y-auto`}>
                                                        {Object.entries(preset.tables).map(([tableName, table]) => {
                                                            const isSelected = selectedTables[preset.id]?.has(tableName) || false;
                                                            return (
                                                                <div
                                                                    key={tableName}
                                                                    css={[
                                                                        tw`flex items-center p-2 rounded cursor-pointer transition-colors`,
                                                                        isSelected ? tw`bg-purple-900 bg-opacity-30` : tw`hover:bg-neutral-700`
                                                                    ]}
                                                                    onClick={() => toggleTable(preset.id, tableName)}
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={isSelected}
                                                                        onChange={() => {}}
                                                                        css={tw`mr-2 h-3 w-3 text-purple-600 border-neutral-600 rounded`}
                                                                    />
                                                                    <FontAwesomeIcon icon={faTable} css={tw`text-purple-400 mr-2 text-sm`} />
                                                                    <div css={tw`flex-1`}>
                                                                        <div css={tw`text-sm text-white font-medium`}>{tableName}</div>
                                                                        <div css={tw`text-xs text-gray-300`}>{table.description}</div>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}

                        {/* Action Buttons */}
                        <div css={tw`flex justify-between items-center pt-6 border-t border-neutral-600`}>
                            <div css={tw`text-sm text-gray-200`}>
                                {selectedPresets.size > 0 && (
                                    <>
                                        {selectedPresets.size} preset{selectedPresets.size !== 1 ? 's' : ''} selected
                                        {Object.values(selectedTables).reduce((total, tables) => total + tables.size, 0) > 0 && (
                                            <> • {Object.values(selectedTables).reduce((total, tables) => total + tables.size, 0)} table{Object.values(selectedTables).reduce((total, tables) => total + tables.size, 0) !== 1 ? 's' : ''}</>
                                        )}
                                    </>
                                )}
                            </div>
                            <div css={tw`flex space-x-3`}>
                                <button
                                    type="button"
                                    onClick={onDismissed}
                                    css={tw`px-6 py-3 bg-neutral-700 hover:bg-neutral-600 text-neutral-300 font-semibold rounded-lg transition-all duration-300`}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={applyPresets}
                                    disabled={selectedPresets.size === 0 || applying}
                                    css={[
                                        tw`px-6 py-3 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-500 hover:to-purple-600 text-white font-semibold rounded-lg shadow-lg transition-all duration-300 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none`,
                                        applying && tw`animate-pulse`
                                    ]}
                                >
                                    {applying ? (
                                        <>
                                            <FontAwesomeIcon icon={faSpinner} spin css={tw`mr-2`} />
                                            Applying...
                                        </>
                                    ) : (
                                        <>
                                            <FontAwesomeIcon icon={faPlus} css={tw`mr-2`} />
                                            Apply Presets
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </GradientModal>
    );
};

export default DatabasePresetsModal;
