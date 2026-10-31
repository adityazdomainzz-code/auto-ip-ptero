@extends('layouts.admin')

@section('title')
    Cloudflare DNS
@endsection

@section('content-header')
    <h1>Cloudflare DNS<small>Every new server gets its own address from its name.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Cloudflare DNS</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box {{ $active ? 'box-success' : 'box-primary' }}">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-cloud"></i> Status</h3>
            </div>
            <div class="box-body">
                @if($active)
                    <p class="text-success"><strong><i class="fa fa-check-circle"></i> Active</strong> &mdash;
                        server <code>Survival SMP</code> becomes <code>survival-smp.{{ $config['zone_name'] }}</code>.
                        Minecraft servers also get an SRV record, so players join without a port.</p>
                @elseif($connected)
                    <p class="text-warning"><strong><i class="fa fa-pause-circle"></i> Connected, not active</strong> &mdash; pick a domain, tick "Enable" and save.</p>
                @else
                    <p class="text-muted"><strong><i class="fa fa-plug"></i> Not connected</strong> &mdash; paste a Cloudflare API token below.</p>
                @endif
                <p class="text-muted small" style="margin-bottom:0">
                    If a name is already used, the server is <strong>not created</strong> and the panel shows "Server name already exists".
                    Renaming a server moves its address, deleting a server removes its records. Records are DNS-only (grey cloud) because game traffic cannot pass the Cloudflare proxy.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">1. Cloudflare API token</h3>
            </div>
            <form action="{{ route('admin.autoip.connect') }}" method="POST">
                <div class="box-body">
                    <div class="form-group">
                        <label class="control-label">API Token
                            @if($connected)<small class="text-muted">(saved {{ $tokenHint }} &mdash; paste a new one to replace)</small>@endif
                        </label>
                        <input type="password" name="token" class="form-control" placeholder="Cloudflare API token" autocomplete="off">
                        <p class="text-muted small">
                            Cloudflare &rarr; My Profile &rarr; API Tokens &rarr; Create Token &rarr; template <strong>Edit zone DNS</strong> &rarr; Zone Resources: your domain.
                            <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">Open <i class="fa fa-external-link"></i></a>
                        </p>
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-plug"></i> Connect</button>
                </div>
            </form>
            @if($connected)
                <form action="{{ route('admin.autoip.disconnect') }}" method="POST" style="padding:0 10px 10px">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Disconnect Cloudflare? Existing records stay in Cloudflare.')"><i class="fa fa-unlink"></i> Disconnect</button>
                </form>
            @endif
        </div>
    </div>

    <div class="col-md-6">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">2. Domain &amp; IP</h3>
            </div>
            @if(!$connected)
                <div class="box-body"><p class="text-muted">Connect a token first.</p></div>
            @else
                <form action="{{ route('admin.autoip.save') }}" method="POST">
                    <div class="box-body">
                        @if($zoneError)
                            <div class="alert alert-danger">{{ $zoneError }}</div>
                        @endif
                        <div class="form-group">
                            <label class="control-label">Domain</label>
                            <select name="zone_id" class="form-control">
                                <option value="">Select a domain...</option>
                                @foreach($zones as $z)
                                    <option value="{{ $z['id'] }}" @if($config['zone_id'] === $z['id']) selected @endif>{{ $z['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Public IP for DNS <small class="text-muted">(optional)</small></label>
                            <input type="text" name="target_ip" class="form-control" value="{{ $config['target_ip'] }}" placeholder="empty = the server's primary allocation (IP alias first, then IP)">
                            <p class="text-muted small">Set this when your node IP is private (NAT / 10.x / 192.168.x). Private IPs are never published.</p>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="enabled" value="1" @if($config['enabled']) checked @endif>
                                Create <code>&lt;server-name&gt;.{{ $config['zone_name'] ?: 'your-domain' }}</code> for every new server
                            </label>
                        </div>
                    </div>
                    <div class="box-footer">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-save"></i> Save</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Server addresses ({{ count($records) }})</h3>
                @if($active && $missing > 0)
                    <div class="box-tools">
                        <form action="{{ route('admin.autoip.sync') }}" method="POST" style="display:inline">
                            {!! csrf_field() !!}
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-refresh"></i> Create for {{ $missing }} existing server(s)</button>
                        </form>
                    </div>
                @endif
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <tbody>
                        <tr><th>Server</th><th>Address</th></tr>
                        @forelse($records as $serverId => $hostname)
                            <tr>
                                <td><a href="{{ route('admin.servers.view', $serverId) }}">{{ $servers[$serverId] ?? ('#' . $serverId) }}</a></td>
                                <td><code>{{ $hostname }}</code></td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">No addresses yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
