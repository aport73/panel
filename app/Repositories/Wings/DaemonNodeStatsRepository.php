<?php

namespace Pterodactyl\Repositories\Wings;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Node;
use Psr\Http\Message\ResponseInterface;
use Webmozart\Assert\Assert;

class DaemonNodeStatsRepository extends DaemonRepository
{
    /**
     * Gets the node metrics (RAM, Disk, CPU and SWAP) from Wings.
     *
     * @return ResponseInterface
     * @throws DaemonConnectionException
     * @throws GuzzleException
     */
    public function getNodeStats(): ResponseInterface
    {
        Assert::isInstanceOf($this->node, Node::class);

        try {
            return $this->getHttpClient()->get('/api/node/stats');
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }
}
