import React from 'react';
import styled from 'styled-components';
import tw from 'twin.macro';
import { DatabaseContents } from '@/api/server/databases/getDatabaseContents';
import DatabaseTablesView from './DatabaseTablesView';
import SQLConsole from './SQLConsole';

interface DatabaseTablesContainerProps {
    databaseId: string;
    databaseContents: DatabaseContents;
    loading: boolean;
    onRefresh?: () => void;
}

const Container = styled.div`
    ${tw`w-full h-full flex flex-col gap-6`};
`;

const TablesSection = styled.div`
    ${tw`flex-1`};
`;

const ConsoleSection = styled.div`
    ${tw`flex-shrink-0`};
`;

const DatabaseTablesContainer: React.FC<DatabaseTablesContainerProps> = ({
    databaseId,
    databaseContents,
    loading,
    onRefresh
}) => {
    return (
        <Container>
            <TablesSection>
                <DatabaseTablesView 
                    databaseId={databaseId}
                    databaseContents={databaseContents}
                    loading={loading}
                    onRefresh={onRefresh}
                />
            </TablesSection>
            
            <ConsoleSection>
                <SQLConsole databaseId={databaseId} />
            </ConsoleSection>
        </Container>
    );
};

export default DatabaseTablesContainer;