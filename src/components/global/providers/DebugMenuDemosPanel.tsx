import { useState } from 'react'
import { ExternalLink } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { DebugDemoCatalogPanel } from '@/components/global/providers/DebugDemoCatalogPanel'
import { DEFAULT_DEBUG_DEMO_ID, getDebugDemoById } from '@/lib/debug-demos/catalog'
import { openDebugDemo } from '@/lib/debug-demos/navigate'

type DebugMenuDemosPanelProps = {
  onLaunchDemo?: () => void
}

export function DebugMenuDemosPanel({ onLaunchDemo }: DebugMenuDemosPanelProps) {
  const navigate = useNavigate()
  const [selectedId, setSelectedId] = useState(DEFAULT_DEBUG_DEMO_ID)
  const selected = getDebugDemoById(selectedId)

  const routeHref = selected?.type === 'route' ? selected.href : undefined

  const handleOpen = () => {
    openDebugDemo(selectedId, navigate, { freshSession: true })
    onLaunchDemo?.()
  }

  const handleOpenInTab = () => {
    if (!routeHref) return
    window.open(routeHref, '_blank', 'noopener,noreferrer')
  }

  return (
    <div className="flex flex-col gap-3 px-1 py-1" aria-label="Demos">
      <p className="px-1 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
        Opens /debug preview routes that reuse production UI without live auth
        or API side effects. Use the floating panel to switch demos and states.
      </p>
      <DebugDemoCatalogPanel
        currentId={selectedId}
        onSelect={setSelectedId}
        density="menu"
        showCategoryFilter
        className="max-h-[min(44dvh,360px)]"
      />
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          className="h-9 flex-1 text-[13px]"
          onClick={handleOpen}
        >
          Open demo
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-9 w-9 shrink-0"
          disabled={!routeHref}
          onClick={handleOpenInTab}
          aria-label="Open in new tab"
        >
          <ExternalLink className="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>
  )
}
