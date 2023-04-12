<?php

namespace CommonGateway\NaamgebruikVrijBRPBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\NaamgebruikVrijBRPBundle\Service\SimXmlToZgwService;
use CommonGateway\NaamgebruikVrijBRPBundle\Service\ZdsToZgwService;

/**
 * Convert Sim Xml to a ZGW Zaak.
 */
class SimXmlZaakActionHandler implements ActionHandlerInterface
{

    /**
     * @var SimXmlToZgwService
     */
    private SimXmlToZgwService $simXmlToZgwService;


    /**
     * @param SimXmlToZgwService $simXmlToZgwService The Sim XML to ZGW service
     */
    public function __construct(SimXmlToZgwService $simXmlToZgwService)
    {
        $this->simXmlToZgwService = $simXmlToZgwService;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://simxml.nl/ActionHandler/SimXmlZaakActionHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SimXmlZaakActionHandler',
            'description' => 'This handler converts Sim Xml to a ZGW Zaak.',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the service.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->simXmlToZgwService->zaakActionHandler($data, $configuration);

    }//end run()


}//end class
