import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { AppwriteException } from '@appwrite.io/console'
import type { editor } from 'monaco-editor'
import { Loader2, Search, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import {
  commitConsoleAccountToCaches,
  CONSOLE_ACCOUNT_STALE_TIME_MS,
  fetchConsoleAccount,
  updateAccountPrefs,
  updateConsoleTeamPrefs,
  useConsoleTeam,
  useProject,
} from '@/lib/react-query/hooks'
import {
  classifyPrefs,
  summarizePrefsClassification,
  type ClassifiedPrefEntry,
  type PrefsRuntimeScope,
} from '@/lib/prefs-catalog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import { cn } from '@/lib/utils'

function stringifyPrefs(prefs: Record<string, unknown> | null | undefined) {
  return JSON.stringify(prefs ?? {}, null, 2)
}

function parsePrefsJson(text: string): Record<string, unknown> {
  const parsed: unknown = JSON.parse(text)
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('Prefs must be a JSON object.')
  }
  return parsed as Record<string, unknown>
}

function parseValueInput(raw: string): unknown {
  const t = raw.trim()
  if (!t) return ''
  try {
    return JSON.parse(t) as unknown
  } catch {
    return raw
  }
}

type PrefSearchMatch = {
  key: string
  preview: string
}

function getMatchingPrefKeys(
  prefsJson: string,
  query: string,
): PrefSearchMatch[] {
  const q = query.trim().toLowerCase()
  if (!q) return []

  try {
    const prefs = parsePrefsJson(prefsJson)
    return Object.entries(prefs)
      .filter(([key, value]) => {
        const valueStr =
          typeof value === 'string' ? value : JSON.stringify(value)
        return (
          key.toLowerCase().includes(q) || valueStr.toLowerCase().includes(q)
        )
      })
      .map(([key, value]) => {
        const valueStr =
          typeof value === 'string' ? value : JSON.stringify(value)
        return {
          key,
          preview:
            valueStr.length > 80 ? `${valueStr.slice(0, 80)}…` : valueStr,
        }
      })
      .sort((a, b) => a.key.localeCompare(b.key))
  } catch {
    return []
  }
}

function filterClassifiedPrefs(
  entries: ClassifiedPrefEntry[],
  query: string,
): ClassifiedPrefEntry[] {
  const q = query.trim().toLowerCase()
  if (!q) return entries
  return entries.filter((entry) => {
    const description = entry.catalog?.description?.toLowerCase() ?? ''
    const category = entry.catalog?.category?.toLowerCase() ?? ''
    return (
      entry.key.toLowerCase().includes(q) ||
      entry.preview.toLowerCase().includes(q) ||
      description.includes(q) ||
      category.includes(q)
    )
  })
}

