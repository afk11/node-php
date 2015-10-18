<?php

namespace BitWasp\Bitcoin\Node;


use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Networking\Factory as NetworkingFactory;
use BitWasp\Bitcoin\Networking\Messages\Block;
use BitWasp\Bitcoin\Networking\Messages\GetHeaders;
use BitWasp\Bitcoin\Networking\Messages\Headers;
use BitWasp\Bitcoin\Networking\Messages\Inv;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\NetworkMessage;
use BitWasp\Bitcoin\Networking\Peer\Locator;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Bitcoin\Node\State\Peers;
use BitWasp\Bitcoin\Node\State\PeerState;
use BitWasp\Bitcoin\Node\State\PeerStateCollection;
use BitWasp\Buffertools\Buffer;
use Evenement\EventEmitter;
use Packaged\Config\Provider\Ini\IniConfigProvider;
use React\EventLoop\LoopInterface;

class BitcoinNode extends EventEmitter
{
    /**
     * @var \BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface
     */
    private $adapter;

    /**
     * @var NetworkingFactory
     */
    private $netFactory;

    /**
     * @var PeerStateCollection
     */
    private $peerState;

    /**
     * @var bool
     */
    private $syncing = false;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var \Packaged\Config\ConfigProviderInterface
     */
    public $config;

    /**
     * @var MySqlDb
     */
    public $db;

    /**
     * @var Params
     */
    public $params;


    /**
     * @param Params $params
     * @param LoopInterface $loop
     */
    public function __construct(Params $params, LoopInterface $loop)
    {
        echo " [App] start \n";
        $start = microtime(true);

        $this->loop = $loop;
        $this->params = $params;
        $this->adapter = Bitcoin::getEcAdapter();
        $this->network = Bitcoin::getNetwork();
        $this->inventory = new KnownInventory();
        $this->peerState = new PeerStateCollection();
        $this->peersInbound = new Peers();
        $this->peersOutbound = new Peers();

        $this->netFactory = new NetworkingFactory($loop);
        $this->zmq = new \React\ZMQ\Context($loop);
        $this
            ->initControl()
            ->initConfig();

        $this->consensus = new Consensus($this->adapter->getMath(), $this->params);
        $this->db = new MySqlDb($this->config, false);
        $this->chains = new Chains($this->adapter);
        $this->pow = new ProofOfWork($this->adapter->getMath(), $params);
        $this->headers = new Index\Headers($this->db, $this->adapter, $this->params, $this->pow);
        $this->blocks = new Index\Blocks($this->db, $this->adapter, $this->params, $this->pow);
        $this->initChainState();

        $this->on('blocks.syncing', function () {
            echo " [App] ... BLOCKS: syncing\n";
        });

        $this->on('headers.syncing', function () {
            echo " [App] ... HEADERS: syncing\n";
        });

        $this->on('headers.synced', function () {
            echo " [App] ... HEADERS: synced!\n";
        });

        echo " [App] Startup took: " . (microtime(true) - $start) . " seconds \n";
    }

    public function stop()
    {
        $this->peersInbound->close();
        $this->peersOutbound->close();
        $this->loop->stop();
        $this->db->stop();
    }

    /**
     *
     */
    private function initControl()
    {
        $control = $this->zmq->getSocket(\ZMQ::SOCKET_PULL);
        $control->bind('tcp://127.0.0.1:5560');
        $control->on('message', function ($e) {
            if ($e == 'shutdown') {
                echo "Shutdown\n";
                $this->stop();
            }
        });

        return $this;
    }

    /**
     * @return \Packaged\Config\Provider\Ini\IniConfigProvider
     */
    private function initConfig()
    {
        if (is_null($this->config)) {
            $file = getenv("HOME") . "/.bitcoinphp/bitcoin.ini";
            $this->config = new IniConfigProvider();
            $this->config->loadFile($file);
        }

        return $this;
    }

    private function initChainState()
    {
        $states = $this->db->fetchChainState($this->headers);
        foreach ($states as $state) {
            $this->chains->trackChain($state);
        }
        $this->chains->checkTips();
        return $this;
    }

    /**
     * @param ChainState $best
     * @param Peer $peer
     * @param PeerState $state
     */
    private function doDownloadBlocks(ChainState $best, Peer $peer, PeerState $state)
    {
        $blockHeight = $best->getLastBlock()->getHeight();
        echo "Download more blocks from (" . $blockHeight . ")\n";

        // We make sure to sync no more than 500 blocks at a time.
        $stopHeight = min($blockHeight + 500, $best->getChainIndex()->getHeight());
        $hashStop = Buffer::hex($best->getChain()->getHashFromHeight($stopHeight), 32, $this->adapter->getMath());
        $locator = $best->getLocator($blockHeight, $hashStop);
        echo "send getblocks\n";
        $peer->getblocks($locator);

        $state->useForBlockDownload(true);
        $state->addDownloadBlocks($stopHeight - $blockHeight - 1);
    }

    /**
     * @param Peer $peer
     */
    public function startBlockSync(Peer $peer)
    {
        $peerState = $this->peerState->fetch($peer);
        if (!$peerState->isBlockDownload()) {
            $this->emit('blocks.syncing');
            $this->doDownloadBlocks($this->chains->best(), $peer, $peerState);
        }
    }

