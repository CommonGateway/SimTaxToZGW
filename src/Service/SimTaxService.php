<?php
/**
 * An example service for adding business logic to your class.
 *
 * @author  Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle\Service;

use Adbar\Dot;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

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
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param CacheService           $cacheService    The CacheService
     * @param MappingService         $mappingService  The Mapping Service
     * @param EntityManagerInterface $entityManager   The Entity Manager.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService = $resourceService;
        $this->cacheService    = $cacheService;
        $this->mappingService  = $mappingService;
        $this->entityManager   = $entityManager;
        $this->logger          = $pluginLogger;

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

        $this->logger->debug("SimTaxService -> simTaxHandler()");

        $dotBody = new Dot($this->data['body']);
        if ($dotBody->has('SOAP-ENV:Body.ns2:vraagBericht.ns1:stuurgegevens') === false) {
            $this->logger->error('No vraagBericht -> stuurgegevens found in xml body, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'No vraagBericht -> stuurgegevens found in xml body'], 400)];
        }

        $vraagBericht  = $dotBody->get('SOAP-ENV:Body.ns2:vraagBericht');
        $stuurGegevens = $vraagBericht['ns1:stuurgegevens'];
        $this->logger->debug("BerichtSoort {$stuurGegevens['ns1:berichtsoort']} & entiteittype {$stuurGegevens['ns1:entiteittype']}");

        switch ($stuurGegevens['ns1:berichtsoort'].'-'.$stuurGegevens['ns1:entiteittype']) {
        case 'Lv01-BLJ':
            $responseContent = $this->getAanslagen($vraagBericht);
            break;
        case 'Lv01-OPO':
            $responseContent = $this->getAanslag($vraagBericht);
            break;
        case 'Lk01-BGB':
            $responseContent = $this->createBezwaar($vraagBericht);
            break;
        default:
            $this->logger->warning('Unknown berichtsoort & entiteittype combination, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'Unknown berichtsoort & entiteittype combination'], 400)];
        }

        return ['response' => $this->createResponse(($responseContent ?? ['Nothing to return']), 200)];

    }//end simTaxHandler()


    /**
     * Get aanslagen objects based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return array
     */
    public function getAanslagen(array $vraagBericht): array
    {
        $aanslagen = $this->cacheService->searchObjects(
            null,
            [],
            ['https://openbelasting.nl/schemas/openblasting.aanslagbiljet.schema.json']
        )['results'];

        return ['Lv01-BLJ'];

    }//end getAanslagen()


    /**
     * Get a single aanslag object based on the input.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return array
     */
    public function getAanslag(array $vraagBericht): array
    {
        // todo
        return ['Lv01-OPO'];

    }//end getAanslag()


    /**
     * Create a bezwaar object based on the input.
     * This will actually only map the input and throw an event for the OpenBelastingenBundle to handle.
     *
     * @param array $vraagBericht The vraagBericht content from the body of the current request.
     *
     * @return array
     */
    public function createBezwaar(array $vraagBericht): array
    {
        // todo
        return ['Lk01-BGB'];

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
        $xmlEncoder    = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status, ['Content-Type' => 'application/soap+xml']);

    }//end createResponse()


}//end class
