/**
 * Shared repository picker for connecting to a Git repository.
 * Used in site creation wizard, site settings, and function settings
 * "Connect repository" modal.
 *
 * Features: installation selector with "Add account", search, repo list with
 * framework/runtime icon, pagination, "Can't find a repository?" note, optional
 * "Create a new site" link.
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
import { Search, Lock, Plus } from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import { useRepositories } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { RefreshButton } from '@/components/global/shared/RefreshButton'

const REPO_PAGE_SIZE = 5

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

function ProviderIcon({
  provider,
  className,
}: {
  provider?: string
  className?: string
}) {
  const normalizedProvider = provider?.toLowerCase() || 'github'
  if (normalizedProvider === 'github') {
    return <GitHubIcon className={className} />
  }
  return <GitHubIcon className={className} />
}

function RepositoryRowSkeleton({ provider }: { provider?: string }) {
  return (
    <div className="flex w-full items-center gap-3 px-4 py-3.5">
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
        <ProviderIcon provider={provider} className="h-3.5 w-3.5" />
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
  /** URL for "Update GitHub permissions" and "Add account" (e.g. from useProject + region) */
  getGitHubAuthUrl: string
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
  const [repoSearch, setRepoSearch] = useState('')
  const [debouncedRepoSearch, setDebouncedRepoSearch] = useState('')
  const [repoPage, setRepoPage] = useState(1)

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
  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )
  const isFetching = isFetchingProp ?? reposFetching

  return (
    <div className={cn('flex flex-col', className)}>
      <div className="space-y-4">
        {selectedInstallationId && (
          <div className="flex items-center gap-2">
            <Select
              value={selectedInstallationId}
              onValueChange={(value) => {
                onInstallationChange(value)
                setRepoPage(1)
              }}
            >
              <SelectTrigger
                id="repo-picker-installation"
                className="w-[180px] shrink-0 h-9 text-[13px]"
              >
                <SelectValue placeholder={t('Select organization')}>
                  {selectedInstallation && (
                    <span className="flex items-center gap-2">
                      <ProviderIcon
                        provider={selectedInstallation.provider}
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
                {installations.map((inst) => (
                  <SelectItem key={inst.$id} value={inst.$id}>
                    <span className="flex items-center gap-2">
                      <ProviderIcon
                        provider={inst.provider}
                        className="h-4 w-4 shrink-0"
                      />
                      <span>{inst.organization}</span>
                    </span>
                  </SelectItem>
                ))}
                <div className="border-t border-border mt-1 pt-1">
                  <a
                    href={getGitHubAuthUrl}
                    className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                  >
                    <Plus className="h-3 w-3" />
                    {t('Add account')}
                  </a>
                </div>
              </SelectContent>
            </Select>
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
          </div>
        )}

        {!selectedInstallationId && installations.length > 0 && (
          <Select
            value={selectedInstallationId}
            onValueChange={(value) => {
              onInstallationChange(value)
              setRepoPage(1)
            }}
          >
            <SelectTrigger
              id="repo-picker-installation"
              className="w-full h-9 text-[13px]"
            >
              <SelectValue placeholder={t('Select organization')} />
            </SelectTrigger>
            <SelectContent>
              {installations.map((inst) => (
                <SelectItem key={inst.$id} value={inst.$id}>
                  <span className="flex items-center gap-2">
                    <ProviderIcon
                      provider={inst.provider}
                      className="h-4 w-4 shrink-0"
                    />
                    <span>{inst.organization}</span>
                  </span>
                </SelectItem>
              ))}
              <div className="border-t border-border mt-1 pt-1">
                <a
                  href={getGitHubAuthUrl}
                  className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                >
                  <Plus className="h-3 w-3" />
                  {t('Add account')}
                </a>
              </div>
            </SelectContent>
          </Select>
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
                              <ProviderIcon
                                provider={selectedInstallation?.provider}
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
                href={getGitHubAuthUrl}
                className="link-neutral"
              >
                {t('Update GitHub permissions')}
              </a>{' '}
              {t('to include more repos.')}
            </p>
          </>
        )}
      </div>
    </div>
  )
}
