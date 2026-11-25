import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { monitorQueryOptions, useUpdateMonitor } from '@/lib/react-query/hooks'
import type {
  ParsedMonitor,
  ProbeMonitorChanges,
  ProbeType,
} from '@/lib/appwrite/monitors'
import { isProbeType } from '@/lib/alerts/probes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  emptyProbeForm,
  probeFormError,
  probeFormFromMonitor,
  probeInputFromForm,
  type ProbeForm,
} from '../../ProbeFields'
import {
  emptyRuleForm,
  monitorInputFromForm,
  ruleFormError,
  ruleFormFromMonitor,
  type RuleForm,
} from '../../RuleFields'
import { changedFields } from '../Details'

/**
 * One settings card, as the console's other resource settings draw them: a
 * title and description, the control, and an Update footer when it saves.
 */
export function SettingsCard({
  title,
  description,
  children,
  footer,
  titleId,
  flush = false,
}: {
  title: string
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  /** Labels a control in the body by the card's title. */
  titleId?: string
  /** Drops the body padding, for a table that runs edge to edge. */
  flush?: boolean
}) {
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 id={titleId} className="text-[15px] font-semibold text-foreground">
          {title}
        </h3>
        {description && (
          <p className="mt-2 text-[13px] text-muted-foreground">
            {description}
          </p>
        )}
      </div>
      <div className="border-t border-border" />
      <div className={flush ? undefined : 'px-6 py-4'}>{children}</div>
      {footer && (
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          {footer}
        </div>
      )}
    </div>
  )
}

/** The Update button of a card, with why it cannot save yet. */
export function UpdateButton({
  error,
  pending,
  onClick,
}: {
  error?: string
  pending: boolean
  onClick: () => void
}) {
  const t = useT()
  const button = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      disabled={!!error || pending}
      onClick={onClick}
    >
      {t('Update')}
    </Button>
  )
  if (!error) return button
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{button}</span>
      </TooltipTrigger>
      <TooltipContent side="top">{error}</TooltipContent>
    </Tooltip>
  )
}

/**
 * A card's own copy of the monitor's form. Update sends only the fields the
 * card owns, so saving one card leaves what another card has typed alone.
 */
export function useMonitorDraft(
  projectId: string,
  monitorId: string,
  /** The update fields this card saves; the rest are ignored. */
  owns: (field: string) => boolean,
) {
  const t = useT()
  const { data: monitor } = useQuery(monitorQueryOptions(projectId, monitorId))
  const update = useUpdateMonitor(projectId)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)
  const [probe, setProbe] = useState<ProbeForm>(() => emptyProbeForm('http'))
  const probeMonitor =
    monitor && isProbeType(monitor.type)
      ? (monitor as ParsedMonitor & { type: ProbeType })
      : null

  // Starts from the saved monitor, and again whenever it is saved.
  useEffect(() => {
    if (!monitor) return
    if (probeMonitor) setProbe(probeFormFromMonitor(probeMonitor))
    else setForm(ruleFormFromMonitor(monitor))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [monitor?.$updatedAt, monitor?.$id])

  const changes = useMemo(() => {
    if (!monitor) return {}
    const all: Record<string, unknown> = probeMonitor
      ? changedFields<ProbeMonitorChanges>(
          probeInputFromForm(probeFormFromMonitor(probeMonitor)).params,
          probeInputFromForm(probe).params,
        )
      : changedFields(
          monitorInputFromForm(ruleFormFromMonitor(monitor), t),
          monitorInputFromForm(form, t),
        )
    return Object.fromEntries(
      Object.entries(all).filter(([field]) => owns(field)),
    )
  }, [monitor, probeMonitor, form, probe, t, owns])

  const dirty = Object.keys(changes).length > 0
  const error =
    (probeMonitor ? probeFormError(probe, t) : ruleFormError(form, t)) ??
    (dirty ? undefined : t('No changes to save.'))

  const save = () => {
    if (!monitor || error) return
    update.mutate(
      { monitorId: monitor.$id, type: monitor.type, changes },
      {
        onSuccess: () => toast.success(t('Monitor has been updated')),
        onError: (err: Error) =>
          toast.error(getErrorMessage(err, t('Failed to update monitor'))),
      },
    )
  }

  return {
    monitor,
    probe: probeMonitor ? probe : null,
    form,
    setForm: (next: Partial<RuleForm>) =>
      setForm((current) => ({ ...current, ...next })),
    setProbe: (next: Partial<ProbeForm>) =>
      setProbe((current) => ({ ...current, ...next })),
    error,
    pending: update.isPending,
    save,
  }
}

/** Fields a card that is not about the monitor's target saves, by name. */
export const NOTIFY_FIELDS = ['notify', 'escalationId']
export const NON_TARGET_FIELDS = [
  'name',
  'enabled',
  'cooldown',
  'severity',
  ...NOTIFY_FIELDS,
]
