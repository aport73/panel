@extends('layouts.admin')

@section('title')
    {{ $node->name }}
@endsection

@section('content-header')
    <h1>{{ $node->name }}<small>A quick overview of your node.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li class="active">{{ $node->name }}</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="nav-tabs-custom nav-tabs-floating">
            <ul class="nav nav-tabs">
                <li class="active">
                    <a href="{{ route('admin.nodes.view', $node->id) }}">About</a>
                </li>
                <li>
                    <a href="{{ route('admin.nodes.view.settings', $node->id) }}">Settings</a>
                </li>
                <li>
                    <a href="{{ route('admin.nodes.view.configuration', $node->id) }}">Configuration</a>
                </li>
                <li>
                    <a href="{{ route('admin.nodes.view.allocation', $node->id) }}">Allocation</a>
                </li>
                <li>
                    <a href="{{ route('admin.nodes.view.servers', $node->id) }}">Servers</a>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-8">
        <div class="row">
            <div class="col-xs-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Information</h3>
                    </div>
                    <div class="box-body table-responsive no-padding">
                        <table class="table table-hover">
                            <tr>
                                <td>Daemon Version</td>
                                <td>
                                    <code data-attr="info-version">
                                        <i class="fa fa-refresh fa-fw fa-spin"></i>
                                    </code>
                                    (Latest: <code>{{ $version->getDaemon() }}</code>)
                                </td>
                            </tr>
                            <tr>
                                <td>System Information</td>
                                <td data-attr="info-system">
                                    <i class="fa fa-refresh fa-fw fa-spin"></i>
                                </td>
                            </tr>
                            <tr>
                                <td>Total CPU Threads</td>
                                <td data-attr="info-cpus">
                                    <i class="fa fa-refresh fa-fw fa-spin"></i>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            @if ($node->description)
                <div class="col-xs-12">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            Description
                        </div>
                        <div class="box-body table-responsive">
                            <pre>{{ $node->description }}</pre>
                        </div>
                    </div>
                </div>
            @endif

            <div class="col-xs-12">
                <div class="box box-danger">
                    <div class="box-header with-border">
                        <h3 class="box-title">Delete Node</h3>
                    </div>
                    <div class="box-body">
                        <p class="no-margin">
                            Deleting a node is an irreversible action and will immediately remove
                            this node from the panel. There must be no servers associated with this
                            node in order to continue.
                        </p>
                    </div>
                    <div class="box-footer">
                        <form action="{{ route('admin.nodes.view.delete', $node->id) }}" method="POST">
                            {!! csrf_field() !!}
                            {!! method_field('DELETE') !!}
                            <button type="submit"
                                    class="btn btn-danger btn-sm pull-right"
                                    {{ ($node->servers_count < 1) ?: 'disabled' }}>
                                Yes, Delete This Node
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">At-a-Glance</h3>
            </div>
            <div class="box-body">
                <div class="row">
                    @if($node->maintenance_mode)
                    <div class="col-sm-12">
                        <div class="info-box bg-orange">
                            <span class="info-box-icon"><i class="ion ion-wrench"></i></span>
                            <div class="info-box-content" style="padding: 23px 10px 0;">
                                <span class="info-box-text">This node is under</span>
                                <span class="info-box-number">Maintenance</span>
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="col-sm-12">
                        <div class="info-box bg-{{ $stats['disk']['css'] }}">
                            <span class="info-box-icon">
                                <i class="ion ion-ios-folder-outline"></i>
                            </span>
                            <div class="info-box-content" style="padding: 15px 10px 0;">
                                <span class="info-box-text">Disk Space Allocated</span>
                                <span class="info-box-number">
                                    {{ $stats['disk']['value'] }} / {{ $stats['disk']['max'] }} MiB
                                </span>
                                <div class="progress">
                                    <div class="progress-bar"
                                         style="width: {{ $stats['disk']['percent'] }}%">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-12">
                        <div class="info-box bg-{{ $stats['memory']['css'] }}">
                            <span class="info-box-icon">
                                <i class="ion ion-ios-barcode-outline"></i>
                            </span>
                            <div class="info-box-content" style="padding: 15px 10px 0;">
                                <span class="info-box-text">Memory Allocated</span>
                                <span class="info-box-number">
                                    {{ $stats['memory']['value'] }} / {{ $stats['memory']['max'] }} MiB
                                </span>
                                <div class="progress">
                                    <div class="progress-bar"
                                         style="width: {{ $stats['memory']['percent'] }}%">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-12">
                        <div class="info-box bg-blue">
                            <span class="info-box-icon">
                                <i class="ion ion-social-buffer-outline"></i>
                            </span>
                            <div class="info-box-content" style="padding: 23px 10px 0;">
                                <span class="info-box-text">Total Servers</span>
                                <span class="info-box-number">{{ $node->servers_count }}</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Node Usage Stats</h3>
            </div>
            <div class="box-body" style="height: auto;">

                <div class="info-box bg-purple">
                    <span class="info-box-icon"><i class="ion ion-speedometer"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">CPU Usage</span>
                            <span class="info-box-number">
                                <span id="cpu_usage">--</span>% (Threads: <span id="cpu_threads">--</span>)
                            </span>
                            <span class="info-box-text">
                                Processor: <span id="cpu_model">--</span>
                            </span>
                            <div class="progress">
                                <div id="cpu_bar" class="progress-bar" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="cpuChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="ion ion-ios-pulse"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">RAM Usage</span>
                            <span class="info-box-number">
                                <span id="ram_usage">--</span> / <span id="ram_total">--</span> GB
                                (<span id="ram_percent">--</span>%)
                            </span>
                            <div class="progress">
                                <div id="ram_bar" class="progress-bar" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="ramChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="info-box bg-blue">
                    <span class="info-box-icon"><i class="ion ion-ios-folder"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">Disk Usage</span>
                            <span class="info-box-number">
                                <span id="disk_usage">--</span> / <span id="disk_total">--</span> GB
                                (<span id="disk_percent">--</span>%)
                            </span>
                            <div class="progress">
                                <div id="disk_bar" class="progress-bar" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="diskChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="ion ion-ios-analytics"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">Swap Usage</span>
                            <span class="info-box-number">
                                <span id="swap_usage">--</span> / <span id="swap_total">--</span> GB
                                (<span id="swap_percent">--</span>%)
                            </span>
                            <div class="progress">
                                <div id="swap_bar" class="progress-bar" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="swapChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="info-box bg-teal">
                    <span class="info-box-icon"><i class="ion ion-ios-cloud-download-outline"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">Disk I/O Rate</span>
                            <span class="info-box-number">
                                Read: <span id="disk_read_rate">--</span> MB/s
                            </span>
                            <span class="info-box-number">
                                Write: <span id="disk_write_rate">--</span> MB/s
                            </span>
                            <div class="progress" style="margin-top: 5px;">
                                <div id="disk_read_bar" class="progress-bar"
                                     style="width: 0%; background-color: #17a2b8;">
                                </div>
                                <div id="disk_write_bar" class="progress-bar"
                                     style="width: 0%; background-color: #ffc107;">
                                </div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="ioChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="info-box bg-maroon">
                    <span class="info-box-icon"><i class="ion ion-arrow-swap"></i></span>
                    <div class="info-box-content d-flex"
                         style="display: flex; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px; margin-right: 10px;">
                            <span class="info-box-text">Network Usage</span>
                            <span class="info-box-number">
                                In: <span id="net_in_rate">--</span> MB/s
                            </span>
                            <span class="info-box-number">
                                Out: <span id="net_out_rate">--</span> MB/s
                            </span>
                            <div class="progress" style="margin-top: 5px;">
                                <div id="net_in_bar" class="progress-bar"
                                     style="width: 0%; background-color: #8bc34a;">
                                </div>
                                <div id="net_out_bar" class="progress-bar"
                                     style="width: 0%; background-color: #ffc107;">
                                </div>
                            </div>
                        </div>
                        <div class="sparkline-container"
                             style="flex: 0 0 auto; width: 130px; max-width: 100%; height: 50px;">
                            <canvas id="netChart"></canvas>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
