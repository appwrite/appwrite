/**
 * Repositories View Component
 *
 * Shows repositories from connected VCS installations (GitHub, GitLab, Bitbucket).
 * Dense, productivity-focused UI for senior developers.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { SimplePagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  Search,
  GitBranch,
  Lock,
  ExternalLink,
  LayoutTemplate,
  RefreshCw,
  ChevronRight,
  Upload,
} from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import { useRepositories } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'

// Provider icons
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

function GitLabIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z" />
    </svg>
  )
}

function BitbucketIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M.778 1.213a.768.768 0 0 0-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 0 0 .77-.646l3.27-20.03a.768.768 0 0 0-.768-.891zM14.52 15.53H9.522L8.17 8.466h7.561z" />
    </svg>
  )
}

// Get provider icon component
function ProviderIcon({ provider, className }: { provider?: string; className?: string }) {
  const normalizedProvider = provider?.toLowerCase() || 'github'
  
  switch (normalizedProvider) {
    case 'gitlab':
      return <GitLabIcon className={className} />
    case 'bitbucket':
      return <BitbucketIcon className={className} />
    case 'github':
    default:
      return <GitHubIcon className={className} />
  }
}

// Repository skeleton row - matches exact layout of actual rows
function RepositorySkeleton({ index = 0, provider }: { index?: number; provider?: string }) {
  // Vary widths to look more natural
  const nameWidths = ['w-28', 'w-36', 'w-32', 'w-24', 'w-40']
  const metaWidths = ['w-20', 'w-24', 'w-16', 'w-28', 'w-22']
  
  return (
    <div className="flex w-full items-center gap-3 px-3 py-2.5 bg-card/50">
      {/* Icon - matches h-8 w-8 shrink-0, use provider icon as placeholder */}
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
        <ProviderIcon provider={provider} className="h-4 w-4" />
      </div>
      
      {/* Info - matches flex-1 min-w-0 */}
      <div className="flex-1 min-w-0">
        {/* First line - name */}
        <div className="flex items-center gap-1.5 h-[20px]">
          <Skeleton className={cn('h-[13px]', nameWidths[index % nameWidths.length])} />
        </div>
        {/* Second line - meta */}
        <div className="flex items-center gap-2 h-[16px] mt-0.5">
          <Skeleton className={cn('h-[11px]', metaWidths[index % metaWidths.length])} />
        </div>
      </div>
      
      {/* Chevron - matches h-4 w-4 shrink-0 */}
      <div className="h-4 w-4 shrink-0" />
    </div>
  )
}

const PAGE_SIZE = 5

