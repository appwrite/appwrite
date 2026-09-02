import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { History } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import {
  isEligiblePitrRestoreTarget,
  isTimeInPitrWindow,
  pitrWindowFromResponse,
} from '@/lib/databases/dedicated-pitr'
import {
  useDedicatedDatabasePitrWindows,
  useDedicatedDatabaseRestorations,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { TimezoneSelect } from '@/components/global/shared/TimezoneSelect'
import {
  PitrAbsoluteTimestamp,
  PitrRestorePicker,
  getUserTimeZone,
} from './PitrRestorePicker'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useConsoleProfile } from '@/hooks/use-console-profile'

type RestoreTarget = 'same' | 'other'

type RestorationStatusVariant =
  | 'pending'
  | 'processing'
  | 'completed'
  | 'failed'

function restorationStatusMeta(status: string): {
  label: string
  variant: RestorationStatusVariant
} {
  const normalized = status.toLowerCase()
  if (normalized === 'running') {
    return { label: 'Processing', variant: 'processing' }
  }
  if (normalized === 'completed') {
    return { label: 'Complete', variant: 'completed' }
  }
  if (normalized === 'failed') {
    return { label: 'Failed', variant: 'failed' }
  }
  return { label: 'Pending', variant: 'pending' }
}

function restorationTypeLabel(type: string): string {
  return type.toLowerCase() === 'pitr' ? 'PITR' : 'Backup'
}

type RestorePitrSharedProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  engine?: string | null
  canWrite: boolean
}

