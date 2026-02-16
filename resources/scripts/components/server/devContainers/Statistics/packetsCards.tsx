import React, { useState } from 'react';
import { CloudDownloadIcon, CloudUploadIcon } from '@heroicons/react/outline';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { SocketEvent } from '@/components/server/events';

interface PacketStats {
    rx: number;
    tx: number;
}

export default () => {
    const [stats, setStats] = useState<PacketStats>({ rx: 0, tx: 0 });

    useWebsocketEvent(SocketEvent.STATS, (data: string) => {
        try {
            const values = JSON.parse(data);
            if (values.network) {
                setStats({
                    rx: values.network.rx_packets || 0,
                    tx: values.network.tx_packets || 0
                });
            }
        } catch (error) {
            console.error('Failed to parse network statistics:', error);
        }
    });
    const formatNumberWithCommas = (num: number) => {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    };

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8 mb-8">
            <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-6 border border-blue-500/20 shadow-lg hover:shadow-blue-500/5 transition-all duration-200">
                <div className="flex items-center mb-4">
                    <div className="bg-gradient-to-br from-blue-600/40 to-cyan-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-blue-400/50 shadow-lg shadow-blue-500/20">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6 text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="text-blue-400 font-medium text-lg">Incoming Packets</h3>
                        <p className="text-white text-2xl font-bold tracking-tight mt-1">{stats.rx.toLocaleString()} <span className="text-blue-400/70 text-base font-normal">packets</span></p>
                    </div>
                </div>
                
                <div className="mt-4 bg-blue-500/5 rounded-lg p-3 border border-blue-500/10">
                    <div className="flex items-center">
                        <div className="bg-blue-500/10 p-1 rounded mr-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <p className="text-xs text-gray-400">Total received packets since server start</p>
                    </div>
                </div>
            </div>
            <div className="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-6 border border-amber-500/20 shadow-lg hover:shadow-amber-500/5 transition-all duration-200">
                <div className="flex items-center mb-4">
                    <div className="bg-gradient-to-br from-amber-600/40 to-amber-900/40 p-3 rounded-xl mr-4 backdrop-blur-sm ring-1 ring-amber-400/50 shadow-lg shadow-amber-500/20">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M7 11l5-5m0 0l5 5m-5-5v12" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="text-amber-400 font-medium text-lg">Outgoing Packets</h3>
                        <p className="text-white text-2xl font-bold tracking-tight mt-1">{stats.tx.toLocaleString()} <span className="text-amber-400/70 text-base font-normal">packets</span></p>
                    </div>
                </div>
                
                <div className="mt-4 bg-amber-500/5 rounded-lg p-3 border border-amber-500/10">
                    <div className="flex items-center">
                        <div className="bg-amber-500/10 p-1 rounded mr-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <p className="text-xs text-gray-400">Total transmitted packets since server start</p>
                    </div>
                </div>
            </div>
        </div>
    );
};