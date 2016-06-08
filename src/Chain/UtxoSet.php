<?php

namespace BitWasp\Bitcoin\Node\Chain;

use BitWasp\Bitcoin\Node\Db\DbInterface;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializer;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Bitcoin\Utxo\UtxoInterface;
use BitWasp\Buffertools\Buffer;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\RedisCache;

class UtxoSet
{
    /**
     * @var DbInterface
     */
    private $db;

    /**
     * @var bool
     */
    private $caching = false;

    /**
     * @var RedisCache
     */
    private $set;

    /**
     * @var array
     */
    private $cacheHits = [];

    /**
     * @var OutPointSerializer
     */
    private $outpointSerializer;

    /**
     * UtxoSet constructor.
     * @param DbInterface $db
     */
    public function __construct(DbInterface $db)
    {
        $this->db = $db;
        $this->outpointSerializer = new OutPointSerializer();
    }

    /**
     * @param OutPointInterface[] $deleteOutPoints
     * @param Utxo[] $newUtxos
     */
    public function applyBlock(array $deleteOutPoints, array $newUtxos)
    {
        $this->db->updateUtxoSet($this->outpointSerializer, $deleteOutPoints, $newUtxos, $this->cacheHits);
    }

    /**
     * @param OutPointInterface[] $required
     * @return UtxoInterface[]
     */
    public function fetchView(array $required)
    {
        try {
            $utxos = [];
            if (empty($required) === false) {
                $utxos = $this->db->fetchUtxoDbList($this->outpointSerializer, $required);
            }

            return $utxos;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to find UTXOS in set');
        }
    }
}
