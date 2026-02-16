<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Statistics;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetStatisticsRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'days' => 'sometimes|integer|min:1|max:30',
        ];
    }
}
