import { useState } from 'react'
import { ShieldAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import {
  useAttackModeRule,
  useSetFirewallAttackMode,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { isAttackModeScope } from '@/lib/firewall/attack-mode'
import {
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from '../../shared/service-header-container'
import type { FirewallResourceSelection } from './FirewallResourceSelector'

type AttackModeConfirmIntent = 'on' | 'off'

interface AttackModeSharedProps {
  projectId: string
  resourceSelection: FirewallResourceSelection
  canWrite: boolean
}

function useAttackModeControls({
  projectId,
  resourceSelection,
  canWrite,
}: AttackModeSharedProps) {
  const t = useT()
  const resourceType = resourceSelection.resourceType
  const resourceId = resourceSelection.resourceId?.trim() || undefined
  const scopeReady = isAttackModeScope(resourceType, resourceId)
  const { rule, isOn } = useAttackModeRule(projectId, resourceType, resourceId)
  const setAttackMode = useSetFirewallAttackMode(
    projectId,
    resourceType,
    resourceId,
  )
  const [confirmIntent, setConfirmIntent] =
    useState<AttackModeConfirmIntent | null>(null)

  const pending = setAttackMode.isPending
  const confirmOpen = confirmIntent !== null

  const handleConfirm = async () => {
    if (!confirmIntent) return
    const enabled = confirmIntent === 'on'
    try {
      await setAttackMode.mutateAsync(enabled)
      toast.success(enabled ? t('Attack mode is on') : t('Attack mode is off'))
      setConfirmIntent(null)
    } catch (error) {
      toast.error(
        getErrorMessage(
          error as Error,
          enabled
            ? t('Failed to turn on attack mode')
            : t('Failed to turn off attack mode'),
        ),
      )
    }
  }

  return {
    t,
    isOn,
    hasRule: !!rule,
    scopeReady,
    canWrite,
    pending,
    confirmIntent,
    confirmOpen,
    setConfirmIntent,
    handleConfirm,
  }
}

function AttackModeConfirmDialog({
  open,
  intent,
  pending,
  onOpenChange,
  onConfirm,
}: {
  open: boolean
  intent: AttackModeConfirmIntent | null
  pending: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: () => void
}) {
  const t = useT()
  const turningOn = intent === 'on'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>
            {turningOn
              ? t('Turn on attack mode')
              : t('Turn off attack mode')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {turningOn ? (
              <>
                {t('Challenge every visitor until you turn it off.')}{' '}
                {t('Visitors must pass a challenge before they can continue.')}{' '}
                {t(
                  'Bypass rules with a lower priority number still apply.',
                )}
              </>
            ) : (
              <>
                {t(
                  'New requests will no longer be challenged by attack mode.',
                )}{' '}
                {t('Your other firewall rules stay in place.')}
              </>
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={pending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={onConfirm} disabled={pending}>
            {turningOn ? t('Turn on') : t('Turn off')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function AttackModeButton({
  projectId,
  resourceSelection,
  canWrite,
  createDisabled = false,
  createDisabledTooltip,
}: AttackModeSharedProps & {
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const {
    t,
    isOn,
    hasRule,
    scopeReady,
    pending,
    confirmIntent,
    confirmOpen,
    setConfirmIntent,
    handleConfirm,
  } = useAttackModeControls({ projectId, resourceSelection, canWrite })

  if (!scopeReady) return null

  const needsCreate = !hasRule
  const noPermission = !canWrite
  const atPlanLimit = needsCreate && createDisabled
  const disabled = noPermission || atPlanLimit || !scopeReady || pending
  const disabledTooltip = noPermission
    ? t("You don't have permission to update firewall rules.")
    : atPlanLimit
      ? (createDisabledTooltip ??
        t("You've reached the limit for this resource on your plan"))
      : undefined

  const label = t('Attack mode')
  const button = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      disabled={disabled}
      aria-pressed={isOn}
      aria-label={label}
      onClick={() => setConfirmIntent(isOn ? 'off' : 'on')}
      className={cn(
        serviceHeaderIconOnlyButton,
        'text-[13px] font-medium',
        isOn &&
          'border-amber-500/40 bg-amber-500/10 text-amber-700 hover:bg-amber-500/15 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300',
      )}
      {...analyticsAttrs('firewall-attack-mode')}
    >
      <ShieldAlert className="h-4 w-4 shrink-0" />
      <span className={serviceHeaderShowLabel}>{label}</span>
      <span className="sr-only @[640px]:hidden">{label}</span>
    </Button>
  )

  return (
    <>
      {disabled && disabledTooltip ? (
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <div>{button}</div>
            </TooltipTrigger>
            <TooltipContent side="bottom">
              <p>{disabledTooltip}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      ) : (
        button
      )}
      <AttackModeConfirmDialog
        open={confirmOpen}
        intent={confirmIntent}
        pending={pending}
        onOpenChange={(open) => {
          if (!open) setConfirmIntent(null)
        }}
        onConfirm={() => void handleConfirm()}
      />
    </>
  )
}

export function AttackModeBanner({
  projectId,
  resourceSelection,
  canWrite,
}: AttackModeSharedProps) {
  const {
    t,
    isOn,
    scopeReady,
    pending,
    confirmIntent,
    confirmOpen,
    setConfirmIntent,
    handleConfirm,
  } = useAttackModeControls({ projectId, resourceSelection, canWrite })

  if (!scopeReady || !isOn) return null

  const summary = t('Attack mode is on. Every visitor is challenged.')

  return (
    <>
      <HeaderAlertBar
        variant="warning"
        icon={ShieldAlert}
        role="status"
        aria-label={summary}
        action={
          canWrite ? (
            <button
              type="button"
              className={headerAlertOutlineButtonClass('warning')}
              disabled={pending}
              onClick={() => setConfirmIntent('off')}
              {...analyticsAttrs('firewall-attack-mode')}
            >
              {t('Turn off')}
            </button>
          ) : undefined
        }
      >
        {summary}
      </HeaderAlertBar>
      <AttackModeConfirmDialog
        open={confirmOpen && confirmIntent === 'off'}
        intent={confirmIntent}
        pending={pending}
        onOpenChange={(open) => {
          if (!open) setConfirmIntent(null)
        }}
        onConfirm={() => void handleConfirm()}
      />
    </>
  )
}
