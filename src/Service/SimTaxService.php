<?php
/**
 * An example service for adding business logic to your class.
 *
 * @author  Wilco Louwerse <wilco@conduction.nl>, Barry Brands <barry@conduction.nl>, Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle\Service;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\MappingService;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use App\Entity\ObjectEntity;
use App\Entity\Entity;
use App\Event\ActionEvent;
use DateTime;
use CommonGateway\OpenBelastingBundle\Service\SyncAanslagenService;

class SimTaxService
{

    /**
     * The configuration of the current action.
     *
     * @var array
     */
    private array $configuration;

    /**
     * The data array from/for the current api call.
     *
     * @var array
     */
    private array $data;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SyncAanslagenService
     */
    private SyncAanslagenService $syncAanslagenService;

    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * The plugin name of this plugin.
     */
    private const PLUGIN_NAME = 'common-gateway/sim-tax-to-zgw-bundle';

    /**
     * The mapping references used in this service.
     */
    private const MAPPING_REFS = [
        "GetAanslagen"  => "https://dowr.simxml.nl/mapping/simxml.get.aanslagen.mapping.json",
        "GetAanslag"    => "https://dowr.simxml.nl/mapping/simxml.get.aanslag.mapping.json",
        "CreateBezwaar" => "https://dowr.simxml.nl/mapping/simxml.post.bezwaar.mapping.json",
    ];

    /**
     * The schema references used in this service.
     */
    private const SCHEMA_REFS = [
        "Aanslagbiljet"   => "https://openbelasting.nl/schemas/openblasting.aanslagbiljet.schema.json",
        "BezwaarAanvraag" => "https://openbelasting.nl/schemas/openblasting.bezwaaraanvraag.schema.json",
    ];


    /**
     * @param GatewayResourceService   $resourceService        The Gateway Resource Service.
     * @param CacheService             $cacheService           The CacheService
     * @param MappingService           $mappingService         The Mapping Service
     * @param SynchronizationService   $synchronizationService The Synchronization Service
     * @param EntityManagerInterface   $entityManager          The Entity Manager.
     * @param SyncAanslagenService     $syncAanslagenService   The Sync Aanslagen Service.
     * @param LoggerInterface          $pluginLogger           The plugin version of the logger interface.
     * @param EventDispatcherInterface $eventDispatcher        The EventDispatcherInterface.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        SynchronizationService $synchronizationService,
        EntityManagerInterface $entityManager,
        SyncAanslagenService $syncAanslagenService,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService        = $resourceService;
        $this->cacheService           = $cacheService;
        $this->mappingService         = $mappingService;
        $this->synchronizationService = $synchronizationService;
        $this->entityManager          = $entityManager;
        $this->syncAanslagenService   = $syncAanslagenService;
        $this->logger                 = $pluginLogger;
        $this->eventDispatcher        = $eventDispatcher;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * An example handler that is triggered by an action.
     *
     * @param array $data          The data array
     * @param array $configuration The configuration array
     *
     * @return array A handler must ALWAYS return an array
     */
    public function simTaxHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        $this->logger->info("SimTaxService -> simTaxHandler()");

        if (isset($this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht']['ns1:stuurgegevens']) === false
            && isset($this->data['body']['SOAP-ENV:Body']['ns2:kennisgevingsBericht']['ns1:stuurgegevens']) === false
        ) {
            $this->logger->error('No vraagBericht -> stuurgegevens OR kennisgevingsBericht -> stuurgegevens found in xml body, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'No vraagBericht -> stuurgegevens OR kennisgevingsBericht -> stuurgegevens found in xml body'], 400)];
        }

        $vraagBericht         = $this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht'] ?? null;
        $kennisgevingsBericht = $this->data['body']['SOAP-ENV:Body']['ns2:kennisgevingsBericht'] ?? null;
        $stuurGegevens        = ($vraagBericht['ns1:stuurgegevens'] ?? $kennisgevingsBericht['ns1:stuurgegevens']);

        $this->logger->info("BerichtSoort {$stuurGegevens['ns1:berichtsoort']} & entiteittype {$stuurGegevens['ns1:entiteittype']}");

        switch ($stuurGegevens['ns1:berichtsoort'].'-'.$stuurGegevens['ns1:entiteittype']) {
        case 'Lv01-BLJ':
            $response = $this->getAanslagen($vraagBericht);
            break;
        case 'Lv01-OPO':
            $response = $this->getAanslag($vraagBericht);
            break;
        case 'Lk01-BGB':
            $response = $this->createBezwaar($kennisgevingsBericht);
            break;
        default:
            $this->logger->warning('Unknown berichtsoort & entiteittype combination, returning bad request error');
            $response = $this->createResponse(['Error' => 'Unknown berichtsoort & entiteittype combination'], 400);
        }

        return ['response' => $response];

    }//end simTaxHandler()


    /**
     * Get aanslagen objects based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return Response
     */
    public function getAanslagen(array $vraagBericht): Response
    {
        $mapping = $this->resourceService->getMapping($this::MAPPING_REFS['GetAanslagen'], $this::PLUGIN_NAME);
        if ($mapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['GetAanslagen']}."], 501);
        }

        $filter = [];
        if (isset($vraagBericht['ns2:body']['ns2:BLJ'][0]['ns2:BLJPRS']['ns2:PRS']['ns2:bsn-nummer']) === true) {
            $bsn = $vraagBericht['ns2:body']['ns2:BLJ'][0]['ns2:BLJPRS']['ns2:PRS']['ns2:bsn-nummer'];
        }

        if (isset($bsn) === false) {
            return $this->createResponse(['Error' => "No bsn given."], 501);
        }

        $filter['embedded.belastingplichtige.burgerservicenummer'] = $bsn;

        // Sync aanslagen from openbelasting api with given bsn.
        $this->syncAanslagenService->fetchAndSyncAanslagen($bsn);

        // Then fetch synced aanslagen through cacheService.
        $aanslagen = $this->cacheService->searchObjects(null, $filter, [$this::SCHEMA_REFS['Aanslagbiljet']]);

        $aanslagen['vraagbericht'] = $vraagBericht;

        $responseContext = $this->mappingService->mapping($mapping, $aanslagen);

        return $this->createResponse($responseContext, 200);

    }//end getAanslagen()


    /**
     * Get a single aanslag object based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return Response
     */
    public function getAanslag(array $vraagBericht): Response
    {
        $mapping = $this->resourceService->getMapping($this::MAPPING_REFS['GetAanslag'], $this::PLUGIN_NAME);
        if ($mapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['GetAanslag']}."], 501);
        }

        $filter = [];
        if (isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer']) === true) {
            $aanslagBiljetNummer               = explode("-", $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer']);
            $filter['aanslagbiljetnummer']     = $aanslagBiljetNummer[0];
            $filter['aanslagbiljetvolgnummer'] = $aanslagBiljetNummer[1] ?? null;
        }

        if (isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetVolgNummer']) === true) {
            $filter['aanslagbiljetvolgnummer'] = $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetVolgNummer'];
        }

        $aanslagen = $this->cacheService->searchObjects(null, $filter, [$this::SCHEMA_REFS['Aanslagbiljet']]);
        if ($aanslagen['count'] > 1) {
            $this->logger->warning('Found more than 1 aanslag with these filters: ', $filter);
            return $this->createResponse(['Error' => 'Found more than 1 aanslag with these filters', 'Filters' => $filter], 500);
        } else if ($aanslagen['count'] === 1) {
            $aanslagen['result'] = $aanslagen['results'][0];
        }

        $aanslagen['vraagbericht'] = $vraagBericht;

        $responseContext = $this->mappingService->mapping($mapping, $aanslagen);

        return $this->createResponse($responseContext, 200);

    }//end getAanslag()


    /**
     * Map a bezwaar array based on the input.
     * TODO: This function contains a lot of ugly / hacky code, we should at least split this into functions when we get the time!!!
     *
     * @param array $kennisgevingsBericht The vraagBericht content from the body of the current request.
     *
     * @return Response|array
     */
    private function mapXMLToBezwaar(array $kennisgevingsBericht)
    {
        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:referentienummer']) === false) {
            return $this->createResponse(['Error' => "No referentienummer given."], 400);
        }

        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:tijdstipBericht']) === false) {
            return $this->createResponse(['Error' => "No tijdstipBericht given."], 400);
        }

        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer']) === false) {
            return $this->createResponse(['Error' => "No bsn given."], 400);
        }

        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement']) === false) {
            return $this->createResponse(['Error' => "No 'ns2:extraElementen' given."], 400);
        }

        $bezwaarArray = [
            'aanvraagdatum'           => null,
            'aanvraagnummer'          => null,
            'aanslagbiljetnummer'     => null,
            'aanslagbiljetvolgnummer' => null,
        ];

        $bezwaarArray['belastingplichtige']['burgerservicenummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer'];

        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']) === true) {
            $dateTime = DateTime::createFromFormat('YmdHisu', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            if ($dateTime === false) {
                $dateTime = DateTime::createFromFormat('Ymd', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            }

            if ($dateTime === false) {
                $bezwaarArray['aanvraagdatum'] = null;
            } else {
                $bezwaarArray['aanvraagdatum'] = $dateTime->format('Y-m-d');
            }
        }//end if

        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagnummer']) === true) {
            $bezwaarArray['aanvraagnummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagnummer'];
        }

        $bezwaarArray['gehoordWorden'] = false;
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden']) === true
            && $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden'] === 'J'
        ) {
            $bezwaarArray['gehoordWorden'] = true;
        }

        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT']) === true) {
            foreach ($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT'] as $bijlage) {
                $bezwaarArray['bijlagen'][] = [
                    'naamBestand' => $bijlage['ns2:ATT']['ns2:naam'],
                    'typeBestand' => $bijlage['ns2:ATT']['ns2:type'],
                    'bestand'     => $bijlage['ns2:ATT']['ns2:bestand'],
                ];
            }
        }//end if

        // Keep track of groups of 'codeGriefSoort', 'toelichtingGrief' & 'keuzeOmschrijvingGrief' from the 'ns2:extraElementen' in this $regels array
        // We need [0 => []] for isset($regels[count($regels) - 1]['...']) check to work.
        $regels = [0 => []];
        // Keep track of all 'belastingplichtnummers' & 'beschikkingSleutels'
        $belastingplichtnummers = [];
        $beschikkingSleutels    = [];

        foreach ($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement'] as $element) {
            switch ($element['@naam']) {
            case 'kenmerkNummerBesluit':
                isset($bezwaarArray['aanslagbiljetnummer']) === false && $bezwaarArray['aanslagbiljetnummer'] = $element['#'];
                break;
            case 'kenmerkVolgNummerBesluit':
                isset($bezwaarArray['aanslagbiljetvolgnummer']) === false && $bezwaarArray['aanslagbiljetvolgnummer'] = $element['#'];
                break;
            case 'codeRedenBezwaar':
                // todo codeRedenBezwaar ?
                break;
            case 'keuzeOmschrijvingRedenBezwaar':
                // todo keuzeOmschrijvingRedenBezwaar ?
                break;
            case 'belastingplichtnummer':
                $belastingplichtnummers[] = $element['#'];
                break;
            case 'codeGriefSoort':
                if (isset($regels[(count($regels) - 1)]['codeGriefSoort']) === true) {
                    $regels[] = ['codeGriefSoort' => $element['#']];
                    break;
                }

                $regels[(count($regels) - 1)]['codeGriefSoort'] = $element['#'];
                break;
            case 'toelichtingGrief':
                if (isset($regels[(count($regels) - 1)]['toelichtingGrief']) === true) {
                    $regels[] = ['toelichtingGrief' => $element['#']];
                    break;
                }

                $regels[(count($regels) - 1)]['toelichtingGrief'] = $element['#'];
                break;
            case 'keuzeOmschrijvingGrief':
                if (isset($regels[(count($regels) - 1)]['keuzeOmschrijvingGrief']) === true) {
                    $regels[] = ['keuzeOmschrijvingGrief' => $element['#']];
                    break;
                }

                $regels[(count($regels) - 1)]['keuzeOmschrijvingGrief'] = $element['#'];
                break;
            case 'beschikkingSleutel':
                $beschikkingSleutels[] = $element['#'];
                break;
            default:
                break;
            }//end switch
        }//end foreach

        // Loop through all $regels groups and add them to $bezwaarArray 'aanslagregels' or 'beschikkingsregels'
        foreach ($regels as $key => $regel) {
            if (isset($regel['codeGriefSoort']) === false) {
                // If we ever get here the structure of the XML request body extraElementen is most likely incorrect.
                // (or what we were told, how to map this, was incorrect)
                $this->logger->error("Something went wrong while creating a 'bezwaar', found a 'regel' without a 'codeGriefSoort'.");
                continue;
            }

            // 'aanslagregels' & 'beschikkingsregels' both use the same data structure for 'grieven'
            $grief = [
                'soortGrief'       => $regel['codeGriefSoort'],
                'toelichtingGrief' => ($regel['keuzeOmschrijvingGrief'] ?? '').(isset($regel['keuzeOmschrijvingGrief']) && isset($regel['toelichtingGrief']) ? ' - ' : '').($regel['toelichtingGrief'] ?? ''),
            ];

            // The first items in $regels array are always 'aanslagregels', equal to the amount of 'belastingplichtnummers' are present.
            if ($key < count($belastingplichtnummers)) {
                $belastingplichtnummer = $belastingplichtnummers[$key];

                // Check if we are dealing with multiple (2nd and more) 'grieven' for one 'aanslagregel' with the same $belastingplichtnummer.
                if (isset($bezwaarArray['aanslagregels'])) {
                    $aanslagregels = array_filter(
                        $bezwaarArray['aanslagregels'],
                        function (array $aanslagregel) use ($belastingplichtnummer) {
                            return $aanslagregel['belastingplichtnummer'] === $belastingplichtnummer;
                        }
                    );
                    if (count($aanslagregels) > 0) {
                        $bezwaarArray['aanslagregels'][array_key_first($aanslagregels)]['grieven'][] = $grief;
                        continue;
                    }
                }

                // If there does not exist an 'aanslagregel' with $belastingplichtnummer yet add it.
                $bezwaarArray['aanslagregels'][] = [
                    'belastingplichtnummer' => $belastingplichtnummer,
                    'grieven'               => [0 => $grief],
                ];
                continue;
            }//end if

            // The last items in $regels array are always 'beschikkingsregels', equal to the amount of 'sleutelBeschikkingsregel' are present.
            if (($key - count($belastingplichtnummers)) < count($beschikkingSleutels)) {
                $beschikkingSleutel = $beschikkingSleutels[($key - count($belastingplichtnummers))];

                // Check if we are dealing with multiple (2nd and more) 'grieven' for one 'beschikkingsregel' with the same $beschikkingSleutel.
                if (isset($bezwaarArray['beschikkingsregels'])) {
                    $beschikkingsregels = array_filter(
                        $bezwaarArray['beschikkingsregels'],
                        function (array $beschikkingsregel) use ($beschikkingSleutel) {
                            return $beschikkingsregel['sleutelBeschikkingsregel'] === $beschikkingSleutel;
                        }
                    );
                    if (count($beschikkingsregels) > 0) {
                        $bezwaarArray['beschikkingsregels'][array_key_first($beschikkingsregels)]['grieven'][] = $grief;
                        continue;
                    }
                }

                // If there does not exist a 'beschikkingsregel' with $beschikkingSleutel yet add it.
                $bezwaarArray['beschikkingsregels'][] = [
                    'sleutelBeschikkingsregel' => $beschikkingSleutel,
                    'grieven'                  => [0 => $grief],
                ];
            }//end if
        }//end foreach

        foreach ($bezwaarArray as $key => $property) {
            if ($property === null) {
                return $this->createResponse(['Error' => "No $key given."], 400);
            }
        }//end foreach

        return $bezwaarArray;

    }//end mapXMLToBezwaar()


    /**
     * Map a bezwaar response array based on the input.
     *
     * @param array $kennisgevingsBericht The vraagBericht content from the body of the current request.
     *
     * @return array
     */
    private function mapBezwaarResponse(array $kennisgevingsBericht)
    {
        $responseArray = [
            'soapenv:Body' => [
                'StUF:bevestigingsBericht' => [
                    '@xmlns:StUF'        => 'http://www.egem.nl/StUF/StUF0204',
                    '@xmlns:xsi'         => 'http://www.w3.org/2001/XMLSchema-instance',
                    'StUF:stuurgegevens' => [
                        '@xmlns'            => 'http://www.egem.nl/StUF/StUF0204',
                        'berichtsoort'      => 'Bv01',
                        'entiteittype'      => 'BGB',
                        'sectormodel'       => 'ef',
                        'versieStUF'        => '0204',
                        'versieSectormodel' => '0204',
                        'zender'            => ['applicatie' => 'CGS'],
                        'ontvanger'         => [
                            'organisatie' => 'SIM',
                            'applicatie'  => 'simsite',
                        ],
                        'referentienummer'  => $kennisgevingsBericht['ns1:stuurgegevens']['ns1:referentienummer'],
                        'tijdstipBericht'   => $kennisgevingsBericht['ns1:stuurgegevens']['ns1:tijdstipBericht'],
                        'bevestiging'       => ['crossRefNummer' => $kennisgevingsBericht['ns1:stuurgegevens']['ns1:referentienummer']],
                    ],
                ],
            ],
        ];

        return $responseArray;

    }//end mapBezwaarResponse()


    /**
     * Checks if we arent creating 2 bezwaren for one aanslagbiljet (forbidden).
     *
     * @param array  $bezwaarArray
     * @param Entity $bezwaarSchema
     *
     * @return bool true if unique, false if not.
     */
    private function isBezwaarUnique(array $bezwaarArray, Entity $bezwaarSchema): bool
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['reference' => 'https://openbelasting.nl/source/openbelasting.pinkapi.source.json']);

        $synchronization = $this->synchronizationService->findSyncBySource($source, $bezwaarSchema, $bezwaarArray['aanslagbiljetnummer'].'-'.$bezwaarArray['aanslagbiljetvolgnummer']);

        // If we already have a sync with a object for given aanslagbiljet return error (cant create 2 bezwaren for one aanslagbiljet).
        if ($synchronization->getObject() !== null) {
            return false;
        }

        return true;

    }//end isBezwaarUnique()


    /**
     * Create a bezwaar object based on the input.
     *
     * @param array $kennisgevingsBericht The kennisgevingsBericht content from the body of the current request.
     *
     * @return Response
     */
    public function createBezwaar(array $kennisgevingsBericht): Response
    {
        $bezwaarSchema = $this->resourceService->getSchema($this::SCHEMA_REFS['BezwaarAanvraag'], $this::PLUGIN_NAME);
        if ($bezwaarSchema === null) {
            return $this->createResponse(['Error' => "No schema found for {$this::SCHEMA_REFS['BezwaarAanvraag']}."], 501);
        }

        $bezwaarArray = $this->mapXMLToBezwaar($kennisgevingsBericht);

        // Check if we are not creating 2 bezwaren for the same aanslagbiljet.
        if ($this->isBezwaarUnique($bezwaarArray, $bezwaarSchema) === false) {
            return $this->createResponse(['Error' => "Bezwaar for aanslagbiljetnummer/kenmerkNummerBesluit: {$bezwaarArray['aanslagbiljetnummer']} and aanslagbiljetvolgnummer/kenmerkVolgNummerBesluit: {$bezwaarArray['aanslagbiljetvolgnummer']} already exists."], 400);
        };

        if ($bezwaarArray instanceof Response === true) {
            return $bezwaarArray;
        }

        $bezwaarObject = new ObjectEntity($bezwaarSchema);
        // $bezwaarArray  = $this->mappingService->mapping($mapping, $vraagBericht);
        $bezwaarObject->hydrate($bezwaarArray);

        $this->entityManager->persist($bezwaarObject);
        $this->entityManager->flush();

        $event = new ActionEvent('commongateway.object.create', ['response' => $bezwaarObject->toArray(), 'reference' => $bezwaarSchema->getReference()]);
        $this->eventDispatcher->dispatch($event, $event->getType());

        $responseArray = $this->mapBezwaarResponse($kennisgevingsBericht);

        return $this->createResponse($responseArray, 201);

    }//end createBezwaar()


    /**
     * Creates a response based on content.
     *
     * @param array $content The content to incorporate in the response
     * @param int   $status  The status code of the response
     *
     * @return Response
     */
    public function createResponse(array $content, int $status): Response
    {
        $this->logger->debug('Creating XML response');
        $xmlEncoder                = new XmlEncoder(['xml_root_node_name' => 'soapenv:Envelope']);
        $content['@xmlns:soapenv'] = 'http://schemas.xmlsoap.org/soap/envelope/';
        $contentString             = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);
        $contentString             = $this->replaceCdata($contentString);

        return new Response($contentString, $status, ['Content-Type' => 'application/soap+xml']);

    }//end createResponse()


    /**
     * Removes CDATA from xml array content
     *
     * @param string $contentString The content to incorporate in the response
     *
     * @return string The updated array.
     */
    private function replaceCdata(string $contentString): string
    {
        $contentString = str_replace(["<![CDATA[", "]]>"], "", $contentString);

        $contentString = preg_replace_callback(
            '/&amp;amp;amp;#([0-9]{3});/',
            function ($matches) {
                return chr((int) $matches[1]);
            },
            $contentString
        );

        return $contentString;

    }//end replaceCdata()


}//end class
