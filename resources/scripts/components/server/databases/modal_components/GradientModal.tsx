import React, { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes } from '@fortawesome/free-solid-svg-icons';
import Fade from '@/components/elements/Fade';

interface GradientModalProps {
    visible: boolean;
    onDismissed: () => void;
    children: React.ReactNode;
    dismissable?: boolean;
    closeOnEscape?: boolean;
    closeOnBackground?: boolean;
    size?: 'sm' | 'md' | 'lg' | 'xl' | 'xxl';
}

const ModalOverlay = styled.div`
    ${tw`fixed inset-0 flex items-center justify-center p-4`};
    z-index: 9999;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(8px);
    transition: all 0.3s ease;
    
    &:hover {
        backdrop-filter: blur(12px);
    }
`;

const ModalContainer = styled.div<{ size: string }>`
    ${tw`relative overflow-hidden rounded-2xl shadow-2xl bg-gray-800 border border-gray-700 transition-all duration-300`};
    backdrop-filter: blur(20px);
    
    ${props => {
        switch (props.size) {
            case 'sm': return tw`w-full max-w-md`;
            case 'md': return tw`w-full max-w-lg`;
            case 'lg': return tw`w-full max-w-2xl`;
            case 'xl': return tw`w-full max-w-4xl`;
            case 'xxl': return tw`w-full max-w-6xl`;
            default: return tw`w-full max-w-lg`;
        }
    }}
    
    max-height: 90vh;
    
    &:hover {
        ${tw`border-gray-600`};
    }
    
    &::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        ${tw`bg-gray-600`};
        z-index: 1;
        transition: all 0.3s ease;
    }
    
    &:hover::before {
        height: 3px;
        ${tw`bg-blue-500`};
        box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
    }
`;

const CloseButton = styled.button`
    ${tw`absolute -top-2 -right-2 p-1.5 text-gray-300 hover:text-white rounded-full transition-all duration-200 z-50 bg-gray-800 hover:bg-gray-700 border-2 border-gray-600 hover:border-gray-500 shadow-lg`};
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    
    &:hover {
        transform: rotate(90deg) scale(1.15);
    }
    
    &:active {
        transform: rotate(90deg) scale(1.0);
        transition: all 0.1s ease;
    }
`;

const ModalContent = styled.div`
    ${tw`relative overflow-y-auto transition-all duration-300`};
    max-height: calc(90vh - 2rem);
    
    &::-webkit-scrollbar {
        width: 8px;
        transition: all 0.3s ease;
    }
    
    &::-webkit-scrollbar-track {
        ${tw`bg-gray-700 rounded`};
        transition: all 0.3s ease;
    }
    
    &::-webkit-scrollbar-thumb {
        ${tw`bg-gray-500 rounded transition-all duration-300`};
    }
    
    &::-webkit-scrollbar-thumb:hover {
        ${tw`bg-gray-400`};
    }
    
    &:hover::-webkit-scrollbar {
        width: 10px;
    }
`;

const GradientModal: React.FC<GradientModalProps> = ({
    visible,
    onDismissed,
    children,
    dismissable = true,
    closeOnEscape = true,
    closeOnBackground = true,
    size = 'md'
}) => {
    const modalRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!visible || !closeOnEscape) return;

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onDismissed();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [visible, closeOnEscape, onDismissed]);

    useEffect(() => {
        if (visible) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'unset';
        }

        return () => {
            document.body.style.overflow = 'unset';
        };
    }, [visible]);

    const handleOverlayClick = (event: React.MouseEvent) => {
        if (closeOnBackground && event.target === event.currentTarget) {
            onDismissed();
        }
    };

    if (!visible) return null;

    const element = document.getElementById('modal-portal') || document.body;

    return createPortal(
        <Fade appear in={visible} timeout={200}>
            <ModalOverlay onClick={handleOverlayClick}>
                <ModalContainer
                    ref={modalRef}
                    size={size}
                    onClick={(e) => e.stopPropagation()}
                >
                    {dismissable && (
                        <CloseButton onClick={onDismissed} type="button">
                            <FontAwesomeIcon icon={faTimes} size="sm" />
                        </CloseButton>
                    )}
                    <ModalContent>
                        {children}
                    </ModalContent>
                </ModalContainer>
            </ModalOverlay>
        </Fade>,
        element
    );
};

export default GradientModal;