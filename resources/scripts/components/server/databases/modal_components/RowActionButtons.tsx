import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus, faSearch } from '@fortawesome/free-solid-svg-icons';
import styled from 'styled-components/macro';

const DataActionButtons = styled.div`
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    
    @media (max-width: 640px) {
        gap: 0.25rem;
    }
`;

const DataActionButton = styled.button<{ variant?: 'success' | 'primary' }>`
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    min-width: fit-content;
    
    ${props => props.variant === 'success' ? `
        background-color: #10b981;
        color: white;
        &:hover:not(:disabled) {
            background-color: #059669;
        }
    ` : `
        background-color: #374151;
        color: #d1d5db;
        &:hover:not(:disabled) {
            background-color: #4b5563;
        }
    `}
    
    &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    @media (max-width: 640px) {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        gap: 0.25rem;
    }
    
    @media (max-width: 320px) {
        padding: 0.25rem 0.5rem;
        font-size: 0.625rem;
        gap: 0.125rem;
    }
`;

interface RowActionButtonsProps {
    onAddRow: () => void;
    onRefresh: () => void;
    canAddRows: boolean;
    isLoading: boolean;
    addRowTooltip?: string;
}

const RowActionButtons: React.FC<RowActionButtonsProps> = ({
    onAddRow,
    onRefresh,
    canAddRows,
    isLoading,
    addRowTooltip
}) => {
    return (
        <DataActionButtons>
            <DataActionButton
                variant="success"
                onClick={() => {
                    onAddRow();
                }}
                disabled={!canAddRows}
                title={addRowTooltip || (canAddRows ? "Add new row" : "Table must have a primary key to add rows")}
            >
                <FontAwesomeIcon icon={faPlus} size="sm" />
                Add Row
            </DataActionButton>
            <DataActionButton
                onClick={onRefresh}
                disabled={isLoading}
            >
                <FontAwesomeIcon icon={faSearch} size="sm" />
                Refresh
            </DataActionButton>
        </DataActionButtons>
    );
};

export default RowActionButtons;