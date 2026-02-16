import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck } from '@fortawesome/free-solid-svg-icons';

interface StyledCheckboxProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    size?: 'sm' | 'md' | 'lg';
    color?: 'purple' | 'blue' | 'green' | 'red' | 'yellow';
    label?: React.ReactNode;
    className?: string;
}

const CheckboxContainer = styled.div<{ disabled?: boolean }>`
    ${tw`flex items-center cursor-pointer select-none`};
    ${props => props.disabled && tw`cursor-not-allowed opacity-50`};
`;

const CheckboxInput = styled.input`
    ${tw`sr-only`};
`;

const CheckboxBox = styled.div<{ 
    checked: boolean; 
    size: string; 
    color: string; 
    disabled?: boolean;
}>`
    ${tw`relative flex items-center justify-center rounded-lg border-2 transition-all duration-300 shadow-sm`};
    
    ${props => {
        switch (props.size) {
            case 'sm': return tw`w-4 h-4`;
            case 'lg': return tw`w-6 h-6`;
            default: return tw`w-5 h-5`;
        }
    }}
    
    ${props => {
        if (props.disabled) {
            return tw`bg-gray-600 border-gray-500`;
        }
        
        if (props.checked) {
            switch (props.color) {
                case 'blue': return tw`bg-blue-600 border-blue-500 shadow-lg`;
                case 'green': return tw`bg-green-600 border-green-500 shadow-lg`;
                case 'red': return tw`bg-red-600 border-red-500 shadow-lg`;
                case 'yellow': return tw`bg-yellow-600 border-yellow-500 shadow-lg`;
                default: return tw`bg-purple-600 border-purple-500 shadow-lg`;
            }
        } else {
            return tw`bg-gray-700 border-gray-500 hover:border-gray-400`;
        }
    }}
    
    &:hover {
        ${props => !props.disabled && !props.checked && tw`bg-gray-600 border-gray-400`};
        ${props => !props.disabled && props.checked && tw`shadow-lg scale-105`};
    }
    
    &:active {
        ${props => !props.disabled && tw`scale-95`};
        transition: all 0.1s ease;
    }
`;

const CheckIcon = styled(FontAwesomeIcon)<{ 
    checked: boolean; 
    checkboxSize: string;
}>`
    ${tw`text-white transition-all duration-300`};
    ${props => props.checked ? tw`opacity-100 scale-100` : tw`opacity-0 scale-50`};
    
    ${props => {
        switch (props.checkboxSize) {
            case 'sm': return tw`text-xs`;
            case 'lg': return tw`text-sm`;
            default: return tw`text-xs`;
        }
    }}
`;

const CheckboxLabel = styled.span<{ size: string; disabled?: boolean }>`
    ${tw`text-gray-300 transition-colors duration-200`};
    ${props => props.disabled && tw`text-gray-500`};
    
    ${props => {
        switch (props.size) {
            case 'sm': return tw`text-xs ml-2`;
            case 'lg': return tw`text-base ml-3`;
            default: return tw`text-sm ml-2`;
        }
    }}
    
    ${CheckboxContainer}:hover & {
        ${props => !props.disabled && tw`text-white`};
    }
`;

const StyledCheckbox: React.FC<StyledCheckboxProps> = ({
    checked,
    onChange,
    disabled = false,
    size = 'md',
    color = 'purple',
    label,
    className
}) => {
    const handleClick = () => {
        if (!disabled) {
            onChange(!checked);
        }
    };

    return (
        <CheckboxContainer 
            className={className}
            disabled={disabled}
            onClick={handleClick}
        >
            <CheckboxInput
                type="checkbox"
                checked={checked}
                onChange={() => {}} // Handled by container click
                disabled={disabled}
            />
            <CheckboxBox
                checked={checked}
                size={size}
                color={color}
                disabled={disabled}
            >
                <CheckIcon
                    icon={faCheck}
                    checked={checked}
                    checkboxSize={size}
                />
            </CheckboxBox>
            {label && (
                <CheckboxLabel size={size} disabled={disabled}>
                    {label}
                </CheckboxLabel>
            )}
        </CheckboxContainer>
    );
};

export default StyledCheckbox;
