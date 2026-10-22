import { useCallback, useEffect, useState } from 'react'
import {
  AlertTriangle,
  Check,
  Copy,
  Loader2,
  RefreshCw,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  CLIENT_IP_DEBUG_HEADERS,
  CLIENT_IP_HEADER,
  type ClientIpSnapshot,
} from '@/lib/client-ip'
import {
  DEBUG_CLIENT_IP_PATH,
  fetchBrowserPublicIp,
  fetchLiveClientIpSnapshot,
  ipsMatch,
  readSsrClientIpSnapshot,
  type BrowserPublicIpResult,
} from '@/lib/debug-client-ip'

type LoadState = 'loading' | 'ready' | 'error'

function formatIp(ip: string | null | undefined, loading: boolean): string {
  if (loading) return '…'
  return ip || 'Missing'
}

function sourceLabel(source: BrowserPublicIpResult['source']): string {
  if (source === 'cdn-cgi/trace') return '/cdn-cgi/trace'
  if (source === 'ipify') return 'ipify'
  return 'Browser lookup'
}

function resolvedSourceLabel(snapshot: ClientIpSnapshot | null): string {
  if (snapshot?.source === CLIENT_IP_HEADER) return CLIENT_IP_HEADER
  if (snapshot?.source === 'runtime') return 'Runtime request IP'
  return 'No IP resolved'
}

