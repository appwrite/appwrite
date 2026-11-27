import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useProjectFunction } from '@/lib/react-query/hooks'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { GitSettingsCard } from '../GitSettingsCard'
import { GitSilentModeCard } from '../GitSilentModeCard'
import { RepositoryReviewCard } from '../../reviews/_components/RepositoryReviewCard'
import { useReviewsAccess } from '../../reviews/_components/useReviewsAccess'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const { data: func, isLoading } = useProjectFunction(projectId, functionId)
  const { visible: reviewsVisible } = useReviewsAccess(projectId)

  const hasRepository = Boolean(
    func?.installationId && func?.providerRepositoryId,
  )

  const cards = useMemo((): SettingsCardItem[] => {
    if (!func) return []
    const items: SettingsCardItem[] = [
      {
        id: 'repository',
        search: {
          title: 'Repository',
          keywords: [
            'git',
            'github',
            'branch',
            'connect',
            'disconnect',
            'root directory',
          ],
        },
        node: <GitSettingsCard func={func} />,
      },
    ]
    if (hasRepository) {
      items.push({
        id: 'silent-mode',
        search: {
          title: 'Git automation',
          keywords: [
            'silent mode',
            'automation',
            'deploy on push',
            'production branch',
            'summaries',
            'comments',
            'commit status',
            'checks',
            'commits',
            'pull request',
            'deployment',
          ],
        },
        node: <GitSilentModeCard func={func} />,
      })
    }
    if (hasRepository && reviewsVisible && projectId) {
      items.push({
        id: 'code-review',
        search: {
          title: 'Code review',
          keywords: ['review', 'reviews', 'pull request', 'strength', 'github'],
        },
        node: (
          <RepositoryReviewCard
            projectId={projectId}
            installationId={func.installationId}
            providerRepositoryId={func.providerRepositoryId}
            resource={{ type: 'function', id: func.$id }}
          />
        ),
      })
    }
    return items
  }, [func, hasRepository, reviewsVisible, projectId])

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!func) return null

  return <SettingsCardsList cards={cards} />
}
