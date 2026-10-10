import { CheckCircle2, GitCommitHorizontal, GitMerge, Tag, TriangleAlert } from 'lucide-react'
import { commitUrl, day, releaseUrl, type Commit, type LibrarySummary, type Sync } from '@/lib/docs'

/** A short sync badge for cards and lists. */
export function SyncBadge({ sync }: { sync: LibrarySummary['sync'] }) {
  const pending = (sync.behind ?? 0) + (sync.upstream ?? 0)
  if (sync.state === 'behind' || pending)
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-progress-soft px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-progress-ink" title="PHP changed since the Rust crate last matched it">
        <TriangleAlert className="size-3" />
        {pending} behind PHP
      </span>
    )
  return null
}

function CommitRow({ c }: { c: Commit }) {
  return (
    <a href={commitUrl(c.hash)} target="_blank" rel="noopener" className="grid gap-x-3 border-b border-border px-3 py-2 text-[13px] last:border-b-0 hover:bg-accent/40 md:grid-cols-[5.5rem_minmax(0,1fr)_auto]">
      <code className="font-mono text-[12px] text-muted-foreground">{c.short}</code>
      <span className="min-w-0 truncate">{c.subject}</span>
      <span className="text-[12px] whitespace-nowrap text-muted-foreground">
        {c.author} · {day(c.date)}
      </span>
    </a>
  )
}

function Commits({ list, total, title }: { list: Commit[]; total: number; title: string }) {
  if (!list.length) return null
  return (
    <div className="grid gap-2">
      <div className="text-[12px] font-medium text-muted-foreground">
        {title}
        {total > list.length ? ` (latest ${list.length} of ${total})` : ''}
      </div>
      <div className="overflow-hidden rounded-lg border border-border">
        {list.map((c) => (
          <CommitRow key={c.hash} c={c} />
        ))}
      </div>
    </div>
  )
}

/** How far the Rust crate is behind its PHP library, with the commits and releases to catch up on. */
export function SyncPanel({ slug, sync }: { slug: string; sync: Sync }) {
  if (sync.state === 'untracked')
    return <p className="m-0 text-[13px] text-muted-foreground">This library's PHP code is not in this repository, so its changes cannot be tracked here.</p>
  if (sync.state === 'planned' || sync.state === 'unknown')
    return (
      <div className="grid gap-3 rounded-lg border border-border bg-card p-4 text-[13px]">
        <span className="text-muted-foreground">No Rust crate follows it yet. The PHP library's latest activity:</span>
        <div className="flex flex-wrap gap-x-6 gap-y-2">
          {sync.latest ? (
            <a href={commitUrl(sync.latest.hash)} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 hover:underline">
              <GitCommitHorizontal className="size-4 text-muted-foreground" />
              {sync.latest.subject} <span className="text-muted-foreground">· {day(sync.latest.date)}</span>
            </a>
          ) : null}
          {sync.release ? (
            <a href={releaseUrl(slug, sync.release.version)} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 hover:underline">
              <Tag className="size-4 text-muted-foreground" />
              {sync.release.version} <span className="text-muted-foreground">· {day(sync.release.date)}</span>
            </a>
          ) : null}
        </div>
      </div>
    )
  const behind = sync.behind
  const upstream = sync.upstream
  const synced = sync.state === 'synced'
  return (
    <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
      <div className="flex flex-wrap items-start gap-3">
        {synced ? <CheckCircle2 className="mt-0.5 size-5 flex-none text-ok" /> : <TriangleAlert className="mt-0.5 size-5 flex-none text-progress" />}
        <div className="grid gap-1">
          <span className="text-[14px] font-medium">
            {synced ? 'Up to date with PHP' : `${behind?.commits} PHP ${behind?.commits === 1 ? 'commit' : 'commits'} to catch up on`}
            {!synced && behind ? (
              <span className="font-normal text-muted-foreground">
                {' '}
                · {behind.files} {behind.files === 1 ? 'file' : 'files'}, +{behind.insertions} −{behind.deletions}
              </span>
            ) : null}
          </span>
          {sync.synced ? (
            <span className="text-[12.5px] text-muted-foreground">
              {sync.estimated ? 'Estimated from when the crate was created: ' : 'Last matched PHP at '}
              <a href={commitUrl(sync.synced.hash)} target="_blank" rel="noopener" className="font-mono text-foreground hover:underline">
                {sync.synced.short}
              </a>{' '}
              ({day(sync.synced.date)}, {sync.synced.subject})
            </span>
          ) : null}
          {sync.release ? (
            <span className="text-[12.5px] text-muted-foreground">
              Latest PHP release{' '}
              <a href={releaseUrl(slug, sync.release.version)} target="_blank" rel="noopener" className="font-mono text-foreground hover:underline">
                {sync.release.version}
              </a>{' '}
              ({day(sync.release.date)})
            </span>
          ) : null}
        </div>
      </div>
      {sync.releases?.length ? (
        <div className="flex flex-wrap items-center gap-2 text-[12.5px] text-muted-foreground">
          <Tag className="size-3.5" />
          Released since:
          {sync.releases.map((r) => (
            <a key={r.tag} href={releaseUrl(slug, r.version)} target="_blank" rel="noopener" className="rounded-md border border-border px-2 py-0.5 font-mono text-[12px] text-foreground hover:border-ring">
              {r.version}
            </a>
          ))}
        </div>
      ) : null}
      {behind ? <Commits title="Changed in PHP since then" list={behind.list} total={behind.commits} /> : null}
      {upstream?.commits ? (
        <div className="grid gap-2">
          <div className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground">
            <GitMerge className="size-3.5" />
            Also on {upstream.ref}, not merged into this branch yet ({upstream.commits})
          </div>
          <Commits title="" list={upstream.list} total={upstream.commits} />
        </div>
      ) : upstream ? (
        <span className="text-[12px] text-muted-foreground">
          Nothing newer on {upstream.ref} (as last fetched, {day(upstream.head)}).
        </span>
      ) : null}
      {sync.estimated ? (
        <p className="m-0 text-[12px] text-muted-foreground">
          Once the crate matches PHP, run <code className="font-mono">bin/compat sync {slug}</code> to record the point it follows.
        </p>
      ) : null}
    </div>
  )
}
