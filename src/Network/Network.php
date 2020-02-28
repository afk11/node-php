<?php

namespace BitWasp\Bitcoin\Node\Network;


use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Networking\Settings\NetworkSettings;

abstract class Network
{
    /**
     * @var NetworkInterface
     */
    protected $vchParams;

    /**
     * @var Params
     */
    protected $chainParams;

    /**
     * @var NetworkSettings
     */
    protected $networkParams;

    public static function load($name) {
        $lwr = strtolower($name);
        if ("bitcoin" === $lwr) {
            return new BitcoinNetwork();
        } else if ("bitcoin-regtest" === $lwr) {
            return new RegtestNetwork();
        }

        throw new \Exception("Unknown network");
    }

    /**
     * @return NetworkInterface
     */
    public function getVchParams()
    {
        return $this->vchParams;
    }

    /**
     * @return Params
     */
    public function getChainParams()
    {
        return $this->chainParams;
    }

    /**
     * @return NetworkSettings
     */
    public function getNetworkParams()
    {
        return $this->networkParams;
    }
}
