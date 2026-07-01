import { useCallback, useEffect, useState } from 'react'
import { Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  clearCliTerminalCache,
  loadCliTerminalCacheSummary,
  type CliTerminalCacheSummary,
} from '@/lib/cli-shell/clear-terminal-cache'

function formatCacheSavedAt(savedAt: number): string {
  return new Date(savedAt).toLocaleString()
}

export function DebugMenuTerminalPanel() {
  const [summary, setSummary] = useState<CliTerminalCacheSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [clearing, setClearing] = useState(false)

  const refreshSummary = useCallback(async () => {
    setLoading(true)
    try {
      setSummary(await loadCliTerminalCacheSummary())
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refreshSummary()
  }, [refreshSummary])

  const handleClearCache = async () => {
    if (
      !window.confirm(
        'Clear the local terminal cache? The Appwrite CLI will be reinstalled on the next command.',
      )
    ) {
      return
    }

    setClearing(true)
    try {
      await clearCliTerminalCache()
      await refreshSummary()
      toast.success('Terminal cache cleared')
    } catch {
      toast.error('Failed to clear terminal cache')
    } finally {
      setClearing(false)
    }
  }

  const cache = summary?.cache

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Terminal settings">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">Browser CLI cache</p>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          The project terminal installs the Appwrite CLI in the browser and caches
          the install in IndexedDB so later commands start faster.
        </p>
      </div>

      <div className="space-y-2 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-3">
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">CLI version</span>
          <span className="font-medium text-foreground">
            {summary?.version ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">Package</span>
          <span className="font-medium text-foreground">
            {summary?.packageName ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">IndexedDB</span>
          <span className="font-medium text-foreground">
            {summary?.indexedDb ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">Cached install</span>
          {loading ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin text-[var(--network-globe-edge)]" />
          ) : cache ? (
            <span className="font-medium text-foreground">
              {cache.fileCount.toLocaleString()} files
            </span>
          ) : (
            <span className="font-medium text-foreground/70">None</span>
          )}
        </div>
        {cache ? (
          <div className="flex items-center justify-between gap-3 text-[11px]">
            <span className="text-[var(--network-globe-edge)]/80">Saved at</span>
            <span className="font-medium text-foreground">
              {formatCacheSavedAt(cache.savedAt)}
            </span>
          </div>
        ) : null}
      </div>

      <div className="px-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 w-full border-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--border))] bg-transparent text-[13px] text-foreground hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={loading || clearing}
          onClick={() => void handleClearCache()}
        >
          {clearing ? (
            <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <Trash2 className="me-1.5 h-3.5 w-3.5" />
          )}
          Clear terminal cache
        </Button>
        <p className="mt-2 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/70">
          Clears the cached CLI install and resets in-memory terminal runtimes. Open
          terminals will reinstall the CLI on the next command.
        </p>
      </div>
    </div>
  )
}
