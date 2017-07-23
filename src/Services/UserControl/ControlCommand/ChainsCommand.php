<?php


namespace BitWasp\Bitcoin\Node\Services\UserControl\ControlCommand;

use BitWasp\Bitcoin\Node\NodeInterface;

class ChainsCommand extends Command
{
    public function configure()
    {
        $this
            ->setName('chains')
            ->setDescription('Returns information about chains tracked by the node');
    }

    /**
     * @param NodeInterface $node
     * @param array $params
     * @return array
     */
    public function execute(NodeInterface $node, array $params)
    {
        $chains = [];
        $nodeChains = $node->chains();
        foreach ($nodeChains->getSegments() as $segment) {
            $bestHeaderIdx = $segment->getLast();
            $view = $nodeChains->view($segment);
            $bestBlockIdx = $nodeChains->blocksView($view)->getIndex();

            $chains[] = [
                'best_header' => $this->convertIndexToArray($bestHeaderIdx),
                'best_block' => $this->convertIndexToArray($bestBlockIdx)
            ];
        }

        return $chains;
    }
}
