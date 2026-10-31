import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import ServerContentBlock from '@/components/elements/ServerContentBlock';

type Source = 'modrinth' | 'hangar' | 'spigot';

interface Item {
    id: string;
    name: string;
    description: string;
    author: string;
    downloads: number;
    icon: string;
    url: string;
    external?: boolean;
}
interface Version {
    id: string;
    name: string;
    game: string;
    file: string;
    size: number;
}
interface Installed {
    file: string;
    size: number;
    enabled: boolean;
}

const BASE = '/extensions/mcmanager';
const SOURCES: { key: Source; label: string }[] = [
    { key: 'modrinth', label: 'Modrinth' },
    { key: 'hangar', label: 'Hangar (Paper)' },
    { key: 'spigot', label: 'SpigotMC' },
];
const LOADERS = ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'velocity', 'bungeecord', 'waterfall', 'fabric', 'forge', 'neoforge', 'quilt'];

const fmt = (n: number) => (n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n));
const size = (b: number) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
const errMsg = (e: any) => e?.response?.data?.message || e?.response?.data?.errors?.[0]?.detail || e?.message || 'Request failed';

const card = 'rounded-lg bg-neutral-700 border border-neutral-600 p-4';
const btn = 'px-3 py-1.5 rounded text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed';
const input = 'w-full rounded bg-neutral-800 border border-neutral-600 text-neutral-100 text-sm px-3 py-2 focus:outline-none focus:border-primary-400';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [tab, setTab] = useState<'browse' | 'installed'>('browse');
    const [source, setSource] = useState<Source>('modrinth');
    const [loader, setLoader] = useState('paper');
    const [version, setVersion] = useState('');
    const [folder, setFolder] = useState('/plugins');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(0);
    const [items, setItems] = useState<Item[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const [selected, setSelected] = useState<Item | null>(null);
    const [versions, setVersions] = useState<Version[]>([]);
    const [versionsLoading, setVersionsLoading] = useState(false);
    const [installing, setInstalling] = useState<string | null>(null);

    const [installed, setInstalled] = useState<Installed[]>([]);
    const [installedNote, setInstalledNote] = useState<string | null>(null);
    const [busyFile, setBusyFile] = useState<string | null>(null);
    const timer = useRef<any>(null);

    useEffect(() => {
        http.get(`${BASE}/info`, { params: { server: uuid } })
            .then(({ data }) => {
                if (data?.data) {
                    setLoader(data.data.loader || 'paper');
                    setVersion(data.data.version || '');
                    setFolder(data.data.folder || '/plugins');
                }
            })
            .catch(() => undefined);
    }, [uuid]);

    const search = useCallback(
        (pageNum: number) => {
            setLoading(true);
            setError(null);
            http.get(`${BASE}/search`, { params: { server: uuid, source, q, page: pageNum, loader, version } })
                .then(({ data }) => setItems(data.data || []))
                .catch((e) => {
                    setItems([]);
                    setError(errMsg(e));
                })
                .then(() => setLoading(false));
        },
        [uuid, source, q, loader, version]
    );

    useEffect(() => {
        if (tab !== 'browse') return;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => search(page), 350);
        return () => clearTimeout(timer.current);
    }, [search, page, tab]);

    const loadInstalled = useCallback(() => {
        http.get(`${BASE}/installed`, { params: { server: uuid } })
            .then(({ data }) => {
                setInstalled(data.data || []);
                setInstalledNote(data.note || null);
                if (data.folder) setFolder(data.folder);
            })
            .catch((e) => setError(errMsg(e)));
    }, [uuid]);

    useEffect(() => {
        if (tab === 'installed') loadInstalled();
    }, [tab, loadInstalled]);

    const open = (item: Item) => {
        setSelected(item);
        setVersions([]);
        setVersionsLoading(true);
        http.get(`${BASE}/versions`, { params: { server: uuid, source, project: item.id, loader, version } })
            .then(({ data }) => setVersions(data.data || []))
            .catch((e) => setError(errMsg(e)))
            .then(() => setVersionsLoading(false));
    };

    const install = (v: Version) => {
        if (!selected) return;
        setInstalling(v.id);
        setError(null);
        http.post(`${BASE}/install`, { server: uuid, source, project: selected.id, versionId: v.id, loader, version })
            .then(({ data }) => {
                setNotice(data.message || 'Installed.');
                setSelected(null);
            })
            .catch((e) => setError(errMsg(e)))
            .then(() => setInstalling(null));
    };

    const toggle = (f: Installed) => {
        setBusyFile(f.file);
        http.post(`${BASE}/toggle`, { server: uuid, file: f.file })
            .then(() => loadInstalled())
            .catch((e) => setError(errMsg(e)))
            .then(() => setBusyFile(null));
    };

    const remove = (f: Installed) => {
        if (!window.confirm(`Delete ${f.file}?`)) return;
        setBusyFile(f.file);
        http.post(`${BASE}/remove`, { server: uuid, file: f.file })
            .then(() => loadInstalled())
            .catch((e) => setError(errMsg(e)))
            .then(() => setBusyFile(null));
    };

    return (
        <ServerContentBlock title={'Plugins'}>
            <div className={'flex flex-wrap gap-2 mb-4'}>
                {(['browse', 'installed'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={`${btn} ${tab === t ? 'bg-primary-500 text-white' : 'bg-neutral-700 text-neutral-300 hover:bg-neutral-600'}`}
                    >
                        {t === 'browse' ? 'Browse' : `Installed (${folder})`}
                    </button>
                ))}
            </div>

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

            {tab === 'browse' && (
                <>
                    <div className={`${card} mb-4`}>
                        <div className={'flex flex-wrap gap-2 mb-3'}>
                            {SOURCES.map((s) => (
                                <button
                                    key={s.key}
                                    onClick={() => {
                                        setSource(s.key);
                                        setPage(0);
                                    }}
                                    className={`${btn} ${source === s.key ? 'bg-primary-500 text-white' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-600'}`}
                                >
                                    {s.label}
                                </button>
                            ))}
                        </div>
                        <div className={'grid grid-cols-1 md:grid-cols-4 gap-2'}>
                            <div className={'md:col-span-2'}>
                                <input
                                    className={input}
                                    placeholder={'Search plugins...'}
                                    value={q}
                                    onChange={(e) => {
                                        setQ(e.target.value);
                                        setPage(0);
                                    }}
                                />
                            </div>
                            <select className={input} value={loader} onChange={(e) => setLoader(e.target.value)}>
                                {LOADERS.map((l) => (
                                    <option key={l} value={l}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                            <input
                                className={input}
                                placeholder={'MC version (any)'}
                                value={version}
                                onChange={(e) => setVersion(e.target.value.trim())}
                            />
                        </div>
                    </div>

                    {loading ? (
                        <p className={'text-neutral-400 text-sm text-center py-8'}>Loading...</p>
                    ) : items.length === 0 ? (
                        <p className={'text-neutral-400 text-sm text-center py-8'}>Nothing found.</p>
                    ) : (
                        <div className={'grid grid-cols-1 md:grid-cols-2 gap-3'}>
                            {items.map((it) => (
                                <div key={it.id} className={`${card} flex gap-3 cursor-pointer hover:border-primary-400`} onClick={() => open(it)}>
                                    {it.icon ? (
                                        <img src={it.icon} alt={''} className={'w-12 h-12 rounded flex-none object-cover'} />
                                    ) : (
                                        <div className={'w-12 h-12 rounded flex-none bg-neutral-600'} />
                                    )}
                                    <div className={'min-w-0'}>
                                        <p className={'text-neutral-100 font-medium truncate'}>{it.name}</p>
                                        <p className={'text-neutral-400 text-xs truncate'}>
                                            {it.author && `by ${it.author} · `}
                                            {fmt(it.downloads)} downloads{it.external ? ' · external' : ''}
                                        </p>
                                        <p className={'text-neutral-300 text-sm mt-1 line-clamp-2'}>{it.description}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className={'flex justify-center gap-2 mt-4'}>
                        <button className={`${btn} bg-neutral-700 text-neutral-200`} disabled={page === 0 || loading} onClick={() => setPage(page - 1)}>
                            ← Prev
                        </button>
                        <span className={'text-neutral-400 text-sm py-1.5'}>Page {page + 1}</span>
                        <button className={`${btn} bg-neutral-700 text-neutral-200`} disabled={items.length < 20 || loading} onClick={() => setPage(page + 1)}>
                            Next →
                        </button>
                    </div>
                </>
            )}

            {tab === 'installed' && (
                <div className={card}>
                    {installedNote && <p className={'text-yellow-300 text-sm mb-2'}>{installedNote}</p>}
                    {installed.length === 0 ? (
                        <p className={'text-neutral-400 text-sm text-center py-6'}>No plugins in {folder}.</p>
                    ) : (
                        installed.map((f) => (
                            <div key={f.file} className={'flex items-center justify-between gap-2 py-2 border-b border-neutral-600 last:border-0'}>
                                <div className={'min-w-0'}>
                                    <p className={`truncate ${f.enabled ? 'text-neutral-100' : 'text-neutral-500 line-through'}`}>{f.file}</p>
                                    <p className={'text-neutral-400 text-xs'}>{size(f.size)}</p>
                                </div>
                                <div className={'flex gap-2 flex-none'}>
                                    <button
                                        disabled={busyFile === f.file}
                                        onClick={() => toggle(f)}
                                        className={`${btn} ${f.enabled ? 'bg-yellow-600 hover:bg-yellow-500' : 'bg-green-600 hover:bg-green-500'} text-white`}
                                    >
                                        {f.enabled ? 'Disable' : 'Enable'}
                                    </button>
                                    <button disabled={busyFile === f.file} onClick={() => remove(f)} className={`${btn} bg-red-600 hover:bg-red-500 text-white`}>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        ))
                    )}
                    <p className={'text-neutral-400 text-xs mt-3'}>Restart the server after changes.</p>
                </div>
            )}

            {selected && (
                <div className={'fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4'} onClick={() => setSelected(null)}>
                    <div className={`${card} w-full max-w-xl max-h-[80vh] overflow-y-auto`} onClick={(e) => e.stopPropagation()}>
                        <div className={'flex justify-between items-start gap-3 mb-3'}>
                            <div>
                                <p className={'text-neutral-100 text-lg font-medium'}>{selected.name}</p>
                                <a href={selected.url} target={'_blank'} rel={'noopener noreferrer'} className={'text-primary-300 text-xs'}>
                                    Open plugin page ↗
                                </a>
                            </div>
                            <button className={'text-neutral-400'} onClick={() => setSelected(null)}>
                                ✕
                            </button>
                        </div>
                        <p className={'text-neutral-300 text-sm mb-3'}>{selected.description}</p>
                        {versionsLoading ? (
                            <p className={'text-neutral-400 text-sm'}>Loading versions...</p>
                        ) : versions.length === 0 ? (
                            <p className={'text-neutral-400 text-sm'}>No version found for {loader}{version ? ` ${version}` : ''}. Try another loader / version.</p>
                        ) : (
                            versions.map((v) => (
                                <div key={v.id} className={'flex items-center justify-between gap-2 py-2 border-b border-neutral-600 last:border-0'}>
                                    <div className={'min-w-0'}>
                                        <p className={'text-neutral-100 text-sm truncate'}>{v.name}</p>
                                        <p className={'text-neutral-400 text-xs truncate'}>
                                            {v.game || 'any version'}
                                            {v.size ? ` · ${size(v.size)}` : ''}
                                        </p>
                                    </div>
                                    <button
                                        disabled={installing !== null}
                                        onClick={() => install(v)}
                                        className={`${btn} bg-primary-500 hover:bg-primary-400 text-white flex-none`}
                                    >
                                        {installing === v.id ? 'Installing...' : 'Install'}
                                    </button>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </ServerContentBlock>
    );
};