@parent
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatNumber(value, decimals = 2) {
        return parseFloat(value).toLocaleString(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    (function getInformation() {
        $.ajax({
            method: 'GET',
            url: '/admin/nodes/view/{{ $node->id }}/system-information',
            timeout: 5000,
        }).done(function(data) {
            $('[data-attr="info-version"]').html(escapeHtml(data.version));
            $('[data-attr="info-system"]').html(
                escapeHtml(data.system.type) + ' (' + escapeHtml(data.system.arch) + ')' +
                ' <code>' + escapeHtml(data.system.release) + '</code>'
            );
            $('[data-attr="info-cpus"]').html(data.system.cpus);
        }).fail(function() {
            console.error("Error fetching system information.");
        }).always(function() {
            setTimeout(getInformation, 10000);
        });
    })();

    const MAX_POINTS = 60;

    function createSparkline(ctx) {
        return new Chart(ctx, {
            type: 'line',
            data: { labels: [], datasets: [{
                data: [],
                borderColor: 'rgba(255,255,255,0.9)',
                backgroundColor: 'transparent',
                borderWidth: 1,
                tension: 0.1,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false
            }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 0 },
                hover: { animationDuration: 0 },
                responsiveAnimationDuration: 0,
                scales: {
                    x: { display: false },
                    y: { display: false, min: 0, max: 100 }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
    }

    function createTwoLineSparkline(ctx, color1, color2) {
        return new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        data: [],
                        borderColor: color1,
                        backgroundColor: 'transparent',
                        borderWidth: 1,
                        tension: 0.1,
                        pointRadius: 0,
                        pointHoverRadius: 0,
                        fill: false
                    },
                    {
                        data: [],
                        borderColor: color2,
                        backgroundColor: 'transparent',
                        borderWidth: 1,
                        tension: 0.1,
                        pointRadius: 0,
                        pointHoverRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 0 },
                hover: { animationDuration: 0 },
                responsiveAnimationDuration: 0,
                scales: {
                    x: { display: false },
                    y: { display: false } 
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
    }

    const cpuChart  = createSparkline(document.getElementById('cpuChart').getContext('2d'));
    const ramChart  = createSparkline(document.getElementById('ramChart').getContext('2d'));
    const diskChart = createSparkline(document.getElementById('diskChart').getContext('2d'));
    const swapChart = createSparkline(document.getElementById('swapChart').getContext('2d'));

    const ioChart   = createTwoLineSparkline(
        document.getElementById('ioChart').getContext('2d'),
        '#17a2b8', // read
        '#ffc107'  // write
    );
    const netChart  = createTwoLineSparkline(
        document.getElementById('netChart').getContext('2d'),
        '#8bc34a', // in
        '#ffc107'  // out
    );

    function pushData(chart, value) {
        chart.data.labels.push('');
        chart.data.datasets[0].data.push(value);
        if (chart.data.labels.length > MAX_POINTS) {
            chart.data.labels.shift();
            chart.data.datasets[0].data.shift();
        }
        chart.update();
    }

    function pushData2(chart, val1, val2) {
        chart.data.labels.push('');
        chart.data.datasets[0].data.push(val1);
        chart.data.datasets[1].data.push(val2);
        if (chart.data.labels.length > MAX_POINTS) {
            chart.data.labels.shift();
            chart.data.datasets[0].data.shift();
            chart.data.datasets[1].data.shift();
        }
        chart.update();
    }

    function fetchNodeStats() {
        $.ajax({
            method: 'GET',
            url: '/admin/nodes/view/{{ $node->id }}/stats',
            timeout: 3000,
        }).done(function(data) {
            const cpuUsed = data.cpu_used.toFixed(2);
            $('#cpu_usage').text(cpuUsed);
            $('#cpu_threads').text(data.cpu_threads);
            $('#cpu_model').text(data.cpu_model);
            $('#cpu_bar').css('width', cpuUsed + '%');
            pushData(cpuChart, parseFloat(cpuUsed));

            const ramTotalGB  = (data.ram_total / 1024).toFixed(2);
            const ramUsedGB   = (data.ram_used / 1024).toFixed(2);
            const ramPercent  = data.ram_percent.toFixed(2);
            $('#ram_usage').text(formatNumber(ramUsedGB));
            $('#ram_total').text(formatNumber(ramTotalGB));
            $('#ram_percent').text(formatNumber(ramPercent));
            $('#ram_bar').css('width', ramPercent + '%');
            pushData(ramChart, parseFloat(ramPercent));

            const diskPercent = data.disk_percent.toFixed(2);
            $('#disk_usage').text(formatNumber(data.disk_used.toFixed(2)));
            $('#disk_total').text(formatNumber(data.disk_total.toFixed(2)));
            $('#disk_percent').text(formatNumber(diskPercent));
            $('#disk_bar').css('width', diskPercent + '%');
            pushData(diskChart, parseFloat(diskPercent));

            const swapPercent = data.swap_percent.toFixed(2);
            $('#swap_usage').text(formatNumber(data.swap_used.toFixed(2)));
            $('#swap_total').text(formatNumber(data.swap_total.toFixed(2)));
            $('#swap_percent').text(formatNumber(swapPercent));
            $('#swap_bar').css('width', swapPercent + '%');
            pushData(swapChart, parseFloat(swapPercent));

            const dReadRate  = parseFloat(data.disk_read_rate).toFixed(2);
            const dWriteRate = parseFloat(data.disk_write_rate).toFixed(2);
            $('#disk_read_rate').text(formatNumber(dReadRate));
            $('#disk_write_rate').text(formatNumber(dWriteRate));

            let totalIO = parseFloat(dReadRate) + parseFloat(dWriteRate);
            let readP = 0, writeP = 0;
            if (totalIO > 0) {
                readP = (dReadRate / totalIO) * 100;
                writeP = (dWriteRate / totalIO) * 100;
            }
            $('#disk_read_bar').css('width', readP + '%');
            $('#disk_write_bar').css('width', writeP + '%');

            pushData2(ioChart, parseFloat(dReadRate), parseFloat(dWriteRate));

            const netInRate  = parseFloat(data.net_in_rate).toFixed(2);
            const netOutRate = parseFloat(data.net_out_rate).toFixed(2);
            $('#net_in_rate').text(formatNumber(netInRate));
            $('#net_out_rate').text(formatNumber(netOutRate));

            let totalNet = parseFloat(netInRate) + parseFloat(netOutRate);
            let inP = 0, outP = 0;
            if (totalNet > 0) {
                inP = (netInRate / totalNet) * 100;
                outP = (netOutRate / totalNet) * 100;
            }
            $('#net_in_bar').css('width', inP + '%');
            $('#net_out_bar').css('width', outP + '%');

            pushData2(netChart, parseFloat(netInRate), parseFloat(netOutRate));

        }).fail(function() {
            console.error("Error fetching node stats.");
        }).always(function() {
            setTimeout(fetchNodeStats, 1000);
        });
    }

    fetchNodeStats();
</script>
@endsection
