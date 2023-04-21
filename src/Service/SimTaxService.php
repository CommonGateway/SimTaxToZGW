<?php
/**
 * An example service for adding business logic to your class.
 *
 * @author  Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle\Service;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use CommonGateway\OpenBelastingBundle\Service\SyncAanslagenService;

class SimTaxService
{

    /**
     * @var array
     */
    private array $configuration;

    /**
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
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SyncAanslagenService
     */
    private SyncAanslagenService $syncAanslagenService;

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
     * @param GatewayResourceService $resourceService      The Gateway Resource Service.
     * @param CacheService           $cacheService         The CacheService
     * @param MappingService         $mappingService       The Mapping Service
     * @param EntityManagerInterface $entityManager        The Entity Manager.
     * @param SyncAanslagenService   $syncAanslagenService The Sync Aanslagen Service.
     * @param LoggerInterface        $pluginLogger         The plugin version of the logger interface.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        EntityManagerInterface $entityManager,
        SyncAanslagenService $syncAanslagenService,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService      = $resourceService;
        $this->cacheService         = $cacheService;
        $this->mappingService       = $mappingService;
        $this->entityManager        = $entityManager;
        $this->syncAanslagenService = $syncAanslagenService;
        $this->logger               = $pluginLogger;

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

        if (isset($this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht']['ns1:stuurgegevens']) === false) {
            $this->logger->error('No vraagBericht -> stuurgegevens found in xml body, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'No vraagBericht -> stuurgegevens found in xml body'], 400)];
        }

        $vraagBericht  = $this->data['body']['SOAP-ENV:Body']['ns2:vraagBericht'];
        $stuurGegevens = $vraagBericht['ns1:stuurgegevens'];

        $this->logger->info("BerichtSoort {$stuurGegevens['ns1:berichtsoort']} & entiteittype {$stuurGegevens['ns1:entiteittype']}");

        switch ($stuurGegevens['ns1:berichtsoort'].'-'.$stuurGegevens['ns1:entiteittype']) {
        case 'Lv01-BLJ':
            $response = $this->getAanslagen($vraagBericht);
            break;
        case 'Lv01-OPO':
            $response = $this->getAanslag($vraagBericht);
            break;
        case 'Lk01-BGB':
            $response = $this->createBezwaar($vraagBericht);
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
            $bsn = $vraagBericht['ns2:body']['ns2:ABT'][0]['ns2:ABTSUBANV']['ns2:PRS']['ns2:bsn-nummer'];
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
        // todo: make these two filters AND and not OR
        if (isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer']) === true) {
            $filter['aanslagbiljetnummer'] = $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagBiljetNummer'];
        }

        if (isset($vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagbiljetvolgnummer']) === true) {
            $filter['aanslagbiljetvolgnummer'] = $vraagBericht['ns2:body']['ns2:OPO'][0]['ns2:aanslagbiljetvolgnummer'];
        }

        $aanslagen = $this->cacheService->searchObjects(null, $filter, [$this::SCHEMA_REFS['Aanslagbiljet']]);
        if ($aanslagen['count'] > 1) {
            // todo return & monolog
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
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return Response
     */
    public function createBezwaar(array $vraagBericht): Response
    {
        $mapping = $this->resourceService->getMapping($this::MAPPING_REFS['CreateBezwaar'], $this::PLUGIN_NAME);
        if ($mapping === null) {
            return $this->createResponse(['Error' => "No mapping found for {$this::MAPPING_REFS['CreateBezwaar']}."], 501);
        }

        $bezwaarSchema = $this->resourceService->getSchema($this::SCHEMA_REFS['BezwaarAanvraag'], $this::PLUGIN_NAME);
        if ($bezwaarSchema === null) {
            return $this->createResponse(['Error' => "No schema found for {$this::SCHEMA_REFS['BezwaarAanvraag']}."], 501);
        }

        // todo: maybe re-use brkBundle->BrkService->clearXmlNamespace() here to do mapping?
        // todo
        return $this->createResponse(['Lk01-BGB'], 201);

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
        $xmlEncoder    = new XmlEncoder(['xml_root_node_name' => 'soapenv:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status, ['Content-Type' => 'application/soap+xml']);

    }//end createResponse()


}//end class
