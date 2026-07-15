/**
 * Create Function View Component
 *
 * Two-column wizard:
 * - Left: Connect Git repository
 * - Right: Clone template (quick start + highlighted templates from API)
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
import {
  CreateWizardLeftColumn,
  CreateWizardRightColumn,
} from '@/components/global/shared/CreateWizardColumns'
import { SimplePagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  Search,
  Plus,
  Lock,
  ArrowRight,
  ChevronRight,
  LayoutTemplate,
} from 'lucide-react'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { VCSDetectionType } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import {
  functionTemplatesPageQueryOptions,
  useRepositories,
  useProject,
} from '@/lib/react-query/hooks'
import {
  CREATE_FUNCTION_WIZARD_BROWSE_LIMIT,
  CREATE_FUNCTION_WIZARD_STARTER_LIMIT,
} from '@/lib/react-query/hooks/constants'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { RESOURCE_CARD_GRID_2_COL_CLASSNAME } from '../../shared/ResourceCard'

const TEMPLATE_CARD_FOCUS_CLASSNAME =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useFunctionWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

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

function ProviderIcon({
  provider,
  className,
}: {
  provider?: string
  className?: string
}) {
  const normalizedProvider = provider?.toLowerCase() || 'github'
  switch (normalizedProvider) {
    case 'gitlab':
      return <GitLabIcon className={className} />
    case 'bitbucket':
      return <BitbucketIcon className={className} />
    default:
      return <GitHubIcon className={className} />
  }
}

const REPO_PAGE_SIZE = 7

const QUICK_START_USE_CASE = 'starter'

/** Language keys for the clone-by-runtime cards; order matches display. */
const LANGUAGE_RUNTIMES = [
  'node',
  'python',
  'bun',
  'php',
  'dart',
  'go',
  'rust',
  'deno',
  'ruby',
] as const

function getRuntimeBase(r: { name?: string; key?: string } | string): string {
  const raw =
    typeof r === 'string' ? r : (r?.name ?? (r as { key?: string })?.key ?? '')
  return raw.toLowerCase().split('-')[0]
}

function LanguageCard({
  projectId,
  language,
  template,
}: {
  projectId: string
  language: string
  template: Models.TemplateFunction | null
}) {
  const label =
    language === 'php'
      ? 'PHP'
      : language.charAt(0).toUpperCase() + language.slice(1)
  const disabled = !template
  const content = (
    <div className="flex items-center justify-between gap-2">
      <div className="flex items-center gap-3 min-w-0">
        <RuntimeIcon runtime={language} size="md" className="shrink-0" />
        <span className="text-[14px] font-semibold text-foreground">
          {label}
        </span>
      </div>
      {!disabled && (
        <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-foreground" />
      )}
    </div>
  )
  if (disabled) {
    return (
      <div
        className={cn(
          'rounded-xl border border-border bg-card/50 p-4 text-start opacity-60 cursor-not-allowed',
        )}
      >
        {content}
      </div>
    )
  }
  return (
    <Link
      to="/projects/$projectId/functions/create/template/$templateId"
      params={{ projectId, templateId: template!.id }}
      search={{ runtime: language }}
      className={cn(
        'group block min-w-0 rounded-xl border border-border bg-card/50 p-4 text-start transition-all hover:border-border/80 hover:bg-card',
        TEMPLATE_CARD_FOCUS_CLASSNAME,
      )}
    >
      {content}
    </Link>
  )
}

