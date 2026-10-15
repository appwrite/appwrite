import {
  Copy,
  ExternalLink,
  FileJson,
  Filter,
  FilterX,
  LayoutList,
  Link2,
  Square,
  X,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { formatActivityEventJson } from '@/components/pages/projects/$projectId/activity/activity-utils'
import { useT } from '@/lib/i18n/translate'

/** One queryable cell on the row, same attributes as click-to-filter. */
export type ActivityRowFilterItem = {
  attribute: string
  value: string
  /** Column label (`Event`, `Actor`, …). */
  label: string
  /** Human value shown next to the column label. */
  displayValue: string
}

interface ActivityLogRowContextMenuProps {
  projectId: string
  event: Models.ActivityEvent
  /** Opens the activity detail drawer (same as the View event button). */
  onOpenDetails: () => void
  /** Filterable values on this row (`event`, `actorId`, `country`, …). */
  filterItems?: ActivityRowFilterItem[]
  isEqualFilterActive?: (attribute: string, value: string) => boolean
  isNotEqualFilterActive?: (attribute: string, value: string) => boolean
  onToggleEqualFilter?: (attribute: string, value: string) => void
  onExcludeFilter?: (attribute: string, value: string) => void
  children: React.ReactNode
}

function activityPermalink(projectId: string, eventId: string) {
  const path = `/projects/${projectId}/activity?event=${encodeURIComponent(eventId)}`
  return buildConsoleUrl(path)
}

function filterItemDisplay(
  item: ActivityRowFilterItem,
  t: (text: string) => string,
): string {
  if (item.attribute === 'actorType' || item.attribute === 'resourceType') {
    return t(item.displayValue)
  }
  return item.displayValue
}

export function ActivityLogRowContextMenu({
  projectId,
  event,
  onOpenDetails,
  filterItems = [],
  isEqualFilterActive,
  isNotEqualFilterActive,
  onToggleEqualFilter,
  onExcludeFilter,
  children,
}: ActivityLogRowContextMenuProps) {
  const t = useT()
  if (!event?.$id) {
    return <>{children}</>
  }

  const eventHref = activityPermalink(projectId, event.$id)
  const hasName = !!event.actorName?.trim()
  const items = filterItems.filter((item) => item.value.trim())
  const canFilter =
    items.length > 0 && !!onToggleEqualFilter && !!isEqualFilterActive
  const excludable = canFilter
    ? items.filter(
        (item) =>
          !isEqualFilterActive(item.attribute, item.value) &&
          !isNotEqualFilterActive?.(item.attribute, item.value),
      )
    : []

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onOpenDetails()}>
          <ContextMenuIcon icon={LayoutList} />
          {t('Overview')}
        </ContextMenuItem>
        {canFilter ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuSub>
              <ContextMenuSubTrigger>
                <ContextMenuIcon icon={Filter} />
                {t('Filters')}
              </ContextMenuSubTrigger>
              <ContextMenuSubContent className="w-64">
                {items.map((item) => {
                  const equalActive = isEqualFilterActive(
                    item.attribute,
                    item.value,
                  )
                  const display = filterItemDisplay(item, t)
                  return (
                    <ContextMenuItem
                      key={`equal-${item.attribute}`}
                      onSelect={() =>
                        onToggleEqualFilter(item.attribute, item.value)
                      }
                    >
                      <ContextMenuIcon icon={equalActive ? X : Filter} />
                      <span className="min-w-0 truncate">
                        {equalActive
                          ? `${t('Remove filter')}: ${t(item.label)}`
                          : `${t(item.label)}: ${display}`}
                      </span>
                    </ContextMenuItem>
                  )
                })}
                {onExcludeFilter && excludable.length > 0 ? (
                  <>
                    <ContextMenuSeparator />
                    {excludable.map((item) => (
                      <ContextMenuItem
                        key={`exclude-${item.attribute}`}
                        onSelect={() =>
                          onExcludeFilter(item.attribute, item.value)
                        }
                      >
                        <ContextMenuIcon icon={FilterX} />
                        <span className="min-w-0 truncate">
                          {`${t('Exclude this value')}: ${filterItemDisplay(item, t)}`}
                        </span>
                      </ContextMenuItem>
                    ))}
                  </>
                ) : null}
              </ContextMenuSubContent>
            </ContextMenuSub>
          </>
        ) : null}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', event.$id)}>
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {hasName && (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', event.actorName)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
            )}
            <ContextMenuItem onSelect={() => copyToClipboard('Link', eventHref)}>
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('JSON', formatActivityEventJson(event))
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(eventHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(eventHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
