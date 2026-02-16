<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Statistics;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreNetworkStatsRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'rx_bytes' => 'required|integer|min:0|max:' . PHP_INT_MAX,
            'tx_bytes' => 'required|integer|min:0|max:' . PHP_INT_MAX,
            'rx_packets' => 'required|integer|min:0|max:' . PHP_INT_MAX,
            'tx_packets' => 'required|integer|min:0|max:' . PHP_INT_MAX,
        ];
    }

    protected function authorize(): bool
    {
        return $this->user()->can('store-stats', $this->route('server'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rx_bytes' => (int) $this->input('rx_bytes'),
            'tx_bytes' => (int) $this->input('tx_bytes'),
            'rx_packets' => (int) $this->input('rx_packets'),
            'tx_packets' => (int) $this->input('tx_packets'),
        ]);
    }
}
