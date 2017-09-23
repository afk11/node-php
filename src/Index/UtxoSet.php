<?php

namespace BitWasp\Bitcoin\Node\Index;


use BitWasp\Bitcoin\Node\Chain\ChainView;
use BitWasp\Bitcoin\Node\Index\Validation\BlockData;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializerInterface;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionOutputSerializer;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Buffertools\Buffer;
use Packaged\Config\ConfigProviderInterface;

class UtxoSet
{
    /**
     * @var ConfigProviderInterface
     */
    private $config;

    /**
     * @var OutPointSerializerInterface
     */
    private $outPointSerializer;

    /**
     * @var TransactionOutputSerializer
     */
    private $txOutSerializer;

    /**
     * @var \LevelDB
     */
    private $db;

    /**
     * UtxoSet constructor.
     * @param ConfigProviderInterface $config
     */
    public function __construct(ConfigProviderInterface $config)
    {
        $this->config = $config;
        $path = __DIR__ . "/../../utxo";
        $this->db = new \LevelDB($path);
        $this->txOutSerializer = new TransactionOutputSerializer();
    }

    /**
     * @param ChainView $chain
     */
    public function init(ChainView $chain)
    {
        $bestHash = $this->db->get("tip.current");
        if ($bestHash === null) {
            $genesis = $chain->getHashFromHeight(0);
            $this->db->set('tip.current', $genesis);
        }
    }

    /**
     * @param BlockData $blockData
     */
    public function applyBlock(BlockData $blockData)
    {
        $this->updateUtxoSet($blockData);
    }

    /**
     * @param BlockData $blockData
     */
    private function updateUtxoSet(BlockData $blockData)
    {
        $batch = new \LevelDBWriteBatch();
        $v = [];
        $packV = function ($n) use (&$v) {
            if (array_key_exists($n, $v)) {
                return $v[$n];
            }
            $s = pack("V", $n);
            $v[$n]= $s;
            return $s;
        };

        if (!empty($blockData->requiredOutpoints)) {
            $c = 0;
            $diff = 0;
            foreach ($blockData->requiredOutpoints as $outPoint) {
                $a = microtime(true);
                $key = "utxo:{$outPoint->getTxId()->getBinary()}{$packV($outPoint->getVout())}";
                $batch->delete($key);

                $diff += microtime(true)-$a;
                $c++;
            }
            echo "[delete utxos($c) : $diff] ";
        }

        if (!empty($blockData->remainingNew)) {
            $c = 0;
            $diff = 0;
            foreach ($blockData->remainingNew as $hashKey => $utxo) {
                $a = microtime(true);
                $output = $utxo->getOutput();
                $outpoint = $utxo->getOutPoint();

                $key = "utxo:{$outpoint->getTxId()->getBinary()}{$packV($outpoint->getVout())}";
                $txOut = $this->txOutSerializer->serialize($output)->getBinary();
                $batch->put($key, $txOut);

                $diff += microtime(true)-$a;
                $c++;
            }
            echo "[insert ($c): $diff] ";
        }

        $this->db->write($batch);
    }

    /**
     * @param BlockData $blockData
     * @return \BitWasp\Bitcoin\Utxo\Utxo[]
     */
    public function fetchView(BlockData $blockData)
    {
        return $this->fetchUtxos($blockData->requiredOutpoints);
    }

    /**
     * @param array $vOutPoint
     * @return Utxo[]
     */
    private function fetchUtxos(array $vOutPoint)
    {
        if (count($vOutPoint) === 0) {
            return [];
        }

        $outputSet = [];
        foreach ($vOutPoint as $outPointKey => $outPoint) {
            $txOut = $this->db->get("utxo:$outPointKey");
            $outputSet[$outPointKey] = new Utxo($outPoint, $this->txOutSerializer->parse(new Buffer($txOut)));
        }

        return $outputSet;
    }
}
