import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { AppwriteException } from '@appwrite.io/console'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import {
  updateAccountPrefs,
  updateConsoleTeamPrefs,
  useConsoleTeam,
  useProject,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
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

const tabListClass =
  'grid h-9 w-full grid-cols-2 gap-0 rounded-lg border border-[#9B87F5]/25 bg-black/30 p-[3px] text-[#9B87F5]/90'

const tabTriggerClass =
  'rounded-md border border-transparent px-2 py-1 text-[12px] font-medium text-[#E5DEFF]/85 transition-colors hover:text-[#E5DEFF] data-[state=active]:border-[#9B87F5]/35 data-[state=active]:bg-[#9B87F5]/20 data-[state=active]:text-[#E5DEFF] data-[state=inactive]:text-[#9B87F5]/75'

const editorShellClass =
  'min-h-[min(42dvh,320px)] flex-1 overflow-hidden rounded-lg border border-[#9B87F5]/25 bg-black/20'

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
    queryFn: () => sdk.forConsole.account.get(),
    staleTime: 0,
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
  const [accountBusy, setAccountBusy] = useState(false)
  const [teamBusy, setTeamBusy] = useState(false)

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

  const invalidateAccount = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
  }, [queryClient])

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
      <p className="text-[11px] leading-relaxed text-[#9B87F5]/90">
        Edits call the Console API directly. Use{' '}
        <span className="font-medium text-[#E5DEFF]">Apply</span> after editing
        JSON, or the helpers below. Team scope uses the organization in the URL
        when present, otherwise the current project&apos;s team.
      </p>

      <Tabs defaultValue="account" className="gap-3">
        <TabsList className={tabListClass}>
          <TabsTrigger value="account" className={tabTriggerClass}>
            Account prefs
            {accountLoading && (
              <Loader2 className="ml-1 h-3 w-3 shrink-0 animate-spin text-[#9B87F5]" />
            )}
          </TabsTrigger>
          <TabsTrigger value="team" className={tabTriggerClass}>
            Team prefs
            {resolvedTeamId && teamLoading && (
              <Loader2 className="ml-1 h-3 w-3 shrink-0 animate-spin text-[#9B87F5]" />
            )}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="account" className="mt-0 flex flex-col gap-2">
          {accountIdLabel && (
            <p className="text-[10px] text-[#9B87F5]/75">
              User{' '}
              <code className="rounded bg-black/30 px-1 text-[#E5DEFF]/90">
                {accountIdLabel}
              </code>
            </p>
          )}
          {accountErrMsg && (
            <p className="text-[11px] text-amber-300/90">{accountErrMsg}</p>
          )}
          <div className={cn(editorShellClass, 'flex flex-col')}>
            <CodeEditor
              modelPath="debug-menu/prefs/account.json"
              language="json"
              value={accountDraft}
              onChange={setAccountDraft}
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
            <p className="text-[11px] text-[#9B87F5]/80">
              No team in context. Open an organization or a project route to
              load team preferences.
            </p>
          ) : (
            <>
              <p className="text-[10px] text-[#9B87F5]/75">
                Team{' '}
                <code className="rounded bg-black/30 px-1 text-[#E5DEFF]/90">
                  {resolvedTeamId}
                </code>
                {teamSource ? ` · ${teamSource}` : null}
              </p>
              {teamErrMsg && (
                <p className="text-[11px] text-amber-300/90">{teamErrMsg}</p>
              )}
              <div className={cn(editorShellClass, 'flex flex-col')}>
                <CodeEditor
                  modelPath="debug-menu/prefs/team.json"
                  language="json"
                  value={teamDraft}
                  onChange={setTeamDraft}
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