export function RestorePitrDialog({
  open,
  onOpenChange,
  projectId,
  databaseId,
  database,
  engine,
  canWrite,
  initialTimeZone,
}: RestorePitrSharedProps & {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialTimeZone?: string
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const pitrEnabled = database.pitr === true
  const { data: windowsData } = useDedicatedDatabasePitrWindows(
    projectId,
    databaseId,
    engine ?? database.engine,
    open && pitrEnabled,
  )
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    open ? projectId : undefined,
  )
  const recoveryWindow = useMemo(
    () => pitrWindowFromResponse(windowsData),
    [windowsData],
  )
  const targets = useMemo(
    () =>
      dedicatedDatabases.filter((candidate) =>
        isEligiblePitrRestoreTarget(database, candidate),
      ),
    [database, dedicatedDatabases],
  )

  const [targetTime, setTargetTime] = useState<string | null>(null)
  const [timeZone, setTimeZone] = useState(getUserTimeZone)
  const [restoreTarget, setRestoreTarget] = useState<RestoreTarget>('same')
  const [targetDatabaseId, setTargetDatabaseId] = useState('')
  const [confirmRestore, setConfirmRestore] = useState(false)
  const latestMs = recoveryWindow?.latest.getTime()

  useEffect(() => {
    if (!open) return
    setRestoreTarget('same')
    setTargetDatabaseId('')
    setConfirmRestore(false)
    setTimeZone(initialTimeZone || getUserTimeZone())
    setTargetTime(
      latestMs != null ? new Date(latestMs).toISOString() : null,
    )
  }, [open, latestMs, initialTimeZone])

  const selectedDate = useMemo(() => {
    if (!targetTime) return null
    const date = new Date(targetTime)
    return Number.isNaN(date.getTime()) ? null : date
  }, [targetTime])

  const timeInWindow =
    !!selectedDate &&
    !!recoveryWindow &&
    isTimeInPitrWindow(selectedDate, recoveryWindow)

  const restoreMutation = useMutation({
    mutationFn: async () => {
      if (!selectedDate) {
        throw new Error(t('Choose a restore time inside the recovery window.'))
      }
      const projectSdk = sdk.forProject(projectId)
      return dedicatedEngineService(
        projectSdk,
        engine ?? database.engine,
      ).createRestoration({
        databaseId,
        type: 'pitr',
        targetTime: selectedDate.toISOString(),
        ...(restoreTarget === 'other' && targetDatabaseId
          ? { targetDatabaseId }
          : {}),
      })
    },
    onSuccess: async () => {
      toast.success(t('Database restore initiated'))
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['postgres-database', 'project', projectId, databaseId],
        }),
        queryClient.refetchQueries({
          queryKey: ['mysql-database', 'project', projectId, databaseId],
        }),
        queryClient.refetchQueries({
          queryKey: ['dedicated-restorations', 'project', projectId, databaseId],
        }),
        queryClient.refetchQueries({
          queryKey: ['dedicated-databases', 'project', projectId],
        }),
      ])
      onOpenChange(false)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to restore to this point in time')))
    },
  })

  const writeDisabled = !canWrite || restoreMutation.isPending
  const otherTargetInvalid =
    restoreTarget === 'other' &&
    (!targetDatabaseId || targetDatabaseId === databaseId)
  const canSubmit =
    !writeDisabled &&
    confirmRestore &&
    timeInWindow &&
    !otherTargetInvalid

  const handleOpenChange = (nextOpen: boolean) => {
    if (restoreMutation.isPending) return
    onOpenChange(nextOpen)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="sm:max-w-3xl p-0 max-h-[90dvh] overflow-y-auto"
        disableAutoFocus
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Restore to a point in time')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose a moment inside the recovery window. The target database is unavailable while restoring, and everything after that moment is discarded.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 space-y-5">
          {!recoveryWindow ? (
            <p className="text-[13px] text-muted-foreground">
              {t(
                'No recovery window is available yet. Continuous archiving has to capture its first segment after PITR is enabled.',
              )}
            </p>
          ) : (
            <>
              <PitrRestorePicker
                value={selectedDate}
                onChange={(next) => setTargetTime(next.toISOString())}
                recoveryWindow={recoveryWindow}
                timeZone={timeZone}
                onTimeZoneChange={setTimeZone}
                disabled={writeDisabled}
              />

              <div>
                <h3 className="text-[15px] font-semibold text-foreground mb-4">
                  {t('Restore target')}
                </h3>
                <RadioGroup
                  value={restoreTarget}
                  onValueChange={(value) =>
                    setRestoreTarget(value as RestoreTarget)
                  }
                  className="grid grid-cols-2 gap-3"
                  disabled={writeDisabled}
                >
                  <Label
                    htmlFor="pitr-restore-same"
                    className={cn(
                      'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors',
                      restoreTarget === 'same'
                        ? 'border-primary/50 bg-muted/50'
                        : 'border-border bg-card/50 hover:bg-muted/30',
                    )}
                  >
                    <RadioGroupItem
                      value="same"
                      id="pitr-restore-same"
                      className="mt-0.5 shrink-0"
                    />
                    <div className="min-w-0 flex-1 space-y-2">
                      <span className="text-[13px] font-semibold text-foreground">
                        {t('Current database')}
                      </span>
                      <p className="text-[12px] text-muted-foreground leading-snug">
                        {t(
                          'Overwrite this database in place. This cannot be undone.',
                        )}
                      </p>
                    </div>
                  </Label>
                  <Label
                    htmlFor="pitr-restore-other"
                    className={cn(
                      'flex items-start gap-3 rounded-lg border p-4 transition-colors',
                      targets.length === 0
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer',
                      restoreTarget === 'other'
                        ? 'border-primary/50 bg-muted/50'
                        : 'border-border bg-card/50 hover:bg-muted/30',
                    )}
                  >
                    <RadioGroupItem
                      value="other"
                      id="pitr-restore-other"
                      className="mt-0.5 shrink-0"
                      disabled={targets.length === 0}
                    />
                    <div className="min-w-0 flex-1 space-y-2">
                      <span className="text-[13px] font-semibold text-foreground">
                        {t('Another database')}
                      </span>
                      <p className="text-[12px] text-muted-foreground leading-snug">
                        {t(
                          'Restore into a ready database with the same engine and version.',
                        )}
                      </p>
                    </div>
                  </Label>
                </RadioGroup>
              </div>

              {restoreTarget === 'other' ? (
                <div className="space-y-2">
                  <Label htmlFor="pitr-target-database" className="text-[13px]">
                    {t('Target database')}
                  </Label>
                  {targets.length === 0 ? (
                    <p className="text-[13px] text-muted-foreground">
                      {t(
                        'No other ready database with the same engine and version is available.',
                      )}
                    </p>
                  ) : (
                    <Select
                      value={targetDatabaseId}
                      onValueChange={setTargetDatabaseId}
                      disabled={writeDisabled}
                    >
                      <SelectTrigger
                        id="pitr-target-database"
                        className="h-9 text-[13px]"
                      >
                        <SelectValue placeholder={t('Select a database')} />
                      </SelectTrigger>
                      <SelectContent>
                        {targets.map((target) => (
                          <SelectItem key={target.$id} value={target.$id}>
                            {target.name || target.$id}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </div>
              ) : null}

              <div className="rounded-lg border border-border bg-muted/30 p-4">
                <div className="flex items-start gap-3">
                  <Checkbox
                    id="confirm-pitr-restore"
                    checked={confirmRestore}
                    onCheckedChange={(checked) =>
                      setConfirmRestore(checked === true)
                    }
                    className="mt-0.5"
                    disabled={writeDisabled}
                  />
                  <Label
                    htmlFor="confirm-pitr-restore"
                    className="flex-1 cursor-pointer text-[13px] text-foreground"
                  >
                    {restoreTarget === 'other'
                      ? t(
                          'I understand that the selected database will be permanently replaced with data from this point in time.',
                        )
                      : t(
                          'I understand that all current database data will be permanently replaced, and everything after this time will be discarded.',
                        )}
                  </Label>
                </div>
              </div>
            </>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={restoreMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            {...analyticsAttrs('restore-pitr')}
            onClick={() => restoreMutation.mutate()}
            disabled={!canSubmit || !recoveryWindow}
          >
            {t('Restore')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function RestorePitrButton({
  projectId,
  databaseId,
  database,
  engine,
  canWrite,
}: RestorePitrSharedProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const [open, setOpen] = useState(false)
  const showPitrRestore = features.databasePitrRestore
  const pitrEnabled = database.pitr === true
  const { data: windowsData } = useDedicatedDatabasePitrWindows(
    projectId,
    databaseId,
    engine ?? database.engine,
    showPitrRestore && pitrEnabled,
  )
  const recoveryWindow = pitrWindowFromResponse(windowsData)
  const disabledReason = !canWrite
    ? t("You don't have permission to change database settings.")
    : !pitrEnabled
      ? t('Enable PITR first to restore to a specific moment.')
      : !recoveryWindow
        ? t(
            'No recovery window is available yet. Continuous archiving has to capture its first segment after PITR is enabled.',
          )
        : undefined

  if (!showPitrRestore) {
    return null
  }

  const trigger = (
    <Button
      variant="outline"
      size="sm"
      className="h-8 gap-1.5 text-[12px] font-medium"
      disabled={!!disabledReason}
      onClick={() => setOpen(true)}
    >
      <History className="h-3.5 w-3.5" />
      {t('Restore PITR')}
    </Button>
  )

  return (
    <>
      {disabledReason ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span>{trigger}</span>
          </TooltipTrigger>
          <TooltipContent>
            <p className="text-xs">{disabledReason}</p>
          </TooltipContent>
        </Tooltip>
      ) : (
        trigger
      )}
      <RestorePitrDialog
        open={open}
        onOpenChange={setOpen}
        projectId={projectId}
        databaseId={databaseId}
        database={database}
        engine={engine}
        canWrite={canWrite}
      />
    </>
  )
}

export function DedicatedDatabasePitrRestoreCard({
  projectId,
  databaseId,
  database,
  engine,
  canWrite,
}: RestorePitrSharedProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const [open, setOpen] = useState(false)
  const [timeZone, setTimeZone] = useState(getUserTimeZone)
  const showPitrRestore = features.databasePitrRestore
  const pitrEnabled = database.pitr === true
  const {
    data: windowsData,
    isLoading: windowsLoading,
    isError: windowsError,
  } = useDedicatedDatabasePitrWindows(
    projectId,
    databaseId,
    engine ?? database.engine,
    showPitrRestore && pitrEnabled,
  )
  const { data: restorationsData } = useDedicatedDatabaseRestorations(
    projectId,
    databaseId,
    engine ?? database.engine,
    showPitrRestore && pitrEnabled,
  )
  const recoveryWindow = pitrWindowFromResponse(windowsData)
  const restorations = restorationsData?.restorations ?? []
  const restoreDisabledReason = !canWrite
    ? t("You don't have permission to change database settings.")
    : !pitrEnabled
      ? t('Enable PITR first to restore to a specific moment.')
      : windowsError
        ? t('Could not load the recovery window. Try again in a moment.')
        : !recoveryWindow && !windowsLoading
          ? t(
              'No recovery window is available yet. Continuous archiving has to capture its first segment after PITR is enabled.',
            )
          : windowsLoading
            ? t('Loading recovery window...')
            : undefined

  if (!showPitrRestore) {
    return null
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Restore to a point in time')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Database changes are recorded continuously, so you can restore to any moment in the recovery window.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        {!pitrEnabled ? (
          <p className="text-[13px] text-muted-foreground">
            {t('Enable PITR first to restore to a specific moment.')}
          </p>
        ) : (
          <div className="space-y-4">
            <div className="space-y-1.5">
              <p className="text-[12px] text-muted-foreground">
                {t('Time zone')}
              </p>
              <TimezoneSelect
                value={timeZone}
                onValueChange={setTimeZone}
                at={recoveryWindow?.latest}
              />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">
                  {t('Restore available from')}
                </span>
                {recoveryWindow ? (
                  <PitrAbsoluteTimestamp
                    date={recoveryWindow.earliest}
                    timeZone={timeZone}
                  />
                ) : (
                  <span className="text-foreground">-</span>
                )}
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-muted-foreground">
                  {t('Latest restore available at')}
                </span>
                {recoveryWindow ? (
                  <PitrAbsoluteTimestamp
                    date={recoveryWindow.latest}
                    timeZone={timeZone}
                  />
                ) : (
                  <span className="text-foreground">-</span>
                )}
              </div>
            </div>
            {recoveryWindow ? (
              <p className="text-[13px] text-muted-foreground">
                {t("You'll pick the date and time when you start.")}
              </p>
            ) : null}
          </div>
        )}

        {restorations.length > 0 ? (
          <div className="rounded-lg border border-border overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Type')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Target time')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {restorations.map((restoration) => {
                  const status = restorationStatusMeta(restoration.status)
                  return (
                    <TableRow key={restoration.$id}>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] font-medium">
                          {t(restorationTypeLabel(restoration.type))}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {restoration.targetTime ? (
                          <DateTooltip date={restoration.targetTime} />
                        ) : (
                          <span className="text-[13px]">-</span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Badge
                          variant={status.variant}
                          className="text-[10px] shrink-0"
                        >
                          {t(status.label)}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        {restoreDisabledReason ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <span>
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled
                >
                  {t('Start restore')}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>
              <p className="text-xs">{restoreDisabledReason}</p>
            </TooltipContent>
          </Tooltip>
        ) : (
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setOpen(true)}
          >
            {t('Start restore')}
          </Button>
        )}
      </div>
      <RestorePitrDialog
        open={open}
        onOpenChange={setOpen}
        projectId={projectId}
        databaseId={databaseId}
        database={database}
        engine={engine}
        canWrite={canWrite}
        initialTimeZone={timeZone}
      />
    </div>
  )
}
