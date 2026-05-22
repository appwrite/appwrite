import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { SiteBuildFrameworkCard } from './SiteBuildFrameworkCard'
import { SiteBuildCommandsCard } from './SiteBuildCommandsCard'
import { SiteBuildSpecificationCard } from './SiteBuildSpecificationCard'
import { SiteDeploymentRetentionCard } from './SiteDeploymentRetentionCard'

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)
  const isCloud = true

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!site) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'framework',
      search: {
        title: 'Framework',
        keywords: ['adapter', 'static', 'ssg', 'next', 'react'],
      },
      node: (
        <SiteBuildFrameworkCard
          projectId={projectId}
          siteId={siteId}
          site={site}
        />
      ),
    },
    {
      id: 'commands',
      search: {
        title: 'Commands',
        keywords: ['install', 'build', 'output', 'compile'],
      },
      node: (
        <SiteBuildCommandsCard
          projectId={projectId}
          siteId={siteId}
          site={site}
        />
      ),
    },
    {
      id: 'deployment-retention',
      search: {
        title: 'Deployment retention',
        description:
          'Keep active deployments and choose when inactive deployments are deleted.',
        keywords: [
          'deployment',
          'retention',
          'delete',
          'inactive',
          'forever',
          'cleanup',
        ],
      },
      node: (
        <SiteDeploymentRetentionCard
          projectId={projectId}
          siteId={siteId}
          site={site}
        />
      ),
    },
    {
      id: 'specification',
      search: {
        title: 'Specification',
        keywords: ['vcpu', 'memory', 'worker', 'profile'],
      },
      node: (
        <SiteBuildSpecificationCard
          projectId={projectId}
          siteId={siteId}
          site={site}
          isCloud={isCloud}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
