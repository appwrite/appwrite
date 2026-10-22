import { useMemo } from 'react'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import type { Tab } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { useT } from '@/lib/i18n/translate'
import { LiveVisitors } from './LiveVisitors'

export type PropertyTabId = 'analytics' | 'settings'

/**
 * The property's tabs as routes, like Sites and Functions: each tab is its
 * own page with its own loader, so the URL keeps the tab on reload and share.
 */
export function usePropertyTabs(projectId: string, propertyId: string): Tab[] {
  const t = useT()
  return useMemo(
    () => [
      {
        id: 'analytics',
        label: t('Analytics'),
        to: '/projects/$projectId/analytics/$propertyId',
        params: { projectId, propertyId },
      },
      {
        id: 'settings',
        label: t('Settings'),
        to: '/projects/$projectId/analytics/$propertyId/settings',
        params: { projectId, propertyId },
      },
    ],
    [projectId, propertyId, t],
  )
}

/**
 * Same title as every other detail view (functions, sites, topics, users):
 * back, a switcher to jump between properties, and the ID. Shared by the
 * property's tabs so the header doesn't change between them.
 */
export function PropertyHeaderTitle({
  projectId,
  propertyId,
  property,
  onBack,
}: {
  projectId: string
  propertyId: string
  property: Models.AnalyticsProperty | undefined
  onBack?: () => void
}) {
  const t = useT()
  return (
    <div className="flex min-w-0 items-center gap-2">
      <DetailResourceHeaderTitle
        kind="analyticsProperty"
        label={property?.name || t('Property')}
        resourceId={propertyId}
        projectId={projectId}
        back={
          onBack
            ? { onClick: onBack, 'aria-label': t('Back to analytics') }
            : undefined
        }
      />
      {property && !property.enabled && (
        <Badge variant="warning" className="shrink-0 text-[10px]">
          {t('Disabled')}
        </Badge>
      )}
    </div>
  )
}

/** "N online", next to the title on every tab. */
export function PropertyHeaderLive({
  projectId,
  propertyId,
  property,
}: {
  projectId: string
  propertyId: string
  property: Models.AnalyticsProperty | undefined
}) {
  return (
    <LiveVisitors
      projectId={projectId}
      propertyId={propertyId}
      enabled={property?.enabled !== false}
    />
  )
}
