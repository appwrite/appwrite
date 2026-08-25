/**
 * Create Function View Component
 *
 * Two-column wizard:
 * - Left: Connect Git repository
 * - Right: Clone template (quick start + highlighted templates from API)
 */

import { useState, useEffect, useMemo, useRef } from 'react'
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
  Lock,
  ArrowRight,
  ChevronRight,
  LayoutTemplate,
  GitBranch,
} from 'lucide-react'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { VCSDetectionType } from '@appwrite.io/console'
import { useQuery } from '@tanstack/react-query'
import {
  functionTemplatesPageQueryOptions,
  useRepositories,
  useProject,
  useNamespacesForInstallations,
} from '@/lib/react-query/hooks'
import {
  CREATE_FUNCTION_WIZARD_BROWSE_LIMIT,
  CREATE_FUNCTION_WIZARD_STARTER_LIMIT,
} from '@/lib/react-query/hooks/constants'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { VcsInstallationErrorState } from '@/components/global/shared/VcsInstallationError'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { useFunctionWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import {
  getKnownVcsProvider,
  buildVcsAuthUrl,
  VcsIcon,
  VCS_PROVIDERS,
  buildVcsOrgOptions,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '../../shared/ResourceCard'

const TEMPLATE_CARD_FOCUS_CLASSNAME =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

/** Explicit viewport grid — fullscreen wizard has no `#main-content` `@container`. */
const MORE_TEMPLATES_GRID_CLASSNAME =
  'grid min-w-0 grid-cols-2 content-start gap-3 [&>*]:min-w-0'

const RUNTIME_AVATAR_SIZE_CLASS = 'size-7'
const MAX_VISIBLE_RUNTIMES = 4

const runtimeTileClassName = cn(
  'grid shrink-0 place-items-center overflow-hidden rounded-md border border-border/80 bg-muted/50 text-muted-foreground',
  'transition-colors duration-150',
  RUNTIME_AVATAR_SIZE_CLASS,
)

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

function getBaseRuntimeNames(runtimes: Models.TemplateFunction['runtimes']) {
  const names: string[] = []
  const seen = new Set<string>()
  for (const runtime of runtimes ?? []) {
    const base = getRuntimeBase(runtime)
    if (!base || seen.has(base)) continue
    seen.add(base)
    names.push(base)
  }
  return names
}

function formatRuntimeLabel(runtime: string) {
  if (runtime === 'php') return 'PHP'
  return runtime.charAt(0).toUpperCase() + runtime.slice(1)
}

function formatUseCaseLabel(useCase: string) {
  const u = useCase.trim()
  if (u.toLowerCase() === 'ai') return 'AI'
  return u.charAt(0).toUpperCase() + u.slice(1)
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
  const baseRuntimes = getBaseRuntimeNames(template.runtimes)
  const visibleRuntimes = baseRuntimes.slice(0, MAX_VISIBLE_RUNTIMES)
  const overflowRuntimes = baseRuntimes.slice(MAX_VISIBLE_RUNTIMES)
  const overflowCount = overflowRuntimes.length
  const useCases = Array.isArray(template.useCases) ? template.useCases : []
  const primaryUseCase = useCases[0] ? formatUseCaseLabel(useCases[0]) : null
  const hasRuntimes = visibleRuntimes.length > 0

  return (
    <Link
      to="/projects/$projectId/functions/create/template/$templateId"
      params={{ projectId, templateId: template.id }}
      className={cn(
        'group flex min-w-0 flex-col rounded-xl border border-border bg-card/50 p-4 pb-0 text-start transition-all hover:border-border/80 hover:bg-card',
        TEMPLATE_CARD_FOCUS_CLASSNAME,
      )}
    >
      <div className="flex min-w-0 flex-1 flex-col gap-3">
        <div className="space-y-1">
          <h3 className="truncate text-[14px] font-semibold leading-snug text-foreground transition-colors group-hover:text-foreground">
            {template.name}
          </h3>
          {template.tagline ? (
            <p className="line-clamp-1 text-[12px] leading-relaxed text-muted-foreground">
              {template.tagline}
            </p>
          ) : null}
        </div>

        <div
          className={cn(
            RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
            'mt-auto flex items-center justify-between gap-2',
          )}
        >
          <div className="flex min-w-0 flex-1 flex-nowrap items-center gap-x-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            {hasRuntimes ? (
              <ul
                className="m-0 inline-flex list-none items-center gap-1 p-0"
                aria-label={baseRuntimes.map(formatRuntimeLabel).join(', ')}
              >
                {visibleRuntimes.map((runtime) => {
                  const label = formatRuntimeLabel(runtime)
                  return (
                    <li key={runtime} className="shrink-0">
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <div
                            className={cn(
                              runtimeTileClassName,
                              'hover:border-border hover:bg-muted hover:text-foreground',
                            )}
                            aria-label={label}
                          >
                            <RuntimeIcon
                              runtime={runtime}
                              size="sm"
                              className="!size-3.5 shrink-0"
                            />
                          </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-[12px]">
                          {label}
                        </TooltipContent>
                      </Tooltip>
                    </li>
                  )
                })}
                {overflowCount > 0 ? (
                  <li className="shrink-0">
                    <div
                      className={cn(
                        runtimeTileClassName,
                        'text-[10px] font-semibold tabular-nums tracking-tight text-muted-foreground',
                      )}
                      aria-label={overflowRuntimes
                        .map(formatRuntimeLabel)
                        .join(', ')}
                      title={overflowRuntimes
                        .map(formatRuntimeLabel)
                        .join(', ')}
                    >
                      +{overflowCount}
                    </div>
                  </li>
                ) : null}
              </ul>
            ) : null}
            {hasRuntimes && primaryUseCase ? (
              <span
                className="shrink-0 text-[10px] text-muted-foreground/40"
                aria-hidden
              >
                ·
              </span>
            ) : null}
            {primaryUseCase ? (
              <span className="min-w-0 truncate text-[12px] font-medium text-muted-foreground">
                {primaryUseCase}
              </span>
            ) : !hasRuntimes ? (
              <span className="text-[12px] text-muted-foreground">-</span>
            ) : null}
          </div>
          <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground opacity-60 transition-colors group-hover:opacity-100" />
        </div>
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
        <VcsIcon type={provider} className="h-3.5 w-3.5" />
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

  // Whenever the selected installation changes (including on initial load)
  // or its namespaces finish loading, make sure the namespace is a valid
  // row for that installation -- an installation with multiple namespaces
  // has no bare-installationId row, so without this the picker would show
  // blank until the user manually picks one.
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
    setSelectedInstallationId(option.installationId)
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

  // Runs only once, so a later intentional clear isn't silently re-defaulted.
  const hasAutoSelectedInstallation = useRef(false)
  useEffect(() => {
    if (
      installations.length > 0 &&
      !selectedInstallationId &&
      !hasAutoSelectedInstallation.current
    ) {
      hasAutoSelectedInstallation.current = true
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

  const getVcsAuthUrl = useMemo(() => {
    return (
      provider: VcsProviderId = 'github',
      mode: 'create' | 'update' = 'create',
    ) => {
      void mode // this redirect doesn't distinguish create/update, unlike Overview.tsx
      if (typeof window === 'undefined' || !projectId) return '#'
      const origin = window.location.origin
      let redirectUrl = `${origin}/projects/${projectId}/functions/create`
      if (selectedInstallationId) {
        redirectUrl += `?installation=${selectedInstallationId}`
      }
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectEndpoint, projectId, selectedInstallationId])
  const getGitHubAuthUrl = getVcsAuthUrl('github')

  // The picked installation lives in component state, seeded from
  // `?installation=`, so the provider has to return to a URL that still names
  // it -- the bare current URL would drop the selection on the way back.
  const reconnectReturnUrl = useMemo(() => {
    if (typeof window === 'undefined' || !projectId) return undefined
    const base = `${window.location.origin}/projects/${projectId}/functions/create`
    return selectedInstallationId
      ? `${base}?installation=${selectedInstallationId}`
      : base
  }, [projectId, selectedInstallationId])

  const { reconnectUrl } = useVcsInstallationReconnect(
    projectId,
    selectedInstallationId || null,
    reconnectReturnUrl,
  )

  const {
    data: repositoriesData,
    isLoading: reposLoading,
    isFetching: reposFetching,
    error: reposError,
    refetch: refetchRepos,
  } = useRepositories(
    projectId,
    selectedInstallationId || null,
    VCSDetectionType.Runtime,
    repoPage - 1,
    REPO_PAGE_SIZE,
    debouncedRepoSearch || undefined,
    selectedNamespace || undefined,
  )

  const repositories = useMemo(
    () => repositoriesData?.runtimeProviderRepositories || [],
    [repositoriesData],
  )
  const hasMoreRepos = repositories.length === REPO_PAGE_SIZE

  // An installation that can no longer authenticate returns nothing, which is
  // indistinguishable from an organization with no repositories. Read the error
  // so this step stops reporting a failure as an empty list.
  const installationErrorKind = getVcsInstallationErrorKind(reposError)

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
      .slice(0, 8)
    return { templateByLanguage: byLanguage, highlighted: rest }
  }, [quickStartTemplates, allTemplatesForHighlighted])

  const hasInstallations = installations.length > 0

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
      <div className="grid items-stretch gap-12 lg:grid-cols-5">
        <CreateWizardLeftColumn title={t('Connect Git repository')}>
          {!hasInstallations ? (
            <div className="rounded-lg border border-border bg-card/50 p-6 text-center">
              <div className="flex justify-center mb-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <GitBranch className="h-5 w-5 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-[13px] font-medium text-foreground mb-1">
                {t('Connect Git provider')}
              </h3>
              <p className="text-[11px] text-muted-foreground mb-3">
                {t(
                  'Connect a repository to deploy functions from your codebase',
                )}
              </p>
              <div className="flex flex-wrap items-center justify-center gap-2">
                <Button size="sm" variant="secondary" asChild>
                  <a href={getGitHubAuthUrl}>
                    <VcsIcon type="github" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect GitHub')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('gitlab')}>
                    <VcsIcon type="gitlab" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect GitLab')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('bitbucket')}>
                    <VcsIcon type="bitbucket" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect Bitbucket')}
                  </a>
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href={getVcsAuthUrl('origin')}>
                    <VcsIcon type="origin" className="me-1.5 h-3.5 w-3.5" />
                    {t('Connect Origin')}
                  </a>
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex flex-1 flex-col">
              <div className="mb-4 flex items-center gap-2">
                <Select
                  value={selectedOptionKey}
                  onValueChange={(key) => selectOption(key)}
                  onOpenChange={(open) => {
                    if (!open) setOrgFilter('')
                  }}
                >
                  <SelectTrigger className="w-[200px] h-9 text-[13px]">
                    <SelectValue placeholder={t('Select organization')}>
                      {selectedOption && (
                        <span className="flex items-center gap-2">
                          <VcsIcon
                            type={selectedOption.provider}
                            className="h-4 w-4 shrink-0"
                          />
                          <span className="truncate">
                            {selectedOption.label}
                          </span>
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
                          href={getVcsAuthUrl(p.id)}
                          className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                        >
                          <p.Icon className="h-3 w-3" />
                          {t(`Add ${p.label} account`)}
                        </a>
                      ))}
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
                              <VcsIcon
                                type={selectedInstallation?.provider}
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
                ) : installationErrorKind ? (
                  <div className="px-4 py-8">
                    <VcsInstallationErrorState
                      kind={installationErrorKind}
                      provider={selectedInstallation?.provider}
                      organization={selectedInstallation?.organization}
                      reconnectUrl={reconnectUrl}
                      onRetry={() => refetchRepos()}
                      isRetrying={reposFetching}
                      className="py-0"
                    />
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

              {!installationErrorKind && (
                <SimplePagination
                  currentPage={repoPage}
                  hasMore={hasMoreRepos}
                  onPageChange={setRepoPage}
                  disabled={reposFetching}
                />
              )}

              {(() => {
                // A broken installation is not a scope problem, so this hint
                // would send the user to widen permissions that are already
                // wide enough.
                if (installationErrorKind) return null
                const knownProvider = getKnownVcsProvider(
                  selectedInstallation?.provider,
                )
                if (!knownProvider) return null
                return (
                  <div className="mt-8 rounded-lg border border-border bg-muted/30 px-4 py-4">
                    <p className="text-[12px] text-muted-foreground">
                      {t('Missing a repository?')}{' '}
                      <a
                        href={getVcsAuthUrl(knownProvider.id, 'update')}
                        className="link-neutral inline-flex items-center gap-1 font-medium"
                      >
                        {t('Check your permissions')}
                        <ArrowRight className="h-3.5 w-3.5" />
                      </a>
                    </p>
                  </div>
                )
              })()}
            </div>
          )}
        </CreateWizardLeftColumn>

        <CreateWizardRightColumn title={t('Clone template')}>
          <div className="flex h-full min-h-0 flex-col gap-8">
            <div className="grid shrink-0 grid-cols-2 gap-3 sm:grid-cols-3">
              {LANGUAGE_RUNTIMES.map((lang) => (
                <LanguageCard
                  key={lang}
                  projectId={projectId!}
                  language={lang}
                  template={templateByLanguage[lang] ?? null}
                />
              ))}
            </div>

            <div className="flex min-h-0 flex-1 flex-col">
              <div className="mb-4 flex shrink-0 items-center justify-between gap-4">
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
                <div className={MORE_TEMPLATES_GRID_CLASSNAME}>
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
