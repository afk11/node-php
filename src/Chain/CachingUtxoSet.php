<?php

namespace BitWasp\Bitcoin\Node\Chain;

use BitWasp\Bitcoin\Node\Db\DbInterface;
use BitWasp\Bitcoin\Node\Index\Validation\BlockData;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializer;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Bitcoin\Utxo\UtxoInterface;
use BitWasp\Buffertools\Buffer;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\RedisCache;

class CachingUtxoSet
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

        if (class_exists('Redis')) {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $this->set = new RedisCache();
            $this->set->setRedis($redis);
        } else {
            $this->set = new ArrayCache();
        }

        $this->outpointSerializer = new OutPointSerializer();
    }

    /**
     * @param BlockData $blockData
     */
    public function applyBlock(BlockData $blockData)
    {
        $this->db->updateUtxoSet($this->outpointSerializer, $blockData);
        
        if ($this->caching) {
            foreach ($this->cacheHits as $key) {
                $this->set->delete($key);
            }

            foreach ($blockData->remainingNew as $c => $utxo) {
                $new = $this->outpointSerializer->serialize($utxo->getOutPoint())->getBinary();
                $this->set->save($new, [
                    $utxo->getOutput()-> getValue(),
                    $utxo->getOutput()->getScript()->getBinary(),
                ], 500000);
            }

            echo "Inserts: " . count($blockData->remainingNew). " | Deletes: " . count($blockData->requiredOutpoints). " | " . "CacheHits: " . count($this->cacheHits) .PHP_EOL;

            $this->cacheHits = [];
        }
    }

    /**
     * @param OutPointInterface[] $requiredOutpoints
     * @return UtxoInterface[]
     */
    public function fetchView(array $requiredOutpoints)
    {
        try {
            $utxos = [];
            $required = [];
            $cacheHits = [];
            foreach ($requiredOutpoints as $c => $outpoint) {
                $key = $this->outpointSerializer->serialize($outpoint)->getBinary();
                if ($this->set->contains($key)) {
                    list ($value, $scriptPubKey) = $this->set->fetch($key);
                    $cacheHits[] = $key;
                    $utxos[] = new Utxo($outpoint, new TransactionOutput($value, new Script(new Buffer($scriptPubKey))));
                } else {
                    $required[] = $outpoint;
                }
            }

            if (empty($required) === false) {
                $utxos = array_merge($utxos, $this->db->fetchUtxoDbList($this->outpointSerializer, $required));
            }

            if ($this->caching) {
                $this->cacheHits = $cacheHits;
            }

            return $utxos;
        } catch (\Exception $e) {
            echo $e->getMessage().PHP_EOL;
            throw new \RuntimeException('Failed to find UTXOS in set');
        }
    }
}
