/**
 * Shared repository picker for connecting to a Git repository.
 * Used in site creation wizard, site settings, and function settings
 * "Connect repository" modal.
 *
 * Features: a single combined org picker (GitHub orgs and GitLab
 * namespaces -- personal account and every group -- flattened into one
 * list, no separate provider/account/group steps, per product decision:
 * GitLab has no per-installation org scoping like GitHub, but the picker
 * should look the same regardless), search, repo list with
 * framework/runtime icon, pagination, "Can't find a repository?" note,
 * optional "Create a new site" link.
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
import { Search, Lock } from 'lucide-react'
import {
  getKnownVcsProvider,
  VCS_PROVIDERS,
  VcsIcon,
  buildVcsOrgOptions,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepositories,
  useNamespacesForInstallations,
} from '@/lib/react-query/hooks'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { VcsInstallationErrorState } from '@/components/global/shared/VcsInstallationError'
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

  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )

  // Personal namespace vs. group selected within a GitLab installation.
  const [selectedNamespace, setSelectedNamespace] = useState('')

  const { namespacesByInstallation } = useNamespacesForInstallations(
    projectId,
    installations,
  )
  const orgOptions = useMemo(
    () => buildVcsOrgOptions(installations, namespacesByInstallation),
    [installations, namespacesByInstallation],
  )
  const selectedOptionKey = selectedNamespace
    ? `${selectedInstallationId}:${selectedNamespace}`
    : selectedInstallationId
  const selectedOption = orgOptions.find((o) => o.key === selectedOptionKey)

  // Whenever the selected installation changes (including on initial
  // load) or its namespaces finish loading, make sure the namespace is a
  // valid row for that installation -- an installation with multiple
  // namespaces has no bare-installationId row, so without this the picker
  // would show blank until the user manually picks one.
  useEffect(() => {
    if (!selectedInstallationId) {
      if (selectedNamespace) setSelectedNamespace('')
      return
    }
    const isValid = orgOptions.some((o) => o.key === selectedOptionKey)
    if (!isValid) {
      const firstForInstallation = orgOptions.find(
        (o) => o.installationId === selectedInstallationId,
      )
      setSelectedNamespace(firstForInstallation?.providerNamespace ?? '')
    }
  }, [selectedInstallationId, orgOptions, selectedOptionKey, selectedNamespace])

  const selectOption = (key: string) => {
    const option = orgOptions.find((o) => o.key === key)
    if (!option) return
    onInstallationChange(option.installationId)
    setSelectedNamespace(option.providerNamespace ?? '')
    setRepoPage(1)
  }

  // Filter the combined org list client-side -- it's already fully loaded
  // (every installation's namespaces are fetched up front), so there's no
  // need for a server round-trip just to narrow a list this size.
  const [orgFilter, setOrgFilter] = useState('')
  const filteredOrgOptions = useMemo(
    () =>
      orgFilter.trim()
        ? orgOptions.filter((o) =>
            o.label.toLowerCase().includes(orgFilter.trim().toLowerCase()),
          )
        : orgOptions,
    [orgOptions, orgFilter],
  )

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
    error: reposError,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    vcsType,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
    selectedNamespace || undefined,
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

  // An installation that cannot authenticate returns no repositories, which is
  // indistinguishable from an organization that genuinely has none. Read the
  // error so the empty state does not claim a failure was a successful result.
  const installationErrorKind = getVcsInstallationErrorKind(reposError)

  return (
    <div className={cn('flex flex-col', className)}>
      <div className="space-y-4">
        {installations.length > 0 && (
          <div className="flex items-center gap-2">
            <Select
              value={selectedOptionKey}
              onValueChange={(key) => selectOption(key)}
              onOpenChange={(open) => {
                if (!open) setOrgFilter('')
              }}
            >
              <SelectTrigger
                id="repo-picker-installation"
                className={cn(
                  'shrink-0 h-9 text-[13px]',
                  selectedInstallationId ? 'w-[200px]' : 'w-full',
                )}
              >
                <SelectValue placeholder={t('Select organization')}>
                  {selectedOption && (
                    <span className="flex items-center gap-2">
                      <VcsIcon
                        type={selectedOption.provider}
                        className="h-4 w-4 shrink-0"
                      />
                      <span className="truncate">{selectedOption.label}</span>
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                <div
                  className="px-1 pb-1 mb-1 border-b border-border"
                  onKeyDown={(e) => e.stopPropagation()}
                >
                  <div className="relative">
                    <Search className="absolute start-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input
                      value={orgFilter}
                      onChange={(e) => setOrgFilter(e.target.value)}
                      placeholder={t('Filter organizations...')}
                      className="h-8 ps-7 text-[12px]"
                    />
                  </div>
                </div>
                {filteredOrgOptions.length > 0 ? (
                  filteredOrgOptions.map((option) => (
                    <SelectItem key={option.key} value={option.key}>
                      <span className="flex items-center gap-2">
                        <VcsIcon
                          type={option.provider}
                          className="h-4 w-4 shrink-0"
                        />
                        <span>{option.label}</span>
                      </span>
                    </SelectItem>
                  ))
                ) : (
                  <p className="px-2 py-1.5 text-[12px] text-muted-foreground">
                    {t('No matches')}
                  </p>
                )}
                <div className="border-t border-border mt-1 pt-1">
                  {Object.values(VCS_PROVIDERS).map((p) => (
                    <a
                      key={p.id}
                      href={vcsAuthUrl(p.id)}
                      className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                    >
                      <p.Icon className="h-3 w-3" />
                      {t(`Add ${p.label} account`)}
                    </a>
                  ))}
                </div>
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
              ) : installationErrorKind ? (
                <div className="py-8 px-4">
                  <VcsInstallationErrorState
                    kind={installationErrorKind}
                    provider={selectedInstallation?.provider}
                    organization={selectedInstallation?.organization}
                    reconnectUrl={
                      getKnownVcsProvider(selectedInstallation?.provider)
                        ? vcsAuthUrl(
                            getKnownVcsProvider(selectedInstallation?.provider)!
                              .id,
                            'update',
                          )
                        : undefined
                    }
                    onRetry={() => {
                      refetchRepos()
                      onRefetch?.()
                    }}
                    isRetrying={isFetching}
                    className="py-0"
                  />
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
            {(() => {
              // A broken installation is not a scope problem; this hint would
              // send the user to widen permissions that already cover the repos.
              if (installationErrorKind) return null
              const knownProvider = getKnownVcsProvider(
                selectedInstallation?.provider,
              )
              if (!knownProvider) return null
              return (
                <p className="text-[12px] text-muted-foreground">
                  {t("Can't find a repository?")}{' '}
                  <a
                    href={vcsAuthUrl(knownProvider.id, 'update')}
                    className="link-neutral"
                  >
                    {t(`Update ${knownProvider.label} permissions`)}
                  </a>{' '}
                  {t('to include more repos.')}
                </p>
              )
            })()}
          </>
        )}
      </div>
    </div>
  )
}
