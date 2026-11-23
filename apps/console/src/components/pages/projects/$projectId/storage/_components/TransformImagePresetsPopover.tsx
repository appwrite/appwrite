import { useCallback, useEffect, useMemo, useState } from 'react'
import type { Dispatch, SetStateAction } from 'react'
import { Filter } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { SavedImageTransformPresetRow } from '@/components/global/shared/SavedImageTransformPresetRow'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import {
  useImageTransformSavedPresets,
  type ImageTransformSavedPresetLevel,
} from '@/lib/react-query/hooks/auth'
import { useProject } from '@/lib/react-query/hooks/projects'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  applyImageTransformPreset,
  IMAGE_TRANSFORM_PRESETS,
  mergeJsonIntoTransformState,
  transformStateToJsonCompact,
  type ImageTransformState,
  type StorageInspectorPreviewDefaults,
} from './transform-image-wizard-state'
import type { SavedImageTransformPreset } from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
}

type PendingApply =
  | { kind: 'builtin'; id: string }
  | { kind: 'saved'; json: string; label: string }

type PresetBrowseTab = 'builtin' | 'user' | 'team'

function tabCountBadge(count: number) {
  if (count <= 0) return null
  return (
    <span className="flex size-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-medium tabular-nums text-muted-foreground">
      {count}
    </span>
  )
}

/** Same row chrome as {@link SavedImageTransformPresetRow} when read-only: spacer, label, Apply. */
function BuiltinTransformPresetRow({
  label,
  onApply,
}: {
  label: string
  onApply: () => void
}) {
  const t = useT()
  return (
    <div
      className="group flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-2 py-1.5 transition-colors"
      aria-label={label}
    >
      <span className="h-3.5 w-3.5 shrink-0" aria-hidden />
      <span className="min-w-0 flex-1 truncate text-[13px] text-foreground">
        {label}
      </span>
      <Button
        type="button"
        variant="secondary"
        size="sm"
        className="h-7 shrink-0 text-[12px]"
        onClick={(e) => {
          e.stopPropagation()
          onApply()
        }}
      >
        {t('Apply')}
      </Button>
    </div>
  )
}

export type TransformImagePresetsPopoverProps = {
  preferAvif: boolean
  previewDefaults?: StorageInspectorPreviewDefaults
  projectId: string
  state: ImageTransformState
  setState: Dispatch<SetStateAction<ImageTransformState>>
  recordUndoPoint: () => void
}

