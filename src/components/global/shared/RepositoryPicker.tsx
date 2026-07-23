/**
 * Shared repository picker for connecting to a Git repository.
 * Used in site creation wizard, site settings, and function settings
 * "Connect repository" modal.
 *
 * Features: installation selector scoped to one provider at a time (with an
 * explicit "Switch Git Provider" action, mirroring how Vercel/Netlify keep
 * each provider's orgs in their own list rather than interleaving them),
 * search, repo list with framework/runtime icon, pagination, "Can't find a
 * repository?" note, optional "Create a new site" link.
 */

import { useState, useEffect, useMemo } from 'react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SimplePagination } from '@/components/global/shared/Pagination'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Search, Lock, ArrowLeft, ArrowLeftRight } from 'lucide-react'
import {
  getVcsProvider,
  VCS_PROVIDERS,
  VcsIcon,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import { VCSDetectionType } from '@appwrite.io/console'
import { useRepositories } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { RefreshButton } from '@/components/global/shared/RefreshButton'

const REPO_PAGE_SIZE = 5

function RepositoryRowSkeleton({ provider }: { provider?: string }) {
  return (
    <div className="flex w-full items-center gap-3 px-4 py-3.5">
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
        <VcsIcon type={provider} className="h-3.5 w-3.5" />
      </div>
      <div className="flex-1 min-w-0 flex items-center gap-2">
        <div className="h-3.5 w-28 rounded bg-muted/50" />
        <div className="h-3 w-14 rounded bg-muted/50 shrink-0" />
      </div>
      <div className="h-7 w-[68px] shrink-0 rounded-md bg-muted/50" />
    </div>
  )
}

export interface RepositoryPickerProps {
  projectId: string | null | undefined
  /** URL for "Update permissions" and "Add account" (e.g. from useProject + region) */
  getGitHubAuthUrl: string
  /** Build the OAuth authorize URL for a specific provider/mode. Falls back to getGitHubAuthUrl (create-mode GitHub) when omitted. */
  getVcsAuthUrl?: (
    provider?: VcsProviderId,
    mode?: 'create' | 'update',
  ) => string
  installations: Models.Installation[]
  selectedInstallationId: string
  onInstallationChange: (installationId: string) => void
  /** For connect mode: which repo is currently selected */
  selectedRepositoryId?: string
  onRepositorySelect: (
    repo: Models.ProviderRepositoryFramework | Models.ProviderRepositoryRuntime,
  ) => void
  /** create = wizard (Connect button per row); connect = modal (select one then Confirm) */
  mode: 'create' | 'connect'
  /** Framework for sites, Runtime for functions. Default Framework. */
  detectionType?: 'framework' | 'runtime'
  /** Show "Or create a new site" link (e.g. in connect modal) */
  showCreateNewSiteLink?: boolean
  /** Optional refetch for refresh button */
  onRefetch?: () => void
  isFetching?: boolean
  className?: string
}

export function RepositoryPicker({
  projectId,
  getGitHubAuthUrl,
  getVcsAuthUrl,
  installations,
  selectedInstallationId,
  onInstallationChange,
  selectedRepositoryId,
  onRepositorySelect,
  mode,
  detectionType = 'framework',
  onRefetch,
  isFetching: isFetchingProp,
  className,
}: RepositoryPickerProps) {
  const t = useT()
  const vcsAuthUrl = (
    provider?: VcsProviderId,
    mode: 'create' | 'update' = 'create',
  ) => (getVcsAuthUrl ? getVcsAuthUrl(provider, mode) : getGitHubAuthUrl)
  const [repoSearch, setRepoSearch] = useState('')
  const [debouncedRepoSearch, setDebouncedRepoSearch] = useState('')
  const [repoPage, setRepoPage] = useState(1)
  const [pickerView, setPickerView] = useState<'list' | 'switch'>('list')

  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )

  const [activeProvider, setActiveProvider] = useState<VcsProviderId>(
    () =>
      getVcsProvider(
        selectedInstallation?.provider ?? installations[0]?.provider,
      ).id,
  )

  useEffect(() => {
    if (selectedInstallation) {
      setActiveProvider(getVcsProvider(selectedInstallation.provider).id)
    }
  }, [selectedInstallation])

  const filteredInstallations = installations.filter(
    (inst) => getVcsProvider(inst.provider).id === activeProvider,
  )

  const switchProvider = (provider: VcsProviderId) => {
    setActiveProvider(provider)
    setPickerView('list')
    const firstOfProvider = installations.find(
      (inst) => getVcsProvider(inst.provider).id === provider,
    )
    onInstallationChange(firstOfProvider?.$id ?? '')
    setRepoPage(1)
  }

  const vcsType =
    detectionType === 'runtime'
      ? VCSDetectionType.Runtime
      : VCSDetectionType.Framework

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedRepoSearch(repoSearch)
      setRepoPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [repoSearch])

  const {
    data: repositoriesData,
    isLoading: reposLoading,
    isFetching: reposFetching,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    vcsType,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
  )

  const repositories = useMemo(() => {
    const data = repositoriesData as
      | Models.ProviderRepositoryFrameworkList
      | Models.ProviderRepositoryRuntimeList
      | undefined
    if (vcsType === VCSDetectionType.Runtime) {
      return (
        (data as Models.ProviderRepositoryRuntimeList)
          ?.runtimeProviderRepositories ?? []
      )
    }
    return (
      (data as Models.ProviderRepositoryFrameworkList)
        ?.frameworkProviderRepositories ?? []
    )
  }, [repositoriesData, vcsType])
  const hasMoreRepos = repositories.length === REPO_PAGE_SIZE
  const isFetching = isFetchingProp ?? reposFetching
  const ActiveProviderIcon = getVcsProvider(activeProvider).Icon
  const hasMultipleProviders = Object.keys(VCS_PROVIDERS).length > 1

  return (
    <div className={cn('flex flex-col', className)}>
      <div className="space-y-4">
        {installations.length > 0 && (
          <div className="flex items-center gap-2">
            <Select
              value={selectedInstallationId}
              onValueChange={(value) => {
                onInstallationChange(value)
                setRepoPage(1)
              }}
              onOpenChange={(open) => {
                if (!open) setPickerView('list')
              }}
            >
              <SelectTrigger
                id="repo-picker-installation"
                className={cn(
                  'shrink-0 h-9 text-[13px]',
                  selectedInstallationId ? 'w-[180px]' : 'w-full',
                )}
              >
                <SelectValue placeholder={t('Select organization')}>
                  {selectedInstallation && (
                    <span className="flex items-center gap-2">
                      <VcsIcon
                        type={selectedInstallation.provider}
                        className="h-4 w-4 shrink-0"
                      />
                      <span className="truncate">
                        {selectedInstallation.organization}
                      </span>
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {pickerView === 'switch' ? (
                  <div>
                    <button
                      type="button"
                      onClick={() => setPickerView('list')}
                      className="flex w-full items-center gap-2 px-2 py-1.5 text-[12px] font-medium text-foreground hover:bg-accent/50 rounded-sm"
                    >
                      <ArrowLeft className="h-3.5 w-3.5" />
                      {t('Back')}
                    </button>
                    <div className="border-t border-border mt-1 pt-1">
                      {Object.values(VCS_PROVIDERS).map((p) => (
                        <button
                          type="button"
                          key={p.id}
                          onClick={() => switchProvider(p.id)}
                          className={cn(
                            'flex w-full items-center gap-2 px-2 py-1.5 text-[13px] hover:bg-accent/50 rounded-sm',
                            p.id === activeProvider &&
                              'text-foreground font-medium',
                          )}
                        >
                          <p.Icon className="h-4 w-4 shrink-0" />
                          {p.label}
                        </button>
                      ))}
                    </div>
                  </div>
                ) : (
                  <>
                    {filteredInstallations.map((inst) => (
                      <SelectItem key={inst.$id} value={inst.$id}>
                        <span className="flex items-center gap-2">
                          <VcsIcon
                            type={inst.provider}
                            className="h-4 w-4 shrink-0"
                          />
                          <span>{inst.organization}</span>
                        </span>
                      </SelectItem>
                    ))}
                    <div className="border-t border-border mt-1 pt-1">
                      <a
                        href={vcsAuthUrl(activeProvider)}
                        className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                      >
                        <ActiveProviderIcon className="h-3 w-3" />
                        {t(
                          `Add ${getVcsProvider(activeProvider).label} account`,
                        )}
                      </a>
                      {hasMultipleProviders && (
                        <button
                          type="button"
                          onClick={() => setPickerView('switch')}
                          className="flex w-full items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                        >
                          <ArrowLeftRight className="h-3 w-3" />
                          {t('Switch Git Provider')}
                        </button>
                      )}
                    </div>
                  </>
                )}
              </SelectContent>
            </Select>
            {selectedInstallationId && (
              <>
                <div className="relative flex-1 min-w-0">
                  <Search className="absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    id="repo-picker-search"
                    value={repoSearch}
                    onChange={(e) => setRepoSearch(e.target.value)}
                    placeholder={t('Search repositories...')}
                    className="h-9 ps-9 text-[13px]"
                  />
                </div>
                <RefreshButton
                  onClick={() => {
                    refetchRepos()
                    onRefetch?.()
                  }}
                  isRefreshing={isFetching}
                  tooltip={t('Refresh repositories')}
                />
              </>
            )}
          </div>
        )}

        {selectedInstallationId && (
          <>
            <div className="rounded-lg border border-border overflow-hidden">
              {reposLoading ? (
                <div className="divide-y divide-border">
                  {Array.from({ length: REPO_PAGE_SIZE }).map((_, i) => (
                    <RepositoryRowSkeleton
                      key={i}
                      provider={selectedInstallation?.provider}
                    />
                  ))}
                </div>
              ) : repositories.length > 0 ? (
                <div
                  className={cn(
                    'divide-y divide-border',
                    isFetching && 'opacity-60 pointer-events-none',
                  )}
                >
                  {repositories.map(
                    (
                      repo:
                        | Models.ProviderRepositoryFramework
                        | Models.ProviderRepositoryRuntime,
                    ) => {
                      const isSelected = selectedRepositoryId === repo.id
                      return (
                        <div
                          key={repo.id}
                          className={cn(
                            'flex w-full items-center gap-3 px-4 py-3.5 transition-colors',
                            mode === 'connect'
                              ? 'cursor-pointer hover:bg-accent/50'
                              : '',
                            mode === 'connect' && isSelected && 'bg-primary/5',
                          )}
                          onClick={() =>
                            mode === 'connect' && onRepositorySelect(repo)
                          }
                          onKeyDown={(e) => {
                            if (
                              mode === 'connect' &&
                              (e.key === 'Enter' || e.key === ' ')
                            ) {
                              e.preventDefault()
                              onRepositorySelect(repo)
                            }
                          }}
                          role={mode === 'connect' ? 'button' : undefined}
                          tabIndex={mode === 'connect' ? 0 : undefined}
                        >
                          <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
                            {'framework' in repo && repo.framework ? (
                              <FrameworkIcon
                                framework={repo.framework}
                                size="sm"
                              />
                            ) : 'runtime' in repo &&
                              (repo as Models.ProviderRepositoryRuntime)
                                .runtime ? (
                              <RuntimeIcon
                                runtime={
                                  (repo as Models.ProviderRepositoryRuntime)
                                    .runtime
                                }
                                className="h-3.5 w-3.5"
                              />
                            ) : (
                              <VcsIcon
                                type={selectedInstallation?.provider}
                                className="h-3.5 w-3.5"
                              />
                            )}
                          </div>
                          <div className="flex-1 min-w-0 flex items-center gap-2">
                            <span className="text-[13px] font-medium text-foreground truncate">
                              {repo.organization}/{repo.name}
                            </span>
                            {repo.private && (
                              <Lock className="h-3 w-3 shrink-0 text-muted-foreground/70" />
                            )}
                            {repo.pushedAt && (
                              <span className="text-[11px] text-muted-foreground shrink-0">
                                <DateTooltip date={repo.pushedAt} />
                              </span>
                            )}
                          </div>
                          {mode === 'create' && (
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-7 text-[12px] shrink-0"
                              onClick={(e) => {
                                e.stopPropagation()
                                onRepositorySelect(repo)
                              }}
                            >
                              {t('Connect')}
                            </Button>
                          )}
                          {mode === 'connect' &&
                            (isSelected ? (
                              <span className="text-[12px] font-medium text-primary shrink-0">
                                {t('Selected')}
                              </span>
                            ) : (
                              <Button
                                size="sm"
                                variant="outline"
                                className="h-7 text-[12px] shrink-0"
                                onClick={(e) => {
                                  e.stopPropagation()
                                  onRepositorySelect(repo)
                                }}
                              >
                                {t('Connect')}
                              </Button>
                            ))}
                        </div>
                      )
                    },
                  )}
                </div>
              ) : (
                <div className="py-8 px-4 text-center">
                  <EmptyState
                    title={t('No repositories found')}
                    description={
                      debouncedRepoSearch
                        ? t('Try a different search term or installation')
                        : t('No repositories available for this installation')
                    }
                    className="py-0"
                  />
                </div>
              )}
            </div>

            <div className="min-h-10 flex items-center justify-center">
              {repositories.length > 0 && (
                <SimplePagination
                  currentPage={repoPage}
                  hasMore={hasMoreRepos}
                  onPageChange={setRepoPage}
                  disabled={isFetching}
                />
              )}
            </div>

            {/* Missing repos / permissions note - compact one-liner */}
            <p className="text-[12px] text-muted-foreground">
              {t("Can't find a repository?")}{' '}
              <a
                href={vcsAuthUrl(
                  getVcsProvider(selectedInstallation?.provider).id,
                  'update',
                )}
                className="link-neutral"
              >
                {t(
                  `Update ${getVcsProvider(selectedInstallation?.provider).label} permissions`,
                )}
              </a>{' '}
              {t('to include more repos.')}
            </p>
          </>
        )}
      </div>
    </div>
  )
}
