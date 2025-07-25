<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Workspaces\Hook;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\RelationshipType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SysLog\Action\Database as DatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\DataHandler\CommandMap;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;
use TYPO3\CMS\Workspaces\Messages\StageChangeMessage;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Contains some parts for staging, versioning and workspaces
 * to interact with the TYPO3 Core Engine.
 *
 * @internal This is a specific hook implementation and is not considered part of the Public TYPO3 API.
 */
#[Autoconfigure(public: true)]
class DataHandlerHook
{
    /**
     * For accumulating information about workspace stages raised
     * on elements so a single email is sent as notification.
     */
    protected array $notificationInfo = [];

    /**
     * Contains remapped IDs.
     */
    protected array $remappedIds = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly WorkspacePublishGate $workspacePublishGate,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly CommandMap $commandMap,
    ) {}

    /**
     * hook that is called before any cmd of the commandmap is executed
     *
     * @param DataHandler $dataHandler reference to the main DataHandler object
     */
    public function processCmdmap_beforeStart(DataHandler $dataHandler)
    {
        // Reset notification array
        $this->notificationInfo = [];
        // Resolve dependencies of version/workspaces actions:
        $dataHandler->cmdmap = $this->commandMap->process($dataHandler->cmdmap, $dataHandler->BE_USER->workspace);
    }

    /**
     * hook that is called when no prepared command was found
     *
     * @param string $command the command to be executed
     * @param string $table the table of the record
     * @param int $id the ID of the record
     * @param array $value the value containing the data
     * @param bool $commandIsProcessed can be set so that other hooks or
     * @param DataHandler $dataHandler reference to the main DataHandler object
     */
    public function processCmdmap($command, $table, $id, $value, &$commandIsProcessed, DataHandler $dataHandler)
    {
        // custom command "version"
        if ($command !== 'version') {
            return;
        }
        $action = (string)$value['action'];
        $comment = $value['comment'] ?? '';
        $notificationAlternativeRecipients = $value['notificationAlternativeRecipients'] ?? [];
        switch ($action) {
            case 'swap':
            case 'publish':
                $commandIsProcessed = true;
                $this->version_swap(
                    $table,
                    $id,
                    (int)$value['swapWith'],
                    $dataHandler,
                    $comment,
                    $notificationAlternativeRecipients
                );
                break;
            case 'setStage':
                $commandIsProcessed = true;
                $elementIds = GeneralUtility::intExplode(',', (string)$id, true);
                foreach ($elementIds as $elementId) {
                    $this->version_setStage(
                        $table,
                        $elementId,
                        $value['stageId'],
                        $comment,
                        $dataHandler,
                        $notificationAlternativeRecipients
                    );
                }
                break;
            default:
                // Do nothing
        }
    }

    /**
     * Hook called after all commands of the command map were done
     *
     * @param DataHandler $dataHandler reference to the main DataHandler object
     */
    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        foreach ($this->notificationInfo as $groupedNotificationInformation) {
            $emails = (array)$groupedNotificationInformation['recipients'];
            $affectedElements = [];
            foreach ($groupedNotificationInformation['elements'] as $elementInfo) {
                $elementTable = $elementInfo['table'];
                $elementUid = (int)$elementInfo['uid'];
                $record = BackendUtility::getRecord($elementTable, $elementUid, '*', '', false);
                $affectedElements[] = [
                    // 0 and 1 are kept for legacy reasons (could be used in email templates)
                    0 => $elementTable,
                    1 => $elementUid,
                    'table' => $elementTable,
                    'uid' => $elementUid,
                    'record' => $record,
                ];
            }

            $message = new StageChangeMessage(
                workspaceRecord: $groupedNotificationInformation['workspaceInfo'],
                stageId: (int)$groupedNotificationInformation['stageId'],
                affectedElements: $affectedElements,
                comment: $groupedNotificationInformation['comment'],
                recipients: $emails,
                currentUserRecord: $dataHandler->BE_USER->user,
            );
            $this->messageBus->dispatch($message);
            if ($dataHandler->enableLogging) {
                // @todo: Clean up $this->notificationEmailInfo to create a better data object from it, maybe
                //        instances of StageChangeMessage() directly. Since this is "after finish", we can
                //        probably not add the record within $this->notificationEmailInfo since DH may have
                //        changed it again. But we want to get the record at least only once here and submit
                //        it within StageChangeMessage(), so it does not have to fetch it again.
                // @todo: Consider if we should actually log this at all?
                foreach ($affectedElements as $elementInfo) {
                    $dataHandler->log(
                        $elementInfo['table'],
                        $elementInfo['uid'],
                        DatabaseAction::VERSIONIZE,
                        null,
                        SystemLogErrorClassification::MESSAGE,
                        'Notification email for stage change was sent to "{recipients}"',
                        null,
                        ['recipients' => implode('", "', array_column($emails, 'email'))],
                        (int)($elementInfo['record']['pid'] ?? -1)
                    );
                }
            }
        }
        // Reset notification array
        $this->notificationInfo = [];
        // Reset remapped IDs
        $this->remappedIds = [];
    }

    /**
     * Setting stage of record
     *
     * @param string $table Table name
     * @param int $stageId Stage ID to set
     * @param string $comment Comment that goes into log
     * @param array $notificationAlternativeRecipients comma separated list of recipients to notify instead of normal be_users
     */
    protected function version_setStage(string $table, int $id, $stageId, string $comment, DataHandler $dataHandler, array $notificationAlternativeRecipients = []): void
    {
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->isWorkspaceAware()) {
            $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to set stage for record failed: Table "{table}" does not support versioning', null, ['table' => $table]);
            return;
        }
        $record = BackendUtility::getRecord($table, $id);
        if (!is_array($record)) {
            $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to set stage for record failed: No Record');
            return;
        }
        if ($errorCode = $dataHandler->workspaceCannotEditOfflineVersion($table, $record)) {
            $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to set stage for record failed: {reason}', null, ['reason' => $errorCode]);
            return;
        }
        $pageRecord = [];
        if ($table === 'pages') {
            $pageRecord = $record;
        } elseif ((int)$record['pid'] > 0) {
            $pageRecord = BackendUtility::getRecord('pages', $record['pid']) ?? [];
        }
        if (!$dataHandler->hasPermissionToUpdate($table, $pageRecord)) {
            $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to set stage for record failed because you do not have edit access');
            return;
        }
        $currentStage = (int)$record['t3ver_stage'];
        if (!$dataHandler->BE_USER->workspaceCheckStageForCurrent($currentStage)) {
            // Check if user is allowed to the current stage, so it's also allowed to send to next stage
            $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'The member user tried to set a stage value "{stage}" that was not allowed', null, ['stage' => $stageId]);
            return;
        }
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [
                't3ver_stage' => $stageId,
            ],
            ['uid' => $id]
        );
        $dataHandler->log($table, $id, DatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::MESSAGE, 'Stage for record {table}:{uid} was changed to {stage}. Comment was: "{comment}"', null, ['table' => $table, 'uid' => $id, 'stage' =>  $stageId, 'comment' => mb_substr($comment, 0, 100)], $record['pid']);
        $workspaceInfo = $dataHandler->BE_USER->checkWorkspace($record['t3ver_wsid']);
        $workspaceId = (int)$workspaceInfo['uid'];
        // Write the stage change to history
        $historyStore = $this->getRecordHistoryStore($workspaceId, $dataHandler->BE_USER);
        $historyStore->changeStageForRecord($table, $id, ['current' => $currentStage, 'next' => $stageId, 'comment' => $comment, 'recipients' => $notificationAlternativeRecipients]);
        if ((int)$workspaceInfo['stagechg_notification'] > 0) {
            $this->notificationInfo = $this->createNotificationInformation($this->notificationInfo, $workspaceInfo, $table, $id, $stageId, $comment, $notificationAlternativeRecipients);
        }
    }

    /**
     * Publishing / Swapping (= switching) versions of a record
     * Version from archive (future/past, called "swap version") will get the uid of the "t3ver_oid", the official element with uid = "t3ver_oid" will get the new versions old uid. PIDs are swapped also
     *
     * @param string $table Table name
     * @param int $id UID of the online record to swap
     * @param int $swapWith UID of the workspace version to swap with!
     * @param DataHandler $dataHandler DataHandler object
     * @param string $comment Notification comment
     * @param array $notificationAlternativeRecipients comma separated list of recipients to notify instead of normal be_users
     */
    protected function version_swap(string $table, int $id, int $swapWith, DataHandler $dataHandler, string $comment, array $notificationAlternativeRecipients): void
    {
        $currentUserWorkspace = $dataHandler->BE_USER->workspace;
        // Currently live version, contents will be removed.
        $curVersion = BackendUtility::getRecord($table, $id);
        if ($curVersion === null) {
            return;
        }
        // Store original live version for publish history
        $originalLiveVersion = $curVersion;
        $pageRecord = [];
        if ($table === 'pages') {
            $pageRecord = $curVersion;
        } elseif ((int)$curVersion['pid'] > 0) {
            $pageRecord = BackendUtility::getRecord('pages', $curVersion['pid']) ?? [];
        }
        if (!$dataHandler->hasPermissionToUpdate($table, $pageRecord)) {
            // Return early if online record editing is denied
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'Error: You cannot swap versions for record {table}:{uid} you do not have access to edit', null, ['table' => $table, 'uid' => $id]);
            return;
        }
        // Versioned records which contents will be moved into $curVersion
        $isNewRecord = VersionState::tryFrom($curVersion['t3ver_state'] ?? 0) === VersionState::NEW_PLACEHOLDER;
        if ($isNewRecord) {
            if (!$dataHandler->hasPagePermission(Permission::PAGE_SHOW, $pageRecord)) {
                $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'You cannot publish a record you do not have edit and show permissions for');
                return;
            }
            // @todo: This early return is odd. It means version_swap_processFields() and versionPublishManyToManyRelations()
            //        below are not called for new records to be published. This is "fine" for mm since mm tables have no
            //        t3ver_wsid and need no publish as such. For inline relation publishing, this is indirectly resolved by the
            //        processCmdmap_beforeStart() hook, which adds additional commands for child records - a construct we
            //        may want to avoid altogether due to its complexity. It would be easier to follow if publish here would
            //        handle that instead.
            $this->publishNewRecord($table, $curVersion, $dataHandler, $comment, $notificationAlternativeRecipients);
            return;
        }
        $swapVersion = BackendUtility::getRecord($table, $swapWith);
        if (!is_array($swapVersion)) {
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::SYSTEM_ERROR, 'Error: Either online or swap version for {table}:{uid}->{offlineUid} could not be selected', null, ['table' => $table, 'uid' => $id, 'offlineUid' => $swapWith]);
            return;
        }
        $workspaceId = (int)$swapVersion['t3ver_wsid'];
        $currentStage = (int)$swapVersion['t3ver_stage'];
        if (!$this->workspacePublishGate->isGranted($dataHandler->BE_USER, $workspaceId)) {
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'User could not publish records from workspace #{workspace}', null, ['workspace' => $workspaceId]);
            return;
        }
        $wsAccess = $dataHandler->BE_USER->checkWorkspace($workspaceId);
        if (!($workspaceId <= 0 || !($wsAccess['publish_access'] & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE) || $currentStage === StagesService::STAGE_PUBLISH_ID)) {
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'Records in workspace #{workspace} can only be published when in "Publish" stage', null, ['workspace' => $workspaceId]);
            return;
        }
        $workspaceSwapPageRecord = [];
        if ($table === 'pages') {
            $workspaceSwapPageRecord = $swapVersion;
        } elseif ((int)$swapVersion['pid'] > 0) {
            $workspaceSwapPageRecord = BackendUtility::getRecord('pages', $swapVersion['pid']) ?? [];
        }
        if (!$dataHandler->hasPagePermission(Permission::PAGE_SHOW, $workspaceSwapPageRecord)
            || !$dataHandler->hasPermissionToUpdate($table, $workspaceSwapPageRecord)
        ) {
            $dataHandler->log($table, $swapWith, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'You cannot publish a record you do not have edit and show permissions for');
            return;
        }
        // Check if the swapWith record really IS a version of the original!
        if (!(((int)$swapVersion['t3ver_oid'] > 0 && (int)$curVersion['t3ver_oid'] === 0) && (int)$swapVersion['t3ver_oid'] === (int)$id)) {
            $dataHandler->log($table, $swapWith, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::SYSTEM_ERROR, 'In offline record, either t3ver_oid was not set or the t3ver_oid didn\'t match the id of the online version as it must');
            return;
        }
        $versionState = VersionState::tryFrom($swapVersion['t3ver_state'] ?? 0);

        $schema = $this->tcaSchemaFactory->get($table);
        // Find fields to keep
        $keepFields = $this->getFieldNamesToKeep($schema);
        // Sorting needs to be exchanged for moved records, but should be kept otherwise
        if ($schema->hasCapability(TcaSchemaCapability::SortByField) && $versionState !== VersionState::MOVE_POINTER) {
            $keepFields[] = $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName();
        }
        // Do not update the creation date of the live record while publishing
        if ($schema->hasCapability(TcaSchemaCapability::CreatedAt)) {
            $keepFields[] = $schema->getCapability(TcaSchemaCapability::CreatedAt)->getFieldName();
        }
        // l10n-fields must be kept otherwise the localization
        // will be lost during the publishing
        if ($schema->isLanguageAware()) {
            $keepFields[] = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
        }
        // Swap "keepfields"
        foreach ($keepFields as $fN) {
            $tmp = $swapVersion[$fN];
            $swapVersion[$fN] = $curVersion[$fN];
            $curVersion[$fN] = $tmp;
        }
        // Preserve states:
        $t3ver_state = [];
        $t3ver_state['swapVersion'] = $swapVersion['t3ver_state'];
        // Modify offline version to become online:
        // Set pid for ONLINE (but not for moved records)
        if ($versionState !== VersionState::MOVE_POINTER) {
            $swapVersion['pid'] = (int)$curVersion['pid'];
        }
        // We clear this because t3ver_oid only make sense for offline versions
        // and we want to prevent unintentional misuse of this
        // value for online records.
        $swapVersion['t3ver_oid'] = 0;
        // In case of swapping and the offline record has a state
        // (like 2 or 4 for deleting or move-pointer) we set the
        // current workspace ID so the record is not deselected.
        // @todo: It is odd these information are updated in $swapVersion *before* version_swap_processFields
        //        version_swap_processFields() and versionPublishManyToManyRelations() are called. This leads
        //        to the situation that versionPublishManyToManyRelations() needs another argument to transfer
        //        the "from workspace" information which would usually be retrieved by accessing $swapVersion['t3ver_wsid']
        $swapVersion['t3ver_wsid'] = 0;
        $swapVersion['t3ver_stage'] = 0;
        $swapVersion['t3ver_state'] = VersionState::DEFAULT_STATE->value;
        // Take care of relations in each field (e.g. IRRE)
        foreach ($schema->getFields() as $field) {
            $this->version_swap_processFields($table, $field->getConfiguration(), $curVersion, $swapVersion, $dataHandler);
        }
        $dataHandler->versionPublishManyToManyRelations($table, $curVersion, $swapVersion, $workspaceId);
        unset($swapVersion['uid']);
        // Modify online version to become offline:
        unset($curVersion['uid']);
        // Mark curVersion to contain the oid
        $curVersion['t3ver_oid'] = $id;
        $curVersion['t3ver_wsid'] = 0;
        // Increment lifecycle counter
        $curVersion['t3ver_stage'] = 0;
        $curVersion['t3ver_state'] = VersionState::DEFAULT_STATE->value;
        // Generating proper history data to prepare logging
        $dataHandler->compareFieldArrayWithCurrentAndUnset($table, $id, $swapVersion);
        $dataHandler->compareFieldArrayWithCurrentAndUnset($table, $swapWith, $curVersion);

        // Execute swapping:
        $this->connectionPool->getConnectionForTable($table)->update($table, $swapVersion, ['uid' => $id]);
        // @todo: We should stop updating the workspace record, it will be discarded later on anyways.
        $this->connectionPool->getConnectionForTable($table)->update($table, $curVersion, ['uid' => $swapWith]);

        // Update localized elements to use the live l10n_parent now
        $this->updateL10nOverlayRecordsOnPublish($schema, $id, $swapWith, $workspaceId, $dataHandler);
        // Register swapped ids for later remapping:
        $this->remappedIds[$table][$id] = $swapWith;
        $this->remappedIds[$table][$swapWith] = $id;
        if (VersionState::tryFrom($t3ver_state['swapVersion'] ?? 0) === VersionState::DELETE_PLACEHOLDER) {
            // We're publishing a delete placeholder t3ver_state = 2. This means the live record should
            // be set to deleted. We're currently in some workspace and deal with a live record here. Thus,
            // we temporarily set backend user workspace to 0 so all operations happen as in live.
            $dataHandler->BE_USER->workspace = 0;
            // @todo: This should probably not use such a high level method
            $dataHandler->deleteAction($table, $id, true);
            $dataHandler->BE_USER->workspace = $currentUserWorkspace;
        }
        $this->eventDispatcher->dispatch(new AfterRecordPublishedEvent($table, $id, $workspaceId));
        $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::MESSAGE, 'Record "{table}" uid {liveId}=>{versionId} was published.', null, ['table' => $table, 'versionId' => $swapWith, 'liveId' => $id], (int)$swapVersion['pid']);
        // Create publish entry with complete diff showing what changed
        $publishPayload = [
            'oldRecord' => $originalLiveVersion,
            'newRecord' => array_diff_assoc($swapVersion, $originalLiveVersion), // This contains the new live data after swap
            'workspaceId' => $workspaceId,
            'comment' => $comment,
            'recipients' => $notificationAlternativeRecipients,
        ];
        $historyStore = $this->getRecordHistoryStore((int)$wsAccess['uid'], $dataHandler->BE_USER);
        $historyStore->publishRecord($table, $id, $swapWith, $publishPayload);

        $this->notificationInfo = $this->createNotificationInformation(
            $this->notificationInfo,
            $wsAccess,
            $table,
            $id,
            StagesService::STAGE_PUBLISH_EXECUTE_ID,
            $comment,
            $notificationAlternativeRecipients
        );

        // Clear cache:
        $dataHandler->registerRecordIdForPageCacheClearing($table, $id);
        // Delete the old versioned record from the database
        // @todo: Blind delete: Delete place holder handling above may have deleted the row already.
        $this->connectionPool->getConnectionForTable($table)->delete($table, ['uid' => $swapWith], [Connection::PARAM_INT]);
        if ($table !== 'pages') {
            // @todo: Fishy call. This should probably be relocated or handled somewhere else: Handling such
            //        dependencies at this point is not a great idea, this should be more explicit.
            $dataHandler->deleteL10nOverlayRecords($table, $swapWith);
            $dataHandler->log($table, $swapWith, DatabaseAction::DELETE, null, SystemLogErrorClassification::MESSAGE, 'Record {table}:{uid} was deleted unrecoverable from pages:{pid}', null, ['table' => $table, 'uid' =>  $swapWith, 'pid' => (int)$swapVersion['pid']], (int)($swapVersion['pid']));
            // Update reference index with table/uid on left side (recuid)
            $dataHandler->updateRefIndex($table, $swapWith, $currentUserWorkspace);
            // Update reference index with table/uid on right side (ref_uid). Important if children of a relation are deleted.
            $dataHandler->registerReferenceIndexUpdateForReferencesToItem($table, $swapWith, $currentUserWorkspace);
        }
        // Update reference index of the live record - which could have been a workspace record in case 'new'
        $dataHandler->updateRefIndex($table, $id, 0);
        // The 'swapWith' record has been deleted, so we can drop any reference index the record is involved in
        $dataHandler->registerReferenceIndexRowsForDrop($table, $swapWith, (int)$dataHandler->BE_USER->workspace);
    }

    /**
     * If an editor is doing "partial" publishing, the translated children need to be "linked" to the now pointed
     * live record, as if the versioned record (which is deleted) would have never existed.
     *
     * This is related to the l10n_source and l10n_parent fields.
     *
     * This needs to happen before the hook calls DataHandler->deleteEl() otherwise the children get deleted as well.
     *
     * @param int $liveId the live version / online version of the record that was just published
     * @param int $previouslyUsedVersionId the versioned record ID (wsid>0) which is about to be deleted
     */
    protected function updateL10nOverlayRecordsOnPublish(TcaSchema $schema, int $liveId, int $previouslyUsedVersionId, int $workspaceId, DataHandler $dataHandler): void
    {
        if (!$schema->isLanguageAware()) {
            return;
        }
        if (!$schema->isWorkspaceAware()) {
            return;
        }
        // The database table of the published record
        $table = $schema->getName();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $l10nParentFieldName = $languageCapability->getTranslationOriginPointerField()->getName();
        $constraints = $queryBuilder->expr()->eq(
            $l10nParentFieldName,
            $queryBuilder->createNamedParameter($previouslyUsedVersionId, Connection::PARAM_INT)
        );
        $translationSourceFieldName = $languageCapability->getTranslationSourceField()?->getName();
        if ($translationSourceFieldName) {
            $constraints = $queryBuilder->expr()->or(
                $constraints,
                $queryBuilder->expr()->eq(
                    $translationSourceFieldName,
                    $queryBuilder->createNamedParameter($previouslyUsedVersionId, Connection::PARAM_INT)
                )
            );
        }
        $queryBuilder
            ->select('uid', $l10nParentFieldName)
            ->from($table)
            ->where(
                $constraints,
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)
                )
            );
        if ($translationSourceFieldName) {
            $queryBuilder->addSelect($translationSourceFieldName);
        }
        $statement = $queryBuilder->executeQuery();
        while ($record = $statement->fetchAssociative()) {
            $updateFields = [];
            $dataTypes = [Connection::PARAM_INT];
            if ((int)$record[$l10nParentFieldName] === $previouslyUsedVersionId) {
                $updateFields[$l10nParentFieldName] = $liveId;
                $dataTypes[] = Connection::PARAM_INT;
            }
            if ($translationSourceFieldName && (int)$record[$translationSourceFieldName] === $previouslyUsedVersionId) {
                $updateFields[$translationSourceFieldName] = $liveId;
                $dataTypes[] = Connection::PARAM_INT;
            }
            if (empty($updateFields)) {
                continue;
            }
            $this->connectionPool->getConnectionForTable($table)->update(
                $table,
                $updateFields,
                ['uid' => (int)$record['uid']],
                $dataTypes
            );
            $dataHandler->updateRefIndex($table, $record['uid']);
        }
    }

    /**
     * Processes fields of a record for the publishing/swapping process.
     * Basically this takes care of IRRE (type "inline") child references.
     *
     * @param string $tableName Table name
     * @param array $configuration TCA field configuration
     * @param array $liveData Live record data
     * @param array $versionData Version record data
     * @param DataHandler $dataHandler Calling data-handler object
     */
    protected function version_swap_processFields(string $tableName, array $configuration, array $liveData, array $versionData, DataHandler $dataHandler)
    {
        if (RelationshipType::fromTcaConfiguration($configuration) !== RelationshipType::OneToMany) {
            return;
        }
        $foreignTable = $configuration['foreign_table'];
        // Read relations that point to the current record (e.g. live record):
        $liveRelations = $this->createRelationHandlerInstance();
        $liveRelations->setWorkspaceId(0);
        $liveRelations->start('', $foreignTable, '', $liveData['uid'], $tableName, $configuration);
        // Read relations that point to the record to be swapped with e.g. draft record):
        $versionRelations = $this->createRelationHandlerInstance();
        $versionRelations->setUseLiveReferenceIds(false);
        $versionRelations->start('', $foreignTable, '', $versionData['uid'], $tableName, $configuration);
        // Update relations for both (workspace/versioning) sites:
        if (!empty($liveRelations->itemArray)) {
            $dataHandler->addRemapAction(
                $tableName,
                (int)$liveData['uid'],
                [$this, 'updateInlineForeignFieldSorting'],
                [(int)$liveData['uid'], $foreignTable, $liveRelations->tableArray[$foreignTable], $configuration, $dataHandler->BE_USER->workspace]
            );
        }
        if (!empty($versionRelations->itemArray)) {
            $dataHandler->addRemapAction(
                $tableName,
                (int)$liveData['uid'],
                [$this, 'updateInlineForeignFieldSorting'],
                [(int)$liveData['uid'], $foreignTable, $versionRelations->tableArray[$foreignTable], $configuration, 0]
            );
        }
    }

    /**
     * When a new record in a workspace is published, there is no "replacing" the online version with
     * the versioned record, but instead the workspace ID and the state is changed.
     */
    protected function publishNewRecord(string $table, array $newRecordInWorkspace, DataHandler $dataHandler, string $comment, array $notificationAlternativeRecipients): void
    {
        $id = (int)$newRecordInWorkspace['uid'];
        $workspaceId = (int)$newRecordInWorkspace['t3ver_wsid'];
        if (!$this->workspacePublishGate->isGranted($dataHandler->BE_USER, $workspaceId)) {
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'User could not publish records from workspace #{workspace}', null, ['workspace' => $workspaceId]);
            return;
        }
        $wsAccess = $dataHandler->BE_USER->checkWorkspace($workspaceId);
        if (!($workspaceId <= 0 || !($wsAccess['publish_access'] & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE) || (int)$newRecordInWorkspace['t3ver_stage'] === StagesService::STAGE_PUBLISH_ID)) {
            $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::USER_ERROR, 'Records in workspace #{workspace} can only be published when in "Publish" stage', null, ['workspace' => $workspaceId]);
            return;
        }
        // Modify versioned record to become online
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [
                't3ver_oid' => 0,
                't3ver_wsid' => 0,
                't3ver_stage' => 0,
                't3ver_state' => VersionState::DEFAULT_STATE->value,
            ],
            [
                'uid' => $id,
            ],
            [
                Connection::PARAM_INT,
                Connection::PARAM_INT,
                Connection::PARAM_INT,
                Connection::PARAM_INT,
                Connection::PARAM_INT,
            ]
        );
        $this->eventDispatcher->dispatch(new AfterRecordPublishedEvent($table, $id, $workspaceId));

        $dataHandler->log($table, $id, DatabaseAction::PUBLISH, null, SystemLogErrorClassification::MESSAGE, 'Record {table}:{uid} was published.', null, ['table' => $table, 'uid' => $id], (int)$newRecordInWorkspace['pid']);
        // Write the publish action to the history (usually this is done in updateDB in DataHandler, but we do a manual SQL change)
        $historyStore = $this->getRecordHistoryStore((int)$wsAccess['uid'], $dataHandler->BE_USER);
        $historyStore->publishRecord(
            $table,
            $id,
            0,
            [
                'workspaceId' => $workspaceId,
                'comment' => $comment,
                'recipients' => $notificationAlternativeRecipients,
            ]
        );
        $this->notificationInfo = $this->createNotificationInformation(
            $this->notificationInfo,
            $wsAccess,
            $table,
            $id,
            StagesService::STAGE_PUBLISH_EXECUTE_ID,
            $comment,
            $notificationAlternativeRecipients
        );

        // Clear cache
        $dataHandler->registerRecordIdForPageCacheClearing($table, $id);
        // Update the reference index: Drop the references in the workspace, but update them in the live workspace
        $dataHandler->registerReferenceIndexRowsForDrop($table, $id, $workspaceId);
        $dataHandler->updateRefIndex($table, $id, 0);
        $this->updateReferenceIndexForL10nOverlays($table, $id, $workspaceId, $dataHandler);

        // When dealing with mm relations on local side, existing refindex rows of the new workspace record
        // need to be re-calculated for the now live record. Scenario ManyToMany Publish createContentAndAddRelation
        // These calls are similar to what is done in DH->versionPublishManyToManyRelations() and can not be
        // used from there since publishing new records does not call that method, see @todo in version_swap().
        $dataHandler->registerReferenceIndexUpdateForReferencesToItem($table, $id, $workspaceId, 0);
        $dataHandler->registerReferenceIndexUpdateForReferencesToItem($table, $id, $workspaceId);
    }

    /**
     * A new record was just published, but the reference index for the localized elements needs
     * an update too.
     */
    protected function updateReferenceIndexForL10nOverlays(string $table, int $newVersionedRecordId, int $workspaceId, DataHandler $dataHandler): void
    {
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->isLanguageAware()) {
            return;
        }
        if (!$schema->isWorkspaceAware()) {
            return;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $l10nParentFieldName = $languageCapability->getTranslationOriginPointerField()->getName();
        $constraints = $queryBuilder->expr()->eq(
            $l10nParentFieldName,
            $queryBuilder->createNamedParameter($newVersionedRecordId, Connection::PARAM_INT)
        );
        $translationSourceFieldName = $languageCapability->getTranslationSourceField()?->getName();
        if ($translationSourceFieldName) {
            $constraints = $queryBuilder->expr()->or(
                $constraints,
                $queryBuilder->expr()->eq(
                    $translationSourceFieldName,
                    $queryBuilder->createNamedParameter($newVersionedRecordId, Connection::PARAM_INT)
                )
            );
        }
        $queryBuilder
            ->select('uid', $l10nParentFieldName)
            ->from($table)
            ->where(
                $constraints,
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)
                )
            );
        if ($translationSourceFieldName) {
            $queryBuilder->addSelect($translationSourceFieldName);
        }
        $statement = $queryBuilder->executeQuery();
        while ($record = $statement->fetchAssociative()) {
            $dataHandler->updateRefIndex($table, $record['uid']);
        }
    }

    /**
     * Updates foreign field sorting values of versioned and live
     * parents after(!) the whole structure has been published.
     *
     * This method is used as callback function in
     * DataHandlerHook::version_swap_procBasedOnFieldType().
     * Sorting fields ("sortby") are not modified during the
     * workspace publishing/swapping process directly.
     *
     * @param int $parentId
     * @param string $foreignTableName
     * @param int[] $foreignIds
     * @param array $configuration
     * @param int $targetWorkspaceId
     * @internal
     */
    public function updateInlineForeignFieldSorting(int $parentId, string $foreignTableName, $foreignIds, array $configuration, $targetWorkspaceId)
    {
        $remappedIds = [];
        // Use remapped ids (live id <-> version id)
        foreach ($foreignIds as $foreignId) {
            if (!empty($this->remappedIds[$foreignTableName][$foreignId])) {
                $remappedIds[] = $this->remappedIds[$foreignTableName][$foreignId];
            } else {
                $remappedIds[] = $foreignId;
            }
        }

        $relationHandler = $this->createRelationHandlerInstance();
        $relationHandler->setWorkspaceId($targetWorkspaceId);
        $relationHandler->setUseLiveReferenceIds(false);
        $relationHandler->start(implode(',', $remappedIds), $foreignTableName);
        $relationHandler->processDeletePlaceholder();
        $relationHandler->writeForeignField($configuration, $parentId);
    }

    /**
     * Returns all fieldnames from a table which have the unique evaluation type set,
     * that should not exchange the content between LIVE and the versioned record.
     *
     * @return string[] Array of fieldnames
     */
    protected function getFieldNamesToKeep(TcaSchema $schema): array
    {
        $listArr = [];
        foreach ($schema->getFields() as $field) {
            if ($field->isType(TableColumnType::INPUT, TableColumnType::EMAIL)) {
                $evalCodesArray = GeneralUtility::trimExplode(',', $field->getConfiguration()['eval'] ?? '', true);
                if (in_array('uniqueInPid', $evalCodesArray) || in_array('unique', $evalCodesArray)) {
                    $listArr[] = $field->getName();
                }
            } elseif ($field->isType(TableColumnType::UUID)) {
                $listArr[] = $field->getName();
            }
        }
        return $listArr;
    }

    /**
     * Makes an instance for RecordHistoryStore. This is needed as DataHandler would usually trigger the setHistory()
     * but has no support for tracking "stage change" information.
     *
     * So we have to do this manually. Usually a $dataHandler->updateDB() could do this, but we use raw update statements
     * here in workspaces for the time being, mostly because we also want to add "comment"
     */
    protected function getRecordHistoryStore(int $workspaceId, BackendUserAuthentication $user): RecordHistoryStore
    {
        return GeneralUtility::makeInstance(
            RecordHistoryStore::class,
            RecordHistoryStore::USER_BACKEND,
            (int)$user->user['uid'],
            $user->getOriginalUserIdWhenInSwitchUserMode(),
            $GLOBALS['EXEC_TIME'],
            $workspaceId
        );
    }

    protected function createNotificationInformation(array $notificationBatch, array $workspaceInfo, string $table, int $id, int $stageId, string $comment, array $recipients): array
    {
        $identifier = $workspaceInfo['uid'] . ':' . $stageId . ':' . $comment;
        if (!isset($notificationBatch[$identifier])) {
            $notificationBatch[$identifier]['workspaceInfo'] = $workspaceInfo;
            $notificationBatch[$identifier]['stageId'] = $stageId;
            $notificationBatch[$identifier]['comment'] = $comment;
            $notificationBatch[$identifier]['recipients'] = $recipients;
        }
        $notificationBatch[$identifier]['elements'][] = ['table' => $table, 'uid' => $id];
        return $notificationBatch;
    }

    protected function createRelationHandlerInstance(): RelationHandler
    {
        return GeneralUtility::makeInstance(RelationHandler::class);
    }
}
