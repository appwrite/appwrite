import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PROBE_TYPES } from '@/lib/alerts/probes'
import { metricDefinition } from '@/lib/alerts/metrics'
import { useT } from '@/lib/i18n/translate'
import { ProbeFields } from '../../ProbeFields'
import { ConditionFields } from '../../RuleFields'
import {
  NON_TARGET_FIELDS,
  SettingsCard,
  UpdateButton,
  useMonitorDraft,
} from './Card'

const ownsCondition = (field: string) => field === 'rules' || field === 'window'
const ownsTarget = (field: string) => !NON_TARGET_FIELDS.includes(field)

/** When a usage monitor fires, or what a check calls and how often. */
export function MonitorConditionSettings({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  const usage = useMonitorDraft(projectId, monitorId, ownsCondition)
  const target = useMonitorDraft(projectId, monitorId, ownsTarget)
  const monitor = usage.monitor
  if (!monitor) return null

  const cards: SettingsCardItem[] = target.probe
    ? [
        {
          id: 'target',
          search: {
            title: 'Target',
            description: 'What the check calls, and how often.',
            keywords: ['url', 'host', 'port', 'domain', 'interval', 'timeout'],
          },
          node: (
            <SettingsCard
              title={t('Target')}
              description={t(
                PROBE_TYPES.find((entry) => entry.value === monitor.type)
                  ?.description ?? '',
              )}
              footer={
                <UpdateButton
                  error={target.error}
                  pending={target.pending}
                  onClick={target.save}
                />
              }
            >
              <ProbeFields
                part="target"
                projectId={projectId}
                form={target.probe}
                onChange={target.setProbe}
                disabled={target.pending}
              />
            </SettingsCard>
          ),
        },
      ]
    : [
        {
          id: 'condition',
          search: {
            title: 'Condition',
            description:
              'The metric, its threshold and the window it is counted over.',
            keywords: ['metric', 'threshold', 'window', 'chart'],
          },
          node: (
            <SettingsCard
              title={t('Condition')}
              description={
                metricDefinition(usage.form.metric).counter
                  ? t('Counted over the window and compared at every check.')
                  : t('The latest reading is compared at every check.')
              }
              footer={
                <UpdateButton
                  error={usage.error}
                  pending={usage.pending}
                  onClick={usage.save}
                />
              }
            >
              <ConditionFields
                bare
                projectId={projectId}
                form={usage.form}
                onChange={usage.setForm}
                disabled={usage.pending}
              />
            </SettingsCard>
          ),
        },
      ]

  return <SettingsCardsList cards={cards} />
}
