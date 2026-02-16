import React from 'react';
import { ChartPieIcon, ViewListIcon, ChartBarIcon, ChartSquareBarIcon } from '@heroicons/react/outline';

export type ChartType = 'line' | 'pie' | 'polar' | 'radar' | 'bar';

interface ChartTypeSelectorProps {
    selectedType: ChartType;
    onChange: (type: ChartType) => void;
    className?: string;
}

const ChartTypeSelector: React.FC<ChartTypeSelectorProps> = ({ selectedType, onChange, className = '' }) => {
    return (
        <div className={`flex items-center space-x-2 ${className}`}>
            <div className="text-xs text-gray-400 font-medium mr-2">Chart Type:</div>
            <div className="flex bg-gray-800/50 rounded-lg p-1 backdrop-blur-sm border border-gray-700/30">
                <button
                    onClick={() => onChange('line')}
                    className={`p-1.5 rounded-md flex items-center justify-center transition-all duration-200 ${
                        selectedType === 'line'
                            ? 'bg-gradient-to-br from-blue-600/40 to-cyan-600/40 text-blue-100 shadow-md shadow-blue-900/20'
                            : 'hover:bg-gray-700/50 text-gray-400'
                    }`}
                    title="Line Chart"
                >
                    <ViewListIcon className="h-4 w-4" />
                </button>
                
                <button
                    onClick={() => onChange('bar')}
                    className={`p-1.5 rounded-md flex items-center justify-center transition-all duration-200 ${
                        selectedType === 'bar'
                            ? 'bg-gradient-to-br from-blue-600/40 to-cyan-600/40 text-blue-100 shadow-md shadow-blue-900/20'
                            : 'hover:bg-gray-700/50 text-gray-400'
                    }`}
                    title="Bar Chart"
                >
                    <ChartBarIcon className="h-4 w-4" />
                </button>

                <button
                    onClick={() => onChange('pie')}
                    className={`p-1.5 rounded-md flex items-center justify-center transition-all duration-200 ${
                        selectedType === 'pie'
                            ? 'bg-gradient-to-br from-blue-600/40 to-cyan-600/40 text-blue-100 shadow-md shadow-blue-900/20'
                            : 'hover:bg-gray-700/50 text-gray-400'
                    }`}
                    title="Pie Chart"
                >
                    <ChartPieIcon className="h-4 w-4" />
                </button>

                <button
                    onClick={() => onChange('polar')}
                    className={`p-1.5 rounded-md flex items-center justify-center transition-all duration-200 ${
                        selectedType === 'polar'
                            ? 'bg-gradient-to-br from-blue-600/40 to-cyan-600/40 text-blue-100 shadow-md shadow-blue-900/20'
                            : 'hover:bg-gray-700/50 text-gray-400'
                    }`}
                    title="Polar Area Chart"
                >
                    <div className="h-4 w-4 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <circle cx="12" cy="12" r="9" strokeWidth="2" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v9l-6.5 6.5" strokeWidth="2" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v9l6.5 6.5" strokeWidth="2" />
                        </svg>
                    </div>
                </button>

                <button
                    onClick={() => onChange('radar')}
                    className={`p-1.5 rounded-md flex items-center justify-center transition-all duration-200 ${
                        selectedType === 'radar'
                            ? 'bg-gradient-to-br from-blue-600/40 to-cyan-600/40 text-blue-100 shadow-md shadow-blue-900/20'
                            : 'hover:bg-gray-700/50 text-gray-400'
                    }`}
                    title="Radar Chart"
                >
                    <ChartSquareBarIcon className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
};

export default ChartTypeSelector;
