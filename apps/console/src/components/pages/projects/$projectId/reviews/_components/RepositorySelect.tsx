import { useMemo, useState } from 'react'
import { useQueries } from '@tanstack/react-query'
import { FolderGit2 } from 'lucide-react'
import {
  SearchableSelect,
  type SearchableSelectItem,
} from '@/components/global/shared/SearchableSelect'
import type { ReviewRepository } from '@/lib/appwrite/reviews'
import {
  fetchRepository,
  useReviewRepositories,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { getVcsProvider } from '@/lib/vcs/providers'

/** Repositories offered: the first page the project reviews. */
const REPOSITORY_LIMIT = 100

const ALL_REPOSITORIES = 'all'

export type RepositoryOption = {
  id: string
  /** The installation's provider, such as `github`; empty when unknown. */
  provider: string
  /** `owner/name` once named, the provider's ID until then. */
  name: string
  /** Whether `name` is the real one yet. */
  named: boolean
}

/**
 * The repositories the project reviews, with their providers. A repository row
 * stores the provider's ID only; its name comes from the provider, one slow
 * call per repository, so only those `nameWhen` picks are named. Every tab
 * shares the names' cache.
 */
export function useRepositoryOptions(
  projectId: string,
  nameWhen: (repository: ReviewRepository) => boolean,
): RepositoryOption[] {
  const { repositories } = useReviewRepositories(projectId, 0, REPOSITORY_LIMIT)
  const { data: installationsData } = useVcsInstallations(projectId, 0, 100)
  const providers = useMemo(
    () =>
      new Map(
        (installationsData?.installations ?? []).map((installation) => [
          installation.$id,
          installation.provider,
        ]),
      ),
    [installationsData],
  )
  const names = useQueries({
    queries: repositories.map((repository) => ({
      queryKey: [
        'vcs',
        'repository',
        projectId,
        repository.installationId,
        repository.providerRepositoryId,
      ],
      queryFn: () =>
        fetchRepository(
          projectId,
          repository.installationId,
          repository.providerRepositoryId,
        ),
      enabled: nameWhen(repository),
      staleTime: DEFAULT_STALE_TIME,
      retry: false,
    })),
  })

  return repositories.map((repository, index) => {
    const provider = names[index]?.data
    return {
      id: repository.$id,
      provider: providers.get(repository.installationId) ?? '',
      name: provider
        ? `${provider.organization}/${provider.name}`
        : repository.providerRepositoryId,
      named: !!provider,
    }
  })
}

/**
 * A toolbar filter for one of the reviewed repositories, built like the Logs
 * page's resource filter: all of them, or one, with its provider's mark.
 * Empty `value` means all of them. The repositories are named once the list
 * first opens.
 */
export function RepositorySelect({
  projectId,
  value,
  onChange,
}: {
  projectId: string
  value: string
  onChange: (repositoryId: string) => void
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const options = useRepositoryOptions(
    projectId,
    (repository) => open || repository.$id === value,
  )
  const items: SearchableSelectItem[] = [
    { value: ALL_REPOSITORIES, label: t('All repositories'), icon: FolderGit2 },
    ...options.map((option) => ({
      value: option.id,
      label: option.name,
      icon: getVcsProvider(option.provider).Icon,
    })),
  ]

  return (
    <SearchableSelect
      value={value || ALL_REPOSITORIES}
      onValueChange={(next) => onChange(next === ALL_REPOSITORIES ? '' : next)}
      items={items}
      onOpenChange={setOpen}
      ariaLabel={t('Repository')}
      searchPlaceholder={t('Search repositories...')}
      emptyMessage={t('No repositories found')}
      selectedName={value || undefined}
      triggerClassName="w-[260px]"
      contentClassName="w-[300px]"
      listClassName="min-h-0"
    />
  )
}
