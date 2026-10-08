import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
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
} from '@/lib/deployment-retention'
import { useT } from '@/lib/i18n/translate'

interface DeploymentRetentionCardProps {
  deploymentRetention: number
  onUpdate: (deploymentRetention: number) => void
  isPending?: boolean
}

export function DeploymentRetentionCard({
  deploymentRetention: currentRetention,
  onUpdate,
  isPending = false,
}: DeploymentRetentionCardProps) {
  const t = useT()
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

  const retentionOptions = useMemo(
    () => getRetentionOptions(retentionDays),
    [retentionDays],
  )

  const deploymentRetention = unlimitedRetention ? 0 : retentionDays
  const isUnchanged = currentRetention === deploymentRetention
  const isInvalid =
    !unlimitedRetention &&
    (retentionDays < 1 || retentionDays > MAX_DEPLOYMENT_RETENTION)

  const selectedLabel =
    retentionOptions.find((option) => option.value === retentionDays)?.label ??
    `${retentionDays} days`

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
                'Control how long inactive deployments are kept before they are automatically deleted. Active deployments are always retained.',
              )}
            </p>
          </div>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
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

          {!unlimitedRetention ? (
            <>
              <div className="space-y-2 max-w-[200px]">
                <Label htmlFor="deployment-retention" className="text-[13px]">
                  {t('Retention period')}
                </Label>
                <Select
                  value={String(retentionDays)}
                  onValueChange={(value) => setRetentionDays(Number(value))}
                  disabled={isPending}
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
              <p className="text-[12px] text-muted-foreground">
                {`${t('Inactive deployments are deleted after')} ${t(selectedLabel).toLowerCase()}.`}
              </p>
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
          disabled={isUnchanged || isInvalid || isPending}
          onClick={() => onUpdate(deploymentRetention)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
