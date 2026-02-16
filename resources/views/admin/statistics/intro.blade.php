@extends('layouts.admin')
@section('title')
    Network Statistics Introduction
@endsection

@section('content-header')
    <h1>Network Statistics Module<small>Introduction and information about the Network Statistics module.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.network.stats') }}">Network Statistics</a></li>
        <li class="active">Introduction</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary stats-card" style="background-color: #1e2745; color: white; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); border: 1px solid #2a3356;">
            <div class="box-header with-border" style="border-bottom: 1px solid #2a3356;">
                <h3 class="box-title">Welcome to Network Statistics V2</h3>
            </div>
            <div class="box-body" style="padding: 20px;">
                <div class="row">
                    <div class="col-md-12">
                        <div class="alert alert-info" style="background-color: #162238; border-color: #2a3356;">
                            <h4><i class="fa fa-info-circle"></i> About Network Statistics Module</h4>
                            <p>
                                The Network Statistics module provides comprehensive monitoring and visualization of network traffic across all your servers. 
                                This tool helps administrators track bandwidth usage, identify potential issues, and make informed decisions about resource allocation.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-box" style="background-color: #162238; border-radius: 10px; margin-bottom: 20px; min-height: 120px;">
                            <span class="info-box-icon" style="background-color: #1a2238; border-radius: 10px 0 0 10px;"><i class="fa fa-bar-chart"></i></span>
                            <div class="info-box-content" style="padding: 15px;">
                                <h4 style="margin-top: 0;">Key Features</h4>
                                <ul style="padding-left: 20px;">
                                    <li>Real-time network traffic monitoring</li>
                                    <li>Historical data visualization</li>
                                    <li>Customizable collection intervals</li>
                                    <li>Data retention management</li>
                                    <li>Server-specific filtering</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-box" style="background-color: #162238; border-radius: 10px; margin-bottom: 20px; min-height: 120px;">
                            <span class="info-box-icon" style="background-color: #1a2238; border-radius: 10px 0 0 10px;"><i class="fa fa-cogs"></i></span>
                            <div class="info-box-content" style="padding: 15px;">
                                <h4 style="margin-top: 0;">Configuration</h4>
                                <p>You can configure the Network Statistics module through the <a href="{{ route('admin.networksettings') }}" style="color: #3498db;">Network Settings</a> page:</p>
                                <ul style="padding-left: 20px;">
                                    <li>Set data collection intervals</li>
                                    <li>Configure data retention periods</li>
                                    <li>Manage bandwidth alerts</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="alert alert-warning" style="background-color: #2c3e50; border-color: #e67e22; color: white;">
                            <h4><i class="fa fa-exclamation-triangle"></i> Important Notice - V2 Beta</h4>
                            <p>
                                <strong>The Network Statistics V2 module is currently in beta.</strong> While we've extensively tested this version, 
                                you may encounter occasional bugs or unexpected behavior. If you experience any issues, please report them 
                                immediately to the development team through the support channels or by creating an issue on our GitHub repository.
                            </p>
                            <p>
                                Common issues to watch for:
                            </p>
                            <ul>
                                <li>Inconsistent data collection during high server load</li>
                                <li>Occasional gaps in historical data</li>
                                <li>Delayed updates in the dashboard</li>
                            </ul>
                            <p>
                                Your feedback is invaluable in helping us improve this module. Thank you for your patience and support!
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="box-footer" style="background-color: #1a2238; border-top: 1px solid #2a3356; border-radius: 0 0 10px 10px;">
                <a href="{{ route('admin.network.stats') }}" class="btn btn-primary" style="background-color: #3498db; border-color: #2980b9;">
                    <i class="fa fa-line-chart"></i> Go to Network Statistics
                </a>
                <a href="{{ route('admin.networksettings') }}" class="btn btn-default" style="margin-left: 10px; background-color: #2c3e50; border-color: #2c3e50; color: white;">
                    <i class="fa fa-cog"></i> Configure Settings
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(document).ready(function() {
            // Add any JavaScript functionality here if needed
        });
    </script>
@endsection
