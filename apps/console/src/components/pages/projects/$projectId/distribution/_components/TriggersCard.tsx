import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { InputTags } from '@/components/ui/input-tags'
import { Label } from '@/components/ui/label'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import type {
  DistributionApp,
  UpdateDistributionAppParams,
} from '@/lib/appwrite/distribution'
import {
  normalizeTriggerPatterns,
  triggerPatternsEqual,
  validateTriggerPatterns,
} from '@/lib/git-build-triggers'
import { isRepositorySaved } from '@/lib/distribution/repository'
import { useUpdateDistributionApp } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

interface TriggersCardProps {
  projectId: string
  app: DistributionApp
}

type TriggerField = 'providerBranches' | 'providerPaths' | 'providerTags'

const TRIGGER_FIELDS: TriggerField[] = [
  'providerBranches',
  'providerPaths',
  'providerTags',
]

function hasInvalidPatterns(patterns: string[]): boolean {
  return validateTriggerPatterns(patterns, []).length > 0
}

export function TriggersCard({ projectId, app }: TriggersCardProps) {
  const t = useT()
  const updateApp = useUpdateDistributionApp(projectId)
  const [triggers, setTriggers] = useSyncStateFromServer<
    Record<TriggerField, string[]>
  >({
    providerBranches: app.providerBranches,
    providerPaths: app.providerPaths,
    providerTags: app.providerTags,
  })

  const changedFields = TRIGGER_FIELDS.filter(
    (field) => !triggerPatternsEqual(triggers[field], app[field]),
  )
  const invalidFields = TRIGGER_FIELDS.filter((field) =>
    hasInvalidPatterns(triggers[field]),
  )

  const handleSave = () => {
    const params: UpdateDistributionAppParams = { appId: app.$id }
    for (const field of changedFields) {
      params[field] = normalizeTriggerPatterns(triggers[field])
    }
    updateApp.mutate(params, {
      onSuccess: (updated) => {
        if (!isRepositorySaved(params, updated)) {
          toast.error(t('Failed to update triggers'), {
            description: t('The server did not save these changes.'),
          })
          return
        }
        toast.success(t('Triggers updated.'))
      },
      onError: (error) => {
        toast.error(getErrorMessage(error, t('Failed to update triggers')))
      },
    })
  }

  const productionBranch = app.providerBranch || 'main'
  const fields = [
    {
      field: 'providerBranches',
      label: t('Branches'),
      description: t(
        'Pushes to these branches start a build. When empty, only the production branch builds.',
      ),
      placeholder: `${productionBranch}, release/*, !draft/*`,
    },
    {
      field: 'providerPaths',
      label: t('Paths'),
      description: t(
        'Only changes to these paths start a build. When empty, any change builds.',
      ),
      placeholder: 'lib/**, pubspec.yaml, !docs/**',
    },
    {
      field: 'providerTags',
      label: t('Tags'),
      description: t(
        'Pushing a matching tag starts a build. When empty, tags never build. GitHub and Gitea only.',
      ),
      placeholder: 'v*, release-*',
    },
  ] as const

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Triggers')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Limit which pushes build this app. Use glob patterns, and start a pattern with ! to exclude it.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-5">
        {fields.map(({ field, label, description, placeholder }) => (
          <div key={field}>
            <Label
              htmlFor={`distribution-${field}`}
              className="text-[13px] block"
            >
              {label}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {description}
            </p>
            <InputTags
              id={`distribution-${field}`}
              value={triggers[field]}
              onChange={(value) =>
                setTriggers((previous) => ({ ...previous, [field]: value }))
              }
              disabled={updateApp.isPending}
              splitOnComma
              placeholder={placeholder}
              className="mt-2"
            />
            {invalidFields.includes(field) ? (
              <p className="text-[12px] text-destructive mt-1">
                {t(
                  'Use letters, numbers and glob characters, and do not include and exclude the same pattern.',
                )}
              </p>
            ) : null}
          </div>
        ))}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            changedFields.length === 0 ||
            invalidFields.length > 0 ||
            updateApp.isPending
          }
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
