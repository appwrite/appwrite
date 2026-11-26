import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  monitorQueryOptions,
  useDeleteMonitor,
  useUpdateMonitor,
} from '@/lib/react-query/hooks'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { MONITOR_TYPES } from '../../ProbeFields'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { suggestedName } from '../../RuleFields'
import { SettingsCard, UpdateButton, useMonitorDraft } from './Card'

const ownsName = (field: string) => field === 'name'

/** The monitor's identity, whether it runs, and deleting it. */
export function MonitorGeneralSettings({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const { data: monitor } = useQuery(monitorQueryOptions(projectId, monitorId))
  const draft = useMonitorDraft(projectId, monitorId, ownsName)
  const toggle = useUpdateMonitor(projectId)
  const remove = useDeleteMonitor(projectId)
  const [enabled, setEnabled] = useSyncStateFromServer(monitor?.enabled ?? true)
  const [deleting, setDeleting] = useState(false)

  if (!monitor) return null

  const type = MONITOR_TYPES.find((entry) => entry.value === monitor.type)
  const TypeIcon = type?.icon ?? Trash2
  const name = draft.probe ? draft.probe.name : draft.form.name

  const saveEnabled = () =>
    toggle.mutate(
      { monitorId: monitor.$id, type: monitor.type, changes: { enabled } },
      {
        onSuccess: () =>
          toast.success(
            enabled
              ? t('Monitor has been resumed')
              : t('Monitor has been paused'),
          ),
        onError: (err: Error) =>
          toast.error(getErrorMessage(err, t('Failed to update monitor'))),
      },
    )

  const handleDelete = () =>
    remove.mutate(monitor.$id, {
      onSuccess: () => {
        toast.success(t('Monitor has been deleted'))
        void navigate({
          to: '/projects/$projectId/monitors',
          params: { projectId },
        })
      },
      onError: (err: Error) =>
        toast.error(getErrorMessage(err, t('Failed to delete monitor'))),
    })

  const cards: SettingsCardItem[] = [
    {
      id: 'details',
      search: {
        title: 'Details',
        description: 'Identifiers and timestamps for this monitor.',
        keywords: ['id', 'created', 'updated', 'identifiers'],
      },
      node: (
        <SettingsCard
          title={t('Details')}
          description={t('Identifiers and timestamps for this monitor.')}
        >
          <div className="space-y-1">
            <p className="text-[13px] text-muted-foreground">
              {t('Monitor ID:')}{' '}
              <span className="ms-1.5">
                <CopyableId id={monitor.$id} size="sm" />
              </span>
            </p>
            <p className="text-[13px] text-muted-foreground">
              {t('Type:')}{' '}
              <span className="text-foreground">
                {t(type?.label ?? monitor.type)}
              </span>
            </p>
            <p className="text-[13px] text-muted-foreground">
              {t('Created:')}{' '}
              <DateTooltip
                date={new Date(monitor.$createdAt)}
                showFormattedDate
                className="text-foreground"
              />
            </p>
            <p className="text-[13px] text-muted-foreground">
              {t('Last updated:')}{' '}
              <DateTooltip
                date={new Date(monitor.$updatedAt || monitor.$createdAt)}
                showFormattedDate
                className="text-foreground"
              />
            </p>
          </div>
        </SettingsCard>
      ),
    },
    {
      id: 'name',
      search: {
        title: 'Name',
        description: 'Shown in the monitors list and in every alert.',
        keywords: ['rename', 'display'],
      },
      node: (
        <SettingsCard
          title={t('Name')}
          description={t('Shown in the monitors list and in every alert.')}
          footer={
            <UpdateButton
              error={draft.error}
              pending={draft.pending}
              onClick={draft.save}
            />
          }
        >
          <Input
            value={name}
            onChange={(event) =>
              draft.probe
                ? draft.setProbe({ name: event.target.value })
                : draft.setForm({ name: event.target.value })
            }
            placeholder={draft.probe ? t('Name') : suggestedName(draft.form, t)}
            maxLength={128}
            className="h-9 max-w-sm border-border bg-background text-[13px]"
          />
        </SettingsCard>
      ),
    },
    {
      id: 'status',
      search: {
        title: 'Status',
        description:
          'Pause the monitor to stop its checks and alerts without deleting it.',
        keywords: ['enabled', 'paused', 'pause', 'resume'],
      },
      node: (
        <SettingsCard
          title={t('Status')}
          description={t(
            'Pause the monitor to stop its checks and alerts without deleting it.',
          )}
          footer={
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={enabled === monitor.enabled || toggle.isPending}
              onClick={saveEnabled}
            >
              {t('Update')}
            </Button>
          }
        >
          <div className="flex items-center gap-3">
            <Switch
              id="monitor-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={toggle.isPending}
            />
            <Label
              htmlFor="monitor-enabled"
              className="text-[13px] text-foreground"
            >
              {enabled ? t('Active') : t('Paused')}
            </Label>
          </div>
        </SettingsCard>
      ),
    },
    {
      id: 'delete',
      search: {
        title: 'Delete monitor',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
        <div className="overflow-hidden rounded-xl border border-destructive/50 bg-card/50">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Delete monitor')}
            </h3>
          </div>
          <div className="border-t border-destructive/20" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                'Permanently delete this monitor. Its open alert resolves, and its alerts stay in the history. This action cannot be undone.',
              )}
            </p>
            <div className="mt-4 flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <TypeIcon className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-[14px] font-medium text-foreground">
                  {monitor.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {t(type?.label ?? monitor.type)}
                </p>
              </div>
            </div>
          </div>
          <div className="border-t border-destructive/20 bg-destructive/5 px-6 py-4">
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={remove.isPending}
              onClick={() => setDeleting(true)}
            >
              <Trash2 className="me-1.5 h-4 w-4" />
              {t('Delete monitor')}
            </Button>
            <ConfirmNameDialog
              open={deleting}
              onOpenChange={setDeleting}
              title="Delete monitor"
              description={
                <>
                  {t('Are you sure you want to delete')}{' '}
                  <span className="font-medium text-foreground">
                    {monitor.name}
                  </span>
                  ? {t('This action cannot be undone.')}
                </>
              }
              confirmValue={monitor.name.trim() || monitor.$id}
              confirmPlaceholder="Enter monitor name"
              onConfirm={handleDelete}
              isConfirming={remove.isPending}
            />
          </div>
        </div>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
