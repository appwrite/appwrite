import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useUpdateDistributionApp } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

interface StatusCardProps {
  projectId: string
  app: DistributionApp
}

export function StatusCard({ projectId, app }: StatusCardProps) {
  const t = useT()
  const updateApp = useUpdateDistributionApp(projectId)
  const [enabled, setEnabled] = useSyncStateFromServer(app.enabled)
  const hasChanges = enabled !== app.enabled

  const handleSave = () => {
    updateApp.mutate(
      { appId: app.$id, enabled },
      {
        onSuccess: (updated) => {
          toast.success(updated.enabled ? t('App enabled') : t('App disabled'))
        },
        onError: (error) => {
          toast.error(getErrorMessage(error, t('Failed to update app status')))
        },
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Status')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('A disabled app ignores pushes and does not accept new builds.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id="distribution-app-enabled"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={updateApp.isPending}
          />
          <Label
            htmlFor="distribution-app-enabled"
            className="text-[13px] text-foreground"
          >
            {enabled ? t('Enabled') : t('Disabled')}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateApp.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
