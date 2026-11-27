import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  calculateDedicatedDatabaseMonthlyCost,
  getDedicatedDatabaseCreatePricing,
} from '@/lib/database-create-pricing'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { mapDedicatedDatabaseSpecifications } from '@/lib/database-specs'
import { dedicatedDatabaseSourceFromRouteKind } from '@/lib/databases/dedicated-database-source'
import {
  MIN_PITR_RETENTION_DAYS,
  parsePitrRetentionDays,
} from '@/lib/databases/dedicated-pitr'
import { useT } from '@/lib/i18n/translate'
import {
  invalidateDatabaseModel,
  updateProductDatabasePitr,
  useDatabaseSpecifications,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { ProjectDatabaseDetail } from './types'

type DatabasePitrCardProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  database: ProjectDatabaseDetail
  canWrite: boolean
}

export function DatabasePitrCard({
  projectId,
  databaseId,
  dbKind,
  database,
  canWrite,
}: DatabasePitrCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { data: specificationsData } = useDatabaseSpecifications(
    projectId,
    dedicatedDatabaseSourceFromRouteKind(dbKind),
  )
  const currentPitr = database.pitr === true
  const currentRetention = String(database.pitrRetentionDays ?? '')
  const [pitrEnabled, setPitrEnabled] = useState(currentPitr)
  const [pitrRetentionDays, setPitrRetentionDays] = useState(currentRetention)

  useEffect(() => {
    setPitrEnabled(currentPitr)
    setPitrRetentionDays(currentRetention)
  }, [currentPitr, currentRetention])

  const maxRetentionDays =
    organizationPlan?.dedicatedDatabases?.maxPitrRetentionDays ?? null

  const pricing = useMemo(
    () =>
      getDedicatedDatabaseCreatePricing(
        organizationPlan,
        specificationsData?.pricing ?? null,
      ),
    [organizationPlan, specificationsData?.pricing],
  )

  const basePriceUsd = useMemo(() => {
    const specs = mapDedicatedDatabaseSpecifications(
      specificationsData?.specifications,
    )
    return (
      specs.find((spec) => spec.id === database.specification)?.priceUsd ?? 0
    )
  }, [database.specification, specificationsData?.specifications])

  const pitrCost = calculateDedicatedDatabaseMonthlyCost({
    basePriceUsd,
    replicaCount: 0,
    pitrEnabled: true,
    pricing,
  }).pitrUsd
  const pitrRatePercent = Math.round(pricing.pitrRate * 100)
  const showPitrChargeAlert =
    features.billing && pitrEnabled && !currentPitr && pricing.pitrRate > 0

  const retentionChanged = pitrRetentionDays !== currentRetention
  const parsedRetention = parsePitrRetentionDays(
    pitrRetentionDays,
    maxRetentionDays,
  )
  const retentionInvalid =
    pitrEnabled && retentionChanged && parsedRetention == null
  const pitrDirty =
    pitrEnabled !== currentPitr || (pitrEnabled && retentionChanged)

  const updateMutation = useMutation({
    mutationFn: () =>
      updateProductDatabasePitr(projectId, databaseId, dbKind, {
        name: database.name,
        enabled: database.enabled !== false,
        pitr: pitrEnabled,
        ...(pitrEnabled && retentionChanged && parsedRetention != null
          ? { pitrRetentionDays: parsedRetention }
          : {}),
      }),
    onSuccess: async () => {
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.removeQueries({
        queryKey: ['dedicated-pitr-windows', 'project', projectId, databaseId],
      })
      queryClient.removeQueries({
        queryKey: ['dedicated-restorations', 'project', projectId, databaseId],
      })
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['database', 'project', projectId, databaseId],
        }),
        queryClient.refetchQueries({
          queryKey: ['dedicated-database', 'project', projectId, databaseId],
        }),
      ])
      toast.success(t('PITR settings updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update PITR settings')))
    },
  })

  const writeDisabled = !canWrite || updateMutation.isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined

  return (
    <div
      data-card-id="pitr"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Point-in-time recovery (PITR)')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Restore this database to any moment within the retention window.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="pitr-enabled" className="text-[13px]">
            {t('Enable PITR')}
          </Label>
          <Switch
            id="pitr-enabled"
            checked={pitrEnabled}
            onCheckedChange={setPitrEnabled}
            disabled={writeDisabled}
          />
        </div>

        {pitrEnabled ? (
          <div className="space-y-2">
            <Label htmlFor="pitr-retention" className="text-[13px]">
              {t('PITR retention (days)')}
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Sets how far back you can restore. Recovery points older than this period are deleted, so choose a window that covers how long data issues may go unnoticed.',
              )}
            </p>
            <Input
              id="pitr-retention"
              type="number"
              min={MIN_PITR_RETENTION_DAYS}
              max={maxRetentionDays ?? undefined}
              value={pitrRetentionDays}
              onChange={(event) => setPitrRetentionDays(event.target.value)}
              disabled={writeDisabled}
              aria-invalid={retentionInvalid}
              className="h-9 max-w-xs text-[13px]"
            />
            {retentionInvalid ? (
              <p className="text-[12px] text-destructive">
                {maxRetentionDays != null
                  ? t('Retention must be between 1 and {max} days.').replace(
                      '{max}',
                      String(maxRetentionDays),
                    )
                  : t('Retention must be at least 1 day.')}
              </p>
            ) : null}
          </div>
        ) : null}

        {showPitrChargeAlert ? (
          <Alert>
            <Info className="h-4 w-4" />
            <AlertDescription className="text-[12px]">
              <p>
                {t('Enabling PITR will incur an additional charge of')}{' '}
                <span className="font-medium text-foreground">
                  {formatCurrency(pitrCost, 'USD')} {t('per month')}
                </span>{' '}
                (
                {t('{percent}% of your database price').replace(
                  '{percent}',
                  String(pitrRatePercent),
                )}
                ).
              </p>
            </AlertDescription>
          </Alert>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !pitrDirty || retentionInvalid}
          title={writeTooltip}
          onClick={() => updateMutation.mutate()}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
