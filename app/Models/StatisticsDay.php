<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use Pterodactyl\Models\Server;

class StatisticsDay extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'statistics_days';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'server_uuid',
        'rx_bytes',
        'tx_bytes',
        'rx_packets',
        'tx_packets',
        'collected_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'collected_at' => 'datetime',
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'rx_packets' => 'integer',
        'tx_packets' => 'integer',
    ];

    /**
     * Gets the server associated with these statistics.
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_uuid', 'uuid');
    }
}
