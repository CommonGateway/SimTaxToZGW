<?php
/**
 * The SimTaxToZGWBundle provides an interface between Sim Tax and ZGW
 *
 * @author  Conduction.nl <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\SimTaxToZGWBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class SimTaxToZGWBundle extends Bundle
{


    /**
     * Returns the path the bundle is in
     *
     * @return string
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);

    }//end getPath()


}//end class
