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
     * @param MappingService         $mappingService  The Mapping Service
     * @param EntityManagerInterface $entityManager   The Entity Manager.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        MappingService $mappingService,
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService = $resourceService;
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
        if ($dotBody->has('SOAP-ENV:Body.ns2:vraagBericht') === false) {
            $this->logger->error('No vraagBericht found in xml body, returning bad request error');
            return ['response' => $this->createResponse(['Error' => 'No vraagBericht found in xml body'], 400)];
        }

        $vraagBericht = $dotBody->get('SOAP-ENV:Body.ns2:vraagBericht');

        return ['response' => $this->createResponse(['Hello. Your SimTaxToZGWBundle works. We should do some mapping here?'], 200)];

    }//end simTaxHandler()


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
