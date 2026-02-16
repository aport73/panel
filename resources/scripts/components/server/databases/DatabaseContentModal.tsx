import React, { useState, useEffect } from 'react';
import styled from 'styled-components';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTable, faDatabase, faCube, faInfoCircle, faSpinner, faList, faHdd, faMagic, faChevronDown, faSearch } from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';
import getDatabaseContents, { DatabaseContents } from '@/api/server/databases/getDatabaseContents';
import getDatabaseInfo, { DatabaseInfo } from '@/api/server/databases/getDatabaseInfo';
import Spinner from '@/components/elements/Spinner';
import GreyRowBox from '@/components/elements/GreyRowBox';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Dialog } from '@/components/elements/dialog';
import tw from 'twin.macro';
import { GradientModal } from './modal_components';
import { DatabaseTablesContainer } from './modal_components';
import DatabasePresetsModal from './modal_components/DatabasePresetsModal';
import IndexAnalyzerModal from './modal_components/IndexAnalyzerModal';

interface DatabaseContentModalProps {
    databaseId: string;
    databaseName: string;
    visible: boolean;
    onDismissed: () => void;
}

const ModalHeader = styled.div`
    ${tw`p-6 border-b border-gray-600 bg-gradient-to-r from-gray-700 to-gray-800`};
    transition: all 0.3s ease;
    
    &:hover {
        ${tw`bg-gradient-to-r from-gray-600 to-gray-700 border-gray-500`};
        transform: translateY(-1px);
    }
`;

const HeaderTop = styled.div`
    ${tw`flex items-start mb-4`};
`;

const StatsContainer = styled.div`
    ${tw`grid gap-4 mb-6`};
    grid-template-columns: repeat(3, 1fr);
    
    @media (max-width: 768px) {
        grid-template-columns: 1fr;
        ${tw`gap-4 mb-4`};
    }
    
    @media (max-width: 640px) {
        ${tw`gap-3 mb-3`};
    }
    
    @media (max-width: 480px) {
        ${tw`gap-2`};
    }
    
    @media (min-width: 769px) and (max-width: 1024px) {
        grid-template-columns: repeat(3, 1fr);
        ${tw`gap-3 mb-5`};
    }
`;

const StatCard = styled.div`
    ${tw`bg-gradient-to-br from-gray-600 to-gray-700 p-4 rounded-xl border border-gray-500 transition-all duration-300`};
    backdrop-filter: blur(10px);
    min-height: 120px;
    
    @media (max-width: 768px) {
        ${tw`p-4`};
        min-height: 110px;
    }
    
    @media (max-width: 640px) {
        ${tw`p-3`};
        min-height: 100px;
    }
    
    @media (max-width: 480px) {
        ${tw`p-3`};
        min-height: 90px;
    }
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-500 to-gray-600 border-gray-400 shadow-2xl`};
        transform: translateY(-6px) scale(1.05);
        backdrop-filter: blur(15px);
        
        @media (max-width: 768px) {
            transform: translateY(-3px) scale(1.02);
        }
    }
    
    &:active {
        transform: translateY(-3px) scale(1.02);
        transition: all 0.1s ease;
        
        @media (max-width: 768px) {
            transform: translateY(-1px) scale(1.01);
        }
    }
`;

const StatHeader = styled.div`
    ${tw`flex items-center gap-2 mb-2`};
    transition: all 0.3s ease;
    
    ${StatCard}:hover & {
        transform: translateX(3px);
    }
`;

const StatIcon = styled.div`
    ${tw`w-8 h-8 rounded-lg bg-gray-700 border border-gray-500 flex items-center justify-center transition-all duration-300`};
    
    @media (max-width: 768px) {
        ${tw`w-6 h-6`};
    }
    
    @media (max-width: 480px) {
        ${tw`w-5 h-5`};
    }
    
    ${StatCard}:hover & {
        ${tw`bg-gray-600 border-gray-400 shadow-lg`};
        transform: rotate(10deg) scale(1.1);
        
        @media (max-width: 768px) {
            transform: rotate(5deg) scale(1.05);
        }
    }
