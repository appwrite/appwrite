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
        <p className="text-[13px] font-medium text-[#E5DEFF]">Browser CLI cache</p>
        <p className="text-[11px] leading-relaxed text-[#9B87F5]/80">
          The project terminal installs the Appwrite CLI in the browser and caches
          the install in IndexedDB so later commands start faster.
        </p>
      </div>

      <div className="space-y-2 rounded-lg border border-[#9B87F5]/20 bg-black/20 px-3 py-3">
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[#9B87F5]/80">CLI version</span>
          <span className="font-medium text-[#E5DEFF]">
            {summary?.version ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[#9B87F5]/80">Package</span>
          <span className="font-medium text-[#E5DEFF]">
            {summary?.packageName ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[#9B87F5]/80">IndexedDB</span>
          <span className="font-medium text-[#E5DEFF]">
            {summary?.indexedDb ?? '…'}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 text-[11px]">
          <span className="text-[#9B87F5]/80">Cached install</span>
          {loading ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin text-[#9B87F5]" />
          ) : cache ? (
            <span className="font-medium text-[#E5DEFF]">
              {cache.fileCount.toLocaleString()} files
            </span>
          ) : (
            <span className="font-medium text-[#E5DEFF]/70">None</span>
          )}
        </div>
        {cache ? (
          <div className="flex items-center justify-between gap-3 text-[11px]">
            <span className="text-[#9B87F5]/80">Saved at</span>
            <span className="font-medium text-[#E5DEFF]">
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
          className="h-9 w-full border-[#9B87F5]/30 bg-transparent text-[13px] text-[#E5DEFF] hover:bg-[#9B87F5]/15 hover:text-[#E5DEFF]"
          disabled={loading || clearing}
          onClick={() => void handleClearCache()}
        >
          {clearing ? (
            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <Trash2 className="mr-1.5 h-3.5 w-3.5" />
          )}
          Clear terminal cache
        </Button>
        <p className="mt-2 text-[11px] leading-relaxed text-[#9B87F5]/70">
          Clears the cached CLI install and resets in-memory terminal runtimes. Open
          terminals will reinstall the CLI on the next command.
        </p>
      </div>
    </div>
  )
}
