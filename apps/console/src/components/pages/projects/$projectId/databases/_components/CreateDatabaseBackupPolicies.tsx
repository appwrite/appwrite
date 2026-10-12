import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  getBackupPoliciesPlanLimit,
  isBackupPoliciesAtPlanLimit,
  supportsAdvancedBackupPolicies,
} from '@/lib/databases/backup-policy-plan-limits'

export type BackupPolicyPresetId = 'hourly' | 'daily'

export const BACKUP_POLICY_PRESETS: Record<
  BackupPolicyPresetId,
  {
    schedule: string
    retention: number
    name: string
    label: string
    description: string
  }
> = {
  hourly: {
    schedule: '0 * * * *',
    retention: 1,
    name: 'Hourly backup',
    label: 'Hourly',
    description: 'Runs every hour, retained for 24 hours',
  },
  daily: {
    schedule: '0 2 * * *',
    retention: 7,
    name: 'Daily backup',
    label: 'Daily',
    description: 'Runs every day, retained for 7 days',
  },
}

/** Platform auto-created policies often use a generic label like "Default". */
export function isGenericBackupPolicyName(
  name: string | null | undefined,
): boolean {
  const normalized = (name ?? '').trim().toLowerCase()
  return normalized === '' || normalized === 'default'
}

/** Resolve a preset policy name from a cron schedule. */
export function backupPolicyNameForSchedule(
  schedule: string | null | undefined,
): string | null {
  const normalized = schedule?.trim()
  if (!normalized) return null
  for (const preset of Object.values(BACKUP_POLICY_PRESETS)) {
    if (preset.schedule === normalized) return preset.name
  }
  // Daily cron variants (minute hour * * *) that are not hourly.
  if (/^\d+\s+\d+\s+\*\s+\*\s+\*$/.test(normalized)) {
    return BACKUP_POLICY_PRESETS.daily.name
  }
  if (normalized === '0 * * * *') {
    return BACKUP_POLICY_PRESETS.hourly.name
  }
  return null
}

type CreateDatabaseBackupPoliciesProps = {
  /**
   * Organization plan `backupsEnabled`. When false and the console profile
   * supports database backups, an upgrade warning is shown. Omit while the
   * plan is still loading. Ignored when the profile disables `databaseBackups`.
   */
  planBackupsEnabled?: boolean
  /**
   * Plan `backupPolicies` cap. Same convention as databases/functions/buckets:
   * `0` = unlimited; `> 0` = hard cap. Pro (`1`) is daily-only.
   */
  backupPoliciesLimit?: number
  selectedPresets: BackupPolicyPresetId[]
  onSelectedPresetsChange: (presets: BackupPolicyPresetId[]) => void
  orgId?: string | null
}

export function CreateDatabaseBackupPolicies({
  planBackupsEnabled,
  backupPoliciesLimit = 0,
  selectedPresets,
  onSelectedPresetsChange,
  orgId,
}: CreateDatabaseBackupPoliciesProps) {
  const t = useT()
  const { features } = useConsoleProfile()

  // Console profile gate: hide entirely when backups are not part of this deployment.
  if (!features.databaseBackups) {
    return null
  }

  const canSelect = planBackupsEnabled === true
  const showUpgradeWarning = planBackupsEnabled === false
  const limit = getBackupPoliciesPlanLimit({ backupPolicies: backupPoliciesLimit })
  const supportsHourly =
    canSelect && supportsAdvancedBackupPolicies(limit)
  const atSelectionLimit = isBackupPoliciesAtPlanLimit(
    selectedPresets.length,
    limit,
  )

  const togglePreset = (preset: BackupPolicyPresetId, checked: boolean) => {
    if (!canSelect) return
    if (checked) {
      if (selectedPresets.includes(preset)) return
      if (atSelectionLimit) return
      onSelectedPresetsChange([...selectedPresets, preset])
      return
    }
    onSelectedPresetsChange(selectedPresets.filter((p) => p !== preset))
  }

  return (
    <div className="space-y-8">
      <section>
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t('Backup policies')}
            </h2>
            <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
              {t(
                'Choose when automated backups run for this database. You can change policies later from the Backups tab.',
              )}
            </p>
          </div>

          <div className="border-t border-border" />

          <div className="px-6 py-4 space-y-3">
            {showUpgradeWarning && (
              <Alert
                variant="default"
                className="border-amber-500/30 bg-amber-500/5"
              >
                <AlertCircle className="h-4 w-4 text-amber-500" />
                <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                  {t('Backups not enabled')}
                </AlertTitle>
                <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                  {t(
                    'This database will not be backed up on your current plan.',
                  )}{' '}
                  <UpgradePlanLink orgId={orgId} />{' '}
                  {t('to enable automated backups.')}
                </AlertDescription>
              </Alert>
            )}

            {supportsHourly && (
              <PresetRow
                id="wizard-backup-hourly"
                label={t(BACKUP_POLICY_PRESETS.hourly.label)}
                description={t(BACKUP_POLICY_PRESETS.hourly.description)}
                checked={selectedPresets.includes('hourly')}
                disabled={!canSelect}
                onCheckedChange={(checked) =>
                  togglePreset('hourly', checked)
                }
              />
            )}

            <PresetRow
              id="wizard-backup-daily"
              label={t(BACKUP_POLICY_PRESETS.daily.label)}
              description={t(BACKUP_POLICY_PRESETS.daily.description)}
              checked={selectedPresets.includes('daily')}
              disabled={!canSelect}
              onCheckedChange={(checked) => togglePreset('daily', checked)}
            />

            {canSelect &&
              !supportsHourly &&
              limit === 1 && (
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Your plan only supports the daily preset policy. Upgrade to create custom policies.',
                  )}
                </p>
              )}
          </div>
        </div>
      </section>
    </div>
  )
}

function PresetRow({
  id,
  label,
  description,
  checked,
  disabled,
  onCheckedChange,
}: {
  id: string
  label: string
  description: string
  checked: boolean
  disabled?: boolean
  onCheckedChange: (checked: boolean) => void
}) {
  return (
    <div
      className={cn(
        'flex items-center space-x-2 rounded-lg border border-border p-3',
        disabled && 'opacity-60',
        checked && !disabled && 'border-primary/40 bg-primary/5',
      )}
    >
      <Checkbox
        id={id}
        checked={checked}
        disabled={disabled}
        onCheckedChange={(value) => onCheckedChange(value === true)}
      />
      <Label htmlFor={id} className="flex-1 cursor-pointer">
        <div className="font-medium text-[13px]">{label}</div>
        <div className="text-[12px] text-muted-foreground">{description}</div>
      </Label>
    </div>
  )
}
