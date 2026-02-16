<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Pterodactyl\WebSocket\StatisticsWebSocket;
use Pterodactyl\Models\Node;

class StartWebSocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket server for statistics collection.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $node = Node::first(); 
        if (!$node) {
            $this->error('No nodes found. Please ensure at least one node is configured.');
            return 1;
        }

        $this->info('Starting WebSocket server on ' . $node->getConnectionAddress() . '...');

        $server = IoServer::factory(
            new HttpServer(
                new WsServer(new StatisticsWebSocket())
            ),
            $node->daemonListen 
        );

        $server->run();

        return 0;
    }
}
