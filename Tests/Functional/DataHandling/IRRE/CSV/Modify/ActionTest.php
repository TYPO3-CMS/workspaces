<?php
namespace TYPO3\CMS\Workspaces\Tests\Functional\DataHandling\IRRE\CSV\Modify;

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

/**
 * Functional test for the DataHandler
 */
class ActionTest extends \TYPO3\CMS\Workspaces\Tests\Functional\DataHandling\IRRE\CSV\AbstractActionTestCase
{
    /**
     * @var string
     */
    protected $assertionDataSetDirectory = 'typo3/sysext/workspaces/Tests/Functional/DataHandling/IRRE/CSV/Modify/DataSet/';

    /**
     * Parent content records
     */

    /**
     * @test
     * @see DataSet/createParentContentRecord.csv
     */
    public function createParentContent()
    {
        parent::createParentContent();
        $this->assertAssertionDataSet('createParentContent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1'));
    }

    /**
     * @test
     * @see DataSet/modifyParentContentRecord.csv
     */
    public function modifyParentContent()
    {
        parent::modifyParentContent();
        $this->assertAssertionDataSet('modifyParentContent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/deleteParentContentRecord.csv
     */
    public function deleteParentContent()
    {
        parent::deleteParentContent();
        $this->assertAssertionDataSet('deleteParentContent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #2'));
    }

    /**
     * @test
     * @see DataSet/deleteParentContentRecordAndDiscardDeletedParentRecord.csv
     */
    public function deleteParentContentAndDiscardDeletedParent()
    {
        parent::deleteParentContentAndDiscardDeletedParent();
        $this->assertAssertionDataSet('deleteParentContentNDiscardDeletedParent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #2'));
    }

    /**
     * @test
     * @see DataSet/copyParentContentRecord.csv
     */
    public function copyParentContent()
    {
        parent::copyParentContent();
        $this->assertAssertionDataSet('copyParentContent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['newContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/copyParentContentToDifferentPage.csv
     */
    public function copyParentContentToDifferentPage()
    {
        parent::copyParentContentToDifferentPage();
        $this->assertAssertionDataSet('copyParentContentToDifferentPage');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageIdTarget, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['newContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/localizeParentContentWAllChildren.csv
     */
    public function localizeParentContentWithAllChildren()
    {
        parent::localizeParentContentWithAllChildren();
        $this->assertAssertionDataSet('localizeParentContentWAllChildren');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, self::VALUE_LanguageId, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('[Translate to Dansk:] Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/changeParentContentRecordSorting.csv
     */
    public function changeParentContentSorting()
    {
        parent::changeParentContentSorting();
        $this->assertAssertionDataSet('changeParentContentSorting');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/moveParentContentRecordToDifferentPage.csv
     */
    public function moveParentContentToDifferentPage()
    {
        parent::moveParentContentToDifferentPage();
        $this->assertAssertionDataSet('moveParentContentToDifferentPage');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageIdTarget, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #2'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/moveParentContentRecordToDifferentPageAndChangeSorting.csv
     */
    public function moveParentContentToDifferentPageAndChangeSorting()
    {
        parent::moveParentContentToDifferentPageAndChangeSorting();
        $this->assertAssertionDataSet('moveParentContentToDifferentPageNChangeSorting');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageIdTarget, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #2', 'Regular Element #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * Page records
     */

    /**
     * @test
     * @see DataSet/modifyPageRecord.csv
     */
    public function modifyPage()
    {
        parent::modifyPage();
        $this->assertAssertionDataSet('modifyPage');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Page)->setField('title')->setValues('Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
    }

    /**
     * @test
     * @see DataSet/deletePageRecord.csv
     */
    public function deletePage()
    {
        parent::deletePage();
        $this->assertAssertionDataSet('deletePage');

        $response = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId, false);
        $this->assertContains('PageNotFoundException', $response->getError());
    }

    /**
     * @test
     * @see DataSet/copyPageRecord.csv
     */
    public function copyPage()
    {
        parent::copyPage();
        $this->assertAssertionDataSet('copyPage');

        $responseSections = $this->getFrontendResponse($this->recordIds['newPageId'], 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2', 'Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/copyPageWHotelBeforeParentContent.csv
     */
    public function copyPageWithHotelBeforeParentContent()
    {
        parent::copyPageWithHotelBeforeParentContent();
        $this->assertAssertionDataSet('copyPageWHotelBeforeParentContent');

        $responseSections = $this->getFrontendResponse($this->recordIds['newPageId'], 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2', 'Hotel #1'));
    }

    /**
     * IRRE Child Records
     */

    /**
     * @test
     * See DataSet/changeHotelSortingWithOfferNotWorkspaceAware.csv
     */
    public function changeHotelSortingWithOfferNotWorkspaceAware()
    {
        parent::changeHotelSortingWithOfferNotWorkspaceAware();
        $this->assertAssertionDataSet('changeHotelSortingWithOfferNotWorkspaceAware');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        self::assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
        self::assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Hotel . ':' . self::VALUE_HotelIdFirst)->setRecordField(self::FIELD_HotelOffer)
            ->setTable(self::TABLE_Offer)->setField('title')->setValues('Offer #1.1'));
        self::assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Hotel . ':' . self::VALUE_HotelIdFirst)->setRecordField(self::FIELD_HotelOffer)
            ->setTable(self::TABLE_Offer)->setField('title')->setValues('Offer #1.2'));
        self::assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Hotel . ':' . self::VALUE_HotelIdSecond)->setRecordField(self::FIELD_HotelOffer)
            ->setTable(self::TABLE_Offer)->setField('title')->setValues('Offer #2.1'));
    }

    /**
     * @test
     * @see DataSet/createParentContentRecordWithHotelAndOfferChildRecords.csv
     */
    public function createParentContentWithHotelAndOfferChildren()
    {
        parent::createParentContentWithHotelAndOfferChildren();
        $this->assertAssertionDataSet('createParentContentNHotelNOfferChildren');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['newContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/createAndCopyParentContentRecordWithHotelAndOfferChildRecords.csv
     */
    public function createAndCopyParentContentWithHotelAndOfferChildren()
    {
        parent::createAndCopyParentContentWithHotelAndOfferChildren();
        $this->assertAssertionDataSet('createNCopyParentContentNHotelNOfferChildren');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1 (copy 1)'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['newContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['copiedContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Hotel . ':' . $this->recordIds['copiedHotelId'])->setRecordField(self::FIELD_HotelOffer)
            ->setTable(self::TABLE_Offer)->setField('title')->setValues('Offer #1'));
    }

    /**
     * @test
     * @see DataSet/createAndCopyParentContentRecordWithHotelAndOfferChildRecordsAndDiscardCopiedParentRecord.csv
     */
    public function createAndCopyParentContentWithHotelAndOfferChildrenAndDiscardCopiedParent()
    {
        parent::createAndCopyParentContentWithHotelAndOfferChildrenAndDiscardCopiedParent();
        $this->assertAssertionDataSet('createNCopyParentNHotelNOfferChildrenNDiscardCopiedParent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1 (copy 1)'));
    }

    /**
     * @test
     * @see DataSet/createAndLocalizeParentContentRecordWithHotelAndOfferChildRecords.csv
     */
    public function createAndLocalizeParentContentWithHotelAndOfferChildren()
    {
        parent::createAndLocalizeParentContentWithHotelAndOfferChildren();
        $this->assertAssertionDataSet('createNLocalizeParentContentNHotelNOfferChildren');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, self::VALUE_LanguageId, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('[Translate to Dansk:] Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . $this->recordIds['newContentId'])->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('[Translate to Dansk:] Hotel #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Hotel . ':' . $this->recordIds['localizedHotelId'])->setRecordField(self::FIELD_HotelOffer)
            ->setTable(self::TABLE_Offer)->setField('title')->setValues('[Translate to Dansk:] Offer #1'));
    }

    /**
     * @test
     * @see DataSet/createNLocParentNHotelNOfferChildrenNDiscardCreatedParent.csv
     */
    public function createAndLocalizeParentContentWithHotelAndOfferChildrenAndDiscardCreatedParent()
    {
        parent::createAndLocalizeParentContentWithHotelAndOfferChildrenAndDiscardCreatedParent();
        $this->assertAssertionDataSet('createNLocParentNHotelNOfferChildrenNDiscardCreatedParent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, self::VALUE_LanguageId, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1', '[Translate to Dansk:] Testing #1'));
    }

    /**
     * @test
     * @see DataSet/createAndLocalizeParentContentRecordWithHotelAndOfferChildRecordsAndDiscardLocalizedParentRecord.csv
     */
    public function createAndLocalizeParentContentWithHotelAndOfferChildrenAndDiscardLocalizedParent()
    {
        parent::createAndLocalizeParentContentWithHotelAndOfferChildrenAndDiscardLocalizedParent();
        $this->assertAssertionDataSet('createNLocParentNHotelNOfferChildrenNDiscardLocParent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, self::VALUE_LanguageId, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('[Translate to Dansk:] Testing #1'));
    }

    /**
     * @test
     * @see DataSet/modifyOnlyHotelChildRecord.csv
     */
    public function modifyOnlyHotelChild()
    {
        parent::modifyOnlyHotelChild();
        $this->assertAssertionDataSet('modifyOnlyHotelChild');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Testing #1'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordAndChangeHotelChildRecordsSorting.csv
     */
    public function modifyParentAndChangeHotelChildrenSorting()
    {
        parent::modifyParentAndChangeHotelChildrenSorting();
        $this->assertAssertionDataSet('modifyParentNChangeHotelChildrenSorting');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #2', 'Hotel #1'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordWithHotelChildRecord.csv
     */
    public function modifyParentWithHotelChild()
    {
        parent::modifyParentWithHotelChild();
        $this->assertAssertionDataSet('modifyParentNHotelChild');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Testing #1'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordWithHotelChildRecordAndDiscardModifiedParentRecord.csv
     */
    public function modifyParentWithHotelChildAndDiscardModifiedParent()
    {
        parent::modifyParentWithHotelChildAndDiscardModifiedParent();
        $this->assertAssertionDataSet('modifyParentNHotelChildNDiscardModifiedParent');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #1'));
        // Discarding the parent shall not discard the child records
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureDoesNotHaveRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #2'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordWithHotelChildRecordAndDiscardAllModifiedRecords.csv
     */
    public function modifyParentWithHotelChildAndDiscardAll()
    {
        parent::modifyParentWithHotelChildAndDiscardAll();
        $this->assertAssertionDataSet('modifyParentNHotelChildNDiscardAll');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Regular Element #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdFirst)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordAndAddHotelChildRecord.csv
     */
    public function modifyParentAndAddHotelChild()
    {
        parent::modifyParentAndAddHotelChild();
        $this->assertAssertionDataSet('modifyParentNAddHotelChild');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1', 'Hotel #2'));
    }

    /**
     * @test
     * @see DataSet/modifyParentRecordAndDeleteHotelChildRecord.csv
     */
    public function modifyParentAndDeleteHotelChild()
    {
        parent::modifyParentAndDeleteHotelChild();
        $this->assertAssertionDataSet('modifyParentNDeleteHotelChild');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionStructureHasRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #1'));
        $this->assertThat($responseSections, $this->getRequestSectionStructureDoesNotHaveRecordConstraint()
            ->setRecordIdentifier(self::TABLE_Content . ':' . self::VALUE_ContentIdLast)->setRecordField(self::FIELD_ContentHotel)
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Hotel #2'));
    }

    /**
     * @test
     * @see DataSet/modifyNDiscardNModifyParentWHotelChild.csv
     */
    public function modifyAndDiscardAndModifyParentWithHotelChild()
    {
        parent::modifyAndDiscardAndModifyParentWithHotelChild();
        $this->assertAssertionDataSet('modifyNDiscardNModifyParentWHotelChild');

        $responseSections = $this->getFrontendResponse(self::VALUE_PageId, 0, self::VALUE_BackendUserId, self::VALUE_WorkspaceId)->getResponseSections();
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionDoesNotHaveRecordConstraint()
            ->setTable(self::TABLE_Hotel)->setField('header')->setValues('Testing #1'));
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Content)->setField('header')->setValues('Testing #2'));
        $this->assertThat($responseSections, $this->getRequestSectionHasRecordConstraint()
            ->setTable(self::TABLE_Hotel)->setField('title')->setValues('Testing #2'));
    }
}
