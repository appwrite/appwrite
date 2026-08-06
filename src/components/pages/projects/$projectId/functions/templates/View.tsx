import {
  useMemo,
  useState,
  useEffect,
  useRef,
  useCallback,
} from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import {
  AlertCircle,
  Braces,
  ChevronDown,
  ChevronRight,
  ExternalLink,
  LayoutTemplate,
  Loader2,
  Search,
} from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  RESOURCE_CARD_GRID_WIDE_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '../../shared/ResourceCard'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn, scrollConsoleMainToTop } from '@/lib/utils'
import {
  allFunctionTemplatesQueryOptions,
  functionTemplatesPageQueryOptions,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreateFunction } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useQuery } from '@tanstack/react-query'
import {
  fetchProjectFunctions,
  FUNCTIONS_DEFAULT_SORT_BY,
  FUNCTIONS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/functions'
import {
  GRID_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
import type { Models } from '@appwrite.io/console'
import { Route } from '@/routes/_public/projects.$projectId.functions.templates'
import { useT } from '@/lib/i18n/translate'

/** Legacy 1-based page from URL (when `offset` is not used). */
function parseTemplatesPage(value: unknown): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 1) return 1
  return Math.min(Math.floor(n), 1_000_000)
}

function parseTemplatesOffset(value: unknown): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 0) return 0
  return Math.min(Math.floor(n), 1_000_000_000)
}

function parseTemplatesLimit(value: unknown, fallback: number): number {
  const n = Number(value)
  if (!Number.isFinite(n) || n < 1) return fallback
  return Math.min(Math.max(1, Math.floor(n)), 100)
}

function parseCsvParam(s: string | undefined): string[] {
  if (!s?.trim()) return []
  return s
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean)
}

function joinCsvParam(arr: string[]): string | undefined {
  return arr.length > 0 ? arr.join(',') : undefined
}

type RuntimeRow = NonNullable<Models.TemplateFunction['runtimes']>[number]

function getBaseRuntimes(runtimes: Models.TemplateFunction['runtimes']) {
  const list = runtimes ?? []
  const base = new Map<string, RuntimeRow>()
  for (const runtime of list) {
    const key = runtime.name.split('-')[0] ?? runtime.name
    if (!base.has(key)) {
      base.set(key, { ...runtime, name: key })
    }
  }
  return [...base.values()]
}

function formatUseCaseLabel(useCase: string) {
  const u = useCase.trim()
  if (u.toLowerCase() === 'ai') return 'AI'
  return u.charAt(0).toUpperCase() + u.slice(1)
}

/** Build filter labels from template rows (same source as catalog pages - no extra listTemplates batch). */
function collectTemplateFacetLabels(templates: Models.TemplateFunction[] | undefined) {
  const useCaseSet = new Set<string>()
  const runtimeSet = new Set<string>()
  for (const t of templates ?? []) {
    for (const u of t.useCases ?? []) {
      useCaseSet.add(u)
    }
    for (const r of t.runtimes ?? []) {
      if (r.name) runtimeSet.add(r.name)
    }
  }
  return {
    useCases: [...useCaseSet].sort((a, b) => a.localeCompare(b)),
    runtimes: [...runtimeSet].sort((a, b) => a.localeCompare(b)),
  }
}

function formatRuntimeLabel(runtime: string) {
  return runtime.split('-').join(' ')
}

type TemplatesSearch = {
  search?: string
  offset?: number
  limit?: number
  uc?: string
  rt?: string
}

const filterSectionTitle =
  'text-start text-[11px] font-semibold uppercase tracking-wider text-muted-foreground'

function UseCaseFilterTrigger({
  selectedUseCases,
}: {
  selectedUseCases: string[]
}) {
  const t = useT()
  if (selectedUseCases.length === 0) {
    return (
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">
        {t('All use cases')}
      </span>
    )
  }
  if (selectedUseCases.length === 1) {
    const uc = selectedUseCases[0]!
    return (
      <span className="min-w-0 truncate text-[13px] text-foreground">
        {formatUseCaseLabel(uc)}
      </span>
    )
  }
  return (
    <span className="min-w-0 truncate text-[13px] text-foreground tabular-nums">
      {selectedUseCases.length} {t('selected')}
    </span>
  )
}

function RuntimeFilterTrigger({
  selectedRuntimes,
}: {
  selectedRuntimes: string[]
}) {
  const t = useT()
  if (selectedRuntimes.length === 0) {
    return (
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">
        {t('All runtimes')}
      </span>
    )
  }
  if (selectedRuntimes.length === 1) {
    const rt = selectedRuntimes[0]!
    return (
      <span className="flex min-w-0 flex-1 items-center gap-2">
        <RuntimeIcon
          runtime={rt}
          size="sm"
          className="h-4 w-4 shrink-0 text-muted-foreground"
        />
        <span className="min-w-0 truncate font-mono text-[13px] text-foreground">
          {formatRuntimeLabel(rt)}
        </span>
      </span>
    )
  }
  return (
    <span className="flex min-w-0 flex-1 items-center gap-2">
      <span className="flex shrink-0 -space-x-1.5">
        {selectedRuntimes.slice(0, 3).map((rt) => (
          <span
            key={rt}
            className="inline-flex rounded border border-border bg-muted/60 p-0.5 ring-2 ring-background"
          >
            <RuntimeIcon runtime={rt} size="sm" className="h-3.5 w-3.5" />
          </span>
        ))}
      </span>
      <span className="min-w-0 truncate text-[13px] text-foreground tabular-nums">
        {selectedRuntimes.length} {t('selected')}
      </span>
    </span>
  )
}

