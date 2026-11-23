import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useUpdateDistributionApp } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const NAME_MAX_LENGTH = 128

interface NameCardProps {
  projectId: string
  app: DistributionApp
}

export function NameCard({ projectId, app }: NameCardProps) {
  const t = useT()
  const updateApp = useUpdateDistributionApp(projectId)
  const [name, setName] = useSyncStateFromServer(app.name)
  const trimmed = name.trim()
  const hasChanges = trimmed !== app.name

  const handleSave = () => {
    updateApp.mutate(
      { appId: app.$id, name: trimmed },
      {
        onSuccess: () => {
          toast.success(t('App name updated'))
        },
        onError: (error) => {
          toast.error(getErrorMessage(error, t('Failed to update app name')))
        },
      },
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('The name shown for this app in the Console.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <Input
          aria-label={t('Name')}
          value={name}
          maxLength={NAME_MAX_LENGTH}
          onChange={(event) => setName(event.target.value)}
          placeholder={t('My app')}
          className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || !trimmed || updateApp.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
