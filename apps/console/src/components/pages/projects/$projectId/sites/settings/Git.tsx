import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { GitRepositoryCard } from './GitRepositoryCard'
import { GitSilentModeCard } from './GitSilentModeCard'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)

  const hasRepository = Boolean(
    site?.installationId && site?.providerRepositoryId,
  )

  const cards = useMemo((): SettingsCardItem[] => {
    if (!site) return []
    const items: SettingsCardItem[] = [
      {
        id: 'repository',
        search: {
          title: 'Repository',
          keywords: ['git', 'github', 'branch', 'connect', 'deployment'],
        },
        node: (
          <GitRepositoryCard projectId={projectId} siteId={siteId} site={site} />
        ),
      },
    ]
    if (hasRepository) {
      items.push({
        id: 'silent-mode',
        search: {
          title: 'Silent mode',
          keywords: ['comments', 'commits', 'pull request'],
        },
        node: (
          <GitSilentModeCard
            projectId={projectId}
            siteId={siteId}
            site={site}
          />
        ),
      })
    }
    return items
  }, [site, projectId, siteId, hasRepository])

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

  return <SettingsCardsList cards={cards} />
}