`;

const StatValue = styled.div`
    ${tw`text-2xl font-bold text-white transition-all duration-300`};
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
    
    @media (max-width: 768px) {
        ${tw`text-xl`};
    }
    
    @media (max-width: 480px) {
        ${tw`text-lg`};
    }
    
    ${StatCard}:hover & {
        ${tw`text-3xl`};
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.7);
        
        @media (max-width: 768px) {
            ${tw`text-2xl`};
        }
        
        @media (max-width: 480px) {
            ${tw`text-xl`};
        }
    }
`;

const StatLabel = styled.div`
    ${tw`text-sm font-medium text-gray-300 transition-all duration-300`};
    
    @media (max-width: 768px) {
        ${tw`text-xs`};
    }
    
    @media (max-width: 480px) {
        ${tw`text-xs`};
        font-size: 0.7rem;
    }
    
    ${StatCard}:hover & {
        ${tw`text-gray-100`};
        text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
    }
`;

const ModalTitle = styled.h2`
    ${tw`text-xl font-semibold flex items-center gap-3 text-gray-100 transition-all duration-300`};
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
    
    &:hover {
        ${tw`text-white text-2xl`};
        text-shadow: 0 2px 8px rgba(59, 130, 246, 0.5);
    }
`;

const DatabaseDetails = styled.div`
    ${tw`text-sm text-gray-300 transition-all duration-300`};
    
    &:hover {
        ${tw`text-gray-100`};
    }
`;

const ContentArea = styled.div`
    ${tw`p-6 overflow-y-auto transition-all duration-300`};
    min-height: 24rem;
    max-height: calc(90vh - 120px);
    
    &:hover {
        ${tw`bg-gray-800`};
    }
`;

const LoadingContainer = styled.div`
    ${tw`flex items-center justify-center py-12 transition-all duration-300`};
    
    &:hover {
        transform: scale(1.02);
    }
`;

const PlaceholderContent = styled.div`
    ${tw`text-center py-12 transition-all duration-300`};
    
    &:hover {
        transform: scale(1.02);
    }
`;

const PlaceholderTitle = styled.h3`
    ${tw`text-lg font-medium text-neutral-300 mb-2`};
`;

const PlaceholderText = styled.p`
    ${tw`text-neutral-400 mb-4`};
`;

const ActionButtons = styled.div`
    ${tw`flex gap-3 justify-center`};
`;

const ActionButton = styled.button`
    ${tw`px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center gap-2 bg-gradient-to-br from-gray-500 to-gray-600 text-gray-100 border border-gray-300 shadow-md`};
    
    &:hover {
        ${tw`bg-gradient-to-br from-gray-400 to-gray-500 shadow-xl border-gray-200`};
        transform: translateY(-3px) scale(1.05);
    }
    
    &:active {
        transform: translateY(-1px) scale(1.02);
        transition: all 0.1s ease;
    }
`;

const PresetsDropdownContainer = styled.div`
    ${tw`relative`};
`;

const PresetsButton = styled.button`
    ${tw`px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center gap-2 bg-gradient-to-br from-purple-600 to-purple-700 text-white border border-purple-500 shadow-md`};
    
    &:hover {
        ${tw`bg-gradient-to-br from-purple-500 to-purple-600 shadow-xl border-purple-400`};
        transform: translateY(-2px) scale(1.02);
    }
    
    &:active {
        transform: translateY(-1px) scale(1.01);
        transition: all 0.1s ease;
    }
`;

const PresetsDropdown = styled.div<{ isOpen: boolean }>`
    ${tw`absolute top-full right-0 mt-2 w-64 bg-gray-800 border border-gray-600 rounded-lg shadow-xl z-50 transition-all duration-300`};
    ${props => props.isOpen ? tw`opacity-100 visible` : tw`opacity-0 invisible`};
    backdrop-filter: blur(10px);
`;

const DropdownItem = styled.button`
    ${tw`w-full px-4 py-3 text-left text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 flex items-center gap-3 border-b border-gray-700 last:border-b-0`};
    
    &:first-child {
        ${tw`rounded-t-lg`};
    }
    
    &:last-child {
        ${tw`rounded-b-lg`};
    }
    
    &:hover {
        transform: translateX(4px);
    }