function scrollEditorToPrefKey(
  editorInstance: editor.IStandaloneCodeEditor | null,
  key: string,
) {
  if (!editorInstance) return
  const model = editorInstance.getModel()
  if (!model) return

  const needle = `"${key.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  const matches = model.findMatches(needle, false, false, false, null, false)
  const match = matches[0]
  if (!match) return

  editorInstance.revealLineInCenter(match.range.startLineNumber)
  editorInstance.setSelection(match.range)
  editorInstance.focus()
}

function PrefsSearchBar({
  value,
  onChange,
  matches,
  onSelectKey,
  disabled,
  showMatchList,
}: {
  value: string
  onChange: (value: string) => void
  matches: PrefSearchMatch[]
  onSelectKey: (key: string) => void
  disabled?: boolean
  showMatchList?: boolean
}) {
  const trimmed = value.trim()

  return (
    <div className="flex flex-col gap-1.5">
      <div className="relative">
        <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--network-globe-edge)]/60" />
        <Input
          value={value}
          onChange={(event) => onChange(event.target.value)}
          disabled={disabled}
          placeholder="Search keys, values, or descriptions..."
          className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 ps-8 pe-8 text-[12px] text-foreground placeholder:text-[var(--network-globe-edge)]/50"
        />
        {value && (
          <button
            type="button"
            onClick={() => onChange('')}
            disabled={disabled}
            className="absolute end-2 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground disabled:opacity-50"
            aria-label="Clear search"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {showMatchList && trimmed ? (
        <div className="max-h-[min(24dvh,160px)] overflow-y-auto rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40">
          {matches.length === 0 ? (
            <p className="px-2.5 py-2 text-[11px] text-[var(--network-globe-edge)]/70">
              No matching keys
            </p>
          ) : (
            <ul className="divide-y divide-[color-mix(in_srgb,var(--network-globe-edge)_10%,var(--border))]">
              {matches.map(({ key, preview }) => (
                <li key={key}>
                  <button
                    type="button"
                    onClick={() => onSelectKey(key)}
                    disabled={disabled}
                    className="flex w-full flex-col gap-0.5 px-2.5 py-2 text-start transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)] disabled:opacity-50"
                  >
                    <code className="truncate text-[11px] text-foreground">
                      {key}
                    </code>
                    <span className="truncate text-[10px] text-[var(--network-globe-edge)]/75">
                      {preview}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  )
}

const UNKNOWN_PREF_CATEGORY = 'Unknown'

function getPrefCategory(entry: ClassifiedPrefEntry): string {
  return entry.known
    ? (entry.catalog?.category ?? 'Known')
    : UNKNOWN_PREF_CATEGORY
}

function collectPrefCategories(entries: ClassifiedPrefEntry[]): string[] {
  const categories: string[] = [UNKNOWN_PREF_CATEGORY]
  const seen = new Set<string>([UNKNOWN_PREF_CATEGORY])
  for (const entry of entries) {
    const category = getPrefCategory(entry)
    if (seen.has(category)) continue
    seen.add(category)
    categories.push(category)
  }
  return categories
}

type PrefCategoryGroup = {
  category: string
  entries: ClassifiedPrefEntry[]
}

function groupPrefsByCategory(entries: ClassifiedPrefEntry[]): PrefCategoryGroup[] {
  const groups: PrefCategoryGroup[] = [
    { category: UNKNOWN_PREF_CATEGORY, entries: [] },
  ]
  const indexByCategory = new Map<string, number>([[UNKNOWN_PREF_CATEGORY, 0]])
  for (const entry of entries) {
    const category = getPrefCategory(entry)
    const existing = indexByCategory.get(category)
    if (existing != null) {
      groups[existing]!.entries.push(entry)
      continue
    }
    indexByCategory.set(category, groups.length)
    groups.push({ category, entries: [entry] })
  }
  return groups
}

function categoryNavCount(
  groups: PrefCategoryGroup[],
  category: string,
): number {
  return groups.find((group) => group.category === category)?.entries.length ?? 0
}

function StructuredPrefsList({
  entries,
  disabled,
  onDeleteKey,
}: {
  entries: ClassifiedPrefEntry[]
  disabled?: boolean
  onDeleteKey: (key: string) => void
}) {
  const listRef = useRef<HTMLDivElement | null>(null)
  const categoryHeadingRefs = useRef(new Map<string, HTMLDivElement>())
  const [activeCategory, setActiveCategory] = useState<string | null>(null)

  const categories = useMemo(() => collectPrefCategories(entries), [entries])
  const groups = useMemo(() => groupPrefsByCategory(entries), [entries])

  useEffect(() => {
    if (categories.length === 0) {
      setActiveCategory(null)
      return
    }
    setActiveCategory((current) =>
      current && categories.includes(current) ? current : categories[0]!,
    )
  }, [categories])

  useEffect(() => {
    const root = listRef.current
    if (!root || categories.length === 0) return

    const observer = new IntersectionObserver(
      (observerEntries) => {
        const visible = observerEntries
          .filter((entry) => entry.isIntersecting)
          .sort(
            (a, b) =>
              a.boundingClientRect.top - b.boundingClientRect.top,
          )
        const first = visible[0]
        if (!first) return
        const category = (first.target as HTMLElement).dataset.prefCategory
        if (category) setActiveCategory(category)
      },
      {
        root,
        rootMargin: '-8px 0px -70% 0px',
        threshold: [0, 0.25, 0.5, 1],
      },
    )

    for (const category of categories) {
      const node = categoryHeadingRefs.current.get(category)
      if (node) observer.observe(node)
    }

    return () => observer.disconnect()
  }, [categories, entries])

  const scrollToCategory = (category: string) => {
    const root = listRef.current
    const heading = categoryHeadingRefs.current.get(category)
    if (!root || !heading) return
    setActiveCategory(category)
    const rootRect = root.getBoundingClientRect()
    const headingRect = heading.getBoundingClientRect()
    root.scrollTo({
      top: Math.max(0, root.scrollTop + (headingRect.top - rootRect.top) - 4),
      behavior: 'smooth',
    })
  }

  if (entries.length === 0) {
    return (
      <p className="px-3 py-4 text-center text-[11px] text-[var(--network-globe-edge)]/70">
        No preferences stored.
      </p>
    )
  }

  return (
    <div className="flex max-h-[min(48dvh,420px)] gap-2">
      <nav
        aria-label="Preference categories"
        className="flex w-[120px] shrink-0 flex-col gap-0.5 overflow-y-auto overscroll-contain border-e border-[color-mix(in_srgb,var(--network-globe-edge)_15%,var(--border))] pe-2"
      >
        {categories.map((category) => {
          const isActive = category === activeCategory
          const count = categoryNavCount(groups, category)
          const isUnknown = category === UNKNOWN_PREF_CATEGORY
          return (
            <button
              key={category}
              type="button"
              onClick={() => scrollToCategory(category)}
              className={cn(
                'flex items-center justify-between gap-1 rounded-md px-2 py-1.5 text-start text-[10px] font-medium leading-snug transition-colors',
                isActive
                  ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,transparent)] text-foreground'
                  : 'text-[var(--network-globe-edge)]/75 hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)] hover:text-foreground',
                isUnknown && count > 0 && !isActive && 'text-red-300/90',
              )}
            >
              <span className="min-w-0 truncate">{category}</span>
              <span
                className={cn(
                  'shrink-0 tabular-nums',
                  isUnknown && count > 0
                    ? 'text-red-300/80'
                    : 'text-[var(--network-globe-edge)]/55',
                )}
              >
                {count}
              </span>
            </button>
          )
        })}
      </nav>
      <div
        ref={listRef}
        className="min-w-0 flex-1 space-y-3 overflow-y-auto overscroll-contain"
      >
        {groups.map((group) => {
          const isUnknown = group.category === UNKNOWN_PREF_CATEGORY
          return (
            <section key={group.category} className="space-y-1.5">
              <div
                ref={(node) => {
                  if (node) categoryHeadingRefs.current.set(group.category, node)
                  else categoryHeadingRefs.current.delete(group.category)
                }}
                data-pref-category={group.category}
                className="sticky top-0 z-[1]"
              >
                <div
                  className={cn(
                    'flex items-center gap-2 rounded-md border px-2.5 py-1.5 backdrop-blur-md',
                    isUnknown
                      ? 'border-red-500/25 bg-[color-mix(in_srgb,var(--background)_82%,rgb(127_29_29))] shadow-[0_1px_0_0_color-mix(in_srgb,rgb(239_68_68)_12%,transparent)]'
                      : 'border-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--border))] bg-[color-mix(in_srgb,var(--background)_82%,transparent)] shadow-[0_1px_0_0_color-mix(in_srgb,var(--network-globe-edge)_8%,transparent)]',
                  )}
                >
                  <span
                    className={cn(
                      'shrink-0 text-[11px] font-medium',
                      isUnknown ? 'text-red-200/95' : 'text-foreground/90',
                    )}
                  >
                    {group.category}
                  </span>
                  <span
                    aria-hidden
                    className={cn(
                      'h-px min-w-3 flex-1',
                      isUnknown
                        ? 'bg-red-500/25'
                        : 'bg-[color-mix(in_srgb,var(--network-globe-edge)_22%,var(--border))]',
                    )}
                  />
                  <span
                    className={cn(
                      'shrink-0 rounded px-1.5 py-0.5 text-[10px] tabular-nums',
                      isUnknown
                        ? 'bg-red-500/15 text-red-200/90'
                        : 'bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] text-[var(--network-globe-edge)]/80',
                    )}
                  >
                    {group.entries.length}
                  </span>
                </div>
              </div>
              {group.entries.length === 0 ? (
                <p className="px-2.5 py-2 text-[11px] leading-snug text-[var(--network-globe-edge)]/70">
                  {isUnknown
                    ? 'No unregistered keys. Every stored key matched the prefs catalog.'
                    : 'No keys in this category.'}
                </p>
              ) : (
                group.entries.map((entry) => (
                  <div
                    key={entry.key}
                    className={cn(
                      'rounded-lg border px-3 py-2.5',
                      entry.known
                        ? 'border-emerald-500/25 bg-emerald-500/5'
                        : 'border-red-500/30 bg-red-500/5',
                    )}
                  >
                    <div className="flex items-start gap-2">
                      <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <code
                            className={cn(
                              'break-all text-[11px] font-medium',
                              entry.known ? 'text-emerald-300' : 'text-red-300',
                            )}
                          >
                            {entry.key}
                          </code>
                          {entry.catalog?.legacy ? (
                            <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wide text-amber-200/90">
                              Legacy
                            </span>
                          ) : null}
                          {!entry.known ? (
                            <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wide text-red-200/90">
                              Unknown
                            </span>
                          ) : null}
                        </div>
                        <p className="text-[11px] leading-snug text-[var(--network-globe-edge)]/85">
                          {entry.catalog?.description ??
                            'Not registered in the prefs catalog. Safe to remove if unused.'}
                        </p>
                        <p className="break-all font-mono text-[10px] text-[var(--network-globe-edge)]/65">
                          {entry.preview || '(empty)'}
                        </p>
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 w-7 shrink-0 p-0 text-[var(--network-globe-edge)]/70 hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
                        disabled={disabled}
                        aria-label={`Delete ${entry.key}`}
                        onClick={() => onDeleteKey(entry.key)}
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                ))
              )}
            </section>
          )
        })}
      </div>
    </div>
  )
}

const tabListClass =
  'grid h-9 w-full grid-cols-2 gap-0 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/50 p-[3px] text-[var(--network-globe-edge)]/90'

const tabTriggerClass =
  'rounded-md border border-transparent px-2 py-1 text-[12px] font-medium text-foreground/85 transition-colors hover:text-foreground data-[state=active]:border-[color-mix(in_srgb,var(--network-globe-edge)_35%,var(--border))] data-[state=active]:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] data-[state=active]:text-foreground data-[state=inactive]:text-[var(--network-globe-edge)]/75'

const editorShellClass =
  'min-h-[min(42dvh,320px)] flex-1 overflow-hidden rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40'

const structuredShellClass =
  'min-h-[min(42dvh,320px)] rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/30 p-2'

type PrefsViewMode = 'structured' | 'json'

export function DebugMenuPrefsPanel() {
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const params = useParams({ strict: false }) as {
    orgId?: string
    projectId?: string
  }

  const orgId = typeof params.orgId === 'string' ? params.orgId : undefined
  const projectId =
    typeof params.projectId === 'string' ? params.projectId : undefined
  const { project } = useProject(projectId)

  const resolvedTeamId = useMemo(
    () => orgId || project?.teamId || null,
    [orgId, project?.teamId],
  )

  const teamSource = orgId
    ? 'URL organization'
    : project?.teamId
      ? 'Project organization'
      : null

  const {
    data: account,
    isLoading: accountLoading,
    error: accountError,
    refetch: refetchAccount,
  } = useQuery({
    queryKey: ['account', 'console', consoleImpersonationRevision],
    queryFn: () =>
      fetchConsoleAccount({ revision: consoleImpersonationRevision }),
    staleTime: CONSOLE_ACCOUNT_STALE_TIME_MS,
    retry: false,
  })

  const {
    data: team,
    isLoading: teamLoading,
    error: teamError,
    refetch: refetchTeam,
  } = useConsoleTeam(resolvedTeamId)

  const [accountDraft, setAccountDraft] = useState('')
  const [teamDraft, setTeamDraft] = useState('')
  const [accountSearch, setAccountSearch] = useState('')
  const [teamSearch, setTeamSearch] = useState('')
  const [accountView, setAccountView] = useState<PrefsViewMode>('structured')
  const [teamView, setTeamView] = useState<PrefsViewMode>('structured')
  const [accountBusy, setAccountBusy] = useState(false)
  const [teamBusy, setTeamBusy] = useState(false)
  const accountEditorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
  const teamEditorRef = useRef<editor.IStandaloneCodeEditor | null>(null)

  const accountPrefsObject = useMemo(() => {
    try {
      return accountDraft ? parsePrefsJson(accountDraft) : {}
    } catch {
      return (account?.prefs as Record<string, unknown> | undefined) ?? {}
    }
  }, [accountDraft, account?.prefs])

  const teamPrefsObject = useMemo(() => {
    try {
      return teamDraft ? parsePrefsJson(teamDraft) : {}
    } catch {
      return (team?.prefs as Record<string, unknown> | undefined) ?? {}
    }
  }, [teamDraft, team?.prefs])

  const accountClassified = useMemo(
    () => classifyPrefs(accountPrefsObject, 'account'),
    [accountPrefsObject],
  )
  const teamClassified = useMemo(
    () => classifyPrefs(teamPrefsObject, 'team'),
    [teamPrefsObject],
  )

  const accountFiltered = useMemo(
    () => filterClassifiedPrefs(accountClassified, accountSearch),
    [accountClassified, accountSearch],
  )
  const teamFiltered = useMemo(
    () => filterClassifiedPrefs(teamClassified, teamSearch),
    [teamClassified, teamSearch],
  )

  const accountSummary = useMemo(
    () => summarizePrefsClassification(accountClassified),
    [accountClassified],
  )
  const teamSummary = useMemo(
    () => summarizePrefsClassification(teamClassified),
    [teamClassified],
  )

  const accountSearchMatches = useMemo(
    () => getMatchingPrefKeys(accountDraft, accountSearch),
    [accountDraft, accountSearch],
  )

  const teamSearchMatches = useMemo(
    () => getMatchingPrefKeys(teamDraft, teamSearch),
    [teamDraft, teamSearch],
  )

  const handleAccountEditorMount = useCallback(
    (editorInstance: editor.IStandaloneCodeEditor) => {
      accountEditorRef.current = editorInstance
    },
    [],
  )

  const handleTeamEditorMount = useCallback(
    (editorInstance: editor.IStandaloneCodeEditor) => {
      teamEditorRef.current = editorInstance
    },
    [],
  )

  useEffect(() => {
    return () => {
      accountEditorRef.current = null
      teamEditorRef.current = null
    }
  }, [])

  useEffect(() => {
    if (!account) return
    setAccountDraft(stringifyPrefs(account.prefs as Record<string, unknown>))
  }, [account])

  useEffect(() => {
    if (!resolvedTeamId) {
      setTeamDraft('')
      return
    }
    if (!team) {
      setTeamDraft('{}')
      return
    }
    setTeamDraft(stringifyPrefs(team.prefs as Record<string, unknown>))
  }, [resolvedTeamId, team])

  const invalidateAccount = useCallback(async () => {
    const refreshed = await fetchConsoleAccount({
      revision: consoleImpersonationRevision,
      force: true,
    })
    commitConsoleAccountToCaches(
      queryClient,
      refreshed,
      consoleImpersonationRevision,
    )
  }, [consoleImpersonationRevision, queryClient])

  const invalidateTeam = useCallback(() => {
    if (!resolvedTeamId) return
    void queryClient.invalidateQueries({
      queryKey: ['team', 'console', resolvedTeamId],
    })
  }, [queryClient, resolvedTeamId])

  const copyText = async (label: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success(`${label} copied`)
    } catch {
      toast.error('Could not copy to clipboard')
    }
  }

  const applyAccountPrefs = async () => {
    let next: Record<string, unknown>
    try {
      next = parsePrefsJson(accountDraft)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Invalid JSON')
      return
    }
    setAccountBusy(true)
    try {
      await updateAccountPrefs(next)
      invalidateAccount()
      await refetchAccount()
      toast.success('Account prefs updated')
    } catch (e) {
      const msg =
        e instanceof AppwriteException ? e.message : (e as Error).message
      toast.error(msg || 'Failed to update account prefs')
    } finally {
      setAccountBusy(false)
    }
  }

  const applyTeamPrefs = async () => {
    if (!resolvedTeamId) return
    let next: Record<string, unknown>
    try {
      next = parsePrefsJson(teamDraft)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Invalid JSON')
      return
    }
    setTeamBusy(true)
    try {
      await updateConsoleTeamPrefs(resolvedTeamId, next, { mode: 'replace' })
      invalidateTeam()
      await refetchTeam()
      toast.success('Team prefs updated')
    } catch (e) {
      const msg =
        e instanceof AppwriteException ? e.message : (e as Error).message
      toast.error(msg || 'Failed to update team prefs')
    } finally {
      setTeamBusy(false)
    }
  }

  const resetAccountPrefs = async () => {
    if (
      !window.confirm(
        'Clear all account preference keys? This sends an empty prefs object to the server.',
      )
    ) {
      return
    }
    setAccountBusy(true)
    try {
      await updateAccountPrefs({})
      invalidateAccount()
      const { data } = await refetchAccount()
      setAccountDraft(stringifyPrefs(data?.prefs as Record<string, unknown>))
      const remaining = Object.keys((data?.prefs as object) || {}).length
      if (remaining > 0) {
        toast.message(
          'Some keys may still be present if the API merges prefs. Remove them with Delete key or edit JSON.',
        )
      } else {
        toast.success('Account prefs cleared')
      }
    } catch (e) {
      const msg =
        e instanceof AppwriteException ? e.message : (e as Error).message
      toast.error(msg || 'Failed to reset account prefs')
    } finally {
      setAccountBusy(false)
    }
  }

  const resetTeamPrefs = async () => {
    if (!resolvedTeamId) return
    if (
      !window.confirm(
        'Clear all team preference keys? This sends an empty prefs object to the server.',
      )
    ) {
      return
    }
    setTeamBusy(true)
    try {
      await updateConsoleTeamPrefs(resolvedTeamId, {}, { mode: 'replace' })
      invalidateTeam()
      const { data } = await refetchTeam()
      setTeamDraft(stringifyPrefs(data?.prefs as Record<string, unknown>))
      const remaining = Object.keys((data?.prefs as object) || {}).length
      if (remaining > 0) {
        toast.message(
          'Some keys may still be present if the API merges prefs. Remove them with Delete key or edit JSON.',
        )
      } else {
        toast.success('Team prefs cleared')
      }
    } catch (e) {
      const msg =
        e instanceof AppwriteException ? e.message : (e as Error).message
      toast.error(msg || 'Failed to reset team prefs')
    } finally {
      setTeamBusy(false)
    }
  }

  const deletePrefKey = async (
    scope: PrefsRuntimeScope,
    key: string,
    confirmPrompt = true,
  ) => {
    if (scope === 'team' && !resolvedTeamId) return
    if (
      confirmPrompt &&
      !window.confirm(`Remove preference key "${key}"?`)
    ) {
      return
    }

    if (scope === 'account') {
      setAccountBusy(true)
      try {
        const base = parsePrefsJson(accountDraft)
        if (!(key in base)) {
          toast.error(`Key "${key}" is not in the current draft`)
          return
        }
        const rest = { ...base }
        delete rest[key]
        await updateAccountPrefs(rest)
        invalidateAccount()
        await refetchAccount()
        setAccountDraft(stringifyPrefs(rest))
        toast.success(`Removed "${key}"`)
      } catch (e) {
        const msg =
          e instanceof AppwriteException
            ? e.message
            : e instanceof Error
              ? e.message
              : 'Failed'
        toast.error(msg)
      } finally {
        setAccountBusy(false)
      }
      return
    }

    setTeamBusy(true)
    try {
      const base = parsePrefsJson(teamDraft)
      if (!(key in base)) {
        toast.error(`Key "${key}" is not in the current draft`)
        return
      }
      const rest = { ...base }
      delete rest[key]
      await updateConsoleTeamPrefs(resolvedTeamId!, rest, { mode: 'replace' })
      invalidateTeam()
      await refetchTeam()
      setTeamDraft(stringifyPrefs(rest))
      toast.success(`Removed "${key}"`)
    } catch (e) {
      const msg =
        e instanceof AppwriteException
          ? e.message
          : e instanceof Error
            ? e.message
            : 'Failed'
      toast.error(msg)
    } finally {
      setTeamBusy(false)
    }
  }

  const deleteAccountKey = async () => {
    const key = window.prompt('Account prefs: key to remove')?.trim()
    if (!key) return
    await deletePrefKey('account', key, false)
  }

  const deleteTeamKey = async () => {
    if (!resolvedTeamId) return
    const key = window.prompt('Team prefs: key to remove')?.trim()
    if (!key) return
    await deletePrefKey('team', key, false)
  }

  const setAccountKey = async () => {
    const key = window.prompt('Account prefs: key')?.trim()
    if (!key) return
    const raw = window.prompt('Value (JSON or plain text)', '') ?? ''
    setAccountBusy(true)
    try {
      const base = parsePrefsJson(accountDraft)
      const value = parseValueInput(raw)
      const next = { ...base, [key]: value }
      await updateAccountPrefs(next)
      invalidateAccount()
      await refetchAccount()
      setAccountDraft(stringifyPrefs(next))
      toast.success(`Set "${key}"`)
    } catch (e) {
      const msg =
        e instanceof AppwriteException
          ? e.message
          : e instanceof Error
            ? e.message
            : 'Failed'
      toast.error(msg)
    } finally {
      setAccountBusy(false)
    }
  }

  const setTeamKey = async () => {
    if (!resolvedTeamId) return
    const key = window.prompt('Team prefs: key')?.trim()
    if (!key) return
    const raw = window.prompt('Value (JSON or plain text)', '') ?? ''
    setTeamBusy(true)
    try {
      const base = parsePrefsJson(teamDraft)
      const value = parseValueInput(raw)
      const next = { ...base, [key]: value }
      await updateConsoleTeamPrefs(resolvedTeamId, next, { mode: 'replace' })
      invalidateTeam()
      await refetchTeam()
      setTeamDraft(stringifyPrefs(next))
      toast.success(`Set "${key}"`)
    } catch (e) {
      const msg =
        e instanceof AppwriteException
          ? e.message
          : e instanceof Error
            ? e.message
            : 'Failed'
      toast.error(msg)
    } finally {
      setTeamBusy(false)
    }
  }

  const accountErrMsg =
    accountError instanceof Error
      ? accountError.message
      : accountError != null
        ? String(accountError)
        : null

  const teamErrMsg =
    teamError instanceof Error
      ? teamError.message
      : teamError != null
        ? String(teamError)
        : null

  const accountIdLabel =
    account && typeof account.$id === 'string' ? account.$id : null

  return (
    <div className="flex flex-col gap-3 px-1" aria-label="User and team preferences">
      <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/90">
        Structured view classifies keys against{' '}
        <code className="rounded bg-muted/50 px-1 text-[10px] text-foreground/90">
          prefs-catalog
        </code>
        . Known keys are green; unknown keys are red. JSON remains available for
        bulk edits. Team scope uses the organization in the URL when present,
        otherwise the current project&apos;s team.
      </p>

      <Tabs defaultValue="account" className="gap-3">
        <TabsList className={tabListClass}>
          <TabsTrigger value="account" className={tabTriggerClass}>
            Account
            {accountLoading && (
              <Loader2 className="ms-1 h-3 w-3 shrink-0 animate-spin text-[var(--network-globe-edge)]" />
            )}
          </TabsTrigger>
          <TabsTrigger value="team" className={tabTriggerClass}>
            Team
            {resolvedTeamId && teamLoading && (
              <Loader2 className="ms-1 h-3 w-3 shrink-0 animate-spin text-[var(--network-globe-edge)]" />
            )}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="account" className="mt-0 flex flex-col gap-2">
          {accountIdLabel && (
            <p className="text-[10px] text-[var(--network-globe-edge)]/75">
              User{' '}
              <code className="rounded bg-muted/50 px-1 text-foreground/90">
                {accountIdLabel}
              </code>
            </p>
          )}
          {accountErrMsg && (
            <p className="text-[11px] text-amber-300/90">{accountErrMsg}</p>
          )}
          <div className="flex items-center justify-between gap-2">
            <Tabs
              value={accountView}
              onValueChange={(value) =>
                setAccountView(value as PrefsViewMode)
              }
              className="w-full max-w-[220px]"
            >
              <TabsList className={cn(tabListClass, 'h-8')}>
                <TabsTrigger value="structured" className={cn(tabTriggerClass, 'text-[11px]')}>
                  Structured
                </TabsTrigger>
                <TabsTrigger value="json" className={cn(tabTriggerClass, 'text-[11px]')}>
                  JSON
                </TabsTrigger>
              </TabsList>
            </Tabs>
            <p className="shrink-0 text-[10px] text-[var(--network-globe-edge)]/75">
              <span className="text-emerald-300">{accountSummary.known} known</span>
              {' · '}
              <span className="text-red-300">{accountSummary.unknown} unknown</span>
            </p>
          </div>
          <PrefsSearchBar
            value={accountSearch}
            onChange={setAccountSearch}
            matches={accountSearchMatches}
            onSelectKey={(key) => {
              if (accountView === 'json') {
                scrollEditorToPrefKey(accountEditorRef.current, key)
              }
            }}
            showMatchList={accountView === 'json'}
            disabled={accountLoading || accountBusy || !account}
          />
          {accountView === 'structured' ? (
            <div className={structuredShellClass}>
              <StructuredPrefsList
                entries={accountFiltered}
                disabled={accountLoading || accountBusy || !account}
                onDeleteKey={(key) => void deletePrefKey('account', key)}
              />
            </div>
          ) : (
            <div className={cn(editorShellClass, 'flex flex-col')}>
              <CodeEditor
                modelPath="debug-menu/prefs/account.json"
                language="json"
                value={accountDraft}
                onChange={setAccountDraft}
                onEditorMount={handleAccountEditorMount}
                readOnly={accountLoading || accountBusy || !account}
                minimap={false}
                lineNumbers="on"
                height="min(42dvh, 320px)"
                className="min-h-0 flex-1 rounded-none border-0 bg-transparent"
              />
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant="secondary"
              className="h-8 text-[11px]"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void refetchAccount()}
            >
              Reload
            </Button>
            <Button
              type="button"
              size="sm"
              variant="secondary"
              className="h-8 text-[11px]"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void copyText('Account prefs', accountDraft)}
            >
              Copy
            </Button>
            {accountView === 'json' ? (
              <Button
                type="button"
                size="sm"
                className="h-8 text-[11px]"
                disabled={accountLoading || accountBusy || !account}
                onClick={() => void applyAccountPrefs()}
              >
                Apply
              </Button>
            ) : null}
            <Button
              type="button"
              size="sm"
              variant="secondary"
              className="h-8 text-[11px]"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void setAccountKey()}
            >
              Set key…
            </Button>
            <Button
              type="button"
              size="sm"
              variant="secondary"
              className="h-8 text-[11px]"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void deleteAccountKey()}
            >
              Delete key…
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="h-8 border-amber-500/40 text-[11px] text-amber-200/90 hover:bg-amber-500/10"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void resetAccountPrefs()}
            >
              Reset all
            </Button>
          </div>
        </TabsContent>

        <TabsContent value="team" className="mt-0 flex flex-col gap-2">
          {!resolvedTeamId ? (
            <p className="text-[11px] text-[var(--network-globe-edge)]/80">
              No team in context. Open an organization or a project route to
              load team preferences.
            </p>
          ) : (
            <>
              <p className="text-[10px] text-[var(--network-globe-edge)]/75">
                Team{' '}
                <code className="rounded bg-muted/50 px-1 text-foreground/90">
                  {resolvedTeamId}
                </code>
                {teamSource ? ` · ${teamSource}` : null}
              </p>
              {teamErrMsg && (
                <p className="text-[11px] text-amber-300/90">{teamErrMsg}</p>
              )}
              <div className="flex items-center justify-between gap-2">
                <Tabs
                  value={teamView}
                  onValueChange={(value) => setTeamView(value as PrefsViewMode)}
                  className="w-full max-w-[220px]"
                >
                  <TabsList className={cn(tabListClass, 'h-8')}>
                    <TabsTrigger
                      value="structured"
                      className={cn(tabTriggerClass, 'text-[11px]')}
                    >
                      Structured
                    </TabsTrigger>
                    <TabsTrigger
                      value="json"
                      className={cn(tabTriggerClass, 'text-[11px]')}
                    >
                      JSON
                    </TabsTrigger>
                  </TabsList>
                </Tabs>
                <p className="shrink-0 text-[10px] text-[var(--network-globe-edge)]/75">
                  <span className="text-emerald-300">
                    {teamSummary.known} known
                  </span>
                  {' · '}
                  <span className="text-red-300">
                    {teamSummary.unknown} unknown
                  </span>
                </p>
              </div>
              <PrefsSearchBar
                value={teamSearch}
                onChange={setTeamSearch}
                matches={teamSearchMatches}
                onSelectKey={(key) => {
                  if (teamView === 'json') {
                    scrollEditorToPrefKey(teamEditorRef.current, key)
                  }
                }}
                showMatchList={teamView === 'json'}
                disabled={teamLoading || teamBusy || !team}
              />
              {teamView === 'structured' ? (
                <div className={structuredShellClass}>
                  <StructuredPrefsList
                    entries={teamFiltered}
                    disabled={teamLoading || teamBusy || !team}
                    onDeleteKey={(key) => void deletePrefKey('team', key)}
                  />
                </div>
              ) : (
                <div className={cn(editorShellClass, 'flex flex-col')}>
                  <CodeEditor
                    modelPath="debug-menu/prefs/team.json"
                    language="json"
                    value={teamDraft}
                    onChange={setTeamDraft}
                    onEditorMount={handleTeamEditorMount}
                    readOnly={teamLoading || teamBusy || !team}
                    minimap={false}
                    lineNumbers="on"
                    height="min(42dvh, 320px)"
                    className="min-h-0 flex-1 rounded-none border-0 bg-transparent"
                  />
                </div>
              )}
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  className="h-8 text-[11px]"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void refetchTeam()}
                >
                  Reload
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  className="h-8 text-[11px]"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void copyText('Team prefs', teamDraft)}
                >
                  Copy
                </Button>
                {teamView === 'json' ? (
                  <Button
                    type="button"
                    size="sm"
                    className="h-8 text-[11px]"
                    disabled={teamLoading || teamBusy || !team}
                    onClick={() => void applyTeamPrefs()}
                  >
                    Apply
                  </Button>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  className="h-8 text-[11px]"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void setTeamKey()}
                >
                  Set key…
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  className="h-8 text-[11px]"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void deleteTeamKey()}
                >
                  Delete key…
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="h-8 border-amber-500/40 text-[11px] text-amber-200/90 hover:bg-amber-500/10"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void resetTeamPrefs()}
                >
                  Reset all
                </Button>
              </div>
            </>
          )}
        </TabsContent>
      </Tabs>
    </div>
  )
}
