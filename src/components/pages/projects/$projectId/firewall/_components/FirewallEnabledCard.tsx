import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useProject, useUpdateProjectFirewall } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'
import { Shield } from 'lucide-react'

interface FirewallEnabledCardProps {
  projectId: string
  canWrite: boolean
}

export function FirewallEnabledCard({
  projectId,
  canWrite,
}: FirewallEnabledCardProps) {
  const t = useT()
  const { projectData } = useProject(projectId)
  const updateFirewall = useUpdateProjectFirewall(projectId)
  const enabled = Boolean(projectData?.wafEnabled)

  const handleToggle = async (next: boolean) => {
    try {
      await updateFirewall.mutateAsync(next)
      toast.success(
        next ? t('Firewall enabled') : t('Firewall disabled'),
      )
    } catch (error) {
      toast.error(
        getErrorMessage(
          error as Error,
          t('Failed to update firewall settings'),
        ),
      )
    }
  }

  const switchControl = (
    <Switch
      id="firewall-enabled"
      checked={enabled}
      disabled={!canWrite || updateFirewall.isPending}
      onCheckedChange={handleToggle}
    />
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
      <div className="px-6 py-4 flex items-start justify-between gap-4">
        <div className="flex items-start gap-3 min-w-0">
          <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Shield className="h-4 w-4" />
          </div>
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Firewall protection')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-1">
              {enabled
                ? t(
                    'Firewall is evaluating rules against incoming traffic for this project.',
                  )
                : t(
                    'Enable Firewall to start evaluating rules against incoming traffic.',
                  )}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0 pt-1">
          <Label
            htmlFor="firewall-enabled"
            className="text-[12px] text-muted-foreground"
          >
            {enabled ? t('Enabled') : t('Disabled')}
          </Label>
          {!canWrite ? (
            <TooltipProvider>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span>{switchControl}</span>
                </TooltipTrigger>
                <TooltipContent>
                  {t("You don't have permission to update firewall settings.")}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            switchControl
          )}
        </div>
      </div>
    </div>
  )
}
