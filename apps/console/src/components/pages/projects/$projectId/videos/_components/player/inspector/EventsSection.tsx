import { useMemo, useState } from 'react'
import { AlertTriangle, Check, Copy, Info, Search, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { StreamEventEntry, StreamPlayerState } from '../useStreamPlayer'
import { Empty, FilterChips, formatMs, useInspectorWindow } from './shared'

type Filter = 'all' | StreamEventEntry['kind']

export function EventsSection({
  player,
  onClear,
}: {
  player: StreamPlayerState
  onClear: () => void
}) {
  const t = useT()
  const view = useInspectorWindow()
  const [filter, setFilter] = useState<Filter>('all')
  const [query, setQuery] = useState('')
  const [copied, setCopied] = useState(false)

  const counts = useMemo(() => {
    const result = { all: player.events.length, info: 0, warning: 0, error: 0 }
    for (const event of player.events) result[event.kind] += 1
    return result
  }, [player.events])

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return player.events.filter(
      (event) =>
        (filter === 'all' || event.kind === filter) &&
        (!needle ||
          event.name.toLowerCase().includes(needle) ||
          event.detail?.toLowerCase().includes(needle)),
    )
  }, [player.events, filter, query])

  const loadStart = player.loadStartedAt

  const copyLog = async () => {
    const text = [...visible]
      .reverse()
      .map(
        (event) =>
          `${new Date(event.at).toISOString()} ${event.kind.toUpperCase().padEnd(7)} ${event.name}${event.detail ? ` ${event.detail}` : ''}`,
      )
      .join('\n')
    try {
      await view.navigator.clipboard.writeText(text)
      setCopied(true)
      view.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-full max-w-xs">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t('Filter events...')}
            className="h-8 ps-8 text-[12px]"
          />
        </div>
        <FilterChips
          value={filter}
          onChange={setFilter}
          options={[
            { id: 'all', label: t('All'), count: counts.all },
            { id: 'info', label: t('Info'), count: counts.info },
            { id: 'warning', label: t('Warnings'), count: counts.warning },
            { id: 'error', label: t('Errors'), count: counts.error },
          ]}
        />
        <div className="ms-auto flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-[12px]"
            onClick={() => void copyLog()}
            disabled={visible.length === 0}
          >
            {copied ? (
              <Check className="h-3.5 w-3.5" />
            ) : (
              <Copy className="h-3.5 w-3.5" />
            )}
            {t('Copy log')}
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={onClear}
            disabled={player.events.length === 0}
          >
            {t('Clear')}
          </Button>
        </div>
      </div>

      {player.events.length === 0 ? (
        <Empty>{t('No events recorded yet.')}</Empty>
      ) : visible.length === 0 ? (
        <Empty>{t('No events match your filters.')}</Empty>
      ) : (
        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="max-h-[560px] overflow-y-auto font-mono text-[12px]">
            {visible.map((event, idx) => {
              const Icon =
                event.kind === 'error'
                  ? XCircle
                  : event.kind === 'warning'
                    ? AlertTriangle
                    : Info
              return (
                <div
                  key={`${event.at}-${idx}`}
                  className={cn(
                    'grid grid-cols-[72px_96px_16px_minmax(120px,auto)_minmax(0,1fr)] items-start gap-3 border-b border-border/60 px-4 py-1.5 last:border-b-0',
                    event.kind === 'error' && 'bg-red-500/5',
                    event.kind === 'warning' && 'bg-amber-500/5',
                  )}
                >
                  <span className="text-muted-foreground tabular-nums">
                    {loadStart ? `+${formatMs(event.at - loadStart)}` : '-'}
                  </span>
                  <span className="text-muted-foreground/70 tabular-nums">
                    {new Date(event.at).toISOString().slice(11, 23)}
                  </span>
                  <Icon
                    className={cn(
                      'mt-0.5 h-3.5 w-3.5',
                      event.kind === 'error' && 'text-red-500',
                      event.kind === 'warning' && 'text-amber-500',
                      event.kind === 'info' && 'text-muted-foreground/60',
                    )}
                  />
                  <span
                    className={cn(
                      'font-semibold',
                      event.kind === 'error' &&
                        'text-red-600 dark:text-red-400',
                      event.kind === 'warning' &&
                        'text-amber-600 dark:text-amber-400',
                    )}
                  >
                    {event.name}
                  </span>
                  <span className="min-w-0 break-all text-muted-foreground">
                    {event.detail}
                  </span>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
