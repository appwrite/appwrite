import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { AlertTriangle } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useOrganizationPlan } from '@/lib/react-query/hooks/organizations'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  getRetentionOptions,
  MAX_DEPLOYMENT_RETENTION,
  DEPLOYMENT_RETENTION_CHANGE_DATE,
  getCloudRetentionLimit,
} from '@/lib/deployment-retention'
import { useT } from '@/lib/i18n/translate'

interface DeploymentRetentionCardProps {
  projectId: string | null | undefined
  deploymentRetention: number
  onUpdate: (deploymentRetention: number) => void
  isPending?: boolean
}

export function DeploymentRetentionCard({
  projectId,
  deploymentRetention: currentRetention,
  onUpdate,
  isPending = false,
}: DeploymentRetentionCardProps) {
  const t = useT()
  const { isCloud } = useConsoleProfile()
  const { project } = useProject(projectId ?? undefined)
  const { plan, isLoading } = useOrganizationPlan(
    isCloud ? project?.teamId : null,
  )
  const cloudLimit = getCloudRetentionLimit(plan)
  const maxRetention = isCloud ? (cloudLimit ?? 90) : MAX_DEPLOYMENT_RETENTION
  const limitsUnavailable = isCloud && (isLoading || cloudLimit === undefined)
  const beforeChange = Date.now() < DEPLOYMENT_RETENTION_CHANGE_DATE
  const [unlimitedRetention, setUnlimitedRetention] = useState(
    currentRetention === 0,
  )
  const [retentionDays, setRetentionDays] = useState(
    currentRetention > 0 ? currentRetention : 30,
  )

  useEffect(() => {
    setUnlimitedRetention(currentRetention === 0)
    setRetentionDays(currentRetention > 0 ? currentRetention : 30)
  }, [currentRetention])

  const selectedDays = Math.min(retentionDays, maxRetention)
  const retentionOptions = useMemo(
    () => getRetentionOptions(selectedDays, maxRetention),
    [selectedDays, maxRetention],
  )

  const deploymentRetention = !isCloud && unlimitedRetention ? 0 : selectedDays
  const isUnchanged = currentRetention === deploymentRetention
  const isInvalid =
    (isCloud || !unlimitedRetention) &&
    (selectedDays < 1 || selectedDays > maxRetention)

  const selectedLabel =
    retentionOptions.find((option) => option.value === selectedDays)?.label ??
    `${selectedDays} days`

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex-1">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Retention')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {t(
                'Choose how long to keep inactive deployments. Active deployments and deployments linked to a custom domain are always retained.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          {isCloud && beforeChange && (
            <Alert className="border-amber-500/30 bg-amber-500/5 [&>svg]:text-amber-700 dark:[&>svg]:text-amber-400">
              <AlertTriangle aria-hidden="true" />
              <AlertDescription className="text-[13px] text-amber-700 dark:text-amber-400">
                <p>
                  {t(
                    'From November 5, 2026, Appwrite Cloud will delete inactive deployments after 30 days by default. To keep more history, choose a longer retention period and click Update before then.',
                  )}{' '}
                  <a
                    href="https://appwrite.io/blog/post/default-deployment-retention"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline underline-offset-4"
                  >
                    {t('Learn more')}
                  </a>
                </p>
              </AlertDescription>
            </Alert>
          )}
          {!isCloud && (
            <div className="flex items-center gap-3">
              <Switch
                id="deployment-retention-unlimited"
                checked={unlimitedRetention}
                onCheckedChange={setUnlimitedRetention}
                disabled={isPending}
              />
              <Label
                htmlFor="deployment-retention-unlimited"
                className="text-[13px] text-foreground cursor-pointer"
              >
                {t('Keep deployments forever')}
              </Label>
            </div>
          )}

          {isCloud || !unlimitedRetention ? (
            <>
              <div className="space-y-2 max-w-[200px]">
                <Label htmlFor="deployment-retention" className="text-[13px]">
                  {t('Retention period')}
                </Label>
                <Select
                  value={String(selectedDays)}
                  onValueChange={(value) => setRetentionDays(Number(value))}
                  disabled={isPending || limitsUnavailable}
                >
                  <SelectTrigger
                    id="deployment-retention"
                    className="h-9 border-border bg-background text-[13px] text-foreground focus:ring-0"
                  >
                    <SelectValue placeholder={t('1 Month')} />
                  </SelectTrigger>
                  <SelectContent>
                    {retentionOptions.map((option) => (
                      <SelectItem
                        key={option.value}
                        value={String(option.value)}
                        className="text-[13px]"
                      >
                        {t(option.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {isCloud ? (
                <p className="text-[12px] text-muted-foreground">
                  {limitsUnavailable
                    ? t(
                        'Retention limits are unavailable. Try reloading this page.',
                      )
                    : maxRetention === 90
                      ? t(
                          'Your plan keeps up to 100 inactive deployments for up to 90 days.',
                        )
                      : maxRetention === 1095
                        ? t('Your plan allows retention for up to 3 years.')
                        : t('Your organization has a custom retention policy.')}
                </p>
              ) : (
                <p className="text-[12px] text-muted-foreground">
                  {`${t('Inactive deployments are deleted after')} ${t(selectedLabel).toLowerCase()}.`}
                </p>
              )}
              {isCloud && currentRetention > maxRetention && (
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Your saved retention exceeds your plan limit. Save a supported period to update it.',
                  )}
                </p>
              )}
            </>
          ) : (
            <p className="text-[12px] text-muted-foreground">
              {t('Inactive deployments will not be automatically deleted.')}
            </p>
          )}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={isUnchanged || isInvalid || isPending || limitsUnavailable}
          onClick={() => onUpdate(deploymentRetention)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
