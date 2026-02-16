<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class ServerStatistic extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'server_statistics';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'server_id',
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
    ];

    /**
     * Gets the server associated with these statistics.
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
