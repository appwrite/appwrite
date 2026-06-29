import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { AppwriteException } from '@appwrite.io/console'
import type { editor } from 'monaco-editor'
import { Loader2, Search, X } from 'lucide-react'
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
}: {
  value: string
  onChange: (value: string) => void
  matches: PrefSearchMatch[]
  onSelectKey: (key: string) => void
  disabled?: boolean
}) {
  const trimmed = value.trim()

  return (
    <div className="flex flex-col gap-1.5">
      <div className="relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--network-globe-edge)]/60" />
        <Input
          value={value}
          onChange={(event) => onChange(event.target.value)}
          disabled={disabled}
          placeholder="Search keys or values…"
          className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 pl-8 pr-8 text-[12px] text-foreground placeholder:text-[var(--network-globe-edge)]/50"
        />
        {value && (
          <button
            type="button"
            onClick={() => onChange('')}
            disabled={disabled}
            className="absolute right-2 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground disabled:opacity-50"
            aria-label="Clear search"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {trimmed ? (
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
                    className="flex w-full flex-col gap-0.5 px-2.5 py-2 text-left transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)] disabled:opacity-50"
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

const tabListClass =
  'grid h-9 w-full grid-cols-2 gap-0 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/50 p-[3px] text-[var(--network-globe-edge)]/90'

const tabTriggerClass =
  'rounded-md border border-transparent px-2 py-1 text-[12px] font-medium text-foreground/85 transition-colors hover:text-foreground data-[state=active]:border-[color-mix(in_srgb,var(--network-globe-edge)_35%,var(--border))] data-[state=active]:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] data-[state=active]:text-foreground data-[state=inactive]:text-[var(--network-globe-edge)]/75'

const editorShellClass =
  'min-h-[min(42dvh,320px)] flex-1 overflow-hidden rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40'

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
  const [accountBusy, setAccountBusy] = useState(false)
  const [teamBusy, setTeamBusy] = useState(false)
  const accountEditorRef = useRef<editor.IStandaloneCodeEditor | null>(null)
  const teamEditorRef = useRef<editor.IStandaloneCodeEditor | null>(null)

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
      await updateConsoleTeamPrefs(resolvedTeamId, next)
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
      await updateConsoleTeamPrefs(resolvedTeamId, {})
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

  const deleteAccountKey = async () => {
    const key = window.prompt('Account prefs: key to remove')?.trim()
    if (!key) return
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
  }

  const deleteTeamKey = async () => {
    if (!resolvedTeamId) return
    const key = window.prompt('Team prefs: key to remove')?.trim()
    if (!key) return
    setTeamBusy(true)
    try {
      const base = parsePrefsJson(teamDraft)
      if (!(key in base)) {
        toast.error(`Key "${key}" is not in the current draft`)
        return
      }
      const rest = { ...base }
      delete rest[key]
      await updateConsoleTeamPrefs(resolvedTeamId, rest)
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
      await updateConsoleTeamPrefs(resolvedTeamId, next)
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
        Edits call the Console API directly. Use{' '}
        <span className="font-medium text-foreground">Apply</span> after editing
        JSON, or the helpers below. Team scope uses the organization in the URL
        when present, otherwise the current project&apos;s team.
      </p>

      <Tabs defaultValue="account" className="gap-3">
        <TabsList className={tabListClass}>
          <TabsTrigger value="account" className={tabTriggerClass}>
            Account prefs
            {accountLoading && (
              <Loader2 className="ml-1 h-3 w-3 shrink-0 animate-spin text-[var(--network-globe-edge)]" />
            )}
          </TabsTrigger>
          <TabsTrigger value="team" className={tabTriggerClass}>
            Team prefs
            {resolvedTeamId && teamLoading && (
              <Loader2 className="ml-1 h-3 w-3 shrink-0 animate-spin text-[var(--network-globe-edge)]" />
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
          <PrefsSearchBar
            value={accountSearch}
            onChange={setAccountSearch}
            matches={accountSearchMatches}
            onSelectKey={(key) =>
              scrollEditorToPrefKey(accountEditorRef.current, key)
            }
            disabled={accountLoading || accountBusy || !account}
          />
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
            <Button
              type="button"
              size="sm"
              className="h-8 text-[11px]"
              disabled={accountLoading || accountBusy || !account}
              onClick={() => void applyAccountPrefs()}
            >
              Apply
            </Button>
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
              <PrefsSearchBar
                value={teamSearch}
                onChange={setTeamSearch}
                matches={teamSearchMatches}
                onSelectKey={(key) =>
                  scrollEditorToPrefKey(teamEditorRef.current, key)
                }
                disabled={teamLoading || teamBusy || !team}
              />
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
                <Button
                  type="button"
                  size="sm"
                  className="h-8 text-[11px]"
                  disabled={teamLoading || teamBusy || !team}
                  onClick={() => void applyTeamPrefs()}
                >
                  Apply
                </Button>
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
