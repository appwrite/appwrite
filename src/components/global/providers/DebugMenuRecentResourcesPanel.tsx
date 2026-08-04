import { History, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { useRecentResourcesSafe } from '@/components/global/providers/RecentResourcesProvider'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import {
  PROJECT_RESOURCE_KIND_LABELS,
  getRecentResourceBreadcrumbs,
  getRecentResourceDatabaseIconHints,
  getRecentResourceSiteFramework,
  type RecentResource,
} from '@/lib/command-center'
import {
  RECENT_RESOURCES_MAX_SHOWN,
  RECENT_RESOURCES_MAX_STORED,
  RECENT_RESOURCES_STORAGE_KEY,
} from '@/lib/command-center/recent-resources'

function formatViewedAt(viewedAt: number): string {
  return new Date(viewedAt).toLocaleString()
}

function RecentResourceDebugIcon({ entry }: { entry: RecentResource }) {
  const siteFramework = getRecentResourceSiteFramework(entry)
  if (siteFramework) {
    return (
      <FrameworkIcon
        framework={siteFramework}
        size="sm"
        className="h-3.5 w-3.5"
      />
    )
  }

  const databaseIconHints = getRecentResourceDatabaseIconHints(entry)
  if (databaseIconHints.apiType || databaseIconHints.engine) {
    return (
      <DatabaseTypeIcon
        apiType={databaseIconHints.apiType}
        engine={databaseIconHints.engine}
        className="h-3.5 w-3.5"
      />
    )
  }

  return <History className="h-3.5 w-3.5 text-[var(--network-globe-edge)]" />
}

function metaLine(entry: RecentResource): string {
  const parts: string[] = [
    PROJECT_RESOURCE_KIND_LABELS[entry.kind] ?? entry.kind,
    entry.resourceId,
  ]
  const siteFramework = getRecentResourceSiteFramework(entry)
  if (siteFramework) parts.push(`framework: ${siteFramework}`)
  const databaseIconHints = getRecentResourceDatabaseIconHints(entry)
  if (databaseIconHints.apiType) parts.push(`api: ${databaseIconHints.apiType}`)
  if (databaseIconHints.engine) parts.push(`engine: ${databaseIconHints.engine}`)
  return parts.join(' · ')
}

export function DebugMenuRecentResourcesPanel() {
  const recentResources = useRecentResourcesSafe()
  const resources = recentResources?.resources ?? []

  const handleReset = () => {
    if (
      !window.confirm(
        'Clear all recent Command Center resources from memory and localStorage?',
      )
    ) {
      return
    }

    recentResources?.clearRecentResources()
    toast.success('Recent resources cleared')
  }

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Recent resources">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">
          Command Center recent list
        </p>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Stored in localStorage under{' '}
          <code className="rounded bg-muted px-1 py-0.5 text-[10px]">
            {RECENT_RESOURCES_STORAGE_KEY}
          </code>
          . Newest first. Open a resource again after reset to rebuild the list
          (including site framework icons).
        </p>
      </div>

      <div className="space-y-1.5">
        <div className="flex items-center justify-between gap-3 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-2.5 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">Entries</span>
          <span className="font-medium text-foreground">{resources.length}</span>
        </div>
        <div className="flex items-center justify-between gap-3 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-2.5 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">
            Max stored
          </span>
          <span className="font-medium text-foreground">
            {RECENT_RESOURCES_MAX_STORED}
          </span>
        </div>
        <div className="flex items-center justify-between gap-3 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 px-3 py-2.5 text-[11px]">
          <span className="text-[var(--network-globe-edge)]/80">
            Max shown
          </span>
          <span className="font-medium text-foreground">
            {RECENT_RESOURCES_MAX_SHOWN}
          </span>
        </div>
      </div>

      {resources.length === 0 ? (
        <p className="px-3 text-[11px] text-[var(--network-globe-edge)]/70">
          No recent resources stored.
        </p>
      ) : (
        <div className="space-y-1.5">
          {resources.map((entry, index) => {
            const breadcrumbs = getRecentResourceBreadcrumbs(entry)
            return (
              <div
                key={entry.key}
                className="rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/30 px-3 py-2.5"
              >
                <div className="flex items-start gap-2.5">
                  <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border/60 bg-background/60">
                    <RecentResourceDebugIcon entry={entry} />
                  </div>
                  <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex items-start justify-between gap-2">
                      <p className="truncate text-[12px] font-medium text-foreground">
                        {entry.name}
                      </p>
                      <span className="shrink-0 text-[10px] text-[var(--network-globe-edge)]/60">
                        #{index + 1}
                      </span>
                    </div>
                    <p className="truncate text-[10px] text-[var(--network-globe-edge)]/80">
                      {breadcrumbs.join(' / ') || '-'}
                    </p>
                    <p className="truncate font-mono text-[10px] text-[var(--network-globe-edge)]/70">
                      {metaLine(entry)}
                    </p>
                    <p className="truncate font-mono text-[10px] text-[var(--network-globe-edge)]/60">
                      {entry.href}
                    </p>
                    <p className="text-[10px] text-[var(--network-globe-edge)]/60">
                      {formatViewedAt(entry.viewedAt)}
                    </p>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      <div className="px-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 w-full border-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--border))] bg-transparent text-[13px] text-foreground hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={!recentResources || resources.length === 0}
          onClick={handleReset}
        >
          <Trash2 className="me-1.5 h-3.5 w-3.5" />
          Reset recent resources
        </Button>
        <p className="mt-2 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/70">
          Clears the in-memory list and localStorage. Visit site detail pages
          again to re-record entries with framework icons.
        </p>
      </div>
    </div>
  )
}
