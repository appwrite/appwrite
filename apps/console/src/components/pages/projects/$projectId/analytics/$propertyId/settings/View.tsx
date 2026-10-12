import type { Models } from '@appwrite.io/console'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import {
  useAnalyticsProperty,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateAnalyticsProperty } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import { PropertySettings } from '../../_components/PropertySettings'
import {
  PropertyHeaderLive,
  PropertyHeaderTitle,
  usePropertyTabs,
} from '../../_components/PropertyHeader'

interface ViewProps {
  projectId: string
  propertyId: string
  onBack?: () => void
  /** The property from the route loader, so the first paint has it. */
  initialProperty?: Models.AnalyticsProperty
}

/**
 * The property's Settings tab, its own route like Sites and Functions
 * settings. Header is constrained to the cards (not fullWidth, unlike the
 * Analytics tab). Loads only the property, none of the analytics queries.
 */
export function View({
  projectId,
  propertyId,
  onBack,
  initialProperty,
}: ViewProps) {
  const t = useT()
  const tabs = usePropertyTabs(projectId, propertyId)

  const { property: propertyFromHook, isLoading } = useAnalyticsProperty(
    projectId,
    propertyId,
  )
  const property = propertyFromHook ?? initialProperty

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateAnalyticsProperty(access, features)

  if (!property && !isLoading) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title={t('Analytics')}
          showFilters={false}
          fullWidthBorder
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6">
          <EmptyState
            icon={ANALYTICS_PRODUCT_ICON}
            title={t('Property not found')}
            description={t('This analytics property no longer exists.')}
            variant="card"
            iconSize="md"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <PropertyHeaderTitle
            projectId={projectId}
            propertyId={propertyId}
            property={property}
            onBack={onBack}
          />
        }
        titleRightContent={
          <PropertyHeaderLive
            projectId={projectId}
            propertyId={propertyId}
            property={property}
          />
        }
        tabs={tabs}
        activeTab="settings"
        showFilters={false}
        fullWidthBorder
      />
      {property ? (
        <div className="mx-auto w-full max-w-7xl flex-1">
          <PropertySettings
            projectId={projectId}
            property={property}
            canWrite={canWrite}
          />
        </div>
      ) : null}
    </div>
  )
}
