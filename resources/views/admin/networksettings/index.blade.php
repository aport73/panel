@extends('layouts.admin')
@section('title')
    Network Statistics Settings
@endsection

@section('content-header')
    <h1>Network Statistics Settings<small>Configure network statistics collection and retention.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Network Settings</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            @if (session()->has('success'))
                <div class="alert alert-success alert-dismissable" id="success-alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    {{ session()->get('success') }}
                </div>
            @endif
            <div class="box stats-card" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
                <div class="box-header with-border" style="border-bottom: 1px solid #2a3356;">
                    <h3 class="box-title">Network Statistics Configuration</h3>
                    <div class="box-tools">
                        <button type="button" class="btn btn-sm btn-default" id="developerModeToggle" style="margin-right: 5px; background-color: #2c3e50; border-color: #34495e; border-radius: 20px; padding: 5px 15px;">
                            <i class="fa fa-code"></i> Developer Mode
                        </button>
                        <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#collectStatisticsModal" style="margin-right: 5px; background-color: #3498db; border-color: #2980b9; border-radius: 20px; padding: 5px 15px;">
                            <i class="fa fa-refresh"></i> Collect Statistics Now
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#clearStatisticsModal" style="background-color: #e74c3c; border-color: #c0392b; border-radius: 20px; padding: 5px 15px;">
                            <i class="fa fa-trash"></i> Clear All Statistics
                        </button>
                    </div>
                </div>
                <form action="{{ route('admin.networksettings.update') }}" method="POST">
                    {{-- Developer Mode Panel --}}
                    <div id="developerModePanel" class="box-body" style="display: none; padding: 20px; background-color: #18202e; border: 1px solid #2a3356; margin-bottom: 20px; border-radius: 5px;">
                        <h4><i class="fa fa-code"></i> API Documentation</h4>
                        <p>The Network Statistics Manager exposes several API endpoints for retrieving server network statistics.</p>
                        
                        {{-- Language Selector --}}
                        <div class="nav-tabs-custom" style="box-shadow: none; background-color: transparent; border: none;">
                            <ul class="nav nav-tabs" style="border-bottom-color: #2a3356;">
                                <li class="active"><a href="#curl-tab" data-toggle="tab" class="lang-selector" data-lang="curl">cURL</a></li>
                                <li><a href="#js-tab" data-toggle="tab" class="lang-selector" data-lang="javascript">JavaScript</a></li>
                                <li><a href="#ts-tab" data-toggle="tab" class="lang-selector" data-lang="typescript">TypeScript</a></li>
                                <li><a href="#php-tab" data-toggle="tab" class="lang-selector" data-lang="php">PHP</a></li>
                                <li><a href="#go-tab" data-toggle="tab" class="lang-selector" data-lang="go">Go</a></li>
                                <li><a href="#rust-tab" data-toggle="tab" class="lang-selector" data-lang="rust">Rust</a></li>
                                <li><a href="#csharp-tab" data-toggle="tab" class="lang-selector" data-lang="csharp">C#</a></li>
                                <li><a href="#java-tab" data-toggle="tab" class="lang-selector" data-lang="java">Java</a></li>
                            </ul>
                            <div class="tab-content" style="padding: 15px; background-color: #1a2238; border-radius: 0 0 5px 5px;">
                                {{-- Tab content will be added separately --}}
                                <div class="tab-pane active" id="curl-tab">
                                    <h4 style="margin-top: 0; color: #3498db;">cURL Examples</h4>
                                    
                                    <div class="panel panel-default" style="background-color: #121a2b; border-color: #2a3356;">
                                        <div class="panel-heading" style="background-color: #18202e; color: #3498db; border-color: #2a3356;">
                                            <h5 class="panel-title">Get Current Statistics</h5>
                                        </div>
                                        <div class="panel-body" style="color: #a3a7b7;">
                                            <pre style="background-color: #0d1117; border-color: #2a3356; color: #e6edf3;"><code>curl -X GET \
  "https://your-panel.com/api/client/servers/SERVER_UUID/statistics" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json"</code></pre>
                                        </div>
                                    </div>
                                    
                                    <div class="panel panel-default" style="background-color: #121a2b; border-color: #2a3356;">
                                        <div class="panel-heading" style="background-color: #18202e; color: #3498db; border-color: #2a3356;">
                                            <h5 class="panel-title">Get Historical Network Statistics (30 days)</h5>
                                        </div>
                                        <div class="panel-body" style="color: #a3a7b7;">
                                            <pre style="background-color: #0d1117; border-color: #2a3356; color: #e6edf3;"><code>curl -X GET \
  "https://your-panel.com/api/client/servers/SERVER_UUID/network-stats/history?days=30" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json"</code></pre>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info" style="background-color: #162238; border-color: #2a3356; color: white;">
                                        <i class="fa fa-info-circle"></i> Replace <code>SERVER_UUID</code> with your server's UUID and <code>YOUR_API_KEY</code> with a valid API key from your account settings.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="box-body">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="alert alert-info">
                                    Configure how often network statistics are collected and how long they are retained.
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="collection_interval" class="control-label" style="color: #3498db;">Collection Interval</label>
                                    <div>
                                        <select name="collection_interval" id="collection_interval" class="form-control" style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 10px; padding: 12px; height: 50px; font-size: 16px;">
                                            @foreach($intervalOptions as $value => $label)
                                                <option value="{{ $value }}" {{ $settings['collection_interval'] == $value ? 'selected' : '' }}>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <p class="text-muted" style="color: #a3a7b7;"><small>How often network statistics are collected from servers.</small></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="retention_hours" class="control-label" style="color: #3498db;">Data Retention Period</label>
                                    <div>
                                        <select name="retention_hours" class="form-control" style="background-color: #141b30; border-color: #2a3356; color: white; border-radius: 10px; padding: 12px; height: 50px; font-size: 16px;">
                                            @foreach($retentionOptions as $value => $label)
                                                <option value="{{ $value }}" {{ $settings['retention_hours'] == $value ? 'selected' : '' }}>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <p class="text-muted" style="color: #a3a7b7;"><small>How long network statistics data is kept before being automatically deleted.</small></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="box-footer" style="background-color: #1a2238; border-top: 1px solid #2a3356; border-radius: 0 0 10px 10px;">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-primary pull-right" style="background-color: #3498db; border-color: #2980b9; border-radius: 20px; padding: 8px 25px; font-weight: 600;">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    {{-- Collect Statistics Confirmation Modal --}}
    <div class="modal fade" id="collectStatisticsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="background-color: #1e2745; color: white; border-radius: 10px; border: 1px solid #2a3356;">
                <form action="{{ route('admin.networksettings.collect') }}" method="POST">
                    <div class="modal-header" style="border-bottom: 1px solid #2a3356;">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title">Collect Network Statistics</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="alert alert-info" style="background-color: #162238; border-color: #2a3356; color: white;">
                                    <i class="fa fa-info-circle"></i> This will immediately collect network statistics from all active servers.
                                </div>
                                <p>This process will run in the background and may take some time depending on the number of servers.</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        {!! csrf_field() !!}
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" style="background-color: #3498db; border-color: #2980b9; border-radius: 20px; padding: 8px 20px; font-weight: 600;">Collect Statistics</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    {{-- Clear Statistics Confirmation Modal --}}
    <div class="modal fade" id="clearStatisticsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="background-color: #1e2745; color: white; border-radius: 10px; border: 1px solid #2a3356;">
                <form action="{{ route('admin.networksettings.clear') }}" method="POST">
                    <div class="modal-header" style="border-bottom: 1px solid #2a3356;">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title">Clear All Network Statistics</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="alert alert-danger" style="background-color: #c0392b; border-color: #e74c3c; color: white;">
                                    <i class="fa fa-exclamation-triangle"></i> <strong>Warning:</strong> This action will permanently delete ALL network statistics data from the database. This cannot be undone.
                                </div>
                                <p>Are you sure you want to clear all network statistics data?</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        {!! csrf_field() !!}
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" style="background-color: #e74c3c; border-color: #c0392b; border-radius: 20px; padding: 8px 20px; font-weight: 600;">Clear All Statistics</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(document).ready(function() {
            $("#success-alert").fadeTo(5000, 500).slideUp(500, function(){
                $("#success-alert").slideUp(500);
            });
            $("#developerModeToggle").on('click', function() {
                $("#developerModePanel").slideToggle(300, function() {
                    const isVisible = $("#developerModePanel").is(":visible");
                    $("#developerModeToggle").toggleClass("active", isVisible);
                    
                    if (isVisible) {
                        $("#developerModeToggle").css({
                            "background-color": "#3498db", 
                            "border-color": "#2980b9"
                        });
                    } else {
                        $("#developerModeToggle").css({
                            "background-color": "#2c3e50",
                            "border-color": "#34495e"
                        });
                    }
                });
            });
            
            $(".lang-selector").on('click', function() {
                const lang = $(this).data('lang');
                console.log("Selected language: " + lang);
                
                $(".lang-selector").parent().removeClass("active");
                $(this).parent().addClass("active");
            });
        });
    </script>
@endsection
