<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://www.mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\IntegrationsBundle\Tests\Unit\Sync\SyncDataExchange\Internal\ReportBuilder;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\IntegrationsBundle\Entity\FieldChangeRepository;
use MauticPlugin\IntegrationsBundle\Event\InternalObjectFindEvent;
use MauticPlugin\IntegrationsBundle\IntegrationEvents;
use MauticPlugin\IntegrationsBundle\Internal\Object\Company as InternalCompany;
use MauticPlugin\IntegrationsBundle\Internal\Object\Contact;
use MauticPlugin\IntegrationsBundle\Internal\ObjectProvider;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\InputOptionsDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Report\FieldDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Request\ObjectDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Request\RequestDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Value\EncodedValueDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Value\NormalizedValueDAO;
use MauticPlugin\IntegrationsBundle\Sync\SyncDataExchange\Helper\FieldHelper;
use MauticPlugin\IntegrationsBundle\Sync\SyncDataExchange\Internal\ReportBuilder\FieldBuilder;
use MauticPlugin\IntegrationsBundle\Sync\SyncDataExchange\Internal\ReportBuilder\PartialObjectReportBuilder;
use MauticPlugin\IntegrationsBundle\Sync\SyncDataExchange\MauticSyncDataExchange;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PartialObjectReportBuilderTest extends \PHPUnit_Framework_TestCase
{
    private const INTEGRATION_NAME = 'Test';

    /**
     * @var FieldChangeRepository|\PHPUnit_Framework_MockObject_MockObject
     */
    private $fieldChangeRepository;

    /**
     * @var FieldHelper|\PHPUnit_Framework_MockObject_MockObject
     */
    private $fieldHelper;

    /**
     * @var EventDispatcherInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $dispatcher;

    /**
     * @var FieldBuilder|\PHPUnit_Framework_MockObject_MockObject
     */
    private $fieldBuilder;

    /**
     * @var ObjectProvider|\PHPUnit_Framework_MockObject_MockObject
     */
    private $objectProvider;

    /**
     * @var PartialObjectReportBuilder
     */
    private $reportBuilder;

    protected function setUp(): void
    {
        $this->fieldChangeRepository = $this->createMock(FieldChangeRepository::class);
        $this->fieldHelper           = $this->getMockBuilder(FieldHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept(['getNormalizedFieldType', 'getFieldObjectName'])
            ->getMock();
        $this->dispatcher            = $this->createMock(EventDispatcherInterface::class);
        $this->fieldBuilder          = $this->createMock(FieldBuilder::class);
        $this->objectProvider        = $this->createMock(ObjectProvider::class);
        $this->reportBuilder         = new PartialObjectReportBuilder(
            $this->fieldChangeRepository,
            $this->fieldHelper,
            $this->fieldBuilder,
            $this->objectProvider,
            $this->dispatcher
        );
    }

    public function testTrackedContactChanges(): void
    {
        $requestDAO    = new RequestDAO(self::INTEGRATION_NAME, 1, new InputOptionsDAO(['integration' => self::INTEGRATION_NAME]));
        $fromDateTime  = new \DateTimeImmutable('2018-10-08 00:00:00');
        $toDateTime    = new \DateTimeImmutable('2018-10-08 00:01:00');
        $requestObject = new ObjectDAO(Contact::NAME, $fromDateTime, $toDateTime);
        $requestObject->addField('email');
        $requestObject->addField('firstname');
        $requestDAO->addObject($requestObject);

        $this->fieldBuilder->expects($this->at(0))
            ->method('buildObjectField')
            ->with('email', $this->anything(), $requestObject, MauticSyncDataExchange::NAME)
            ->willReturn(
                new FieldDAO('email', new NormalizedValueDAO(NormalizedValueDAO::EMAIL_TYPE, 'test@test.com'))
            );

        $fieldChange = [
            'object_type'  => Lead::class,
            'object_id'    => 1,
            'modified_at'  => '2018-10-08 00:30:00',
            'column_name'  => 'firstname',
            'column_type'  => EncodedValueDAO::STRING_TYPE,
            'column_value' => 'Bob',
        ];

        $this->fieldHelper->expects($this->once())
            ->method('getFieldChangeObject')
            ->with($fieldChange)
            ->willReturn(
                new FieldDAO('firstname', new NormalizedValueDAO(NormalizedValueDAO::TEXT_TYPE, 'Bob'))
            );

        // Find and return tracked changes
        $this->fieldChangeRepository->expects($this->once())
            ->method('findChangesBefore')
            ->with(
                'Test',
                Lead::class,
                $toDateTime,
                0
            )
            ->willReturn([$fieldChange]);

        $internalObject = new Contact();

        $this->objectProvider->expects($this->once())
            ->method('getObjectByEntityName')
            ->with(Lead::class)
            ->willReturn($internalObject);

        $this->objectProvider->expects($this->once())
            ->method('getObjectByName')
            ->with(Contact::NAME)
            ->willReturn($internalObject);

        // Find the complete object
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                IntegrationEvents::INTEGRATION_FIND_INTERNAL_RECORDS,
                $this->callback(function (InternalObjectFindEvent $event) use ($internalObject) {
                    $this->assertSame($internalObject, $event->getObject());
                    $this->assertSame([1], $event->getIds());

                    // Mock a subscriber:
                    $event->setFoundObjects([
                        [
                            'id'        => 1,
                            'email'     => 'test@test.com',
                            'firstname' => 'Bob and Cat',
                        ],
                    ]);

                    return true;
                })
            );

        $report  = $this->reportBuilder->buildReport($requestDAO);
        $objects = $report->getObjects(Contact::NAME);

        $this->assertTrue(isset($objects[1]));
        $this->assertEquals('test@test.com', $objects[1]->getField('email')->getValue()->getNormalizedValue());
        $this->assertEquals('Bob', $objects[1]->getField('firstname')->getValue()->getNormalizedValue());
    }

    public function testTrackedCompanyChanges(): void
    {
        $requestDAO    = new RequestDAO(self::INTEGRATION_NAME, 1, new InputOptionsDAO(['integration' => self::INTEGRATION_NAME]));
        $fromDateTime  = new \DateTimeImmutable('2018-10-08 00:00:00');
        $toDateTime    = new \DateTimeImmutable('2018-10-08 00:01:00');
        $requestObject = new ObjectDAO(MauticSyncDataExchange::OBJECT_COMPANY, $fromDateTime, $toDateTime);
        $requestObject->addField('email');
        $requestObject->addField('companyname');
        $requestDAO->addObject($requestObject);

        $this->fieldBuilder->expects($this->at(0))
            ->method('buildObjectField')
            ->with('email', $this->anything(), $requestObject, MauticSyncDataExchange::NAME)
            ->willReturn(
                new FieldDAO('email', new NormalizedValueDAO(NormalizedValueDAO::EMAIL_TYPE, 'test@test.com'))
            );

        $fieldChange = [
            'object_type'  => Company::class,
            'object_id'    => 1,
            'modified_at'  => '2018-10-08 00:30:00',
            'column_name'  => 'firstname',
            'column_type'  => EncodedValueDAO::STRING_TYPE,
            'column_value' => 'Bob',
        ];

        $this->fieldHelper->expects($this->once())
            ->method('getFieldChangeObject')
            ->with($fieldChange)
            ->willReturn(
                new FieldDAO('companyname', new NormalizedValueDAO(NormalizedValueDAO::TEXT_TYPE, 'Bob and Cat'))
            );

        // Find and return tracked changes
        $this->fieldChangeRepository->expects($this->once())
            ->method('findChangesBefore')
            ->with(
                'Test',
                Company::class,
                $toDateTime,
                0
            )
            ->willReturn([$fieldChange]);

        $internalObject = new InternalCompany();

        $this->objectProvider->expects($this->once())
            ->method('getObjectByEntityName')
            ->with(Company::class)
            ->willReturn($internalObject);

        $this->objectProvider->expects($this->once())
            ->method('getObjectByName')
            ->with(InternalCompany::NAME)
            ->willReturn($internalObject);

        // Find the complete object
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                IntegrationEvents::INTEGRATION_FIND_INTERNAL_RECORDS,
                $this->callback(function (InternalObjectFindEvent $event) use ($internalObject) {
                    $this->assertSame([1], $event->getIds());
                    $this->assertSame($internalObject, $event->getObject());

                    // Mock a subscriber:
                    $event->setFoundObjects([
                        [
                            'id'          => 1,
                            'email'       => 'test@test.com',
                            'companyname' => 'Bob and Cat',
                        ],
                    ]);

                    return true;
                })
            );

        $report  = $this->reportBuilder->buildReport($requestDAO);
        $objects = $report->getObjects(MauticSyncDataExchange::OBJECT_COMPANY);

        $this->assertTrue(isset($objects[1]));
        $this->assertEquals('test@test.com', $objects[1]->getField('email')->getValue()->getNormalizedValue());
        $this->assertEquals('Bob and Cat', $objects[1]->getField('companyname')->getValue()->getNormalizedValue());
    }
}
