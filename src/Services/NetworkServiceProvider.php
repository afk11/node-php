<?php

namespace BitWasp\Bitcoin\Node\Services;

use BitWasp\Bitcoin\Node\Network\Network;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class NetworkServiceProvider implements ServiceProviderInterface
{
    /**
     * @var Network
     */
    private $network;

    /**
     * NetworkServiceProvider constructor.
     * @param Network $network
     */
    public function __construct(Network $network)
    {
        $this->network = $network;
    }

    /**
     * @param Container $container
     */
    public function register(Container $container)
    {
        $container['network.params.addr'] = $this->network->getVchParams();
        $container['network.params.chain'] = $this->network->getChainParams();
        $container['network.params.p2p'] = $this->network->getNetworkParams();
    }
}
