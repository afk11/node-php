<?php

namespace BitWasp\Bitcoin\Node\Network;

use BitWasp\Bitcoin\Networking\DnsSeeds\DnsSeedList;
use BitWasp\Bitcoin\Networking\Settings\NetworkSettings;

class RegtestSettings extends NetworkSettings
{
    protected function setup()
    {
        $this
            ->setDefaultP2PPort(18444)
            ->setDnsSeeds(new DnsSeedList([]))
        ;
    }
}