    /**
     * @param Inventory $inventory
     * @return bool
     */
    public function checkInventory(Inventory $inventory)
    {
        if ($inventory->isBlock()) {
            //return isset($this->blocks->hashIndex[$inventory->getHash()->getHex()]);
        }

        if ($inventory->isTx()) {
            return $this->db->transactions->fetch($inventory->getHash()->getHex()) !== null;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isSyncing()
    {
        return $this->syncing;
    }

    /**
     * @return ChainState
     */
    public function chain()
    {
        return $this->chains->best();
    }

    /**
     *
     */
    public function start()
    {
        echo "called start\n";
        $dns = $this->netFactory->getDns();
        $peerFactory = $this->netFactory->getPeerFactory($dns);
        $handler = $peerFactory->getPacketHandler();
        $locator = $peerFactory->getLocator();

        $txRelay = $this->config->getItem('config', 'tx_relay', false);
        $manager = $peerFactory->getManager($txRelay);
        $manager->on('outbound', function (Peer $peer) {
            $this->peersOutbound->add($peer);
        });
        $manager->on('inbound', function (Peer $peer) {
            $this->peersInbound->add($peer);
        });
        $manager->registerHandler($handler);

        // Setup listener if required
        if ($this->config->getItem('config', 'listen', '0')) {
            echo ' [App - networking] enable listener';
            $server = new \React\Socket\Server($this->loop);
            $listener = $peerFactory->getListener($server);
            $manager->registerListener($listener);
        }

        $handler->on('ping', function (Peer $peer, Ping $ping) {
            $peer->pong($ping);
        });

        // Only for outbound peers
        $handler->on('outbound', function (Peer $peer) {
            $peer->on('msg', function (Peer $peer, NetworkMessage $msg) {
                $payload = $msg->getPayload();

                if ($msg->getCommand() == 'block') {
                    /** @var Block $payload */
                    echo " [Peer] " . $peer->getRemoteAddr()->getIp() . " - block - " . $payload->getBlock()->getHeader()->getBlockHash() . "\n";
                } else {
                    echo " [Peer] " . $peer->getRemoteAddr()->getIp() . " - " . $msg->getCommand(). "\n";
                }
            });

            $peer->on('block', function (Peer $peer, Block $blockMsg) {
                $best = $this->chain();
                $block = $blockMsg->getBlock();

                try {
                    $this->blocks->accept($best, $block, $this->headers);
                    $this->chains->checkTips();

                    $peerState = $this->peerState->fetch($peer);
                    if ($peerState->isBlockDownload()) {
                        $peerState->unsetDownloadBlock();
                        if (!$peerState->hasDownloadBlocks()) {
                            $this->doDownloadBlocks($best, $peer, $peerState);
                        }
                    }

                } catch (\Exception $e) {
                    $header = $block->getHeader();

                    echo "Failed to accept block\n";
                    echo $e->getTraceAsString() . PHP_EOL;
                    echo $e->getMessage() . PHP_EOL;
                    if ($best->getChain()->containsHash($block->getHeader()->getPrevBlock())) {
                        if ($header->getPrevBlock() == $best->getLastBlock()->getHash()) {
                            echo $block->getHeader()->getBlockHash() . "\n";
                            echo $block->getHex() . "\n";
                            echo 'We have prevblockIndex, so this is weird.';
                        } else {
                            echo "Didn't elongate the chain, probably from the future..\n";
                        }
                    }
                    sleep(3);
                }
            });

            $peer->on('inv', function (Peer $peer, Inv $inv) {
                echo "INV size: " . count($inv->getItems()) . "\n";
                $best = $this->chain();

                $vFetch = [];
                $lastBlock = false;
                foreach ($inv->getItems() as $item) {
                    echo ".";
                    if ($item->isBlock() && !$this->inventory->check($item)) {
                        $this->inventory->save($item);
                        $vFetch[] = $item;
                        $lastBlock = $item->getHash();
                    }
                }

                if ($lastBlock) {
                    $chain = $best->getChain();
                    try {
                        if (!$chain->containsHash($lastBlock->getHex())) {
                            $peer->getheaders($best->getLocator($chain->getIndex()->getHeight(), $lastBlock));
                        }
                    } catch (\Exception $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }

                }

                if (!empty($vFetch)) {
                    echo "Sending getdata\n";
                    $peer->getdata($vFetch);
                }
            });

            $peer->on('getheaders', function (Peer $peer, GetHeaders $getHeaders ) {
                return;
                $chain = $this->chain()->getChain();
                $locator = $getHeaders->getLocator();
                if (count($locator->getHashes()) == 0) {
                    $start = $locator->getHashStop()->getHex();
                } else {
                    $start = $this->db->findFork($chain, $locator);
                }

                $headers = $this->db->fetchNextHeaders($start);
                $peer->headers($headers);
                echo "Sending from " . $start . " + " . count($headers) . " headers \n";
            });

            $peer->on('headers', function (Peer $peer, Headers $headers) {
                $vHeaders = $headers->getHeaders();
                $state = $this->chain();
                if (count($vHeaders) > 0) {
                    try {
                        $this->headers->acceptBatch($state, $vHeaders);
                        $peer->getheaders($state->getHeadersLocator());
                        $this->chains->checkTips();
                    } catch (\Exception $e) {
                        echo "headers FAILURE..\n";
                        echo $e->getMessage() . PHP_EOL;
                        echo $e->getTraceAsString() . PHP_EOL;
                    }
                } else {
                    echo "zero\n";
                }

                echo "start downloading\n";
                $this->doDownloadBlocks($state, $peer, $this->peerState->fetch($peer));
            });
        });

        $locator
            ->queryDnsSeeds(1)
            ->then(function (Locator $locator) use ($manager, $handler) {
                for ($i = 0; $i < 2  ; $i++) {
                    $manager
                        ->connectNextPeer($locator)
                        ->then(function (Peer $peer) {
                            $peer->getheaders($this->chain()->getHeadersLocator());
                        }, function () {
                            echo "connection wtf?\n";
                        });
                }
            }, function () {
                echo 'ERROR';
            });
    }

}