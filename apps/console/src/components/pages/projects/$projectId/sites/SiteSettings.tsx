import { useParams, useNavigate } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { NameCard } from './settings/NameCard'
import { GitRepositoryCard } from './settings/GitRepositoryCard'
import { SiteBuildFrameworkCard } from './settings/SiteBuildFrameworkCard'
import { SiteBuildCommandsCard } from './settings/SiteBuildCommandsCard'
import { SiteBuildSpecificationCard } from './settings/SiteBuildSpecificationCard'
import { SiteRuntimeImageCard } from './settings/SiteRuntimeImageCard'
import { SiteRuntimeStartCommandCard } from './settings/SiteRuntimeStartCommandCard'
import { SiteRuntimeTimeoutCard } from './settings/SiteRuntimeTimeoutCard'
import { SiteRuntimeLoggingCard } from './settings/SiteRuntimeLoggingCard'
import { SiteRuntimeSpecificationCard } from './settings/SiteRuntimeSpecificationCard'
import { DangerZoneCard } from './settings/DangerZoneCard'
import { useT } from '@/lib/i18n/translate'

export function SiteSettingsView() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()

  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )

  // Determine if this is a Cloud environment
  // This would typically come from project/org settings
  const isCloud = true // TODO: Get from project/org settings

  const handleDelete = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  if (siteLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* 1. Name */}
          {site && (
            <NameCard projectId={projectId} siteId={siteId} site={site} />
          )}

          {/* 2. Repository (conditional - only if repository connected) */}
          {site && (
            <GitRepositoryCard
              projectId={projectId}
              siteId={siteId}
              site={site}
            />
          )}

          {/* 3. Build */}
          {site && (
            <>
              <SiteBuildFrameworkCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteBuildCommandsCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteBuildSpecificationCard
                projectId={projectId}
                siteId={siteId}
                site={site}
                isCloud={isCloud}
              />
            </>
          )}

          {/* 4. Runtime */}
          {site && (
            <>
              <SiteRuntimeImageCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteRuntimeStartCommandCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteRuntimeTimeoutCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteRuntimeLoggingCard
                projectId={projectId}
                siteId={siteId}
                site={site}
              />
              <SiteRuntimeSpecificationCard
                projectId={projectId}
                siteId={siteId}
                site={site}
                isCloud={isCloud}
              />
            </>
          )}

          {/* 5. Danger Zone */}
          {site && (
            <DangerZoneCard
              projectId={projectId}
              siteId={siteId}
              site={site}
              onDelete={handleDelete}
            />
          )}
        </div>
      </div>
    </div>
  )
}
