<?php
/**
 * Created by PhpStorm.
 * User: tk
 * Date: 19/12/15
 * Time: 16:13
 */
namespace BitWasp\Bitcoin\Node\Chain;

use BitWasp\Buffertools\Buffer;

interface ChainCacheInterface
{
    /**
     * @param Buffer $hash
     * @return bool
     */
    public function containsHash(Buffer $hash);

    /**
     * @param Buffer $hash
     * @return int
     */
    public function getHeight(Buffer $hash);

    /**
     * @param int $height
     * @throws \RuntimeException
     * @return Buffer
     */
    public function getHash($height);

    /**
     * @param BlockIndexInterface $index
     */
    public function add(BlockIndexInterface $index);

    /**
     * @param int $endHeight
     * @return ChainCacheInterface
     */
    public function subset($endHeight);
}
