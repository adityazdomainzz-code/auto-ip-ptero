import React, { useCallback, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import ServerContentBlock from '@/components/elements/ServerContentBlock';

interface P {
    name: string;
    uuid?: string;
    reason?: string;
}
interface Data {
    online: boolean;
    count: number;
    max: number;
    players: P[];
    ops: P[];
    banned: P[];
    bannedIps: { ip: string; reason: string }[];
    whitelist: P[];
    whitelistOn: boolean;
}

const BASE = '/extensions/mcmanager';
const card = 'rounded-lg bg-neutral-700 border border-neutral-600 p-4';
const btn = 'px-2.5 py-1 rounded text-xs font-medium text-white transition-colors disabled:opacity-50';
const input = 'w-full rounded bg-neutral-800 border border-neutral-600 text-neutral-100 text-sm px-3 py-2 focus:outline-none focus:border-primary-400';
const errMsg = (e: any) => e?.response?.data?.message || e?.message || 'Request failed';
const head = (p: P) => `https://mc-heads.net/avatar/${encodeURIComponent(p.uuid || p.name)}/32`;

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const status = ServerContext.useStoreState((state) => state.status.value);

    const [data, setData] = useState<Data | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [name, setName] = useState('');
    const [reason, setReason] = useState('');

    const load = useCallback(() => {
        http.get(`${BASE}/players`, { params: { server: uuid } })
            .then(({ data }) => setData(data.data))
            .catch((e) => setError(errMsg(e)));
    }, [uuid]);

    useEffect(() => {
        load();
        const t = setInterval(load, 10000);
        return () => clearInterval(t);
    }, [load]);

    const act = (action: string, player?: string, askReason?: boolean) => {
        let why = reason;
        if (askReason) {
            const r = window.prompt(`Reason for ${action} ${player} (optional):`, '');
            if (r === null) return;
            why = r;
        }
        setBusy(true);
        setError(null);
        http.post(`${BASE}/action`, { server: uuid, action, name: player || '', reason: why })
            .then(({ data }) => {
                setNotice(data.message || 'Done.');
                setTimeout(load, 1500);
            })
            .catch((e) => setError(errMsg(e)))
            .then(() => setBusy(false));
    };

    const running = status === 'running';
    const isOp = (n: string) => !!data?.ops.some((o) => o.name.toLowerCase() === n.toLowerCase());

    const Row = ({ p, children }: { p: P; children: React.ReactNode }) => (
        <div className={'flex items-center justify-between gap-2 py-2 border-b border-neutral-600 last:border-0'}>
            <div className={'flex items-center gap-2 min-w-0'}>
                <img src={head(p)} alt={''} className={'w-8 h-8 rounded flex-none'} />
                <div className={'min-w-0'}>
                    <p className={'text-neutral-100 text-sm truncate'}>{p.name}</p>
                    {p.reason ? <p className={'text-neutral-400 text-xs truncate'}>{p.reason}</p> : null}
                </div>
            </div>
            <div className={'flex flex-wrap gap-1 justify-end flex-none'}>{children}</div>
        </div>
    );

    return (
        <ServerContentBlock title={'Players'}>
            {!running && (
                <div className={'mb-4 rounded bg-yellow-500/20 border border-yellow-500/40 text-yellow-200 text-sm px-4 py-2'}>
                    The server is not running - kick / ban / op / whitelist commands need a running server. Lists below are read from the server files.
                </div>
            )}
            {error && (
                <div className={'mb-4 rounded bg-red-500/20 border border-red-500/40 text-red-200 text-sm px-4 py-2 flex justify-between'}>
                    <span>{error}</span>
                    <button onClick={() => setError(null)}>✕</button>
                </div>
            )}
            {notice && (
                <div className={'mb-4 rounded bg-green-500/20 border border-green-500/40 text-green-200 text-sm px-4 py-2 flex justify-between'}>
                    <span>{notice}</span>
                    <button onClick={() => setNotice(null)}>✕</button>
                </div>
            )}

            <div className={`${card} mb-4`}>
                <p className={'text-neutral-200 text-sm mb-2'}>Player by name</p>
                <div className={'grid grid-cols-1 md:grid-cols-3 gap-2 mb-2'}>
                    <input className={input} placeholder={'Player name'} value={name} onChange={(e) => setName(e.target.value.trim())} />
                    <div className={'md:col-span-2'}>
                        <input className={input} placeholder={'Reason (kick / ban, optional)'} value={reason} onChange={(e) => setReason(e.target.value)} />
                    </div>
                </div>
                <div className={'flex flex-wrap gap-1'}>
                    {[
                        ['kick', 'Kick', 'bg-yellow-600'],
                        ['ban', 'Ban', 'bg-red-600'],
                        ['pardon', 'Unban', 'bg-green-600'],
                        ['op', 'OP', 'bg-primary-500'],
                        ['deop', 'De-OP', 'bg-neutral-500'],
                        ['whitelist-add', 'Whitelist +', 'bg-cyan-600'],
                        ['whitelist-remove', 'Whitelist −', 'bg-neutral-500'],
                    ].map(([a, label, color]) => (
                        <button key={a} disabled={busy || !running || !name} onClick={() => act(a, name)} className={`${btn} ${color}`}>
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className={'grid grid-cols-1 lg:grid-cols-2 gap-4'}>
                <div className={card}>
                    <p className={'text-neutral-100 font-medium mb-2'}>
                        Online {data ? `(${data.count}/${data.max})` : ''}
                        {data && !data.online && <span className={'text-neutral-400 text-xs ml-2'}>server not reachable</span>}
                    </p>
                    {data && data.players.length === 0 && <p className={'text-neutral-400 text-sm'}>Nobody online.</p>}
                    {data && data.count > data.players.length && data.players.length > 0 && (
                        <p className={'text-neutral-400 text-xs mb-1'}>The server only reports up to 12 names.</p>
                    )}
                    {data?.players.map((p) => (
                        <Row key={p.name} p={p}>
                            <button disabled={busy || !running} onClick={() => act('kick', p.name, true)} className={`${btn} bg-yellow-600`}>
                                Kick
                            </button>
                            <button disabled={busy || !running} onClick={() => act('ban', p.name, true)} className={`${btn} bg-red-600`}>
                                Ban
                            </button>
                            {isOp(p.name) ? (
                                <button disabled={busy || !running} onClick={() => act('deop', p.name)} className={`${btn} bg-neutral-500`}>
                                    De-OP
                                </button>
                            ) : (
                                <button disabled={busy || !running} onClick={() => act('op', p.name)} className={`${btn} bg-primary-500`}>
                                    OP
                                </button>
                            )}
                        </Row>
                    ))}
                </div>

                <div className={card}>
                    <p className={'text-neutral-100 font-medium mb-2'}>Operators ({data?.ops.length ?? 0})</p>
                    {data?.ops.length === 0 && <p className={'text-neutral-400 text-sm'}>No operators.</p>}
                    {data?.ops.map((p) => (
                        <Row key={p.name} p={p}>
                            <button disabled={busy || !running} onClick={() => act('deop', p.name)} className={`${btn} bg-neutral-500`}>
                                De-OP
                            </button>
                        </Row>
                    ))}
                </div>

                <div className={card}>
                    <div className={'flex justify-between items-center mb-2'}>
                        <p className={'text-neutral-100 font-medium'}>
                            Whitelist ({data?.whitelist.length ?? 0}){' '}
                            <span className={data?.whitelistOn ? 'text-green-400 text-xs' : 'text-neutral-400 text-xs'}>{data?.whitelistOn ? 'ON' : 'OFF'}</span>
                        </p>
                        <button
                            disabled={busy || !running}
                            onClick={() => act(data?.whitelistOn ? 'whitelist-off' : 'whitelist-on')}
                            className={`${btn} ${data?.whitelistOn ? 'bg-neutral-500' : 'bg-cyan-600'}`}
                        >
                            Turn {data?.whitelistOn ? 'off' : 'on'}
                        </button>
                    </div>
                    {data?.whitelist.length === 0 && <p className={'text-neutral-400 text-sm'}>Empty.</p>}
                    {data?.whitelist.map((p) => (
                        <Row key={p.name} p={p}>
                            <button disabled={busy || !running} onClick={() => act('whitelist-remove', p.name)} className={`${btn} bg-neutral-500`}>
                                Remove
                            </button>
                        </Row>
                    ))}
                </div>

                <div className={card}>
                    <p className={'text-neutral-100 font-medium mb-2'}>Banned ({data?.banned.length ?? 0})</p>
                    {data?.banned.length === 0 && <p className={'text-neutral-400 text-sm'}>Nobody is banned.</p>}
                    {data?.banned.map((p) => (
                        <Row key={p.name} p={p}>
                            <button disabled={busy || !running} onClick={() => act('pardon', p.name)} className={`${btn} bg-green-600`}>
                                Unban
                            </button>
                        </Row>
                    ))}
                    {data && data.bannedIps.length > 0 && (
                        <p className={'text-neutral-400 text-xs mt-2'}>Banned IPs: {data.bannedIps.map((b) => b.ip).join(', ')}</p>
                    )}
                </div>
            </div>
        </ServerContentBlock>
    );
};