export function RepositoriesView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { installations, updateFormData, setCurrentPath } = useWizard()

  const [selectedInstallationId, setSelectedInstallationId] = useState<string>('')
  const [searchValue, setSearchValue] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  // Set current path
  useEffect(() => {
    setCurrentPath('repository')
  }, [setCurrentPath])

  // Initialize selected installation
  useEffect(() => {
    if (installations.length > 0 && !selectedInstallationId) {
      setSelectedInstallationId(installations[0].$id)
    }
  }, [installations, selectedInstallationId])

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchValue)
      setCurrentPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchValue])

  // Fetch repositories (0-indexed for API)
  const { data: repositoriesData, isLoading, isFetching, refetch } = useRepositories(
    projectId,
    selectedInstallationId || null,
    VCSDetectionType.Framework,
    currentPage - 1,
    PAGE_SIZE,
    debouncedSearch || undefined,
  )

  const repositories = useMemo(() => {
    return repositoriesData?.frameworkProviderRepositories || []
  }, [repositoriesData])

  // Check if there might be more pages
  const hasMore = repositories.length === PAGE_SIZE

  const hasInstallations = installations.length > 0
  const selectedInstallation = installations.find(i => i.$id === selectedInstallationId)

  const handleSelectRepository = (repo: any) => {
    updateFormData({
      installationId: selectedInstallationId,
      providerRepositoryId: repo.id,
      repositoryOwner: repo.organization,
      repositoryName: repo.name,
      repositoryUrl: repo.url,
      siteName: repo.name,
    })

    navigate({
      to: '/projects/$projectId/sites/create-site/repositories/$repository',
      params: {
        projectId: projectId!,
        repository: encodeURIComponent(`${repo.organization}/${repo.name}`),
      },
    })
  }

  const sidebarContent = (
    <div className="space-y-3">
      {/* Quick links */}
      <div className="rounded-lg border border-border bg-card/50 p-3">
        <div className="space-y-1">
          <Link
            to="/projects/$projectId/sites/create-site/templates"
            params={{ projectId: projectId! }}
            className="flex items-center justify-between py-1.5 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <span className="flex items-center gap-2">
              <LayoutTemplate className="h-3.5 w-3.5" />
              Templates
            </span>
            <ChevronRight className="h-3 w-3" />
          </Link>
          <Link
            to="/projects/$projectId/sites/create-site/manual"
            params={{ projectId: projectId! }}
            className="flex items-center justify-between py-1.5 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <span className="flex items-center gap-2">
              <Upload className="h-3.5 w-3.5" />
              Manual upload
            </span>
            <ChevronRight className="h-3 w-3" />
          </Link>
        </div>
      </div>

      {/* Help text */}
      {hasInstallations && (
        <p className="text-[11px] text-muted-foreground leading-relaxed px-1">
          Missing a repo?{' '}
          <a
            href="https://github.com/apps/appwrite-io/installations/select_target"
            target="_blank"
            rel="noopener noreferrer"
            className="text-foreground hover:underline"
          >
            Configure access
          </a>
        </p>
      )}
    </div>
  )

  return (
    <WizardLayout
      title="Import repository"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      sidebar={sidebarContent}
    >
      {!hasInstallations ? (
        <div className="rounded-lg border border-border bg-card/50 p-8 text-center">
          <div className="flex justify-center gap-2 mb-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              <GitHubIcon className="h-5 w-5 text-muted-foreground" />
            </div>
          </div>
          <h3 className="text-[14px] font-medium text-foreground mb-1">
            Connect a Git provider
          </h3>
          <p className="text-[12px] text-muted-foreground mb-4 max-w-sm mx-auto">
            Install the GitHub App to import repositories and enable automatic deployments.
          </p>
          <Button size="sm" asChild>
            <a
              href="https://github.com/apps/appwrite-io/installations/new"
              target="_blank"
              rel="noopener noreferrer"
            >
              <GitHubIcon className="mr-1.5 h-4 w-4" />
              Connect GitHub
            </a>
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          {/* Controls row */}
          <div className="flex items-center gap-2">
            {/* Provider/Org selector */}
            <Select
              value={selectedInstallationId}
              onValueChange={(value) => {
                setSelectedInstallationId(value)
                setCurrentPage(1)
              }}
            >
              <SelectTrigger className="w-[220px] h-9 text-[13px]">
                <SelectValue placeholder="Select organization">
                  {selectedInstallation && (
                    <span className="flex items-center gap-2">
                      <ProviderIcon
                        provider={selectedInstallation.provider}
                        className="h-4 w-4 shrink-0"
                      />
                      <span className="truncate">{selectedInstallation.organization}</span>
                    </span>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {installations.map((installation) => (
                  <SelectItem key={installation.$id} value={installation.$id}>
                    <span className="flex items-center gap-2">
                      <ProviderIcon
                        provider={installation.provider}
                        className="h-4 w-4 shrink-0"
                      />
                      <span>{installation.organization}</span>
                    </span>
                  </SelectItem>
                ))}
                <div className="border-t border-border mt-1 pt-1">
                  <a
                    href="https://github.com/apps/appwrite-io/installations/new"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-2 px-2 py-1.5 text-[12px] text-muted-foreground hover:text-foreground"
                  >
                    <ExternalLink className="h-3.5 w-3.5" />
                    Add account
                  </a>
                </div>
              </SelectContent>
            </Select>

            {/* Search */}
            <div className="relative flex-1">
              <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
              <Input
                value={searchValue}
                onChange={(e) => setSearchValue(e.target.value)}
                placeholder="Search..."
                className="h-9 pl-9 text-[13px]"
              />
            </div>

            {/* Refresh */}
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              disabled={isFetching}
              className="h-9 w-9 p-0 shrink-0"
            >
              <RefreshCw className={cn('h-4 w-4', isFetching && 'animate-spin')} />
            </Button>
          </div>

          {/* Repository list */}
          <div className="rounded-lg border border-border overflow-hidden">
            {isLoading ? (
              // Skeleton loading state
              <div className="divide-y divide-border">
                {Array.from({ length: PAGE_SIZE }).map((_, i) => (
                  <RepositorySkeleton key={i} index={i} provider={selectedInstallation?.provider} />
                ))}
              </div>
            ) : repositories.length > 0 ? (
              <div className={cn(
                'divide-y divide-border',
                isFetching && 'opacity-60 pointer-events-none'
              )}>
                {repositories.map((repo: any) => (
                  <button
                    key={repo.id}
                    onClick={() => handleSelectRepository(repo)}
                    className="group flex w-full items-center gap-3 px-3 py-2.5 text-left bg-card/50 hover:bg-accent/50 transition-colors"
                  >
                    {/* Icon */}
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
                      {repo.framework ? (
                        <FrameworkIcon framework={repo.framework} size="sm" />
                      ) : (
                        <ProviderIcon provider={selectedInstallation?.provider} className="h-4 w-4" />
                      )}
                    </div>

                    {/* Info */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-1.5">
                        <span className="text-[13px] font-medium text-foreground truncate group-hover:text-primary transition-colors">
                          {repo.name}
                        </span>
                        {repo.private && (
                          <Lock className="h-3 w-3 shrink-0 text-muted-foreground/70" />
                        )}
                      </div>
                      <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                        {repo.framework && (
                          <>
                            <span className="capitalize">{repo.framework}</span>
                            <span>·</span>
                          </>
                        )}
                        {repo.pushedAt && (
                          <DateTooltip date={repo.pushedAt} />
                        )}
                      </div>
                    </div>

                    {/* Action hint */}
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50 group-hover:text-muted-foreground transition-colors" />
                  </button>
                ))}
              </div>
            ) : (
              <div className="py-12 bg-card/50">
                <EmptyState
                  icon={GitBranch}
                  title={searchValue ? 'No matches' : 'No repositories'}
                  description={
                    searchValue
                      ? `No results for "${searchValue}"`
                      : 'No repositories in this organization'
                  }
                  isEmpty={true}
                  hasFilters={!!searchValue}
                />
              </div>
            )}
          </div>

          {/* Pagination */}
          {(repositories.length > 0 || currentPage > 1) && (
            <SimplePagination
              currentPage={currentPage}
              hasMore={hasMore}
              onPageChange={setCurrentPage}
              disabled={isFetching}
            />
          )}
        </div>
      )}
    </WizardLayout>
  )
}
