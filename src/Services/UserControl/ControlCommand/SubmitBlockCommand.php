<?php

namespace BitWasp\Bitcoin\Node\Services\UserControl\ControlCommand;

use BitWasp\Bitcoin\Block\BlockFactory;
use BitWasp\Bitcoin\Node\NodeInterface;

class SubmitBlockCommand extends Command
{
    const PARAM_BLOCK = 'block';

    /**
     * @param NodeInterface $node
     * @param array $params
     * @return array
     */
    public function execute(NodeInterface $node, array $params)
    {
        $s = microtime(true);
        $best = $node->chain();
        $headerIdx = $node->headers();
        $blockIndex = $node->blocks();

        $block = BlockFactory::fromHex($params[self::PARAM_BLOCK]);

        try {
            $index = $blockIndex->accept($block, $best, $headerIdx);
        } catch (\Exception $e) {
            $header = $block->getHeader();
            $this->node->emit('event', ['error.onBlock', ['ip' => $peer->getRemoteAddress()->getIp(), 'hash' => $header->getHash()->getHex(), 'error' => $e->getMessage() . PHP_EOL . $e->getTraceAsString()]]);
        }
        $e = microtime(true);
        echo " BlockAccept " . ($e-$s) . " seconds".PHP_EOL;
    }

    protected function configure()
    {
        $this->setName('submitblock')
            ->setDescription('Submits a block to the node')
            ->setParam(self::PARAM_BLOCK, 'Block hex');
    }
}
