import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import {
  useTeam,
  useUpdateTeamName,
  useUpdateTeamPrefs,
  useDeleteProjectTeam,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Plus, Trash2 } from 'lucide-react'
import { useHashScroll } from '@/lib/hooks/useHashScroll'
import { useT } from '@/lib/i18n/translate'

export function TeamOverview() {
  const t = useT()
  const { projectId, teamId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()

  const { data: team, isLoading } = useTeam(projectId, teamId)
  const updateNameMutation = useUpdateTeamName(projectId, teamId)
  const updatePrefsMutation = useUpdateTeamPrefs(projectId, teamId)
  const deleteTeamMutation = useDeleteProjectTeam(projectId)

  const [teamName, setTeamName] = useState('')
  const [preferences, setPreferences] = useState<
    Array<{ key: string; value: string }>
  >([{ key: '', value: '' }])
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Initialize form values from team data
  useEffect(() => {
    if (team) {
      setTeamName(team.name || '')
      // Convert prefs object to array of key-value pairs
      const prefsArray = Object.entries(team.prefs || {}).map(
        ([key, value]) => ({
          key,
          value: String(value),
        }),
      )
      setPreferences(
        prefsArray.length > 0 ? prefsArray : [{ key: '', value: '' }],
      )
    }
  }, [team])

  // Check if name has changed
  const nameChanged = useMemo(() => {
    return teamName !== (team?.name || '')
  }, [teamName, team?.name])

  // Check if preferences have changed
  const prefsChanged = useMemo(() => {
    if (!team) return false

    const currentPrefs = preferences
      .filter((p) => p.key.trim() && p.value.trim())
      .reduce(
        (acc, { key, value }) => {
          acc[key.trim()] = value.trim()
          return acc
        },
        {} as Record<string, string>,
      )

    // Simple comparison using JSON.stringify for flat objects
    return JSON.stringify(currentPrefs) !== JSON.stringify(team.prefs || {})
  }, [preferences, team])

  // Check if last preference row is complete
  const lastPrefComplete = useMemo(() => {
    const last = preferences[preferences.length - 1]
    return last && last.key.trim() && last.value.trim()
  }, [preferences])

  const handleUpdateName = async () => {
    if (!teamName.trim()) {
      toast.error(t('Team name is required'))
      return
    }

    try {
      await updateNameMutation.mutateAsync(teamName.trim())
      toast.success(t('Name has been updated'))
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update name'),
      )
    }
  }

  const handleUpdatePrefs = async () => {
    // Filter out empty rows and build prefs object
    const prefs = preferences
      .filter((p) => p.key.trim() && p.value.trim())
      .reduce(
        (acc, { key, value }) => {
          acc[key.trim()] = value.trim()
          return acc
        },
        {} as Record<string, string>,
      )

    try {
      await updatePrefsMutation.mutateAsync(prefs)
      toast.success(t('Preferences have been updated'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update preferences'),
      )
    }
  }

  const handleDeleteTeam = async () => {
    if (!teamId) return

    try {
      await deleteTeamMutation.mutateAsync(teamId)
      toast.success(t('Team deleted successfully'))
      navigate({
        to: '/projects/$projectId/auth/teams',
        params: { projectId: projectId as string },
      })
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to delete team'),
      )
      setDeleteDialogOpen(false)
    }
  }

  const handleAddPreference = () => {
    if (lastPrefComplete) {
      setPreferences([...preferences, { key: '', value: '' }])
    }
  }

  const handleRemovePreference = (index: number) => {
    if (preferences.length > 1) {
      setPreferences(preferences.filter((_, i) => i !== index))
    } else {
      // Keep at least one row, just clear it
      setPreferences([{ key: '', value: '' }])
    }
  }

  const handlePreferenceChange = (
    index: number,
    field: 'key' | 'value',
    value: string,
  ) => {
    const newPrefs = [...preferences]
    newPrefs[index] = { ...newPrefs[index], [field]: value }
    setPreferences(newPrefs)
  }

  useHashScroll(!isLoading, location.hash)

  if (isLoading || !team) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading team...')}</div>
      </div>
    )
  }

  // Get total members count (from team.total or calculate from memberships)
  const totalMembers = team.total || 0

  return (
    <div className="space-y-6 overflow-x-hidden w-full min-w-0">
      {/* Team Status Card */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Status')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-start gap-4 flex-wrap">
            <InitialsAvatar name={team.name} size="lg" className="shrink-0" />
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1 flex-wrap">
                <p className="text-[15px] font-medium text-foreground truncate">
                  {team.name}
                </p>
              </div>
              <div className="space-y-1 text-[13px] text-muted-foreground">
                <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                  <span>{t('Team ID:')}</span>
                  <CopyableId id={team.$id} size="sm" />
                </p>
                <div className="flex items-center gap-1.5">
                  <span>
                    {totalMembers}{' '}
                    {totalMembers !== 1 ? t('members') : t('member')}
                  </span>
                </div>
                {team.$createdAt && (
                  <div className="flex items-center gap-1.5">
                    <span>{t('Created:')}</span>
                    <DateTooltip date={new Date(team.$createdAt)} />
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Update Name */}
      <div
        id="team-details"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Update name')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t("Update the team's display name.")}
          </p>
        </div>
        <form
          onSubmit={(e) => {
            e.preventDefault()
            handleUpdateName()
          }}
        >
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor="team-name">{t('Name')}</Label>
              <Input
                id="team-name"
                type="text"
                placeholder={t('Enter team name')}
                value={teamName}
                onChange={(e) => setTeamName(e.target.value)}
                disabled={updateNameMutation.isPending}
                className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                autoComplete="off"
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              type="submit"
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !nameChanged || !teamName.trim() || updateNameMutation.isPending
              }
            >
              {t('Update')}
            </Button>
          </div>
        </form>
      </div>

      {/* Update Preferences */}
      <div
        id="team-preferences"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Update preferences')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Update team preferences as key-value pairs.')}
          </p>
        </div>
        <form
          onSubmit={(e) => {
            e.preventDefault()
            handleUpdatePrefs()
          }}
        >
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-3">
              {preferences.map((pref, index) => (
                <div key={index} className="flex items-center gap-2">
                  <Input
                    type="text"
                    placeholder={t('Key')}
                    value={pref.key}
                    onChange={(e) =>
                      handlePreferenceChange(index, 'key', e.target.value)
                    }
                    disabled={updatePrefsMutation.isPending}
                    className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <Input
                    type="text"
                    placeholder={t('Value')}
                    value={pref.value}
                    onChange={(e) =>
                      handlePreferenceChange(index, 'value', e.target.value)
                    }
                    disabled={updatePrefsMutation.isPending}
                    className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-9 w-9 p-0"
                    onClick={() => handleRemovePreference(index)}
                    disabled={
                      preferences.length === 1 &&
                      !preferences[0].key &&
                      !preferences[0].value
                    }
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              ))}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleAddPreference}
                disabled={!lastPrefComplete || updatePrefsMutation.isPending}
              >
                <Plus className="me-1.5 h-3.5 w-3.5" />
                {t('Add preference')}
              </Button>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              type="submit"
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !prefsChanged ||
                !lastPrefComplete ||
                updatePrefsMutation.isPending
              }
            >
              {t('Update')}
            </Button>
          </div>
        </form>
      </div>

      {/* Delete Team */}
      <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Delete team')}
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Permanently delete this team from the project. This action cannot be undone.',
            )}
          </p>

          {/* Team Info Summary */}
          <div className="flex items-center gap-3 mt-4">
            <InitialsAvatar name={team.name} size="md" />
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {team.name}
              </p>
              <p className="text-[12px] text-muted-foreground">
                {(() => {
                  const parts = [
                    `${totalMembers} ${totalMembers !== 1 ? t('members') : t('member')}`,
                  ]
                  if (team.$createdAt) {
                    parts.push(
                      <>
                        {t('Created:')}{' '}
                        <DateTooltip date={new Date(team.$createdAt)} />
                      </>,
                    )
                  }
                  return parts.map((item, index) => (
                    <span key={index}>
                      {item}
                      {index < parts.length - 1 && ' • '}
                    </span>
                  ))
                })()}
              </p>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
          <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
            <DialogTrigger asChild>
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
              >
                {t('Delete team')}
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Delete team')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('Are you sure you want to delete')}{' '}
                  <strong>{team.name}</strong>?{' '}
                  {t('This action cannot be undone.')}
                </DialogDescription>
              </DialogHeader>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setDeleteDialogOpen(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleDeleteTeam}
                  disabled={deleteTeamMutation.isPending}
                >
                  {t('Delete')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </div>
  )
}
