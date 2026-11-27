import { CircleCheck, GitCommitHorizontal, MessageSquare } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import type {
  DistributionApp,
  UpdateDistributionAppParams,
} from '@/lib/appwrite/distribution'
import { isRepositorySaved } from '@/lib/distribution/repository'
import { useUpdateDistributionApp } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

interface GitAutomationCardProps {
  projectId: string
  app: DistributionApp
}

type AutomationKey = 'deployments' | 'statuses' | 'comments'

type AutomationSettings = Record<AutomationKey, boolean>

const PROVIDER_FLAGS = {
  deployments: 'providerDeployments',
  statuses: 'providerStatuses',
  comments: 'providerComments',
} as const satisfies Record<AutomationKey, keyof DistributionApp>

export function GitAutomationCard({ projectId, app }: GitAutomationCardProps) {
  const t = useT()
  const updateApp = useUpdateDistributionApp(projectId)
  const silent = app.providerSilentMode
  const saved: AutomationSettings = {
    deployments: app.providerDeployments,
    statuses: !silent && app.providerStatuses,
    comments: !silent && app.providerComments,
  }
  const [settings, setSettings] = useSyncStateFromServer(saved)

  const changedKeys = (Object.keys(PROVIDER_FLAGS) as AutomationKey[]).filter(
    (key) => settings[key] !== saved[key],
  )

  const handleSave = () => {
    const keys = silent
      ? (Object.keys(PROVIDER_FLAGS) as AutomationKey[])
      : changedKeys
    const params: UpdateDistributionAppParams = {
      appId: app.$id,
      ...(silent ? { providerSilentMode: false } : {}),
    }
    for (const key of keys) {
      params[PROVIDER_FLAGS[key]] = settings[key]
    }
    updateApp.mutate(params, {
      onSuccess: (updated) => {
        if (!isRepositorySaved(params, updated)) {
          toast.error(t('Failed to update repository settings'), {
            description: t('The server did not save these changes.'),
          })
          return
        }
        toast.success(t('Repository settings updated successfully'))
      },
      onError: (error) => {
        toast.error(
          getErrorMessage(error, t('Failed to update repository settings')),
        )
      },
    })
  }

  const rows = [
    {
      key: 'deployments',
      icon: GitCommitHorizontal,
      label: t('Build on push'),
      description: t(
        'Build every target when a push matches your triggers. When off, start builds manually.',
      ),
    },
    {
      key: 'statuses',
      icon: CircleCheck,
      label: t('Report commit status'),
      description: t(
        'Show building, passed or failed on each commit. Can be required in branch protection.',
      ),
    },
    {
      key: 'comments',
      icon: MessageSquare,
      label: t('Post build summaries'),
      description: t(
        'Distribution does not comment on pull requests yet. This preference is kept for when it does.',
      ),
    },
  ] as const

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Git automation')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Choose what happens when you push to the connected repository.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="rounded-lg border border-border bg-background divide-y divide-border">
          {rows.map((row) => {
            const Icon = row.icon
            return (
              <label
                key={row.key}
                htmlFor={`distribution-git-${row.key}`}
                className="flex cursor-pointer items-center gap-3 px-4 py-3"
              >
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-[13px] font-medium text-foreground">
                    {row.label}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {row.description}
                  </p>
                </div>
                <Switch
                  id={`distribution-git-${row.key}`}
                  checked={settings[row.key]}
                  onCheckedChange={(checked) =>
                    setSettings((previous) => ({
                      ...previous,
                      [row.key]: checked,
                    }))
                  }
                  disabled={updateApp.isPending}
                />
              </label>
            )
          })}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={changedKeys.length === 0 || updateApp.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