function TemplateCard({
  projectId,
  template,
}: {
  projectId: string
  template: Models.TemplateFunction
}) {
  return (
    <Link
      to="/projects/$projectId/functions/create/template/$templateId"
      params={{ projectId, templateId: template.id }}
      className={cn(
        'group block min-w-0 rounded-xl border border-border bg-card/50 p-4 text-start transition-all hover:border-border/80 hover:bg-card',
        TEMPLATE_CARD_FOCUS_CLASSNAME,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-[14px] font-semibold text-foreground leading-tight group-hover:text-foreground transition-colors">
            {template.name}
          </h3>
          {template.tagline && (
            <p className="text-[12px] text-muted-foreground mt-1 line-clamp-2">
              {template.tagline}
            </p>
          )}
        </div>
        <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-foreground" />
      </div>
    </Link>
  )
}

function RepositorySkeleton({
  index = 0,
  provider,
}: {
  index?: number
  provider?: string
}) {
  const nameWidths = ['w-28', 'w-36', 'w-32', 'w-24', 'w-40']
  const dateWidths = ['w-14', 'w-16', 'w-12', 'w-18', 'w-14']
  return (
    <div className="flex w-full items-center gap-3 px-4 py-3.5">
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
        <ProviderIcon provider={provider} className="h-3.5 w-3.5" />
      </div>
      <div className="flex-1 min-w-0 flex items-center gap-2">
        <Skeleton
          className={cn('h-3.5', nameWidths[index % nameWidths.length])}
        />
        <Skeleton
          className={cn('h-3 shrink-0', dateWidths[index % dateWidths.length])}
        />
      </div>
      <Skeleton className="h-7 w-[68px] shrink-0 rounded-md" />
    </div>
  )
}

export function CreateFunctionView() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { installations, updateFormData } = useFunctionWizard()
  const { project } = useProject(projectId)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const [selectedInstallationId, setSelectedInstallationId] = useState('')
  const [repoSearch, setRepoSearch] = useState('')
  const [debouncedRepoSearch, setDebouncedRepoSearch] = useState('')
  const [repoPage, setRepoPage] = useState(1)

  useEffect(() => {
    if (installations.length > 0 && !selectedInstallationId) {
      const urlParams =
        typeof window !== 'undefined'
          ? new URLSearchParams(window.location.search)
          : null
      const fromUrl =
        urlParams?.get('installation') &&
        installations.some((i) => i.$id === urlParams.get('installation'))
      if (fromUrl && urlParams) {
        setSelectedInstallationId(urlParams.get('installation')!)
        return
      }
      setSelectedInstallationId(installations[0].$id)
    }
  }, [installations, selectedInstallationId])

  useEffect(() => {
    const t = setTimeout(() => {
      setDebouncedRepoSearch(repoSearch)
      setRepoPage(1)
    }, 300)
    return () => clearTimeout(t)
  }, [repoSearch])

  const getGitHubAuthUrl = useMemo(() => {
    if (typeof window === 'undefined' || !projectId) return '#'
    const origin = window.location.origin
    let redirectUrl = `${origin}/projects/${projectId}/functions/create`
    if (selectedInstallationId) {
      redirectUrl += `?installation=${selectedInstallationId}`
    }
    const successUrl = encodeURIComponent(redirectUrl)
    const failureUrl = encodeURIComponent(redirectUrl)
    return `${projectEndpoint}/vcs/github/authorize?project=${projectId}&success=${successUrl}&failure=${failureUrl}&mode=admin`
  }, [projectEndpoint, projectId, selectedInstallationId])

  const {
    data: repositoriesData,
    isLoading: reposLoading,
    isFetching: reposFetching,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    VCSDetectionType.Runtime,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
  )

  const repositories = useMemo(
    () => repositoriesData?.runtimeProviderRepositories || [],
    [repositoriesData],
  )
  const hasMoreRepos = repositories.length === REPO_PAGE_SIZE

  const { data: starterPage } = useQuery({
    ...functionTemplatesPageQueryOptions(
      projectId,
      0,
      CREATE_FUNCTION_WIZARD_STARTER_LIMIT,
      [],
      [QUICK_START_USE_CASE],
    ),
  })

  const { data: browsePage } = useQuery({
    ...functionTemplatesPageQueryOptions(
      projectId,
      0,
      CREATE_FUNCTION_WIZARD_BROWSE_LIMIT,
      [],
      [],
    ),
  })

  const starterTemplates = starterPage?.templates ?? []
  const browseTemplates = browsePage?.templates ?? []

  const quickStartTemplates = useMemo(
    () => starterTemplates.slice(0, 6),
    [starterTemplates],
  )

  const allTemplatesForHighlighted = useMemo(
    () => browseTemplates.slice(0, 20),
    [browseTemplates],
  )

  const { templateByLanguage, highlighted } = useMemo(() => {
    const combined = [...quickStartTemplates]
    const seen = new Set(quickStartTemplates.map((t) => t.id))
    for (const t of allTemplatesForHighlighted) {
      if (!seen.has(t.id)) {
        seen.add(t.id)
        combined.push(t)
      }
    }
    const byLanguage: Partial<
      Record<(typeof LANGUAGE_RUNTIMES)[number], Models.TemplateFunction>
    > = {}
    for (const lang of LANGUAGE_RUNTIMES) {
      if (byLanguage[lang]) continue
      const supportsLang = (t: Models.TemplateFunction) =>
        (t.runtimes ?? []).some((r) => getRuntimeBase(r) === lang)
      // Prefer universal `starter` template (same as legacy console quick start).
      const starter =
        combined.find((t) => t.id === 'starter' && supportsLang(t)) ?? null
      const template =
        starter ?? combined.find((t) => supportsLang(t)) ?? undefined
      if (template) byLanguage[lang] = template
    }
    const starterIds = new Set(quickStartTemplates.map((t) => t.id))
    const rest = allTemplatesForHighlighted
      .filter((t) => !starterIds.has(t.id))
      .slice(0, 6)
    return { templateByLanguage: byLanguage, highlighted: rest }
  }, [quickStartTemplates, allTemplatesForHighlighted])

  const hasInstallations = installations.length > 0
  const selectedInstallation = installations.find(
    (i) => i.$id === selectedInstallationId,
  )

  const handleSelectRepository = (repo: {
    id: string
    organization?: string
    name?: string
    url?: string
    pushedAt?: string
  }) => {
    updateFormData({
      installationId: selectedInstallationId,
      providerRepositoryId: repo.id,
      repositoryOwner: repo.organization,
      repositoryName: repo.name,
      repositoryUrl: repo.url,
      functionName: repo.name || '',
    })
    const repositoryParam = encodeURIComponent(
      `${repo.organization || ''}/${repo.name || ''}`,
    )
    navigate({
      to: '/projects/$projectId/functions/create/repository/$repository',
      params: { projectId: projectId!, repository: repositoryParam },
      search: {
        installationId: selectedInstallationId,
        providerRepositoryId: repo.id,
      },
    })
  }

  return (
    <WizardLayout
      title={t('Create function')}
      fallbackPath={`/projects/${projectId}/functions`}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-[1400px]"
    >
      <div className="grid gap-12 lg:grid-cols-5">
        <CreateWizardLeftColumn title={t('Connect Git repository')}>
          {!hasInstallations ? (
            <div className="rounded-lg border border-border bg-card/50 p-6 text-center">
              <div className="flex justify-center mb-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <GitHubIcon className="h-5 w-5 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-[13px] font-medium text-foreground mb-1">
                {t('Connect Git provider')}
              </h3>
              <p className="text-[11px] text-muted-foreground mb-3">
                {t('Connect a repository to deploy functions from your codebase')}
              </p>
              <Button size="sm" asChild>
                <a href={getGitHubAuthUrl}>
                  <GitHubIcon className="me-1.5 h-3.5 w-3.5" />
                  {t('Connect GitHub')}
                </a>
              </Button>
            </div>
          ) : (
            <div className="flex flex-1 flex-col">
              <div className="mb-4 flex items-center gap-2">
                <Select
                  value={selectedInstallationId}
                  onValueChange={(v) => {
                    setSelectedInstallationId(v)
                    setRepoPage(1)
                  }}
                >
                  <SelectTrigger className="w-[180px] h-9 text-[13px]">
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

                <div className="relative flex-1">
                  <Search className="absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    value={repoSearch}
                    onChange={(e) => setRepoSearch(e.target.value)}
                    placeholder={t('Search repositories...')}
                    className="h-9 ps-9 text-[13px]"
                  />
                </div>

                <RefreshButton
                  onClick={() => refetchRepos()}
                  isRefreshing={reposFetching}
                  tooltip={t('Refresh repositories')}
                />
              </div>

              <div className="rounded-lg border border-border overflow-hidden mb-4">
                {reposLoading ? (
                  <div className="divide-y divide-border">
                    {Array.from({ length: REPO_PAGE_SIZE }).map((_, i) => (
                      <RepositorySkeleton
                        key={i}
                        index={i}
                        provider={selectedInstallation?.provider}
                      />
                    ))}
                  </div>
                ) : repositories.length > 0 ? (
                  <div
                    className={cn(
                      'divide-y divide-border',
                      reposFetching && 'opacity-60 pointer-events-none',
                    )}
                  >
                    {repositories.map(
                      (repo: {
                        id: string
                        name?: string
                        organization?: string
                        url?: string
                        pushedAt?: string
                        private?: boolean
                        runtime?: string
                      }) => (
                        <div
                          key={repo.id}
                          className="flex w-full items-center gap-3 px-4 py-3.5 hover:bg-accent/50 transition-colors"
                        >
                          <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted/50 text-muted-foreground">
                            {repo.runtime ? (
                              <RuntimeIcon runtime={repo.runtime} size="sm" />
                            ) : (
                              <ProviderIcon
                                provider={selectedInstallation?.provider}
                                className="h-3.5 w-3.5"
                              />
                            )}
                          </div>
                          <div className="flex-1 min-w-0 flex items-center gap-2">
                            <span className="text-[13px] font-medium text-foreground truncate">
                              {repo.name}
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
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 text-[12px] shrink-0"
                            onClick={() => handleSelectRepository(repo)}
                          >
                            {t('Connect')}
                          </Button>
                        </div>
                      ),
                    )}
                  </div>
                ) : (
                  <div className="py-8 text-center">
                    <p className="text-[12px] text-muted-foreground">
                      {repoSearch
                        ? t('No repositories found')
                        : t('No repositories available')}
                    </p>
                  </div>
                )}
              </div>

              <SimplePagination
                currentPage={repoPage}
                hasMore={hasMoreRepos}
                onPageChange={setRepoPage}
                disabled={reposFetching}
              />

              <div className="mt-8 rounded-lg border border-border bg-muted/30 px-4 py-4">
                <p className="text-[12px] text-muted-foreground">
                  {t('Missing a repository?')}{' '}
                  <a
                    href={getGitHubAuthUrl}
                    className="link-neutral inline-flex items-center gap-1 font-medium"
                  >
                    {t('Check your permissions')}
                    <ArrowRight className="h-3.5 w-3.5" />
                  </a>
                </p>
              </div>
            </div>
          )}
        </CreateWizardLeftColumn>

        <CreateWizardRightColumn title={t('Clone template')}>
          <div className="flex flex-col gap-8">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {LANGUAGE_RUNTIMES.map((lang) => (
                <LanguageCard
                  key={lang}
                  projectId={projectId!}
                  language={lang}
                  template={templateByLanguage[lang] ?? null}
                />
              ))}
            </div>

            <div>
              <div className="mb-4 flex items-center justify-between gap-4">
                <h3 className="text-[13px] font-semibold leading-none text-foreground">
                  {t('More templates')}
                </h3>
                <Link
                  to="/projects/$projectId/functions/templates"
                  params={{ projectId: projectId! }}
                  className="inline-flex shrink-0 items-center gap-1 text-[13px] font-medium leading-none link-neutral"
                >
                  {t('View all templates')}
                  <ChevronRight className="h-3.5 w-3.5" />
                </Link>
              </div>
              {highlighted.length > 0 ? (
                <div className={cn(RESOURCE_CARD_GRID_2_COL_CLASSNAME, 'gap-3')}>
                  {highlighted.map((template) => (
                    <TemplateCard
                      key={template.id}
                      projectId={projectId!}
                      template={template}
                    />
                  ))}
                </div>
              ) : (
                <EmptyState
                  icon={LayoutTemplate}
                  title={t('No additional templates')}
                  description={t(
                    'More highlighted templates will show here when the catalog includes them.',
                  )}
                  isEmpty
                  hasFilters={false}
                  variant="card"
                />
              )}
            </div>
          </div>
        </CreateWizardRightColumn>
      </div>

      <div className="mt-6 pt-6 border-t border-border">
        <p className="text-[12px] text-muted-foreground">
          {t('You can also')}{' '}
          <Link
            to="/projects/$projectId/functions/create/manual"
            params={{ projectId: projectId! }}
            className="link-neutral"
          >
            {t('create a function manually')}
          </Link>
          ,{' '}
          <Link
            to="/projects/$projectId/functions/create/deploy"
            params={{ projectId: projectId! }}
            className="link-neutral"
          >
            {t('deploy from URL')}
          </Link>
          , {t('or using the CLI.')}{' '}
          <DocsRouteLink className="link-neutral" href="/docs/functions">
            {t('Learn more')}
          </DocsRouteLink>
        </p>
      </div>
    </WizardLayout>
  )
}
