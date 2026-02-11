<?php

namespace Pterodactyl\Http\Controllers\Admin\VeltaStudios;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Repositories\Wings\DaemonNodeStatsRepository;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Pterodactyl\Models\Node;

class NodeStatsController extends Controller
{
    /**
     * @var DaemonNodeStatsRepository
     */
    private DaemonNodeStatsRepository $repository;

    /**
     * NodeStatsController constructor.
     */
    public function __construct(DaemonNodeStatsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Obtains real-time metrics for a specific node.
     *
     * @param Request $request
     * @param int $nodeId
     * @return JsonResponse
     */
    public function getNodeStats(Request $request, int $nodeId): JsonResponse
    {
        $node = Node::findOrFail($nodeId);

        try {
            $response = $this->repository->setNode($node)->getNodeStats();
            return response()->json(json_decode($response->getBody(), true));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'The metrics could not be obtained for the node',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