async function copyValue(label: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied`)
  } catch {
    toast.error('Could not copy to clipboard')
  }
}

function IpRow({
  label,
  value,
  hint,
  loading,
}: {
  label: string
  value: string | null
  hint?: string
  loading: boolean
}) {
  const display = formatIp(value, loading)
  const canCopy = Boolean(value) && !loading

  return (
    <div className="flex items-start justify-between gap-3 text-[11px]">
      <div className="min-w-0">
        <p className="text-[var(--network-globe-edge)]/80">{label}</p>
        {hint ? (
          <p className="mt-0.5 text-[10px] leading-relaxed text-[var(--network-globe-edge)]/55">
            {hint}
          </p>
        ) : null}
      </div>
      <div className="flex min-w-0 items-center gap-1.5">
        <code
          className={cn(
            'max-w-[220px] truncate font-medium',
            loading || !value
              ? 'text-[var(--network-globe-edge)]/70'
              : 'text-foreground',
          )}
          title={value ?? undefined}
        >
          {display}
        </code>
        <button
          type="button"
          disabled={!canCopy}
          onClick={() => {
            if (!value) return
            void copyValue(label, value)
          }}
          className="flex h-5 w-5 shrink-0 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
          aria-label={`Copy ${label}`}
        >
          <Copy className="h-3 w-3" aria-hidden />
        </button>
      </div>
    </div>
  )
}

function ComparisonBadge({
  left,
  right,
  loading,
}: {
  left: string | null
  right: string | null
  loading: boolean
}) {
  if (loading) {
    return (
      <span className="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-[var(--network-globe-edge)]/70">
        <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
        Checking
      </span>
    )
  }

  if (!left || !right) {
    return (
      <span className="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-amber-400/90">
        <AlertTriangle className="h-3 w-3" aria-hidden />
        Incomplete
      </span>
    )
  }

  if (ipsMatch(left, right)) {
    return (
      <span className="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-emerald-400/90">
        <Check className="h-3 w-3" aria-hidden />
        Match
      </span>
    )
  }

  return (
    <span className="inline-flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-amber-400/90">
      <AlertTriangle className="h-3 w-3" aria-hidden />
      Mismatch
    </span>
  )
}

export function DebugMenuIpPanel() {
  const [ssrSnapshot, setSsrSnapshot] = useState<ClientIpSnapshot | null>(null)
  const [liveSnapshot, setLiveSnapshot] = useState<ClientIpSnapshot | null>(
    null,
  )
  const [browserIp, setBrowserIp] = useState<BrowserPublicIpResult | null>(null)
  const [state, setState] = useState<LoadState>('loading')
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    setState('loading')
    setError(null)
    setSsrSnapshot(readSsrClientIpSnapshot())

    const [browserResult, liveResult] = await Promise.allSettled([
      fetchBrowserPublicIp(),
      fetchLiveClientIpSnapshot(),
    ])

    if (browserResult.status === 'fulfilled') {
      setBrowserIp(browserResult.value)
    } else {
      setBrowserIp({
        ip: null,
        source: null,
        error:
          browserResult.reason instanceof Error
            ? browserResult.reason.message
            : 'Browser IP lookup failed',
      })
    }

    if (liveResult.status === 'fulfilled') {
      setLiveSnapshot(liveResult.value)
      setState('ready')
      return
    }

    setLiveSnapshot(null)
    setState('error')
    setError(
      liveResult.reason instanceof Error
        ? liveResult.reason.message
        : 'Failed to read the live server IP',
    )
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const loading = state === 'loading'
  const browserValue = browserIp?.ip ?? null
  const ssrValue = ssrSnapshot?.ip ?? null
  const liveValue = liveSnapshot?.ip ?? null

  return (
    <div className="space-y-3 px-1 py-1" aria-label="Client IP comparison">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-[13px] font-medium text-foreground">
            Client IP comparison
          </p>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="flex items-center gap-1.5 text-[10px] text-[var(--network-globe-edge)]/70">
              Browser / SSR
              <ComparisonBadge
                left={browserValue}
                right={ssrValue}
                loading={loading}
              />
            </span>
            <span className="flex items-center gap-1.5 text-[10px] text-[var(--network-globe-edge)]/70">
              SSR / request
              <ComparisonBadge
                left={ssrValue}
                right={liveValue}
                loading={loading}
              />
            </span>
          </div>
        </div>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Browser IP is looked up from the client. SSR and this request resolve
          via{' '}
          <code className="rounded bg-muted/60 px-1 py-0.5 text-[10px]">
            {CLIENT_IP_HEADER}
          </code>
          , then the runtime request IP if every IP header is missing. Hop
          headers are shown for diagnosis only.
        </p>
      </div>

      <div className="space-y-2.5 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-3">
        <IpRow
          label="Browser"
          value={browserValue}
          hint={
            browserIp?.error
              ? browserIp.error
              : sourceLabel(browserIp?.source ?? null)
          }
          loading={loading && !browserIp}
        />
        <IpRow
          label="SSR"
          value={ssrValue}
          hint={resolvedSourceLabel(ssrSnapshot)}
          loading={false}
        />
        <IpRow
          label="This request"
          value={liveValue}
          hint={
            loading
              ? `Client fetch to ${DEBUG_CLIENT_IP_PATH}`
              : resolvedSourceLabel(liveSnapshot)
          }
          loading={loading}
        />
        <IpRow
          label="Runtime"
          value={liveSnapshot?.runtimeIp ?? ssrSnapshot?.runtimeIp ?? null}
          hint="getRequestIP() / request.ip"
          loading={loading && !liveSnapshot}
        />
      </div>

      <div className="rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-3">
        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
          Hop headers
        </p>
        <div className="mb-1.5 grid grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-2 text-[10px] font-medium uppercase tracking-wide text-[var(--network-globe-edge)]/55">
          <span>Header</span>
          <span>SSR</span>
          <span>This request</span>
        </div>
        <div className="space-y-1.5">
          {CLIENT_IP_DEBUG_HEADERS.map((header) => {
            const ssrHeader = ssrSnapshot?.headers[header] ?? null
            const liveHeader = liveSnapshot?.headers[header] ?? null
            return (
              <div
                key={header}
                className="grid grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] items-start gap-2 text-[11px]"
              >
                <code className="break-all text-[var(--network-globe-edge)]/80">
                  {header}
                </code>
                <code
                  className={cn(
                    'truncate font-medium',
                    ssrHeader
                      ? 'text-foreground'
                      : 'text-[var(--network-globe-edge)]/70',
                  )}
                  title={ssrHeader ?? undefined}
                >
                  {ssrHeader || 'Missing'}
                </code>
                <code
                  className={cn(
                    'truncate font-medium',
                    loading
                      ? 'text-[var(--network-globe-edge)]/70'
                      : liveHeader
                        ? 'text-foreground'
                        : 'text-[var(--network-globe-edge)]/70',
                  )}
                  title={liveHeader ?? undefined}
                >
                  {loading ? '…' : liveHeader || 'Missing'}
                </code>
              </div>
            )
          })}
        </div>
      </div>

      {error ? (
        <p className="px-3 text-[11px] text-amber-400/90">{error}</p>
      ) : null}

      <div className="px-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 w-full border-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--border))] bg-transparent text-[13px] text-foreground hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={loading}
          onClick={() => void refresh()}
        >
          {loading ? (
            <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <RefreshCw className="me-1.5 h-3.5 w-3.5" />
          )}
          Refresh
        </Button>
      </div>
    </div>
  )
}