const filterPopoverContentClass =
  'w-[var(--radix-popover-trigger-width)] min-w-[14rem] max-h-[min(320px,var(--radix-popover-content-available-height))] overflow-hidden p-0'

/** Minimal bulk actions under the search field in filter dropdowns */
function FilterDropdownToolbar({
  onSelectAll,
  onClear,
  selectAllDisabled,
  clearDisabled,
}: {
  onSelectAll: () => void
  onClear: () => void
  selectAllDisabled: boolean
  clearDisabled: boolean
}) {
  const t = useT()
  return (
    <div className="flex items-center justify-end gap-0.5 border-b border-border/50 px-2 py-0.5">
      <button
        type="button"
        disabled={selectAllDisabled}
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onSelectAll()
        }}
        className="rounded px-1 py-0.5 text-[11px] leading-none text-muted-foreground/80 transition-colors hover:bg-muted/50 hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
      >
        {t('Select all')}
      </button>
      <span
        className="select-none px-0.5 text-[9px] text-muted-foreground/30"
        aria-hidden
      >
        ·
      </span>
      <button
        type="button"
        disabled={clearDisabled}
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          onClear()
        }}
        className="rounded px-1 py-0.5 text-[11px] leading-none text-muted-foreground/80 transition-colors hover:bg-muted/50 hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
      >
        {t('Clear')}
      </button>
    </div>
  )
}

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

/** Optional fields returned by the templates catalog API. */
type CatalogTemplateExtra = {
  score?: number
  timeout?: number
  permissions?: string[]
  events?: string[]
  cron?: string
  instructions?: string
  vcsProvider?: string
  providerRepositoryId?: string
  providerOwner?: string
  providerVersion?: string
  variables?: Array<{
    name?: string
    description?: string
    placeholder?: string
    required?: boolean
    type?: string
    value?: string
  }>
  scopes?: string[]
}

function asCatalogTemplate(t: Models.TemplateFunction) {
  return t as Models.TemplateFunction & CatalogTemplateExtra
}

function catalogTemplateSourceUrl(
  t: Models.TemplateFunction & CatalogTemplateExtra,
): string | null {
  const provider = (t.vcsProvider ?? '').toLowerCase()
  const owner = t.providerOwner
  const repo = t.providerRepositoryId
  const root = t.runtimes?.[0]?.providerRootDirectory
  if (!owner || !repo || !root) return null
  if (provider === 'github' || provider === '') {
    return `https://github.com/${owner}/${repo}/tree/main/${root}`
  }
  return null
}

function truncateMiddle(s: string, max: number) {
  if (s.length <= max) return s
  const head = Math.floor((max - 1) / 2)
  const tail = Math.ceil((max - 1) / 2)
  return `${s.slice(0, head)}…${s.slice(s.length - tail)}`
}

