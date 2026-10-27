import { useEffect, useMemo, useState } from 'react'
import { Loader2, Lock, Search } from 'lucide-react'
import { toast } from 'sonner'
import { VCSDetectionType, type Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useCreateReviewRepository,
  useProject,
  useRepositories,
  useReviewRepositories,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useDebouncedValue } from '@/lib/hooks/useDebouncedValue'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildVcsAuthUrl,
  VcsIcon,
  vcsProviderButtonIconClassName,
} from '@/lib/vcs/providers'

const REPOSITORIES_PAGE_SIZE = 8

type AddRepositoryProps = {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Turns code review on for a repository of one of the project's GitHub
 * installations. Reviews support GitHub only, so other providers are left out.
 */
export function AddRepository({
  projectId,
  open,
  onOpenChange,
}: AddRepositoryProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { data: installationsData, isLoading: installationsLoading } =
    useVcsInstallations(open ? projectId : null, 0, 100)
  const installations = useMemo(
    () =>
      (installationsData?.installations ?? []).filter(
        (installation) => installation.provider === 'github',
      ),
    [installationsData],
  )
  const { repositories: enrolled } = useReviewRepositories(projectId)
  const enrolledIds = useMemo(
    () =>
      new Set(enrolled.map((repository) => repository.providerRepositoryId)),
    [enrolled],
  )

  const [installationId, setInstallationId] = useState<string>('')
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebouncedValue(search, 300)

  useEffect(() => {
    if (!open) {
      setSearch('')
      return
    }
    if (!installationId && installations[0]) {
      setInstallationId(installations[0].$id)
    }
  }, [open, installationId, installations])

  const { data: repositoriesData, isFetching: repositoriesFetching } =
    useRepositories(
      open ? projectId : null,
      installationId || null,
      VCSDetectionType.Runtime,
      0,
      REPOSITORIES_PAGE_SIZE,
      debouncedSearch,
    )
  const repositories: Models.ProviderRepository[] =
    repositoriesData?.runtimeProviderRepositories ?? []

  const createMutation = useCreateReviewRepository(projectId)

  const handleAdd = (repository: Models.ProviderRepository) => {
    createMutation.mutate(
      { installationId, providerRepositoryId: repository.id },
      {
        onSuccess: () => {
          toast.success(t('Code review turned on'), {
            description: `${repository.organization}/${repository.name}`,
          })
          onOpenChange(false)
        },
        onError: (error) => {
          toast.error(
            getErrorMessage(error, t('Failed to turn on code review')),
          )
        },
      },
    )
  }

  const connectUrl = buildVcsAuthUrl({
    endpoint: getApiEndpoint(project?.region),
    provider: 'github',
    projectId,
    successUrl: `${typeof window === 'undefined' ? '' : window.location.origin}/projects/${projectId}/reviews/repositories`,
    failureUrl: `${typeof window === 'undefined' ? '' : window.location.origin}/projects/${projectId}/reviews/repositories`,
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add repository')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Pull requests in the repository are reviewed when they are opened or get new commits.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4">
          {installationsLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : installations.length === 0 ? (
            <div className="flex flex-col items-center gap-3 py-6 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Connect a GitHub installation to the project to review its repositories.',
                )}
              </p>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a href={connectUrl}>
                  <VcsIcon
                    type="github"
                    className={vcsProviderButtonIconClassName}
                  />
                  {t('Connect to GitHub')}
                </a>
              </Button>
            </div>
          ) : (
            <div className="space-y-3">
              {installations.length > 1 && (
                <Select
                  value={installationId}
                  onValueChange={setInstallationId}
                >
                  <SelectTrigger className="w-full h-9 text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {installations.map((installation) => (
                      <SelectItem
                        key={installation.$id}
                        value={installation.$id}
                        className="text-[13px]"
                      >
                        {installation.organization}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
              <div className="relative">
                <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder={t('Search repositories...')}
                  className="h-9 ps-9 text-[13px]"
                />
              </div>
              <div className="min-h-[200px] rounded-lg border border-border">
                {repositoriesFetching && repositories.length === 0 ? (
                  <div className="flex justify-center py-8">
                    <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                  </div>
                ) : repositories.length === 0 ? (
                  <p className="py-8 text-center text-[13px] text-muted-foreground">
                    {t('No repositories found')}
                  </p>
                ) : (
                  <ul className="divide-y divide-border">
                    {repositories.map((repository) => {
                      const added = enrolledIds.has(repository.id)
                      return (
                        <li
                          key={repository.id}
                          className="flex items-center justify-between gap-3 px-4 py-2.5"
                        >
                          <div className="flex min-w-0 items-center gap-2">
                            <VcsIcon
                              type="github"
                              className="h-4 w-4 shrink-0 text-muted-foreground"
                            />
                            <span className="truncate text-[13px] text-foreground">
                              {repository.name}
                            </span>
                            {repository.private && (
                              <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            )}
                          </div>
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 shrink-0 text-[13px]"
                            disabled={added || createMutation.isPending}
                            onClick={() => handleAdd(repository)}
                          >
                            {added ? t('Already added') : t('Add')}
                          </Button>
                        </li>
                      )
                    })}
                  </ul>
                )}
              </div>
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('Cancel')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
