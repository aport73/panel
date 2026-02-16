<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Statistics;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreStatisticsRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'network_rx_bytes' => 'required|integer|min:0',
            'network_tx_bytes' => 'required|integer|min:0',
            'network_rx_packets' => 'required|integer|min:0',
            'network_tx_packets' => 'required|integer|min:0',
        ];
    }
}
