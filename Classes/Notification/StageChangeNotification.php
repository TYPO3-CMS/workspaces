<?php

declare(strict_types=1);

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

namespace TYPO3\CMS\Workspaces\Notification;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Routing\UnableToLinkToPageException;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3\CMS\Workspaces\Messages\StageChangeMessage;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Responsible for sending out emails when one or multiple records have been changed / sent to the next stage.
 *
 * Relevant options are "tx_workspaces.emails.*" via userTS / pageTS.
 *
 * @internal This is a concrete implementation of sending out emails, and not part of the public TYPO3 Core API
 */
readonly class StageChangeNotification
{
    public function __construct(
        private StagesService $stagesService,
        private PreviewUriBuilder $previewUriBuilder,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * Send an email notification to users in workspace in multiple languages, depending on each BE users' language preference.
     */
    public function notifyStageChange(StageChangeMessage $message): void
    {
        if ($message->recipients === []) {
            // No email recipients, nothing to do
            return;
        }
        $affectedElements = $message->affectedElements;
        $firstElement = reset($affectedElements);
        $elementTable = $firstElement['table'];
        $elementUid = (int)$firstElement['uid'];
        $elementRecord = $firstElement['record'];
        $recordTitle = BackendUtility::getRecordTitle($elementTable, $elementRecord);
        $pageUid = $this->findFirstPageId($elementTable, $elementUid, $elementRecord);
        $emailConfig = BackendUtility::getPagesTSconfig($pageUid)['tx_workspaces.']['emails.'] ?? [];
        $emailConfig = GeneralUtility::removeDotsFromTS($emailConfig);

        $previewLink = '';
        try {
            $schema = $this->tcaSchemaFactory->get($elementTable);
            if ($schema->isLanguageAware()) {
                $languageId = $elementRecord[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] ?? 0;
            } else {
                $languageId = 0;
            }
            $previewLink = $this->previewUriBuilder->buildUriForPage($pageUid, $languageId);
        } catch (UnableToLinkToPageException) {
            // Generating a preview for a page that is a "delete placeholder"
            // in workspaces fails. No preview link in this case.
        }

        $viewPlaceholders = [
            'pageId' => $pageUid,
            'workspace' => $message->workspaceRecord,
            'rootLine' => BackendUtility::getRecordPath($pageUid, '', 20),
            'currentUser' => $message->currentUserRecord,
            'additionalMessage' => $message->comment,
            'recordTitle' => $recordTitle,
            'affectedElements' => $affectedElements,
            'nextStage' => $this->stagesService->getStageTitle($message->stageId),
            'previewLink' => $previewLink,
        ];

        $sentEmails = [];
        foreach ($message->recipients as $recipientData) {
            if (!isset($recipientData['email'])) {
                continue;
            }
            // don't send an email twice
            if (in_array($recipientData['email'], $sentEmails, true)) {
                continue;
            }
            $sentEmails[] = $recipientData['email'];
            try {
                $this->sendEmail($recipientData, $emailConfig, $viewPlaceholders);
            } catch (TransportException $e) {
                $this->logger->warning('Could not send notification email to "{recipient}" due to mailer settings error', [
                    'recipient' => $recipientData['email'],
                    'recipientList' => array_column($message->recipients, 'email'),
                    'exception' => $e,
                ]);
                // At this point we break since the next attempts will also fail due to the invalid mailer settings
                break;
            } catch (RfcComplianceException $e) {
                $this->logger->warning('Could not send notification email to "{recipient}" due to invalid email address', [
                    'recipient' => $recipientData['email'],
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * As it is possible that multiple elements are sent out, or multiple pages, the first "real" page ID is found.
     *
     * @param string $elementTable the table of the first element found
     * @param int $elementUid the uid of the first element in the list
     * @param array $elementRecord the full record
     * @return int the corresponding page ID
     */
    protected function findFirstPageId(string $elementTable, int $elementUid, array $elementRecord): int
    {
        if ($elementTable === 'pages') {
            return $elementUid;
        }
        $pageId = $elementRecord['pid'];
        BackendUtility::workspaceOL($elementTable, $elementRecord);
        return (int)($elementRecord['pid'] ?? $pageId);
    }

    /**
     * Send one email to a specific person, apply multi-language possibilities for sending this email out.
     */
    protected function sendEmail(array $recipientData, array $emailConfig, array $variablesForView): void
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths(array_replace(
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'] ?? [],
            $emailConfig['templateRootPaths'] ?? [],
        ));
        $templatePaths->setLayoutRootPaths(array_replace(
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['layoutRootPaths'] ?? [],
            $emailConfig['layoutRootPaths'] ?? [],
        ));
        $templatePaths->setPartialRootPaths(array_replace(
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['partialRootPaths'] ?? [],
            $emailConfig['partialRootPaths'] ?? [],
        ));

        $emailObject = GeneralUtility::makeInstance(FluidEmail::class, $templatePaths);
        $emailObject
            ->to(new Address($recipientData['email'], $recipientData['realName'] ?? ''))
            // Will be overridden by the template
            ->subject('TYPO3 Workspaces: Stage Change')
            ->setTemplate('StageChangeNotification')
            ->assignMultiple($variablesForView)
            ->assign('language', $recipientData['lang'] ?? 'default');

        // Injecting normalized params
        if ($GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface) {
            $emailObject->setRequest($GLOBALS['TYPO3_REQUEST']);
        }
        if ($emailConfig['format']) {
            $emailObject->format($emailConfig['format']);
        }
        if (!empty($emailConfig['senderEmail']) && GeneralUtility::validEmail($emailConfig['senderEmail'])) {
            $emailObject->from(new Address($emailConfig['senderEmail'], $emailConfig['senderName'] ?? ''));
        }
        $this->mailer->send($emailObject);
    }
}
