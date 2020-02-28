<?php

namespace BitWasp\Bitcoin\Node\Network;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Node\Params\RegtestParams;

class RegtestNetwork extends Network
{
    public function __construct()
    {
        $this->vchParams = NetworkFactory::bitcoinRegtest();
        $this->chainParams = new RegtestParams(Bitcoin::getMath());
        $this->networkParams = new RegtestSettings();
    }
}
