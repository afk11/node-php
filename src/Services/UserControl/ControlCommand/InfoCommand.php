<?php

namespace BitWasp\Bitcoin\Node\Services\UserControl\ControlCommand;

use BitWasp\Bitcoin\Node\NodeInterface;

class InfoCommand extends Command
{
    /**
     * @param NodeInterface $node
     * @param array $params
     * @return array
     */
    public function execute(NodeInterface $node, array $params)
    {
        $chains = $node->chains();
        $nChain = count($chains);

        return [
            'best_header' => $this->convertIndexToArray($chains->best()->getChainIndex()),
            'best_block' => $this->convertIndexToArray($chains->best()->getLastBlock()),
            'nChain' => $nChain
        ];
    }

    protected function configure()
    {
        $this
            ->setName('info')
            ->setDescription('Returns information about the running node');

    }
}