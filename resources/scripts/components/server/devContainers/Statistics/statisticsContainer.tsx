import React from 'react';
import { motion } from 'framer-motion';
import StatisticsInfoCards from './statisticsInfoCards';
import TrafficPieCharts from './trafficPieCharts';
import PacketsCards from './packetsCards';
import StatisticsGraph from './statisticsGraph';
import NetworkUsageCard from './networkUsageCard';
import ProtocolPieChart from './protocolPieChart';

const StatisticsContainer: React.FC = () => {
    return (
        <div className="p-6 flex justify-center mt-12">
            <div className="max-w-[1200px] w-full bg-gray-700 p-8 rounded-lg space-y-8 shadow-lg">
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <StatisticsGraph/>
                </motion.div>
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <NetworkUsageCard/>
                </motion.div>
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <StatisticsInfoCards/>
                </motion.div>
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <PacketsCards/>
                </motion.div>
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <TrafficPieCharts/>
                </motion.div>
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, margin: "-100px" }}
                    transition={{ duration: 0.5 }}
                >
                    <ProtocolPieChart/>
                </motion.div>
            </div>
        </div>
    );
};

export default StatisticsContainer;