function FunctionTemplateDetailDrawer({
  open,
  onOpenChange,
  template,
  projectId,
  createBlockedTooltip,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  template: Models.TemplateFunction | null
  projectId: string
  createBlockedTooltip?: string
}) {
  const tr = useT()
  if (!template) return null

  const t = asCatalogTemplate(template)
  const sourceUrl = catalogTemplateSourceUrl(t)
  const runtimes = t.runtimes ?? []
  const variables = t.variables ?? []
  const scopes = t.scopes ?? []
  const permissions = t.permissions ?? []
  const events = t.events ?? []
  const cron = (t.cron ?? '').trim()

  const useCasesList = t.useCases ?? []
  const hasUseCases = useCasesList.length > 0
  const hasSchedulingOrEvents = events.length > 0 || cron.length > 0

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t.name ?? tr('Template')}
      maxWidth="sm:max-w-lg"
      contentClassName="overflow-hidden"
    >
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="shrink-0 border-b border-border" />
        <ScrollArea className="min-h-0 flex-1">
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-3">
              {t.tagline ? (
                <p className="text-[13px] leading-snug text-muted-foreground">
                  {t.tagline}
                </p>
              ) : null}
              {hasUseCases ? (
                <div className="flex min-w-0 flex-wrap gap-1">
                  {useCasesList.map((u) => (
                    <Badge
                      key={u}
                      variant="info"
                      className="text-[10px] font-normal"
                    >
                      {formatUseCaseLabel(u)}
                    </Badge>
                  ))}
                </div>
              ) : null}
              {(t.providerOwner || t.providerRepositoryId) && (
                <p className="truncate font-mono text-[11px] text-muted-foreground/90">
                  {[t.providerOwner, t.providerRepositoryId]
                    .filter(Boolean)
                    .join('/')}
                  {t.providerVersion ? (
                    <span className="text-muted-foreground/70">
                      {' '}
                      @ {t.providerVersion}
                    </span>
                  ) : null}
                </p>
              )}
            </div>

            {hasSchedulingOrEvents && (
              <div className="space-y-3 border-t border-border pt-4">
                <p className="text-[12px] font-medium text-foreground">
                  {tr('Execution')}
                </p>
                <div className="space-y-3">
                  {events.length > 0 && (
                    <div>
                      <p className="text-[10px] text-muted-foreground">
                        {tr('Events')}
                      </p>
                      <div className="mt-1 flex flex-wrap gap-1">
                        {events.map((ev) => (
                          <code
                            key={ev}
                            className="rounded border border-border bg-muted/30 px-1.5 py-px font-mono text-[10px] text-foreground"
                          >
                            {ev}
                          </code>
                        ))}
                      </div>
                    </div>
                  )}
                  {cron.length > 0 && (
                    <div>
                      <p className="text-[10px] text-muted-foreground">Cron</p>
                      <code className="mt-1 block w-full overflow-x-auto rounded border border-border bg-muted/30 px-2 py-1 font-mono text-[11px] leading-snug text-foreground">
                        {cron}
                      </code>
                    </div>
                  )}
                </div>
              </div>
            )}

            {t.instructions ? (
              <div className="border-t border-border pt-4">
                <p className="mb-2 text-[12px] font-medium text-foreground">
                  {tr('Documentation')}
                </p>
                <div
                  className="text-[13px] leading-relaxed text-muted-foreground prose-links-neutral"
                  dangerouslySetInnerHTML={{ __html: t.instructions }}
                />
              </div>
            ) : null}

            {runtimes.length > 0 ||
            variables.length > 0 ||
            scopes.length > 0 ||
            permissions.length > 0 ? (
              <Accordion
                type="multiple"
                defaultValue={[]}
                className="border-t border-border pt-4"
              >
                {runtimes.length > 0 ? (
                  <AccordionItem value="runtimes" className="border-border">
                    <AccordionTrigger className="cursor-pointer py-3 text-[12px] font-medium text-foreground hover:no-underline">
                      <span className="flex items-baseline gap-1.5">
                        <span>{tr('Runtimes')}</span>
                        <span className="font-normal tabular-nums text-muted-foreground">
                          ({runtimes.length})
                        </span>
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-4 pt-0">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {tr('Runtime')}
                            </TableHead>
                            <TableHead className="px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {tr('Entrypoint')}
                            </TableHead>
                            <TableHead className="px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {tr('Build')}
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {runtimes.map((r) => (
                            <TableRow key={r.name}>
                              <TableCell className="px-3 py-2 align-middle">
                                <div className="flex min-w-0 items-center gap-2">
                                  <RuntimeIcon
                                    runtime={r.name}
                                    size="sm"
                                    className="h-4 w-4 shrink-0 text-muted-foreground"
                                  />
                                  <span className="min-w-0 font-mono text-[11px] text-foreground">
                                    {r.name}
                                  </span>
                                </div>
                              </TableCell>
                              <TableCell className="px-3 py-2 align-top">
                                <span className="break-all font-mono text-[11px] text-muted-foreground">
                                  {r.entrypoint ?? '-'}
                                </span>
                              </TableCell>
                              <TableCell className="px-3 py-2 align-top">
                                <span
                                  className="break-all font-mono text-[10px] leading-snug text-muted-foreground"
                                  title={r.commands ?? ''}
                                >
                                  {r.commands
                                    ? truncateMiddle(r.commands, 48)
                                    : '-'}
                                </span>
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </AccordionContent>
                  </AccordionItem>
                ) : null}

                {variables.length > 0 ? (
                  <AccordionItem value="env" className="border-border">
                    <AccordionTrigger className="cursor-pointer py-3 text-[12px] font-medium text-foreground hover:no-underline">
                      <span className="flex items-baseline gap-1.5">
                        <span>{tr('Environment variables')}</span>
                        <span className="font-normal tabular-nums text-muted-foreground">
                          ({variables.length})
                        </span>
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-4 pt-0">
                      <ul className="space-y-3">
                        {variables.map((v, idx) => (
                          <li
                            key={v.name ?? `var-${idx}`}
                            className="border-b border-border/60 pb-3 last:border-0 last:pb-0"
                          >
                            <div className="flex flex-wrap items-center gap-1.5">
                              <span className="font-mono text-[12px] font-medium text-foreground">
                                {v.name}
                              </span>
                              {v.required ? (
                                <Badge
                                  variant="warning"
                                  className="h-5 text-[9px] px-1"
                                >
                                  {tr('Req')}
                                </Badge>
                              ) : (
                                <Badge variant="info" className="h-5 text-[9px] px-1">
                                  {tr('Opt')}
                                </Badge>
                              )}
                              {v.type ? (
                                <Badge
                                  variant="info"
                                  className="h-5 font-mono text-[9px] px-1"
                                >
                                  {v.type}
                                </Badge>
                              ) : null}
                            </div>
                            {v.description ? (
                              <div
                                className="mt-1.5 text-[12px] leading-snug text-muted-foreground prose-links-neutral"
                                dangerouslySetInnerHTML={{
                                  __html: v.description,
                                }}
                              />
                            ) : null}
                          </li>
                        ))}
                      </ul>
                    </AccordionContent>
                  </AccordionItem>
                ) : null}

                {scopes.length > 0 ? (
                  <AccordionItem value="scopes" className="border-border">
                    <AccordionTrigger className="cursor-pointer py-3 text-[12px] font-medium text-foreground hover:no-underline">
                      <span className="flex items-baseline gap-1.5">
                        <span>{tr('API scopes')}</span>
                        <span className="font-normal tabular-nums text-muted-foreground">
                          ({scopes.length})
                        </span>
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-4 pt-0">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {tr('Scope')}
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {scopes.map((s) => (
                            <TableRow key={s}>
                              <TableCell className="px-3 py-2">
                                <span className="font-mono text-[11px] text-foreground">
                                  {s}
                                </span>
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </AccordionContent>
                  </AccordionItem>
                ) : null}

                {permissions.length > 0 ? (
                  <AccordionItem value="permissions" className="border-border">
                    <AccordionTrigger className="cursor-pointer py-3 text-[12px] font-medium text-foreground hover:no-underline">
                      <span className="flex items-baseline gap-1.5">
                        <span>{tr('Permissions')}</span>
                        <span className="font-normal tabular-nums text-muted-foreground">
                          ({permissions.length})
                        </span>
                      </span>
                    </AccordionTrigger>
                    <AccordionContent className="pb-4 pt-0">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {tr('Permission')}
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {permissions.map((p) => (
                            <TableRow key={p}>
                              <TableCell className="px-3 py-2">
                                <span className="font-mono text-[11px] text-foreground">
                                  {p}
                                </span>
                              </TableCell>
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </AccordionContent>
                  </AccordionItem>
                ) : null}
              </Accordion>
            ) : null}
          </div>
        </ScrollArea>

        <div className="shrink-0 border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {sourceUrl ? (
            <Button variant="outline" className="gap-1.5 sm:w-auto" asChild>
              <a
                href={sourceUrl}
                target="_blank"
                rel="noreferrer noopener"
              >
                <GitHubIcon className="h-4 w-4 shrink-0" />
                {tr('View on GitHub')}
                <ExternalLink className="h-3.5 w-3.5 shrink-0 opacity-70" />
              </a>
            </Button>
          ) : null}
          {createBlockedTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex w-full sm:w-auto">
                    <Button
                      className="w-full sm:w-auto"
                      disabled
                      type="button"
                    >
                      {tr('Create from template')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent>{createBlockedTooltip}</TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <Button className="w-full sm:w-auto" asChild>
              <Link
                to="/projects/$projectId/functions/create/template/$templateId"
                params={{
                  projectId,
                  templateId: String(t.id ?? ''),
                }}
              >
                {tr('Create from template')}
              </Link>
            </Button>
          )}
        </div>
      </div>
    </BaseDrawer>
  )
}

function TemplateCatalogFilters({
  searchInput,
  onSearchInputChange,
  catalogUseCases,
  catalogRuntimes,
  selectedUseCases,
  selectedRuntimes,
  toggleUseCase,
  toggleRuntime,
  onSelectAllUseCases,
  onClearUseCases,
  onSelectAllRuntimes,
  onClearRuntimes,
  className,
}: {
  searchInput: string
  onSearchInputChange: (value: string) => void
  catalogUseCases: string[]
  catalogRuntimes: string[]
  selectedUseCases: string[]
  selectedRuntimes: string[]
  toggleUseCase: (value: string) => void
  toggleRuntime: (value: string) => void
  onSelectAllUseCases: () => void
  onClearUseCases: () => void
  onSelectAllRuntimes: () => void
  onClearRuntimes: () => void
  className?: string
}) {
  const t = useT()
  const [useCaseOpen, setUseCaseOpen] = useState(false)
  const [runtimeOpen, setRuntimeOpen] = useState(false)

  const useCaseAllSelected =
    catalogUseCases.length > 0 &&
    catalogUseCases.every((uc) =>
      selectedUseCases.some((s) => s.toLowerCase() === uc.toLowerCase()),
    )
  const useCaseHasSelection = selectedUseCases.length > 0

  const runtimeAllSelected =
    catalogRuntimes.length > 0 &&
    catalogRuntimes.every((rt) => selectedRuntimes.includes(rt))
  const runtimeHasSelection = selectedRuntimes.length > 0

  return (
    <div
      className={cn(
        'flex max-h-[min(70dvh,calc(100dvh-10rem))] flex-col gap-5',
        className,
      )}
    >
      <section className="space-y-2">
        <label htmlFor="template-catalog-search" className="sr-only">
          {t('Search templates by name')}
        </label>
        <div className="relative w-full">
          <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            id="template-catalog-search"
            type="search"
            placeholder={t('Search by name...')}
            value={searchInput}
            onChange={(e) => onSearchInputChange(e.target.value)}
            className="h-9 w-full border-border bg-background ps-9 pe-3 text-[13px] placeholder:text-muted-foreground"
            autoComplete="off"
          />
        </div>
      </section>

      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain">
        <section className="space-y-2">
          <h3 className={filterSectionTitle}>{t('Use case')}</h3>
          <Popover open={useCaseOpen} onOpenChange={setUseCaseOpen}>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                type="button"
                role="combobox"
                aria-expanded={useCaseOpen}
                className="h-9 w-full justify-between gap-2 px-3 text-[13px] font-normal"
              >
                <UseCaseFilterTrigger selectedUseCases={selectedUseCases} />
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="start"
              className={filterPopoverContentClass}
              onWheelCapture={(e) => e.stopPropagation()}
            >
              <Command>
                <CommandInput
                  placeholder={t('Search use cases...')}
                  className="h-9 text-[13px]"
                />
                {catalogUseCases.length > 0 ? (
                  <FilterDropdownToolbar
                    onSelectAll={onSelectAllUseCases}
                    onClear={onClearUseCases}
                    selectAllDisabled={useCaseAllSelected}
                    clearDisabled={!useCaseHasSelection}
                  />
                ) : null}
                <CommandList className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain">
                  <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                    {t('No use cases match')}
                  </CommandEmpty>
                  <CommandGroup className="p-1">
                    {catalogUseCases.map((uc) => {
                      const checked = selectedUseCases.some(
                        (s) => s.toLowerCase() === uc.toLowerCase(),
                      )
                      const label = formatUseCaseLabel(uc)
                      return (
                        <CommandItem
                          key={uc}
                          value={`${uc} ${label}`}
                          onSelect={() => toggleUseCase(uc)}
                          className={cn(
                            'group cursor-pointer gap-2 rounded-sm px-2 py-2 text-[13px]',
                            '[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/55 [&_[data-slot=checkbox][data-state=unchecked]]:bg-background',
                            'data-[selected=true]:[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/80',
                            '[&_[data-slot=checkbox][data-state=checked]]:!border-primary [&_[data-slot=checkbox][data-state=checked]]:!bg-primary',
                            '[&_[data-slot=checkbox]_svg]:!text-primary-foreground',
                          )}
                        >
                          <Checkbox
                            checked={checked}
                            className="pointer-events-none shrink-0 border-border bg-background shadow-sm data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
                            tabIndex={-1}
                          />
                          <span className="min-w-0 flex-1 text-[13px] leading-snug">
                            {label}
                          </span>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </section>

        <section className="space-y-2">
          <h3 className={filterSectionTitle}>{t('Runtime')}</h3>
          <Popover open={runtimeOpen} onOpenChange={setRuntimeOpen}>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                type="button"
                role="combobox"
                aria-expanded={runtimeOpen}
                className="h-9 w-full justify-between gap-2 px-3 text-[13px] font-normal"
              >
                <RuntimeFilterTrigger selectedRuntimes={selectedRuntimes} />
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              align="start"
              className={filterPopoverContentClass}
              onWheelCapture={(e) => e.stopPropagation()}
            >
              <Command>
                <CommandInput
                  placeholder={t('Search runtimes...')}
                  className="h-9 text-[13px]"
                />
                {catalogRuntimes.length > 0 ? (
                  <FilterDropdownToolbar
                    onSelectAll={onSelectAllRuntimes}
                    onClear={onClearRuntimes}
                    selectAllDisabled={runtimeAllSelected}
                    clearDisabled={!runtimeHasSelection}
                  />
                ) : null}
                <CommandList className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain">
                  <CommandEmpty className="py-6 text-center text-[13px] text-muted-foreground">
                    {t('No runtimes match')}
                  </CommandEmpty>
                  <CommandGroup className="p-1">
                    {catalogRuntimes.map((rt) => {
                      const checked = selectedRuntimes.includes(rt)
                      const label = formatRuntimeLabel(rt)
                      return (
                        <CommandItem
                          key={rt}
                          value={`${rt} ${label}`}
                          onSelect={() => toggleRuntime(rt)}
                          className={cn(
                            'group cursor-pointer gap-2 rounded-sm px-2 py-2 text-[13px]',
                            // cmdk selected row uses bg-accent; keep checkbox readable
                            '[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/55 [&_[data-slot=checkbox][data-state=unchecked]]:bg-background',
                            'data-[selected=true]:[&_[data-slot=checkbox][data-state=unchecked]]:border-foreground/80',
                            '[&_[data-slot=checkbox][data-state=checked]]:!border-primary [&_[data-slot=checkbox][data-state=checked]]:!bg-primary',
                            '[&_[data-slot=checkbox]_svg]:!text-primary-foreground',
                          )}
                        >
                          <Checkbox
                            checked={checked}
                            className="pointer-events-none shrink-0 border-border bg-background shadow-sm data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
                            tabIndex={-1}
                          />
                          <RuntimeIcon
                            runtime={rt}
                            size="sm"
                            className="h-4 w-4 shrink-0 text-muted-foreground group-data-[selected=true]:text-foreground"
                          />
                          <span className="min-w-0 flex-1 font-mono text-[13px] leading-snug">
                            {label}
                          </span>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </section>
      </div>

      <div className="shrink-0 space-y-2 border-t border-border pt-4">
        <h3 className={filterSectionTitle}>{t('Contribute')}</h3>
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {t(
            'This catalog is built from our public GitHub repository. Browse the source, open issues, or submit a pull request if you want to add or improve a template.',
          )}
        </p>
        <a
          href="https://github.com/appwrite/templates"
          target="_blank"
          rel="noreferrer noopener"
          className="link-neutral inline-flex max-w-full items-center gap-1.5 pt-0.5 text-[13px]"
        >
          <GitHubIcon className="h-4 w-4 shrink-0" />
          appwrite/templates on GitHub
          <ExternalLink className="h-3.5 w-3.5 shrink-0 opacity-70" />
        </a>
      </div>
    </div>
  )
}

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const search = Route.useSearch()

  const urlLimit = parseTemplatesLimit(search.limit, GRID_DEFAULT_PAGE_SIZE)

  const urlOffset = useMemo(() => {
    if (search.offset != null) {
      return parseTemplatesOffset(search.offset)
    }
    if (search.page != null) {
      return (parseTemplatesPage(search.page) - 1) * urlLimit
    }
    return 0
  }, [search.offset, search.page, urlLimit])

  const urlSearch = search.search?.trim() ?? ''
  const selectedUseCases = useMemo(
    () => parseCsvParam(search.uc),
    [search.uc],
  )
  const selectedRuntimes = useMemo(
    () => parseCsvParam(search.rt),
    [search.rt],
  )

  const [searchInput, setSearchInput] = useState(urlSearch)
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    setSearchInput(urlSearch)
  }, [urlSearch])

  const navigateCatalog = useCallback(
    (patch: Partial<TemplatesSearch>) => {
      navigate({
        to: '/projects/$projectId/functions/templates',
        params: { projectId: projectId! },
        search: (prev) => {
          const base = { ...(prev as Record<string, unknown>) }
          const next: Record<string, string | number | undefined> = {
            ...base,
            ...patch,
          }
          if (next.search === '' || next.search === undefined) delete next.search
          delete next.page
          if (next.offset === 0 || next.offset === undefined) delete next.offset
          if (
            next.limit === GRID_DEFAULT_PAGE_SIZE ||
            next.limit === undefined
          )
            delete next.limit
          if (!next.uc) delete next.uc
          if (!next.rt) delete next.rt
          return next
        },
        replace: true,
      })
    },
    [navigate, projectId],
  )

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === urlSearch) return
      navigateCatalog({ search: trimmed || undefined, offset: 0 })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [searchInput, urlSearch, navigateCatalog])

  const skipFilterScrollRef = useRef(true)
  useEffect(() => {
    if (skipFilterScrollRef.current) {
      skipFilterScrollRef.current = false
      return
    }
    scrollConsoleMainToTop()
  }, [urlSearch, search.uc, search.rt])

  const isNameSearch = urlSearch.trim().length > 0
  const pageSize = Math.max(1, urlLimit)

  /** First catalog page, unfiltered - drives filter chips; dedupes with `pageQuery` on default URL. */
  const facetSourceQuery = useQuery({
    ...functionTemplatesPageQueryOptions(
      projectId,
      0,
      GRID_DEFAULT_PAGE_SIZE,
      [],
      [],
    ),
    enabled: !!projectId && !isNameSearch,
  })

  const pageQuery = useQuery({
    ...functionTemplatesPageQueryOptions(
      projectId,
      urlOffset,
      pageSize,
      selectedRuntimes,
      selectedUseCases,
    ),
    enabled: !!projectId && !isNameSearch,
  })

  const searchCatalogQuery = useQuery({
    ...allFunctionTemplatesQueryOptions(projectId, {
      runtimes: selectedRuntimes.length ? selectedRuntimes : undefined,
      useCases: selectedUseCases.length ? selectedUseCases : undefined,
    }),
    enabled: !!projectId && isNameSearch,
  })

  const searchFilteredTemplates = useMemo(() => {
    if (!isNameSearch) return []
    const q = urlSearch.toLowerCase()
    const list = searchCatalogQuery.data?.templates ?? []
    return [...list]
      .filter((template) => {
        const name = (template.name ?? '').toLowerCase()
        return name.includes(q)
      })
      .sort((a, b) => {
        const an = (a.name ?? '').toLowerCase()
        const bn = (b.name ?? '').toLowerCase()
        if (an !== bn) return an.localeCompare(bn)
        return String(a.id ?? '').localeCompare(String(b.id ?? ''))
      })
  }, [isNameSearch, urlSearch, searchCatalogQuery.data?.templates])

  const { useCases: catalogUseCases, runtimes: catalogRuntimes } = useMemo(
    () => {
      const templates = isNameSearch
        ? searchCatalogQuery.data?.templates
        : facetSourceQuery.data?.templates
      return collectTemplateFacetLabels(templates)
    },
    [
      isNameSearch,
      facetSourceQuery.data?.templates,
      searchCatalogQuery.data?.templates,
    ],
  )

  /** Distinguishes filter sets so we can reuse last known `total` while paging (offset is not part of key). */
  const catalogFacetKey = useMemo(
    () =>
      `${String(projectId ?? '')}\u0000${[...selectedRuntimes].sort().join(',')}\u0000${[...selectedUseCases].sort().join(',')}`,
    [projectId, selectedRuntimes, selectedUseCases],
  )

  const lastServerTotalByFacetRef = useRef<Map<string, number>>(new Map())
  if (!isNameSearch && pageQuery.data && typeof pageQuery.data.total === 'number') {
    lastServerTotalByFacetRef.current.set(catalogFacetKey, pageQuery.data.total)
  }

  /** Without this, `pageQuery.data` is briefly undefined between pages and total looks like 0, which triggers a bogus clamp to offset 0. */
  const hasKnownCatalogTotal =
    isNameSearch ||
    pageQuery.data != null ||
    lastServerTotalByFacetRef.current.has(catalogFacetKey)

  const totalFiltered = isNameSearch
    ? searchFilteredTemplates.length
    : (pageQuery.data?.total ??
      lastServerTotalByFacetRef.current.get(catalogFacetKey) ??
      0)

  const pageCount = Math.max(1, Math.ceil(totalFiltered / pageSize))
  const maxOffset =
    isNameSearch || hasKnownCatalogTotal
      ? totalFiltered === 0
        ? 0
        : Math.max(0, (pageCount - 1) * pageSize)
      : Number.MAX_SAFE_INTEGER
  const safeOffset =
    maxOffset === Number.MAX_SAFE_INTEGER
      ? urlOffset
      : Math.min(urlOffset, maxOffset)
  const safePage = Math.floor(safeOffset / pageSize) + 1
  const paginatedTemplates = isNameSearch
    ? searchFilteredTemplates.slice(safeOffset, safeOffset + pageSize)
    : (pageQuery.data?.templates ?? [])

  /** Accurate "X–Y of Z" when this page shows fewer rows than `pageSize` (e.g. last page). */
  const paginationDisplayItemRange = useMemo(() => {
    if (totalFiltered <= 0 || paginatedTemplates.length === 0) return undefined
    return {
      start: safeOffset + 1,
      end: Math.min(safeOffset + paginatedTemplates.length, totalFiltered),
    }
  }, [totalFiltered, paginatedTemplates.length, safeOffset])

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  const { data: totalFunctionsData } = useQuery({
    queryKey: [
      'functions',
      'project',
      projectId,
      0,
      GRID_DEFAULT_PAGE_SIZE,
      undefined,
      undefined,
      FUNCTIONS_DEFAULT_SORT_BY,
      FUNCTIONS_DEFAULT_SORT_ORDER,
    ],
    queryFn: () =>
      fetchProjectFunctions(
        projectId!,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        undefined,
        undefined,
        FUNCTIONS_DEFAULT_SORT_BY,
        FUNCTIONS_DEFAULT_SORT_ORDER,
      ),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    refetchOnMount: false,
  })

  const totalFunctionsCount = totalFunctionsData?.total ?? 0
  const functionsLimit = organizationPlan?.functions ?? 0
  const noCreatePermission = !canCreateFunction(access, features)
  const createBlockedTooltip = noCreatePermission
    ? t("You don't have permission to create functions.")
    : functionsLimit > 0 && totalFunctionsCount >= functionsLimit
      ? t('Function limit reached for your plan.')
      : undefined

  useEffect(() => {
    if (urlOffset > maxOffset) {
      navigateCatalog({ offset: maxOffset })
    }
  }, [urlOffset, maxOffset, navigateCatalog])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'functions',
        label: t('Functions'),
        to: '/projects/$projectId/functions/',
        params: { projectId: projectId as string },
      },
      {
        id: 'templates',
        label: t('Templates'),
        to: '/projects/$projectId/functions/templates',
        params: { projectId: projectId as string },
      },
    ],
    [projectId, t],
  )

  const toggleUseCase = (value: string) => {
    const lower = value.toLowerCase()
    const has = selectedUseCases.some((u) => u.toLowerCase() === lower)
    const next = has
      ? selectedUseCases.filter((u) => u.toLowerCase() !== lower)
      : [...selectedUseCases, value]
    navigateCatalog({ uc: joinCsvParam(next), offset: 0 })
  }

  const toggleRuntime = (value: string) => {
    const has = selectedRuntimes.includes(value)
    const next = has
      ? selectedRuntimes.filter((r) => r !== value)
      : [...selectedRuntimes, value]
    navigateCatalog({ rt: joinCsvParam(next), offset: 0 })
  }

  const selectAllUseCases = useCallback(() => {
    navigateCatalog({ uc: joinCsvParam([...catalogUseCases]), offset: 0 })
  }, [navigateCatalog, catalogUseCases])

  const clearUseCases = useCallback(() => {
    navigateCatalog({ uc: undefined, offset: 0 })
  }, [navigateCatalog])

  const selectAllRuntimes = useCallback(() => {
    navigateCatalog({ rt: joinCsvParam([...catalogRuntimes]), offset: 0 })
  }, [navigateCatalog, catalogRuntimes])

  const clearRuntimes = useCallback(() => {
    navigateCatalog({ rt: undefined, offset: 0 })
  }, [navigateCatalog])

  const clearFiltersAndSearch = () => {
    setSearchInput('')
    navigate({
      to: '/projects/$projectId/functions/templates',
      params: { projectId: projectId! },
      search: {},
      replace: true,
    })
  }

  const [detailTemplate, setDetailTemplate] =
    useState<Models.TemplateFunction | null>(null)

  const listError =
    facetSourceQuery.error ?? pageQuery.error ?? searchCatalogQuery.error

  const facetsLoading =
    (!isNameSearch && facetSourceQuery.isPending && !facetSourceQuery.data) ||
    (isNameSearch && searchCatalogQuery.isPending && !searchCatalogQuery.data)
  const pageLoading =
    !isNameSearch &&
    pageQuery.isPending &&
    !pageQuery.data
  const searchLoading =
    isNameSearch &&
    searchCatalogQuery.isPending &&
    !searchCatalogQuery.data

  const showLoading = facetsLoading || pageLoading || searchLoading

  const hasFiltersOrSearch =
    urlSearch.length > 0 ||
    selectedUseCases.length > 0 ||
    selectedRuntimes.length > 0

  const noResults =
    !showLoading &&
    totalFiltered === 0 &&
    hasFiltersOrSearch

  const emptyCatalog =
    !showLoading &&
    totalFiltered === 0 &&
    !hasFiltersOrSearch &&
    !listError

  return (
    <div className="flex flex-col">
      <ServiceHeader title={t('Functions')} tabs={tabs} activeTab="templates" fullWidthBorder />

      <div className="mx-auto flex w-full max-w-7xl flex-1 gap-4 px-4 pb-6 pt-6 sm:px-6 lg:gap-5">
        <aside className="hidden w-[15.5rem] shrink-0 lg:block">
          <div className="sticky top-4">
            <TemplateCatalogFilters
              searchInput={searchInput}
              onSearchInputChange={setSearchInput}
              catalogUseCases={catalogUseCases}
              catalogRuntimes={catalogRuntimes}
              selectedUseCases={selectedUseCases}
              selectedRuntimes={selectedRuntimes}
              toggleUseCase={toggleUseCase}
              toggleRuntime={toggleRuntime}
              onSelectAllUseCases={selectAllUseCases}
              onClearUseCases={clearUseCases}
              onSelectAllRuntimes={selectAllRuntimes}
              onClearRuntimes={clearRuntimes}
            />
          </div>
        </aside>

        <div className="min-w-0 flex-1">
          <div className="mb-4 lg:hidden">
            <TemplateCatalogFilters
              searchInput={searchInput}
              onSearchInputChange={setSearchInput}
              catalogUseCases={catalogUseCases}
              catalogRuntimes={catalogRuntimes}
              selectedUseCases={selectedUseCases}
              selectedRuntimes={selectedRuntimes}
              toggleUseCase={toggleUseCase}
              toggleRuntime={toggleRuntime}
              onSelectAllUseCases={selectAllUseCases}
              onClearUseCases={clearUseCases}
              onSelectAllRuntimes={selectAllRuntimes}
              onClearRuntimes={clearRuntimes}
              className="max-h-[min(55dvh,26rem)] lg:max-h-none"
            />
          </div>
          {listError ? (
            <EmptyState
              icon={AlertCircle}
              title={t("Couldn't load templates")}
              description={t('Something went wrong. Please try again.')}
              isEmpty={false}
              hasFilters={false}
              variant="card"
            />
          ) : showLoading ? (
            <EmptyState variant="card">
              <div className="flex flex-col items-center gap-3">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading templates...')}
                </p>
              </div>
            </EmptyState>
          ) : emptyCatalog ? (
            <EmptyState
              icon={LayoutTemplate}
              title={t('No templates yet')}
              description={t(
                'Function templates will appear here when they are available in the catalog.',
              )}
              isEmpty
              hasFilters={false}
              variant="card"
            />
          ) : noResults ? (
            <EmptyState
              icon={Braces}
              isEmpty={false}
              hasFilters
              variant="card"
              title={t('No templates match')}
              description={t(
                'Try adjusting filters or search, or clear everything to see the full catalog.',
              )}
              action={
                <Button
                  variant="outline"
                  size="sm"
                  onClick={clearFiltersAndSearch}
                >
                  {t('Clear filters and search')}
                </Button>
              }
            />
          ) : (
            <>
              <div className={RESOURCE_CARD_GRID_WIDE_CLASSNAME}>
                {paginatedTemplates.map((template, i) => (
                  <TemplateCard
                    key={`${safeOffset + i}-${String(template.id)}`}
                    template={template}
                    onOpenDetails={() => setDetailTemplate(template)}
                  />
                ))}
              </div>

              {totalFiltered > 0 && (
                <Pagination
                  currentPage={safePage}
                  totalItems={totalFiltered}
                  pageSize={pageSize}
                  displayItemRange={paginationDisplayItemRange}
                  pageSizeOptions={[12, 18, 36, 72]}
                  onPageChange={(page) => {
                    navigateCatalog({ offset: (page - 1) * pageSize })
                  }}
                  onPageSizeChange={(limit) => {
                    navigateCatalog({ limit, offset: 0 })
                  }}
                  itemLabel="templates"
                />
              )}
            </>
          )}
        </div>
      </div>

      <FunctionTemplateDetailDrawer
        open={!!detailTemplate}
        onOpenChange={(open) => !open && setDetailTemplate(null)}
        template={detailTemplate}
        projectId={projectId!}
        createBlockedTooltip={createBlockedTooltip}
      />
    </div>
  )
}

function TemplateCard({
  template,
  onOpenDetails,
}: {
  template: Models.TemplateFunction
  onOpenDetails: () => void
}) {
  const baseRuntimes = getBaseRuntimes(template.runtimes ?? [])
  const displayed = baseRuntimes.slice(0, 2)
  const hidden = baseRuntimes.slice(2)
  const hiddenRuntimeNames = hidden.map((h) => h.name).join(', ')

  const cardClassName = cn(
    RESOURCE_CARD_PADDED_CLASSNAME,
    RESOURCE_CARD_INTERACTIVE_CLASSNAME,
    'flex w-full min-h-[160px] min-w-0 flex-col text-start',
    RESOURCE_CARD_SHELL_CLASSNAME,
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
  )

  return (
    <button
      type="button"
      className={cardClassName}
      onClick={onOpenDetails}
    >
      <div className="flex flex-1 flex-col gap-3">
        <div className="space-y-1">
          <div className="flex min-w-0 items-start justify-between gap-2">
            <h3 className="min-w-0 flex-1 truncate text-[15px] font-semibold leading-snug text-foreground">
              {template.name}
            </h3>
            <LayoutTemplate className="h-4 w-4 shrink-0 text-muted-foreground opacity-60" />
          </div>
          <p className="line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
            {template.tagline}
          </p>
        </div>

        <div
          className={cn(
            RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
            'mt-auto flex items-center justify-between gap-2',
          )}
        >
          <div className="flex min-w-0 items-center gap-1">
            {displayed.map((r) => (
              <div
                key={r.name}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50"
              >
                <RuntimeIcon runtime={r.name} size="sm" className="h-4 w-4" />
              </div>
            ))}
            {hidden.length > 0 ? (
              <span
                className="flex h-8 min-w-8 shrink-0 items-center justify-center rounded-md border border-dashed border-border px-1.5 font-mono text-[10px] text-muted-foreground"
                title={hiddenRuntimeNames || undefined}
              >
                +{hidden.length}
              </span>
            ) : null}
          </div>
          <ChevronRight
            className="h-4 w-4 shrink-0 text-muted-foreground opacity-60 transition-colors group-hover:opacity-100"
            aria-hidden
          />
        </div>
      </div>
    </button>
  )
}
