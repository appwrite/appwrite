import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { COOLDOWNS } from '@/lib/alerts/metrics'
import { useProject } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { NotifyFields, SeverityPicker, durationOptions } from '../../RuleFields'
import {
  NOTIFY_FIELDS,
  SettingsCard,
  UpdateButton,
  useMonitorDraft,
} from './Card'

const ownsRecipients = (field: string) => NOTIFY_FIELDS.includes(field)
const ownsSeverity = (field: string) => field === 'severity'
const ownsCooldown = (field: string) => field === 'cooldown'

/** Who hears about the monitor's alerts, how urgently, and how often. */
export function MonitorNotificationSettings({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  const { project } = useProject(projectId)
  const recipients = useMonitorDraft(projectId, monitorId, ownsRecipients)
  const severity = useMonitorDraft(projectId, monitorId, ownsSeverity)
  const cooldown = useMonitorDraft(projectId, monitorId, ownsCooldown)
  if (!recipients.monitor) return null

  // Probe and usage forms share these fields, so each card reads whichever applies.
  const fields = (draft: typeof recipients) => draft.probe ?? draft.form
  const change = (draft: typeof recipients) =>
    draft.probe ? draft.setProbe : draft.setForm
  const notify = fields(recipients)
  const level = fields(severity).severity
  const wait = fields(cooldown).cooldown

  const cards: SettingsCardItem[] = [
    {
      id: 'recipients',
      search: {
        title: 'Recipients',
        description: 'Who gets an email and a console notification.',
        keywords: ['notify', 'roles', 'owners', 'developers', 'escalation'],
      },
      node: (
        <SettingsCard
          title={t('Recipients')}
          description={t(
            'Who gets an email, a push and a console notification when the monitor fires and when it resolves.',
          )}
          footer={
            <UpdateButton
              error={recipients.error}
              pending={recipients.pending}
              onClick={recipients.save}
            />
          }
        >
          <NotifyFields
            bare
            recipientsOnly
            notify={notify.notify}
            severity={notify.severity}
            escalation={{
              organizationId: project?.teamId,
              recipients: notify.recipients,
              escalationId: notify.escalationId,
            }}
            onChange={change(recipients)}
            disabled={recipients.pending}
          />
        </SettingsCard>
      ),
    },
    {
      id: 'severity',
      search: {
        title: 'Severity',
        description: 'How urgent its alerts are.',
        keywords: ['level', 'critical', 'warning', 'info', 'error'],
      },
      node: (
        <SettingsCard
          title={t('Severity')}
          titleId="alert-severity"
          description={t(
            'How urgent its alerts are. Only critical changes how they are delivered.',
          )}
          footer={
            <UpdateButton
              error={severity.error}
              pending={severity.pending}
              onClick={severity.save}
            />
          }
        >
          <SeverityPicker
            value={level}
            onChange={(next) => change(severity)({ severity: next })}
            disabled={severity.pending}
          />
        </SettingsCard>
      ),
    },
    {
      id: 'cooldown',
      search: {
        title: 'Cooldown',
        description: 'The shortest time between two alerts from this monitor.',
        keywords: ['cooldown', 'repeat', 'frequency'],
      },
      node: (
        <SettingsCard
          title={t('Cooldown')}
          description={t(
            'The shortest time between two alerts from this monitor.',
          )}
          footer={
            <UpdateButton
              error={cooldown.error}
              pending={cooldown.pending}
              onClick={cooldown.save}
            />
          }
        >
          <Select
            value={String(wait)}
            onValueChange={(next) =>
              change(cooldown)({ cooldown: Number(next) })
            }
            disabled={cooldown.pending}
          >
            <SelectTrigger
              className="h-9 w-full max-w-sm"
              aria-label={t('Cooldown')}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {durationOptions(COOLDOWNS, wait, t).map((option) => (
                <SelectItem key={option.value} value={String(option.value)}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingsCard>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
