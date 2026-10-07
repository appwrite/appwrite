import type { ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import {
  Copy,
  ExternalLink,
  FileJson,
  Globe,
  Link2,
  Square,
  Trash2,
} from 'lucide-react'
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
import { fetchAnalyticsProperty } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

/**
 * Right-click menu for an analytics property, on both the grid cards and the
 * table rows. Same shape as the other resource menus (Functions, Sites):
 * open, copy, open elsewhere, delete.
 *
 * Delete is handed back to the list, which owns the confirm dialog, so the
 * card's "..." menu and this one share it.
 */
export function PropertyContextMenu({
  projectId,
  property,
  onDelete,
  children,
}: {
  projectId: string
  property: Pick<Models.AnalyticsProperty, '$id' | 'name' | 'domain' | 'snippetId'>
  /** Omit when the viewer can't delete properties. */
  onDelete?: () => void
  children: ReactNode
}) {
  const t = useT()
  const navigate = useNavigate()

  const propertyHref = buildConsoleUrl(
    `/projects/${projectId}/analytics/${property.$id}`,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() =>
            navigate({
              to: '/projects/$projectId/analytics/$propertyId',
              params: { projectId, propertyId: property.$id },
            })
          }
        >
          <ContextMenuIcon icon={ANALYTICS_PRODUCT_ICON} />
          {t('Analytics')}
        </ContextMenuItem>
        {property.domain ? (
          <ContextMenuItem
            onSelect={() => openInNewTab(`https://${property.domain}`)}
          >
            <ContextMenuIcon icon={Globe} />
            {t('Visit site')}
          </ContextMenuItem>
        ) : null}

        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem onSelect={() => copyToClipboard('ID', property.$id)}>
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {property.name ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', property.name)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
            ) : null}
            {property.snippetId ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Snippet ID', property.snippetId)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy snippet ID')}
              </ContextMenuItem>
            ) : null}
            {property.domain ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Domain', property.domain)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy domain')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', propertyHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchAnalyticsProperty(projectId, property.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(propertyHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(propertyHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>

        {onDelete ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem
              // Wait for the menu's overlay to close before the dialog opens.
              onSelect={() => openDialogAfterOverlayCloses(onDelete)}
            >
              <ContextMenuIcon icon={Trash2} />
              {t('Delete')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}
