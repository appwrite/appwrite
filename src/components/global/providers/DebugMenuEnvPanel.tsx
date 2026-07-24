import { useMemo, useState } from 'react'
import { Check, Minus, Search, X } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import {
  getDebugEnvStatuses,
  summarizeDebugEnvStatuses,
  type DebugEnvGroup,
  type DebugEnvStatus,
} from '@/lib/debug-env-status'

const GROUP_ORDER: DebugEnvGroup[] = [
  'Runtime',
  'Threads',
  'Init ticket storage',
  'Other',
]

function groupStatuses(
  statuses: DebugEnvStatus[],
): { group: DebugEnvGroup; items: DebugEnvStatus[] }[] {
  const byGroup = new Map<DebugEnvGroup, DebugEnvStatus[]>()
  for (const status of statuses) {
    const list = byGroup.get(status.group) ?? []
    list.push(status)
    byGroup.set(status.group, list)
  }
  return GROUP_ORDER.flatMap((group) => {
    const items = byGroup.get(group)
    return items?.length ? [{ group, items }] : []
  })
}

function matchesQuery(entry: DebugEnvStatus, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  const aliasHit = entry.aliases?.some((alias) =>
    alias.toLowerCase().includes(q),
  )
  return (
    entry.key.toLowerCase().includes(q) ||
    Boolean(aliasHit) ||
    (entry.description?.toLowerCase().includes(q) ?? false) ||
    entry.group.toLowerCase().includes(q) ||
    (entry.set ? 'set' : 'not set').includes(q)
  )
}

export function DebugMenuEnvPanel() {
  const [search, setSearch] = useState('')
  const statuses = useMemo(() => getDebugEnvStatuses(), [])
  const summary = useMemo(() => summarizeDebugEnvStatuses(statuses), [statuses])
  const filtered = useMemo(
    () => statuses.filter((entry) => matchesQuery(entry, search)),
    [statuses, search],
  )
  const groups = useMemo(() => groupStatuses(filtered), [filtered])

  return (
    <div className="space-y-3 px-1 py-1" aria-label="Environment variables">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">
          Environment variables
        </p>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Presence only. Values are never shown. Runtime keys come from{' '}
          <code className="rounded bg-muted/60 px-1 py-0.5 text-[10px]">
            window.__APP_CONFIG__
          </code>
          ; others from build-time{' '}
          <code className="rounded bg-muted/60 px-1 py-0.5 text-[10px]">
            import.meta.env
          </code>
          . Script-only secrets (e.g. Cloudflare, X/Twitter) are not available
          in the browser.
        </p>
        <p className="text-[11px] text-[var(--network-globe-edge)]/70">
          {summary.setCount} set · {summary.unsetCount} not set · {summary.total}{' '}
          total
        </p>
      </div>

      <div className="relative px-1">
        <Search className="pointer-events-none absolute start-3.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--network-globe-edge)]/60" />
        <Input
          autoFocus
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search env keys..."
          className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 ps-8 pe-8 text-[12px] text-foreground placeholder:text-[var(--network-globe-edge)]/50"
        />
        {search ? (
          <button
            type="button"
            onClick={() => setSearch('')}
            className="absolute end-3.5 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
            aria-label="Clear search"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        ) : null}
      </div>

      {groups.length === 0 ? (
        <p className="px-3 py-2 text-[11px] text-[var(--network-globe-edge)]/70">
          No matching env keys
        </p>
      ) : (
        <div className="space-y-3">
          {groups.map(({ group, items }) => (
            <div key={group}>
              <div className="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
                {group}
              </div>
              <ul className="space-y-0.5">
                {items.map((entry) => (
                  <li
                    key={entry.key}
                    className="flex items-start gap-3 rounded-lg px-3 py-2"
                  >
                    <span
                      className={cn(
                        'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md',
                        entry.set
                          ? 'bg-emerald-500/15 text-emerald-400'
                          : 'bg-muted/60 text-[var(--network-globe-edge)]/45',
                      )}
                      title={entry.set ? 'Set' : 'Not set'}
                      aria-label={entry.set ? 'Set' : 'Not set'}
                    >
                      {entry.set ? (
                        <Check className="h-3 w-3" aria-hidden />
                      ) : (
                        <Minus className="h-3 w-3" aria-hidden />
                      )}
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <code className="break-all text-[12px] font-medium text-foreground">
                          {entry.key}
                        </code>
                        <span
                          className={cn(
                            'text-[10px] font-medium uppercase tracking-wide',
                            entry.set
                              ? 'text-emerald-400/90'
                              : 'text-[var(--network-globe-edge)]/55',
                          )}
                        >
                          {entry.set ? 'Set' : 'Not set'}
                        </span>
                      </div>
                      {entry.description ? (
                        <p className="mt-0.5 text-[11px] leading-snug text-[var(--network-globe-edge)]/70">
                          {entry.description}
                        </p>
                      ) : null}
                      {entry.aliases?.length ? (
                        <p className="mt-0.5 text-[10px] leading-snug text-[var(--network-globe-edge)]/55">
                          Also accepts: {entry.aliases.join(', ')}
                        </p>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
