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
        "GetAanslagen"        => "https://dowr.simxml.nl/mapping/simxml.get.aanslagen.mapping.json",
        "GetAanslag"          => "https://dowr.simxml.nl/mapping/simxml.get.aanslag.mapping.json",
        "PostBezwaarRequest"  => "https://dowr.simxml.nl/mapping/simxml.post.bezwaar.request.mapping.json",
        "PostBezwaarResponse" => "https://dowr.simxml.nl/mapping/simxml.post.bezwaar.response.mapping.json",
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

        $responseMapping = $this->resourceService->getMapping($this::MAPPING_REFS['PostBezwaarResponse'], $this::PLUGIN_NAME);
        if ($responseMapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['PostBezwaarResponse']}."], 501);
        }

        $bezwaarArray = $this->mapXMLToBezwaar($kennisgevingsBericht);

        if ($bezwaarArray instanceof Response === true) {
            return $bezwaarArray;
        }

        // Check if we are not creating 2 bezwaren for the same aanslagbiljet.
        if ($this->isBezwaarUnique($bezwaarArray, $bezwaarSchema) === false) {
            return $this->createResponse(['Error' => "Bezwaar for aanslagbiljetnummer/kenmerkNummerBesluit: {$bezwaarArray['aanslagbiljetnummer']} and aanslagbiljetvolgnummer/kenmerkVolgNummerBesluit: {$bezwaarArray['aanslagbiljetvolgnummer']} already exists."], 400);
        };

        $bezwaarObject = new ObjectEntity($bezwaarSchema);
        // $bezwaarArray  = $this->mappingService->mapping($mapping, $vraagBericht);
        $bezwaarObject->hydrate($bezwaarArray);

        $this->entityManager->persist($bezwaarObject);
        $this->entityManager->flush();

        $event = new ActionEvent('commongateway.object.create', ['response' => $bezwaarObject->toArray(), 'reference' => $bezwaarSchema->getReference()]);
        $this->eventDispatcher->dispatch($event, $event->getType());

        // In case of an error from Open Belastingen API
        if (isset($event->getData()['response']['Error'])) {
            return $this->createResponse($event->getData()['response'], 500);
        }

        $responseArray = $this->mappingService->mapping($responseMapping, $kennisgevingsBericht);

        return $this->createResponse($responseArray, 201);

    }//end createBezwaar()


    /**
     * Map a bezwaar array based on the input.
     *
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return Response|array
     */
    private function mapXMLToBezwaar(array $kennisgevingsBericht)
    {
        $errorResponse = $this->bezwaarRequiredFields($kennisgevingsBericht);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $bezwaarArray = [];
        $bezwaarArray['aanvraagnummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagnummer'] ?? null;
    
        $bezwaarArray['aanvraagdatum'] = null;
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']) === true) {
            $dateTime = DateTime::createFromFormat('YmdHisu', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            if ($dateTime === false) {
                $dateTime = DateTime::createFromFormat('Ymd', $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:aanvraagdatum']);
            }
        
            if ($dateTime !== false) {
                $bezwaarArray['aanvraagdatum'] = $dateTime->format('Y-m-d');
            }
        }//end if
    
        $bezwaarArray['gehoordWorden'] = false;
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden']) === true
            && $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:indGehoordWorden'] === 'J'
        ) {
            $bezwaarArray['gehoordWorden'] = true;
        }
        
        $bezwaarArray = $this->mapExtraElementen($bezwaarArray, $kennisgevingsBericht);
        
        $bezwaarArray['belastingplichtige']['burgerservicenummer'] = $kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer'];
    
        // Bijlagen
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT']) === true) {
            foreach ($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBATT'] as $bijlage) {
                $bezwaarArray['bijlagen'][] = [
                    'naamBestand' => $bijlage['ns2:ATT']['ns2:naam'],
                    'typeBestand' => $bijlage['ns2:ATT']['ns2:type'],
                    'bestand'     => $bijlage['ns2:ATT']['ns2:bestand'],
                ];
            }
        }//end if

        // As long as we always map with a default value = null for each key, we should catch any missing properties (extraElementen for example)
        foreach ($bezwaarArray as $key => $property) {
            if ($property === null) {
                return $this->createResponse(['Error' => "No $key given."], 400);
            }
        }//end foreach

        return $bezwaarArray;

    }//end mapXMLToBezwaar()


    /**
     * Checks if the given $kennisgevingsBericht array has the minimal (some of the) required fields for creating a bezwaar.
     * Most other fields we get from extraElementen we check later on and not through this function.
     *
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return Response|null Null if everything is in order, an error Response if any required fields are missing.
     */
    private function bezwaarRequiredFields(array $kennisgevingsBericht): ?Response
    {
        // We do not send this to Pink Api, we only need this to return a correct xml response.
        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:referentienummer']) === false) {
            return $this->createResponse(['Error' => "No referentienummer given."], 400);
        }
    
        // We do not send this to Pink Api, we only need this to return a correct xml response.
        if (isset($kennisgevingsBericht['ns1:stuurgegevens']['ns1:tijdstipBericht']) === false) {
            return $this->createResponse(['Error' => "No tijdstipBericht given."], 400);
        }
    
        // We check this here, because this is a way to return a more specific error message.
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:BGBPRSBZW']['ns2:PRS']['ns2:bsn-nummer']) === false) {
            return $this->createResponse(['Error' => "No bsn given."], 400);
        }
    
        // We check this here, because this is a way to return a more specific error message.
        if (isset($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement']) === false) {
            return $this->createResponse(['Error' => "No 'ns2:extraElementen' given."], 400);
        }

        return null;

    }//end bezwaarRequiredFields()
    
    
    /**
     * Map the extraElementen for a bezwaar creation request. Using the $kennisgevingsBericht as input.
     *
     * @param array $bezwaarArray The array we saved the mapped data in. This should contain the mapping done so far.
     * @param array $kennisgevingsBericht The kennisgevinsBericht content from the body of the current request.
     *
     * @return array The updated $bezwaarArray with applied mapping for extraElementen.
     */
    private function mapExtraElementen(array $bezwaarArray, array $kennisgevingsBericht): array
    {
        // Keep track of groups of 'codeGriefSoort', 'toelichtingGrief' & 'keuzeOmschrijvingGrief' from the 'ns2:extraElementen' in this $regelData['regels'] array
        // We need 'regels' => [0 => []] for the first isset($regelData['regels'][count($regelData['regels']) - 1]['...']) check to work.
        // Also keep track of all 'belastingplichtnummers' & 'beschikkingSleutels'
        $regelData = [
            'regels' => [0 => []],
            'belastingplichtnummers' => [],
            'beschikkingSleutels' => [],
        ];
    
        // Make sure to always add these, so we return an error response if they are still null after handling all extraElementen
        $bezwaarArray['aanslagbiljetnummer'] = null;
        $bezwaarArray['aanslagbiljetvolgnummer'] = null;
        
        // Get all data from the extraElementen and add it to $bezwaarArray or $regelData.
        foreach ($kennisgevingsBericht['ns2:body']['ns2:BGB']['ns2:extraElementen']['ns1:extraElement'] as $element) {
            if (!is_array($element)) {
                $element['#'] = $element;
            }
            $this->getExtraElementData($bezwaarArray, $regelData, $element);
        }
    
        // Make sure to remove aanslagbiljetvolgnummer from the aanslagbiljetnummer before creating a Bezwaar
        if (isset($bezwaarArray['aanslagbiljetnummer'])) {
            $bezwaarArray['aanslagbiljetnummer'] = explode('-', $bezwaarArray['aanslagbiljetnummer'])[0];
        }
    
        return $this->mapRegelData($bezwaarArray, $regelData);
        
    }//end mapExtraElementen()
    
    
    /**
     * A function used to map a single extra element and add it to $bezwaarArray or add it to $regelData to be handled later.
     *
     * @param array $bezwaarArray The array we save the mapped data in.
     * @param array $regelData An array used to correctly map all aanslagRegels & beschikkingsregels later on.
     * @param array $element The data of a single element from the extraElementen array.
     *
     * @return void
     */
    private function getExtraElementData(array &$bezwaarArray, array &$regelData, array $element)
    {
        switch ($element['@naam']) {
            case 'kenmerkNummerBesluit':
                isset($bezwaarArray['aanslagbiljetnummer']) === false && $bezwaarArray['aanslagbiljetnummer'] = $element['#'];
                break;
            case 'kenmerkVolgNummerBesluit':
                isset($bezwaarArray['aanslagbiljetvolgnummer']) === false && $bezwaarArray['aanslagbiljetvolgnummer'] = $element['#'];
                break;
            case 'codeRedenBezwaar': // todo codeRedenBezwaar ?
                break;
            case 'keuzeOmschrijvingRedenBezwaar': // todo keuzeOmschrijvingRedenBezwaar ?
                break;
            case 'belastingplichtnummer':
                $regelData['belastingplichtnummers'][] = $element['#'];
                break;
            case 'codeGriefSoort':
                if (isset($regelData['regels'][(count($regelData['regels']) - 1)]['codeGriefSoort']) === true) {
                    $regelData['regels'][] = ['codeGriefSoort' => $element['#']];
                    break;
                }
            
                $regelData['regels'][(count($regelData['regels']) - 1)]['codeGriefSoort'] = $element['#'];
                break;
            case 'toelichtingGrief':
                if (isset($regelData['regels'][(count($regelData['regels']) - 1)]['toelichtingGrief']) === true) {
                    $regelData['regels'][] = ['toelichtingGrief' => $element['#']];
                    break;
                }
            
                $regelData['regels'][(count($regelData['regels']) - 1)]['toelichtingGrief'] = $element['#'];
                break;
            case 'keuzeOmschrijvingGrief':
                if (isset($regelData['regels'][(count($regelData['regels']) - 1)]['keuzeOmschrijvingGrief']) === true) {
                    $regelData['regels'][] = ['keuzeOmschrijvingGrief' => $element['#']];
                    break;
                }
            
                $regelData['regels'][(count($regelData['regels']) - 1)]['keuzeOmschrijvingGrief'] = $element['#'];
                break;
            case 'beschikkingSleutel':
                $regelData['beschikkingSleutels'][] = $element['#'];
                break;
            default:
                break;
        }//end switch
    }//end getExtraElementData()
    
    
    /**
     * todo:
     *
     * @param array $bezwaarArray
     * @param array $regelData
     *
     * @return array
     */
    private function mapRegelData(array $bezwaarArray, array $regelData): array
    {
        // Loop through all $regelData['regels'] groups and add them to $bezwaarArray 'aanslagregels' or 'beschikkingsregels'
        foreach ($regelData['regels'] as $key => $regel) {
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
        
            // TODO: duplicate code, make this a function.
            // The first items in $regelData['regels'] array are always 'aanslagregels', equal to the amount of 'belastingplichtnummers' are present.
            if ($key < count($regelData['belastingplichtnummers'])) {
                $belastingplichtnummer = $regelData['belastingplichtnummers'][$key];
            
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
    
            // TODO: duplicate code, make this a function.
            // The last items in $regelData['regels'] array are always 'beschikkingsregels', equal to the amount of 'sleutelBeschikkingsregel' are present.
            if (($key - count($regelData['belastingplichtnummers'])) < count($regelData['beschikkingSleutels'])) {
                $beschikkingSleutel = $regelData['beschikkingSleutels'][($key - count($regelData['belastingplichtnummers']))];
            
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
        
        return $bezwaarArray;
    }


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
