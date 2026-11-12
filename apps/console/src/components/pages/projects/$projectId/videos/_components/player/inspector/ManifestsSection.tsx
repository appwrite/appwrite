import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Check, Copy, ExternalLink, FileText, RefreshCw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import {
  withConsoleVideoAccess,
  type StreamPlayerState,
} from '../useStreamPlayer'
import type { StreamManifest } from '../StreamDebugPanel'
import { Empty, IconAction, formatMs, useInspectorWindow } from './shared'

type ManifestEntry = { label: string; url: string; available?: boolean }

type Loaded = {
  url: string
  content: string
  status: number | null
  ms: number
  error: string | null
}

export function ManifestsSection({
  manifests,
  activeManifestUrl,
  player,
}: {
  manifests: StreamManifest[]
  activeManifestUrl: string | null
  player: StreamPlayerState
}) {
  const t = useT()
  const view = useInspectorWindow()
  const [viewing, setViewing] = useState<ManifestEntry | null>(null)
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loading, setLoading] = useState(false)
  const [copied, setCopied] = useState<string | null>(null)
  const autoLoaded = useRef(false)

  const entries: ManifestEntry[] = useMemo(
    () => [
      ...manifests,
      ...player.levels
        .filter((level) => level.url)
        .map((level) => ({
          label: `${t('Playlist')} ${formatResolution(level.width, level.height)}`,
          url: level.url,
        })),
    ],
    [manifests, player.levels, t],
  )

  const load = async (entry: ManifestEntry) => {
    setViewing(entry)
    setLoading(true)
    const started = performance.now()
    try {
      const response = await fetch(withConsoleVideoAccess(entry.url), {
        credentials: 'include',
      })
      const content = await response.text()
      setLoaded({
        url: entry.url,
        content,
        status: response.status,
        ms: performance.now() - started,
        error: response.ok ? null : `HTTP ${response.status}`,
      })
    } catch (err) {
      setLoaded({
        url: entry.url,
        content: '',
        status: null,
        ms: performance.now() - started,
        error: err instanceof Error ? err.message : String(err),
      })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (autoLoaded.current) return
    const active = entries.find((e) => e.url === activeManifestUrl)
    if (active) {
      autoLoaded.current = true
      void load(active)
    }
  }, [entries, activeManifestUrl])

  const copy = async (text: string, id: string) => {
    try {
      await view.navigator.clipboard.writeText(text)
      setCopied(id)
      view.setTimeout(() => setCopied(null), 1500)
    } catch {
      setCopied(null)
    }
  }

  const content =
    loaded && viewing && loaded.url === viewing.url ? loaded : null

  return (
    <div className="grid gap-4 xl:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
      <div className="h-fit overflow-hidden rounded-xl border border-border bg-card/50">
        {entries.map((entry) => {
          const isViewing = viewing?.url === entry.url
          return (
            <div
              key={entry.url}
              className={cn(
                'group flex items-center gap-1 border-b border-border last:border-b-0',
                isViewing && 'bg-muted/60',
                entry.available === false && 'opacity-50',
              )}
            >
              <button
                type="button"
                onClick={() => void load(entry)}
                className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2.5 text-start"
              >
                <FileText className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5">
                    <span className="truncate text-[12px] font-medium text-foreground">
                      {entry.label}
                    </span>
                    {entry.url === activeManifestUrl ? (
                      <Badge variant="success" className="text-[10px] shrink-0">
                        {t('Playing')}
                      </Badge>
                    ) : null}
                  </span>
                  <span className="block truncate font-mono text-[10px] text-muted-foreground">
                    {new URL(entry.url, window.location.href).pathname}
                  </span>
                </span>
              </button>
              <div className="pe-2">
                <IconAction
                  label={t('Copy URL')}
                  onClick={() => void copy(entry.url, entry.url)}
                >
                  {copied === entry.url ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                </IconAction>
              </div>
            </div>
          )
        })}
      </div>

      <div className="min-w-0 overflow-hidden rounded-xl border border-border bg-card/50">
        {!viewing ? (
          <div className="p-4">
            <Empty>{t('Select a manifest to view it.')}</Empty>
          </div>
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2.5">
              <span className="text-[13px] font-semibold text-foreground">
                {viewing.label}
              </span>
              {content ? (
                <span className="flex items-center gap-2 font-mono text-[11px] text-muted-foreground">
                  {content.status != null ? (
                    <Badge
                      variant={content.error ? 'error' : 'success'}
                      className="text-[10px] shrink-0"
                    >
                      {content.status}
                    </Badge>
                  ) : null}
                  {formatMs(content.ms)}
                  <span>·</span>
                  {formatBytes(new Blob([content.content]).size)}
                  <span>·</span>
                  {t('Lines')}: {content.content.split('\n').length}
                </span>
              ) : null}
              <div className="ms-auto flex items-center">
                <IconAction
                  label={t('Copy contents')}
                  onClick={() =>
                    content && void copy(content.content, 'content')
                  }
                  disabled={!content?.content}
                >
                  {copied === 'content' ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                </IconAction>
                <IconAction
                  label={t('Reload')}
                  onClick={() => void load(viewing)}
                >
                  <RefreshCw
                    className={cn('h-3.5 w-3.5', loading && 'animate-spin')}
                  />
                </IconAction>
                <IconAction
                  label={t('Open in new tab')}
                  onClick={() =>
                    view.open(
                      withConsoleVideoAccess(viewing.url),
                      '_blank',
                      'noopener',
                    )
                  }
                >
                  <ExternalLink className="h-3.5 w-3.5" />
                </IconAction>
              </div>
            </div>
            {content?.error ? (
              <p className="border-b border-border bg-red-500/5 px-4 py-2 font-mono text-[12px] text-red-600 dark:text-red-400">
                {content.error}
              </p>
            ) : null}
            <div className="max-h-[620px] overflow-auto">
              {loading && !content ? (
                <p className="p-4 text-[12px] text-muted-foreground">
                  {t('Loading...')}
                </p>
              ) : content?.content ? (
                <ManifestCode text={content.content} />
              ) : (
                <p className="p-4 text-[12px] text-muted-foreground">-</p>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function ManifestCode({ text }: { text: string }) {
  const lines = useMemo(() => text.replace(/\s+$/, '').split('\n'), [text])
  const isXml = text.trimStart().startsWith('<')
  return (
    <table
      dir="ltr"
      className="w-full border-collapse font-mono text-[12px] leading-[1.6]"
    >
      <tbody>
        {lines.map((line, i) => (
          <tr key={i} className="hover:bg-muted/40">
            <td className="w-px select-none whitespace-nowrap border-e border-border px-3 text-right align-top text-muted-foreground/50 tabular-nums">
              {i + 1}
            </td>
            <td className="whitespace-pre px-3 align-top text-foreground">
              {isXml ? highlightXml(line) : highlightHls(line)}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function highlightHls(line: string): ReactNode {
  if (line.startsWith('#EXT')) {
    const colon = line.indexOf(':')
    const tag = colon >= 0 ? line.slice(0, colon) : line
    const rest = colon >= 0 ? line.slice(colon + 1) : ''
    const parts = rest.split(/(,(?=(?:[^"]*"[^"]*")*[^"]*$))/)
    return (
      <>
        <span className="text-[var(--chart-1)]">{tag}</span>
        {colon >= 0 ? <span className="text-muted-foreground">:</span> : null}
        {parts.map((part, i) => {
          if (part === ',') {
            return (
              <span key={i} className="text-muted-foreground">
                ,
              </span>
            )
          }
          const eq = part.indexOf('=')
          if (eq < 0) return <span key={i}>{part}</span>
          return (
            <span key={i}>
              <span className="text-[var(--chart-4)]">{part.slice(0, eq)}</span>
              <span className="text-muted-foreground">=</span>
              <span className="text-[var(--chart-2)]">
                {part.slice(eq + 1)}
              </span>
            </span>
          )
        })}
      </>
    )
  }
  if (line.startsWith('#')) {
    return <span className="text-muted-foreground italic">{line}</span>
  }
  return <span className="text-muted-foreground">{line}</span>
}

function highlightXml(line: string): ReactNode {
  const tokens = line.split(/(<\/?[\w:.-]+|\/?>|[\w:.-]+="[^"]*")/g)
  return tokens.map((token, i) => {
    if (/^<\/?[\w:.-]+$/.test(token) || /^\/?>$/.test(token)) {
      return (
        <span key={i} className="text-[var(--chart-1)]">
          {token}
        </span>
      )
    }
    const attr = token.match(/^([\w:.-]+)=("[^"]*")$/)
    if (attr) {
      return (
        <span key={i}>
          <span className="text-[var(--chart-4)]">{attr[1]}</span>
          <span className="text-muted-foreground">=</span>
          <span className="text-[var(--chart-2)]">{attr[2]}</span>
        </span>
      )
    }
    return <span key={i}>{token}</span>
  })
}
