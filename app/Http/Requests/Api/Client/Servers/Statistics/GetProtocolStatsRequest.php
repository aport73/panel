<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Statistics;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetProtocolStatsRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return $this->user()->can('view-stats', $this->route('server'));
    }
}