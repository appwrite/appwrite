import { useCallback } from 'react'
import { useLocation, useNavigate, Link } from '@tanstack/react-router'
import { ArrowLeft, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  ResourceSearchPopover,
  type ResourceSearchKind,
} from '@/components/global/shared/ResourceSearchPopover'

export type ResourceTitleKind = ResourceSearchKind

/** Buckets and tables/collections already have sidebar selectors - no title switcher. */
export const RESOURCE_TITLE_SWITCHER_DISABLED_KINDS = [
  'bucket',
  'table',
] as const satisfies readonly ResourceTitleKind[]

export function isResourceTitleSwitchable(kind: ResourceTitleKind): boolean {
  return !RESOURCE_TITLE_SWITCHER_DISABLED_KINDS.includes(
    kind as (typeof RESOURCE_TITLE_SWITCHER_DISABLED_KINDS)[number],
  )
}

export function replaceResourceIdInPath(
  pathname: string,
  currentResourceId: string,
  newResourceId: string,
): string {
  const needle = `/${currentResourceId}`
  const idx = pathname.indexOf(needle)
  if (idx === -1) return pathname
  return (
    pathname.slice(0, idx) +
    `/${newResourceId}` +
    pathname.slice(idx + needle.length)
  )
}

export function useSwitchResourceInPlace() {
  const navigate = useNavigate()
  const location = useLocation()

  return useCallback(
    (currentResourceId: string, newResourceId: string) => {
      if (!currentResourceId || currentResourceId === newResourceId) return
      const newPath = replaceResourceIdInPath(
        location.pathname,
        currentResourceId,
        newResourceId,
      )
      navigate({
        to: newPath,
        search: location.search as Record<string, unknown>,
      })
    },
    [navigate, location.pathname, location.search],
  )
}

export interface ResourceTitleSwitcherProps {
  kind: ResourceTitleKind
  label: string
  resourceId: string
  projectId?: string | null
  organizationId?: string | null
  databaseId?: string | null
  onSelect: (newResourceId: string) => void
  disabled?: boolean
  className?: string
}

export function ResourceTitleSwitcher(props: ResourceTitleSwitcherProps) {
  if (!isResourceTitleSwitchable(props.kind)) {
    return (
      <span className={cn('truncate', props.className)}>{props.label}</span>
    )
  }
  return <ResourceTitleSwitcherPopover {...props} />
}

function ResourceTitleSwitcherPopover({
  kind,
  label,
  resourceId,
  projectId,
  organizationId,
  databaseId,
  onSelect,
  disabled = false,
  className,
}: ResourceTitleSwitcherProps) {
  return (
    <ResourceSearchPopover
      kind={kind}
      projectId={projectId}
      organizationId={organizationId}
      databaseId={databaseId}
      selectedId={resourceId}
      onSelect={onSelect}
      disabled={disabled}
      trigger={
        <button
          type="button"
          disabled={disabled}
          className={cn(
            'inline-flex min-w-0 max-w-full items-center gap-1 rounded-md px-1 py-0.5 text-start text-[17px] font-semibold text-foreground transition-colors',
            'hover:bg-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            disabled && 'pointer-events-none opacity-50',
            className,
          )}
          aria-label={`Switch ${kind}`}
        >
          <span className="truncate">{label}</span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </button>
      }
    />
  )
}

export interface DetailResourceHeaderTitleProps {
  kind: ResourceTitleKind
  label: string
  resourceId: string
  projectId?: string | null
  organizationId?: string | null
  databaseId?: string | null
  onResourceSelect?: (newResourceId: string) => void
  back?: {
    to?: string
    params?: Record<string, string>
    onClick?: () => void
    'aria-label': string
  }
  showCopyableId?: boolean
}

export function DetailResourceHeaderTitle({
  kind,
  label,
  resourceId,
  projectId,
  organizationId,
  databaseId,
  onResourceSelect,
  back,
  showCopyableId = true,
}: DetailResourceHeaderTitleProps) {
  const switchResource = useSwitchResourceInPlace()
  const handleSelect =
    onResourceSelect ??
    ((newResourceId: string) => switchResource(resourceId, newResourceId))

  return (
    <div className="flex min-w-0 items-center gap-2">
      {back ? (
        back.to ? (
          <Button
            variant="ghost"
            size="sm"
            asChild
            className="h-7 w-7 p-0"
            aria-label={back['aria-label']}
          >
            <Link to={back.to} params={back.params}>
              <ArrowLeft className="h-4 w-4" />
            </Link>
          </Button>
        ) : (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 w-7 p-0"
            onClick={back.onClick}
            aria-label={back['aria-label']}
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        )
      ) : null}
      <ResourceTitleSwitcher
        kind={kind}
        label={label}
        resourceId={resourceId}
        projectId={projectId}
        organizationId={organizationId}
        databaseId={databaseId}
        onSelect={handleSelect}
      />
      {showCopyableId && resourceId ? (
        <CopyableId id={resourceId} size="xs" className="shrink-0" />
      ) : null}
    </div>
  )
}
