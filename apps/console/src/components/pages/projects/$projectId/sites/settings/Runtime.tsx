import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { SiteRuntimeImageCard } from './SiteRuntimeImageCard'
import { SiteRuntimeTimeoutCard } from './SiteRuntimeTimeoutCard'
import { SiteRuntimeLoggingCard } from './SiteRuntimeLoggingCard'
import { SiteRuntimeSpecificationCard } from './SiteRuntimeSpecificationCard'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)
  const isCloud = true

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!site) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'image',
      search: {
        title: 'Image',
        keywords: ['runtime', 'ssr', 'server', 'start', 'image', 'node'],
      },
      node: (
        <SiteRuntimeImageCard projectId={projectId} siteId={siteId} site={site} />
      ),
    },
    {
      id: 'timeout',
      search: {
        title: 'Timeout',
        keywords: ['execute', 'seconds'],
      },
      node: (
        <SiteRuntimeTimeoutCard
          projectId={projectId}
          siteId={siteId}
          site={site}
        />
      ),
    },
    {
      id: 'logging',
      search: {
        title: 'Logging',
        keywords: ['logs', 'stdout'],
      },
      node: (
        <SiteRuntimeLoggingCard
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
        keywords: ['vcpu', 'memory', 'cpu'],
      },
      node: (
        <SiteRuntimeSpecificationCard
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