export function TransformImagePresetsPopover({
  preferAvif,
  previewDefaults,
  projectId,
  state,
  setState,
  recordUndoPoint,
}: TransformImagePresetsPopoverProps) {
  const t = useT()
  const { account } = useAuth()
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(teamId ?? undefined)
  const canTeamPresets = canSaveTeamFilters(access, features)

  const {
    userPresets,
    teamPresets,
    addPreset,
    deletePreset,
    reorderPresets,
    updatePresetName,
    isAdding,
    isReordering,
    hasTeamLevel,
  } = useImageTransformSavedPresets(
    account as { prefs?: Record<string, unknown> } | undefined,
    teamId,
  )

  const [open, setOpen] = useState(false)
  const [browseTab, setBrowseTab] = useState<PresetBrowseTab>('builtin')
  const [alertOpen, setAlertOpen] = useState(false)
  const [dragOverKey, setDragOverKey] = useState<string | null>(null)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [pending, setPending] = useState<PendingApply | null>(null)
  const [saveName, setSaveName] = useState('')
  const [saveLevel, setSaveLevel] =
    useState<ImageTransformSavedPresetLevel>('user')

  useEffect(() => {
    if (!canTeamPresets && saveLevel === 'team') setSaveLevel('user')
  }, [canTeamPresets, saveLevel])

  const savedCount = userPresets.length + teamPresets.length

  const requestApply = useCallback((next: PendingApply) => {
    setOpen(false)
    setPending(next)
    setAlertOpen(true)
  }, [])

  const runApply = useCallback(() => {
    const p = pending
    setPending(null)
    setAlertOpen(false)
    if (!p) return
    recordUndoPoint()
    if (p.kind === 'builtin') {
      const preset = IMAGE_TRANSFORM_PRESETS.find((x) => x.id === p.id)
      if (!preset) return
      setState(() => applyImageTransformPreset(preset, preferAvif, previewDefaults))
      toast.message(`${t('Applied preset:')} ${preset.label}`)
      return
    }
    setState((base) => {
      const r = mergeJsonIntoTransformState(p.json, preferAvif, previewDefaults)
      if (!r.ok) {
        queueMicrotask(() => toast.error(r.error))
        return base
      }
      queueMicrotask(() => toast.message(`${t('Applied preset:')} ${p.label}`))
      return r.state
    })
  }, [pending, preferAvif, previewDefaults, recordUndoPoint, setState, t])

  const handleSaveCurrent = useCallback(async () => {
    const json = transformStateToJsonCompact(state)
    const probe = mergeJsonIntoTransformState(json, preferAvif, previewDefaults)
    if (!probe.ok) {
      toast.error(probe.error)
      return
    }
    if (saveLevel === 'team' && (!teamId || !canTeamPresets)) {
      toast.error(t("You don't have permission to save team presets."))
      return
    }
    try {
      await addPreset({
        name: saveName,
        json,
        level: saveLevel,
      })
      toast.success(
        saveLevel === 'team' ? t('Preset saved for team') : t('Preset saved'),
      )
      setSaveName('')
    } catch (e) {
      toast.error(getErrorMessage(e))
    }
  }, [
    addPreset,
    canTeamPresets,
    preferAvif,
    previewDefaults,
    saveLevel,
    saveName,
    state,
    teamId,
    t,
  ])

  const handleDelete = useCallback(
    async (id: string, level: ImageTransformSavedPresetLevel) => {
      if (level === 'team' && !canTeamPresets) {
        toast.error(t("You don't have permission to remove team presets."))
        return
      }
      try {
        setDeletingId(id)
        await deletePreset(id, level)
        toast.message(t('Preset removed'))
      } catch (e) {
        toast.error(getErrorMessage(e))
      } finally {
        setDeletingId(null)
      }
    },
    [canTeamPresets, deletePreset, t],
  )

  const handlePresetDragStart = (
    e: React.DragEvent,
    level: ImageTransformSavedPresetLevel,
    index: number,
  ) => {
    if ((e.target as HTMLElement).closest('button')) {
      e.preventDefault()
      return
    }
    e.dataTransfer.setData('application/json', JSON.stringify({ level, index }))
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.dropEffect = 'move'
    if (e.currentTarget instanceof HTMLElement) {
      e.dataTransfer.setDragImage(e.currentTarget, 0, 0)
    }
  }

  const handlePresetDrop = (
    e: React.DragEvent,
    level: ImageTransformSavedPresetLevel,
    dropIndex: number,
  ) => {
    e.preventDefault()
    setDragOverKey(null)
    const raw = e.dataTransfer.getData('application/json')
    if (!raw) return
    try {
      const { level: dragLevel, index: dragIndex } = JSON.parse(raw) as {
        level: ImageTransformSavedPresetLevel
        index: number
      }
      if (dragLevel !== level || dragIndex === dropIndex) return
      const list = level === 'user' ? userPresets : teamPresets
      const reordered = reorderList(
        list as SavedImageTransformPreset[],
        dragIndex,
        dropIndex,
      )
      void reorderPresets(reordered, level).catch((err) =>
        toast.error(getErrorMessage(err)),
      )
    } catch {
      // ignore invalid payload
    }
  }

  const rowDropKey = (level: ImageTransformSavedPresetLevel, index: number) =>
    `${level}-${index}`

  const canEditPresetLevel = (l: ImageTransformSavedPresetLevel) =>
    l === 'user' || canTeamPresets

  const alertCopy = useMemo(() => {
    if (!pending) {
      return {
        title: '',
        description: '',
      }
    }
    if (pending.kind === 'builtin') {
      const preset = IMAGE_TRANSFORM_PRESETS.find((x) => x.id === pending.id)
      return {
        title: t('Apply preset?'),
        description: `${t('Applying')} “${preset?.label ?? t('this preset')}” ${t('updates your transform parameters. You can use Undo afterward. Continue?')}`,
      }
    }
    return {
      title: t('Apply saved preset?'),
      description: `${t('Applying')} “${pending.label}” ${t('merges saved parameters into your current transform. You can use Undo afterward. Continue?')}`,
    }
  }, [pending, t])

  return (
    <>
      <Popover modal={false} open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 shrink-0 gap-1.5 px-2.5 text-[12px]"
          >
            <Filter className="h-3.5 w-3.5 shrink-0" />
            {t('Presets')}
            {savedCount > 0 ? (
              <span className="flex size-4 min-w-4 items-center justify-center rounded-full bg-muted text-[10px] font-medium tabular-nums text-muted-foreground">
                {savedCount}
              </span>
            ) : null}
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="flex w-[min(100vw-2rem,380px)] max-h-[min(85dvh,560px)] flex-col overflow-hidden rounded-xl border-border p-0 shadow-lg"
          align="start"
          side="bottom"
          sideOffset={8}
          collisionPadding={16}
        >
          <div className="shrink-0 border-b border-border px-4 py-3">
            <p className="text-[13px] font-semibold text-foreground">
              {t('Presets')}
            </p>
          </div>
          <Tabs
            value={browseTab}
            onValueChange={(v) => setBrowseTab(v as PresetBrowseTab)}
            className="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden"
          >
            <div className="shrink-0 px-4 pb-3 pt-2">
              <TabsList className="grid h-9 w-full grid-cols-3 gap-0.5 p-[3px]">
                <TabsTrigger
                  value="builtin"
                  className="gap-1 px-1.5 text-[11px] sm:text-[12px]"
                >
                  <span className="truncate">{t('Built-in')}</span>
                  {tabCountBadge(IMAGE_TRANSFORM_PRESETS.length)}
                </TabsTrigger>
                <TabsTrigger
                  value="user"
                  className="gap-1 px-1.5 text-[11px] sm:text-[12px]"
                >
                  <span className="truncate">{t('Mine')}</span>
                  {tabCountBadge(userPresets.length)}
                </TabsTrigger>
                <TabsTrigger
                  value="team"
                  className="gap-1 px-1.5 text-[11px] sm:text-[12px]"
                >
                  <span className="truncate">{t('Team')}</span>
                  {tabCountBadge(teamPresets.length)}
                </TabsTrigger>
              </TabsList>
            </div>
            <div className="min-h-0 max-h-[min(52dvh,380px)] flex-1 overflow-y-auto overflow-x-hidden overscroll-contain">
              <TabsContent
                value="builtin"
                className="m-0 px-4 py-3 pe-3 pt-0 outline-none"
              >
                <div className="space-y-1">
                  {IMAGE_TRANSFORM_PRESETS.map((preset) => (
                    <BuiltinTransformPresetRow
                      key={preset.id}
                      label={t(preset.label)}
                      onApply={() =>
                        requestApply({ kind: 'builtin', id: preset.id })
                      }
                    />
                  ))}
                </div>
              </TabsContent>
              <TabsContent
                value="user"
                className="m-0 px-4 py-3 pe-3 pt-0 outline-none"
              >
                {userPresets.length > 0 ? (
                  <div className="space-y-1">
                    {userPresets.map((item, index) => (
                      <SavedImageTransformPresetRow
                        key={`user-${item.id}`}
                        item={item}
                        canEdit={canEditPresetLevel('user') && !isReordering}
                        dragOverKey={dragOverKey}
                        rowDropKey={rowDropKey('user', index)}
                        onDragStart={(ev) =>
                          handlePresetDragStart(ev, 'user', index)
                        }
                        onDragOver={(ev) => {
                          ev.preventDefault()
                          ev.dataTransfer.dropEffect = 'move'
                          setDragOverKey(rowDropKey('user', index))
                        }}
                        onDragLeave={() => setDragOverKey(null)}
                        onDrop={(ev) => handlePresetDrop(ev, 'user', index)}
                        onApply={() =>
                          requestApply({
                            kind: 'saved',
                            json: item.json,
                            label: item.name,
                          })
                        }
                        onDelete={() => void handleDelete(item.id, 'user')}
                        deleteBusy={deletingId === item.id}
                        deleteDisabled={deletingId !== null}
                        onRenameCommit={(name) =>
                          updatePresetName(item.id, 'user', name).catch(
                            (err) => toast.error(getErrorMessage(err)),
                          )
                        }
                      />
                    ))}
                  </div>
                ) : (
                  <p className="text-[12px] leading-relaxed text-muted-foreground">
                    {t('You have not saved any presets yet. Use')}{' '}
                    <span className="font-medium text-foreground">
                      {t('Save preset')}
                    </span>{' '}
                    {t('below to store your current transform parameters.')}
                  </p>
                )}
              </TabsContent>
              <TabsContent
                value="team"
                className="m-0 px-4 py-3 pe-3 pt-0 outline-none"
              >
                {!hasTeamLevel ? (
                  <p className="text-[12px] leading-relaxed text-muted-foreground">
                    {t(
                      'Team presets are available when the project belongs to an organization.',
                    )}
                  </p>
                ) : teamPresets.length > 0 ? (
                  <div className="space-y-1">
                    {teamPresets.map((item, index) => (
                      <SavedImageTransformPresetRow
                        key={`team-${item.id}`}
                        item={item}
                        canEdit={canEditPresetLevel('team') && !isReordering}
                        dragOverKey={dragOverKey}
                        rowDropKey={rowDropKey('team', index)}
                        onDragStart={(ev) =>
                          handlePresetDragStart(ev, 'team', index)
                        }
                        onDragOver={(ev) => {
                          ev.preventDefault()
                          ev.dataTransfer.dropEffect = 'move'
                          setDragOverKey(rowDropKey('team', index))
                        }}
                        onDragLeave={() => setDragOverKey(null)}
                        onDrop={(ev) => handlePresetDrop(ev, 'team', index)}
                        onApply={() =>
                          requestApply({
                            kind: 'saved',
                            json: item.json,
                            label: item.name,
                          })
                        }
                        onDelete={() => void handleDelete(item.id, 'team')}
                        deleteBusy={deletingId === item.id}
                        deleteDisabled={deletingId !== null}
                        onRenameCommit={(name) =>
                          updatePresetName(item.id, 'team', name).catch(
                            (err) => toast.error(getErrorMessage(err)),
                          )
                        }
                      />
                    ))}
                  </div>
                ) : (
                  <p className="text-[12px] leading-relaxed text-muted-foreground">
                    {t(
                      'No team presets yet. Owners and developers can add presets for everyone in the organization using',
                    )}{' '}
                    <span className="font-medium text-foreground">
                      {t('For team')}
                    </span>{' '}
                    {t('below.')}
                  </p>
                )}
              </TabsContent>
            </div>
          </Tabs>

          <div className="shrink-0 border-t border-border bg-muted/20 px-4 py-3">
            <Label
              htmlFor="transform-preset-save-name"
              className="text-[12px] text-muted-foreground"
            >
              {t('Save current parameters')}
            </Label>
            <div className="mt-2 flex gap-2">
              <Input
                id="transform-preset-save-name"
                placeholder={t('Preset name')}
                value={saveName}
                onChange={(e) => setSaveName(e.target.value)}
                className="h-9 flex-1 text-[13px]"
                maxLength={64}
              />
            </div>
            {hasTeamLevel && canTeamPresets ? (
              <Tabs
                value={saveLevel}
                onValueChange={(v) =>
                  setSaveLevel(v as ImageTransformSavedPresetLevel)
                }
                className="mt-3"
              >
                <TabsList className="grid h-9 w-full grid-cols-2">
                  <TabsTrigger value="user" className="text-[12px]">
                    {t('For me')}
                  </TabsTrigger>
                  <TabsTrigger value="team" className="text-[12px]">
                    {t('For team')}
                  </TabsTrigger>
                </TabsList>
              </Tabs>
            ) : (
              <p className="mt-2 text-[11px] text-muted-foreground">
                {t(
                  'Saved to your account. Team presets require owner or developer access.',
                )}
              </p>
            )}
            <Button
              type="button"
              size="sm"
              className="mt-3 h-9 w-full text-[13px]"
              disabled={isAdding || !saveName.trim()}
              onClick={() => void handleSaveCurrent()}
            >
              {t('Save preset')}
            </Button>
          </div>
        </PopoverContent>
      </Popover>

      <Dialog
        open={alertOpen}
        onOpenChange={(next) => {
          if (!next) setPending(null)
          setAlertOpen(next)
        }}
      >
        <DialogContent
          showCloseButton={false}
          overlayClassName="z-[10060]"
          className="z-[10061] gap-0 border-border p-0 sm:max-w-md"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle className="text-[15px]">{alertCopy.title}</DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {alertCopy.description}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <DialogFooter className="flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setPending(null)
                setAlertOpen(false)
              }}
            >
              {t('Cancel')}
            </Button>
            <Button type="button" onClick={() => runApply()}>
              {t('Apply preset')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
