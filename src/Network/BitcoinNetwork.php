<?php

namespace BitWasp\Bitcoin\Node\Network;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Networking\Settings\MainnetSettings;

class BitcoinNetwork extends Network
{
    public function __construct()
    {
        $this->vchParams = NetworkFactory::bitcoin();
        $this->chainParams = new Params(Bitcoin::getMath());
        $this->networkParams = new MainnetSettings();
    }
}
