import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faUpload, faSearch, faHeartbeat } from '@fortawesome/free-solid-svg-icons';
import { Button } from '@/components/elements/button/index';
import ImportDatabaseModal from '@/components/server/databases/ImportDatabaseModal';
import DatabaseContentModal from '@/components/server/databases/DatabaseContentModal';
import ExportDatabaseModal from '@/components/server/databases/ExportDatabaseModal';
import DatabaseHealthModal from '@/components/server/databases/modal_components/DatabaseHealthModal';
import { ServerDatabase } from '@/api/server/databases/getServerDatabases';
import tw from 'twin.macro';

interface DatabaseActionsProps {
    database: ServerDatabase;
}

const DatabaseActions: React.FC<DatabaseActionsProps> = ({ database }) => {
    const [uploadVisible, setUploadVisible] = useState(false);
    const [contentsVisible, setContentsVisible] = useState(false);
    const [exportVisible, setExportVisible] = useState(false);
    const [healthVisible, setHealthVisible] = useState(false);

    return (
        <>
            {/* Action Buttons */}
            <Button variant={Button.Variants.Secondary} css={tw`mr-2`} onClick={() => setContentsVisible(true)}>
                <FontAwesomeIcon icon={faSearch} fixedWidth />
            </Button>
            
            <Button variant={Button.Variants.Secondary} css={tw`mr-2`} onClick={() => setExportVisible(true)}>
                <FontAwesomeIcon icon={faDownload} fixedWidth />
            </Button>
            
            <Button variant={Button.Variants.Secondary} css={tw`mr-2`} onClick={() => setUploadVisible(true)}>
                <FontAwesomeIcon icon={faUpload} fixedWidth />
            </Button>
            
            <Button variant={Button.Variants.Secondary} css={tw`mr-2`} onClick={() => setHealthVisible(true)}>
                <FontAwesomeIcon icon={faHeartbeat} fixedWidth />
            </Button>

            {/* Modals */}
            <ImportDatabaseModal
                databaseId={database.id}
                visible={uploadVisible}
                onDismissed={() => setUploadVisible(false)}
            />
            
            <DatabaseContentModal
                databaseId={database.id}
                databaseName={database.name}
                visible={contentsVisible}
                onDismissed={() => setContentsVisible(false)}
            />
            
            <ExportDatabaseModal
                databaseId={database.id}
                databaseName={database.name}
                visible={exportVisible}
                onDismissed={() => setExportVisible(false)}
            />
            
            <DatabaseHealthModal
                databaseId={database.id}
                databaseName={database.name}
                visible={healthVisible}
                onDismissed={() => setHealthVisible(false)}
            />
        </>
    );
};

export default DatabaseActions;
