@extends('layouts.admin')
@section('title')
    Panic Mode Settings
@endsection

@section('content-header')
    <h1>Panic Mode Settings<small>Configure bandwidth monitoring and alerts.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Panic Mode</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box stats-card" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
            <div class="box-header with-border" style="border-bottom: 1px solid #2a3356;">
                <h3 class="box-title">Panic Mode Configuration</h3>
            </div>
            <form action="{{ route('admin.panicmode.update') }}" method="POST">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info" style="background-color: #162238; border-color: #2a3356; color: white;">
                                <strong>About Panic Mode:</strong> When enabled, this feature monitors server bandwidth usage and sends alerts via Discord webhook when any server exceeds the configured bandwidth threshold.
                            </div>
                        </div>
                        @if (session('success'))
                            <div class="col-md-12">
                                <div class="alert alert-success" style="background-color: #27ae60; border-color: #2ecc71; color: white;">
                                    {{ session('success') }}
                                </div>
                            </div>
                        @endif
                        @if (session('error'))
                            <div class="col-md-12">
                                <div class="alert alert-danger" style="background-color: #c0392b; border-color: #e74c3c; color: white;">
                                    {{ session('error') }}
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="control-label" style="color: #3498db;">Enable Panic Mode</label>
                                <div>
                                    <div class="btn-group toggle-buttons" style="margin-top: 10px;">
                                        <label id="enabled-btn" class="btn toggle-btn {{ $settings['enabled'] ? 'active' : '' }}" style="background-color: {{ $settings['enabled'] ? '#3498db' : '#1e2745' }}; border-color: {{ $settings['enabled'] ? '#2980b9' : '#2a3356' }}; color: {{ $settings['enabled'] ? 'white' : '#a3a7b7' }}; border-radius: 5px 0 0 5px; padding: 6px 15px;">
                                            <input type="radio" name="enabled" value="1" {{ $settings['enabled'] ? 'checked' : '' }}> Enabled
                                        </label>
                                        <label id="disabled-btn" class="btn toggle-btn {{ !$settings['enabled'] ? 'active' : '' }}" style="background-color: {{ !$settings['enabled'] ? '#3498db' : '#1e2745' }}; border-color: {{ !$settings['enabled'] ? '#2980b9' : '#2a3356' }}; color: {{ !$settings['enabled'] ? 'white' : '#a3a7b7' }}; border-radius: 0 5px 5px 0; padding: 6px 15px;">
                                            <input type="radio" name="enabled" value="0" {{ !$settings['enabled'] ? 'checked' : '' }}> Disabled
                                        </label>
                                    </div>
                                </div>
                                <p class="text-muted" style="color: #a3a7b7;"><small>When enabled, the system will monitor server bandwidth usage and trigger alerts.</small></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="bandwidth_threshold_mbps" class="control-label" style="color: #3498db;">Bandwidth Threshold (Mbps)</label>
                                <div>
                                    <div class="input-group">
                                        <input type="number" min="0.01" step="0.01" class="form-control" name="bandwidth_threshold_mbps" value="{{ $settings['bandwidth_threshold_mbps'] }}" required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px 0 0 5px; padding: 8px; height: 34px; font-size: 14px;">
                                        <span class="input-group-addon" style="background-color: #1e2745; border-color: #2a3356; color: #a3a7b7; border-radius: 0 5px 5px 0;">Mbps</span>
                                    </div>
                                </div>
                                <p class="text-muted" style="color: #a3a7b7;"><small>Bandwidth threshold in Mbps that triggers Panic Mode alerts. (0.01 Mbps = 10 Kbps)</small></p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cooldown_minutes" class="control-label" style="color: #3498db;">Alert Cooldown (Minutes)</label>
                                <div>
                                    <input type="number" min="0" class="form-control" name="cooldown_minutes" value="{{ $settings['cooldown_minutes'] }}" required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px; padding: 8px; height: 34px; font-size: 14px;">
                                </div>
                                <p class="text-muted" style="color: #a3a7b7;"><small>Minimum time between alerts for the same server (to prevent alert spam).</small></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="discord_webhook_url" class="control-label" style="color: #3498db;">Discord Webhook URL</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="discord_webhook_url" id="discord_webhook_url" value="{{ $settings['discord_webhook_url'] }}" placeholder="https://discord.com/api/webhooks/..." required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px 0 0 5px; padding: 8px; height: 34px; font-size: 14px;">
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" id="test-webhook" style="background-color: #3498db; border-color: #2980b9; color: white; border-radius: 0 5px 5px 0; height: 34px; font-weight: 600;">Test Webhook</button>
                                    </span>
                                </div>
                                <p class="text-muted" style="color: #a3a7b7;"><small>Discord webhook URL where alerts will be sent. <a href="https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks" target="_blank" style="color: #3498db;">Learn how to create a webhook</a></small></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="box-header with-border" style="margin-top: 15px; border-bottom: 1px solid #2a3356;">
                        <h3 class="box-title">Discord Embed Customization</h3>
                    </div>
                    
                    <div class="row" style="margin-top: 10px;">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="embed_color" class="control-label" style="color: #3498db;">Embed Color</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="embed_color" id="embed_color" value="{{ $settings['embed_color'] }}" required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px 0 0 5px; padding: 8px; height: 34px; font-size: 14px;">
                                    <span class="input-group-addon" style="background-color: #{{ dechex($settings['embed_color']) }}; width: 40px; border-radius: 0 5px 5px 0; border: 1px solid #2a3356; border-left: none;"></span>
                                </div>
                                <p class="text-muted" style="color: #a3a7b7;"><small>Color of the Discord embed in decimal format. <a href="https://www.spycolor.com/" target="_blank" style="color: #3498db;">Color picker</a></small></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="embed_title" class="control-label" style="color: #3498db;">Embed Title</label>
                                <div>
                                    <input type="text" class="form-control" name="embed_title" id="embed_title" value="{{ $settings['embed_title'] }}" required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px; padding: 8px; height: 34px; font-size: 14px;">
                                </div>
                                <p class="text-muted"><small>Title of the Discord embed alert. You can use placeholders: {SERVER_NAME}, {SERVER_UUID}, {SERVER_ID}, {SERVER_IP}, {SERVER_PORT}, {SERVER_IP_PORT}, {BANDWIDTH}, {THRESHOLD}</small></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="embed_description" class="control-label" style="color: #3498db;">Embed Description</label>
                                <div>
                                    <textarea class="form-control" name="embed_description" id="embed_description" rows="3" required style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 5px; padding: 8px; font-size: 14px;">{{ $settings['embed_description'] }}</textarea>
                                </div>
                                <p class="text-muted"><small>Description text for the Discord embed alert. You can use placeholders: {SERVER_NAME}, {SERVER_UUID}, {SERVER_ID}, {SERVER_IP}, {SERVER_PORT}, {SERVER_IP_PORT}, {BANDWIDTH}, {THRESHOLD}</small></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box-footer" style="background-color: #1a2238; border-top: 1px solid #2a3356; border-radius: 0 0 10px 10px;">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-primary pull-right" style="background-color: #3498db; border-color: #2980b9; border-radius: 20px; padding: 8px 25px; font-weight: 600;">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-xs-12">
        <div class="box stats-card" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
            <div class="box-header with-border">
                <h3 class="box-title">Manual Bandwidth Check</h3>
            </div>
            <div class="box-body">
                <p style="color: #a3a7b7;">Run a manual bandwidth check to test the Panic Mode system. This will check all servers for bandwidth usage exceeding the threshold and send alerts if needed.</p>
                <button type="button" class="btn btn-primary" id="run-manual-check" style="background-color: #3498db; border-color: #2980b9; border-radius: 20px; padding: 8px 25px; font-weight: 600; margin-top: 10px;">Run Manual Check</button>
                <div id="manual-check-results" class="mt-3" style="margin-top: 20px; display: none;">
                    <div class="alert" id="manual-check-alert" style="border-radius: 10px; padding: 15px;">
                        <span id="manual-check-message"></span>
                    </div>
                    <div id="manual-check-details" style="margin-top: 15px; color: white;"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(document).ready(function() {
            $('#test-webhook').on('click', function() {
                const webhookUrl = $('input[name="discord_webhook_url"]').val();
                if (!webhookUrl) {
                    alert('Please enter a Discord webhook URL first.');
                    return;
                }
                
                $(this).prop('disabled', true).html('<i class="fa fa-refresh fa-spin"></i> Testing...');
                
                $.ajax({
                    url: '{{ route('admin.panicmode.test-webhook') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function(data) {
                        if (data.success) {
                            alert('Success: ' + data.message);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while testing the webhook.');
                    },
                    complete: function() {
                        $('#test-webhook').prop('disabled', false).text('Test Webhook');
                    }
                });
            });
            
            $('#run-manual-check').on('click', function() {
                $(this).prop('disabled', true).html('<i class="fa fa-refresh fa-spin"></i> Running check...');
                $('#manual-check-results').hide();
                
                $.ajax({
                    url: '{{ route('admin.panicmode.manual-check') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function(data) {
                        $('#manual-check-results').show();
                        $('#manual-check-alert').removeClass('alert-success alert-danger').addClass(data.success ? 'alert-success' : 'alert-danger');
                        $('#manual-check-message').text(data.message);
                        
                        let detailsHtml = '';
                        if (data.servers_exceeding_threshold && data.servers_exceeding_threshold.length > 0) {
                            detailsHtml += '<h4>Servers Exceeding Threshold:</h4>';
                            detailsHtml += '<table class="table table-bordered">';
                            detailsHtml += '<thead><tr><th>Server ID</th><th>Server Name</th><th>Bandwidth (Mbps)</th><th>Threshold (Mbps)</th></tr></thead>';
                            detailsHtml += '<tbody>';
                            
                            data.servers_exceeding_threshold.forEach(function(server) {
                                detailsHtml += '<tr>';
                                detailsHtml += '<td>' + server.server_id + '</td>';
                                detailsHtml += '<td>' + server.server_name + '</td>';
                                detailsHtml += '<td>' + parseFloat(server.bandwidth_mbps).toFixed(2) + '</td>';
                                detailsHtml += '<td>' + server.threshold_mbps + '</td>';
                                detailsHtml += '</tr>';
                            });
                            
                            detailsHtml += '</tbody></table>';
                        } else if (data.success) {
                            detailsHtml += '<p>No servers are currently exceeding the bandwidth threshold.</p>';
                        }
                        
                        $('#manual-check-details').html(detailsHtml);
                    },
                    error: function() {
                        $('#manual-check-results').show();
                        $('#manual-check-alert').removeClass('alert-success').addClass('alert-danger');
                        $('#manual-check-message').text('An error occurred while running the bandwidth check.');
                        $('#manual-check-details').html('');
                    },
                    complete: function() {
                        $('#run-manual-check').prop('disabled', false).text('Run Manual Check');
                    }
                });
            });
        });
    </script>
@endsection