`;


const DatabaseContentModal: React.FC<DatabaseContentModalProps> = ({
    databaseId,
    databaseName,
    visible,
    onDismissed
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [loading, setLoading] = useState(false);
    const [dbInfo, setDbInfo] = useState<DatabaseInfo | null>(null);
    const [dbContents, setDbContents] = useState<DatabaseContents | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [presetsVisible, setPresetsVisible] = useState(false);
    const [presetsDropdownOpen, setPresetsDropdownOpen] = useState(false);
    const [indexAnalyzerVisible, setIndexAnalyzerVisible] = useState(false);

    useEffect(() => {
        if (visible) {

            setLoading(true);
            setError(null);
            Promise.all([
                getDatabaseInfo(uuid, databaseId),
                getDatabaseContents(uuid, databaseId)
            ])
                .then(([info, contents]) => {
                    setDbInfo(info);
                    setDbContents(contents);
                    setLoading(false);
                })
                .catch((err) => {
                    console.error('[DatabaseContentModal] Error fetching database data:', err);
                    console.error('[DatabaseContentModal] Error details:', {
                        message: err.message,
                        response: err.response?.data,
                        status: err.response?.status
                    });
                    setError('Failed to load database information');
                    setLoading(false);
                });
        }
    }, [visible, uuid, databaseId]);

    useEffect(() => {
        if (!presetsDropdownOpen) return;

        const handleClickOutside = (event: MouseEvent) => {
            const target = event.target as Element;
            if (!target.closest('[data-presets-dropdown]')) {
                setPresetsDropdownOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [presetsDropdownOpen]);

    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size="xxl"
            dismissable={true}
            closeOnEscape={true}
            closeOnBackground={true}
        >
            <ModalHeader>
                <HeaderTop>
                    <div css={tw`flex-1 pr-4`}>
                        <ModalTitle>
                            <FontAwesomeIcon icon={faDatabase} style={{ color: 'hsl(211, 13%, 75%)' }} />
                            {databaseName} - Opened DB
                        </ModalTitle>
                        <DatabaseDetails>
                            Database ID: <span className="font-mono text-neutral-300">{databaseId}</span>
                        </DatabaseDetails>
                    </div>
                    
                    <div css={tw`flex items-center pr-12`}>
                        <PresetsDropdownContainer data-presets-dropdown>
                            <PresetsButton
                                type="button"
                                onClick={() => setPresetsDropdownOpen(!presetsDropdownOpen)}
                            >
                                <FontAwesomeIcon icon={faMagic} />
                                Database Presets
                                <FontAwesomeIcon 
                                    icon={faChevronDown} 
                                    css={[
                                        tw`transition-transform duration-300`,
                                        presetsDropdownOpen && tw`transform rotate-180`
                                    ]}
                                />
                            </PresetsButton>
                            
                            <PresetsDropdown isOpen={presetsDropdownOpen}>
                                <DropdownItem
                                    type="button"
                                    onClick={() => {
                                        setPresetsVisible(true);
                                        setPresetsDropdownOpen(false);
                                    }}
                                >
                                    <FontAwesomeIcon icon={faMagic} css={tw`text-purple-400`} />
                                    <div>
                                        <div css={tw`font-medium`}>Browse All Presets</div>
                                        <div css={tw`text-xs text-gray-400`}>View and apply database templates</div>
                                    </div>
                                </DropdownItem>
                                
                            <DropdownItem
                                type="button"
                                onClick={() => {
                                    setIndexAnalyzerVisible(true);
                                    setPresetsDropdownOpen(false);
                                }}
                            >
                                <FontAwesomeIcon icon={faSearch} css={tw`text-green-400`} />
                                <div>
                                    <div css={tw`font-medium`}>Index Analyzer</div>
                                    <div css={tw`text-xs text-gray-400`}>Optimize database performance</div>
                                </div>
                            </DropdownItem>
                            
                            <DropdownItem
                                type="button"
                                onClick={() => {
                                    setPresetsVisible(true);
                                    setPresetsDropdownOpen(false);
                                }}
                            >
                                <FontAwesomeIcon icon={faTable} css={tw`text-blue-400`} />
                                <div>
                                    <div css={tw`font-medium`}>Quick Setup</div>
                                    <div css={tw`text-xs text-gray-400`}>User management tables</div>
                                </div>
                            </DropdownItem>
                            </PresetsDropdown>
                        </PresetsDropdownContainer>
                    </div>
                </HeaderTop>

                {!loading && dbInfo && dbContents && (
                    <StatsContainer>
                        <StatCard>
                            <StatHeader>
                                <StatIcon>
                                    <FontAwesomeIcon icon={faTable} size="sm" style={{ color: '#3B82F6' }} />
                                </StatIcon>
                                <StatLabel>Total Tables</StatLabel>
                            </StatHeader>
                            <StatValue>{dbInfo.table_count}</StatValue>
                        </StatCard>

                        <StatCard>
                            <StatHeader>
                                <StatIcon>
                                    <FontAwesomeIcon icon={faList} size="sm" style={{ color: '#10B981' }} />
                                </StatIcon>
                                <StatLabel>Total Rows</StatLabel>
                            </StatHeader>
                            <StatValue>{dbContents.total_records.toLocaleString()}</StatValue>
                        </StatCard>

                        <StatCard>
                            <StatHeader>
                                <StatIcon>
                                    <FontAwesomeIcon icon={faHdd} size="sm" style={{ color: '#F59E0B' }} />
                                </StatIcon>
                                <StatLabel>Total Size</StatLabel>
                            </StatHeader>
                            <StatValue>{dbInfo.size_mb.toFixed(2)} MB</StatValue>
                        </StatCard>
                    </StatsContainer>
                )}

                {loading && (
                    <StatsContainer>
                        {[1, 2, 3].map((i) => (
                            <StatCard key={i}>
                                <StatHeader>
                                    <StatIcon>
                                        <Spinner size="small" />
                                    </StatIcon>
                                    <StatLabel>Loading...</StatLabel>
                                </StatHeader>
                                <StatValue>--</StatValue>
                            </StatCard>
                        ))}
                    </StatsContainer>
                )}
            </ModalHeader>

            <ContentArea>
                {error ? (
                    <PlaceholderContent>
                        <FontAwesomeIcon
                            icon={faDatabase}
                            size="3x"
                            className="text-red-500 mb-4"
                        />
                        <PlaceholderTitle>Error Loading Database</PlaceholderTitle>
                        <PlaceholderText>{error}</PlaceholderText>
                    </PlaceholderContent>
                ) : !loading && dbContents ? (
                    <DatabaseTablesContainer
                        databaseId={databaseId}
                        databaseContents={dbContents}
                        loading={loading}
                        onRefresh={() => {
                            setLoading(true);
                            setError(null);

                            Promise.all([
                                getDatabaseInfo(uuid, databaseId),
                                getDatabaseContents(uuid, databaseId)
                            ])
                                .then(([info, contents]) => {
                                    setDbInfo(info);
                                    setDbContents(contents);
                                    setLoading(false);
                                })
                                .catch((err) => {
                                    console.error('[DatabaseContentModal] Error refreshing database data:', err);
                                    setError('Failed to refresh database information');
                                    setLoading(false);
                                });
                        }}
                    />
                ) : (
                    <LoadingContainer>
                        <div className="text-center">
                            <Spinner size="large" />
                            <p className="text-neutral-400 mt-4">Loading database contents...</p>
                        </div>
                    </LoadingContainer>
                )}
            </ContentArea>
            
            {/* Database Presets Modal */}
            <DatabasePresetsModal
                databaseId={databaseId}
                databaseName={databaseName}
                visible={presetsVisible}
                onDismissed={() => setPresetsVisible(false)}
            />
            
            {/* Index Analyzer Modal */}
            <IndexAnalyzerModal
                databaseId={databaseId}
                databaseName={databaseName}
                visible={indexAnalyzerVisible}
                onDismissed={() => setIndexAnalyzerVisible(false)}
            />
        </GradientModal>
    );
};

export default DatabaseContentModal;