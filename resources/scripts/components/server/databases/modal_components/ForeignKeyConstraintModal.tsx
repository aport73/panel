import React from 'react';
import GradientModal from './GradientModal';
import { Button } from '@/components/elements/button/index';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import { escapeHtml } from '@/utils/xssProtection';

interface ForeignKeyConstraintModalProps {
    visible: boolean;
    onDismissed: () => void;
    onForceConfirm: () => void;
    errorMessage: string;
    actionType: 'edit' | 'delete';
}

const ForeignKeyConstraintModal: React.FC<ForeignKeyConstraintModalProps> = ({
    visible,
    onDismissed,
    onForceConfirm,
    errorMessage,
    actionType,
}) => {
    return (
        <GradientModal
            visible={visible}
            onDismissed={onDismissed}
            size="md"
        >
            <div className="p-6">
                <div className="flex items-center space-x-4 mb-6">
                    <div className="flex-shrink-0">
                        <FontAwesomeIcon icon={faExclamationTriangle} className="h-8 w-8 text-yellow-400" />
                    </div>
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-100">
                            Foreign Key Constraint Violation
                        </h2>
                        <p className="text-sm text-neutral-400 mt-1">
                            Konstantin's Issue Detected
                        </p>
                    </div>
                </div>

                <div className="bg-red-900/20 border border-red-500/30 rounded-lg p-4 mb-6">
                    <p className="text-sm text-red-300 font-medium mb-2">
                        Cannot {actionType} row due to foreign key constraints:
                    </p>
                    <p className="text-xs text-red-200 font-mono bg-red-900/40 p-2 rounded" dangerouslySetInnerHTML={{ __html: escapeHtml(errorMessage) }} />
                </div>

                <div className="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-4 mb-6">
                    <h3 className="text-sm font-medium text-yellow-300 mb-2">
                        Force {actionType === 'edit' ? 'Update' : 'Delete'} Option
                    </h3>
                    <p className="text-xs text-yellow-200 mb-3">
                        You can force this operation by temporarily disabling foreign key checks. 
                        This will bypass the constraint validation.
                    </p>
                    <div className="bg-yellow-900/40 p-3 rounded border border-yellow-600/30">
                        <p className="text-xs text-yellow-100 font-semibold">⚠️ Warning:</p>
                        <ul className="text-xs text-yellow-200 mt-1 space-y-1">
                            <li>• This may break referential integrity</li>
                            <li>• Related data may become orphaned or inconsistent</li>
                            <li>• Use only if you understand the consequences</li>
                            <li>• Consider updating/deleting related records first</li>
                        </ul>
                    </div>
                </div>

                <div className="flex justify-end space-x-3">
                    <Button type="button" onClick={onDismissed}>
                        Cancel
                    </Button>
                    <Button 
                        type="button" 
                        onClick={onForceConfirm}
                        className="bg-red-600 hover:bg-red-700 text-white"
                    >
                        Force {actionType === 'edit' ? 'Update' : 'Delete'}
                    </Button>
                </div>
            </div>
        </GradientModal>
    );
};

export default ForeignKeyConstraintModal;