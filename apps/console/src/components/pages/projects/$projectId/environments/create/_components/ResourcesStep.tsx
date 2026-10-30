import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  PROJECT_ENVIRONMENT_COPY_GROUPS,
  type ProjectEnvironmentCopyGroupId,
  type ProjectEnvironmentCopySelection,
} from '@/lib/project-environments'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

interface ResourcesStepProps {
  selection: ProjectEnvironmentCopySelection
  onGroupChange: (
    groupId: ProjectEnvironmentCopyGroupId,
    patch: Partial<{ structure: boolean; data: boolean }>,
  ) => void
  isCopyEmpty: boolean
  disabled: boolean
}

/** What the environment copies from the project: a structure box per group, and its data on request. */
export function ResourcesStep({
  selection,
  onGroupChange,
  isCopyEmpty,
  disabled,
}: ResourcesStepProps) {
  const t = useT()

  return (
    <div className="space-y-2">
      <Label>{t('What to copy')}</Label>
      <div className="rounded-lg border border-border divide-y divide-border">
        {PROJECT_ENVIRONMENT_COPY_GROUPS.map((group) => {
          const picked = selection[group.id]
          return (
            <div key={group.id} className="px-3 py-2.5 space-y-2">
              <div className="flex items-start gap-3">
                <Checkbox
                  id={`environment-copy-${group.id}`}
                  checked={picked.structure}
                  onCheckedChange={(checked) =>
                    onGroupChange(
                      group.id,
                      checked === true
                        ? { structure: true }
                        : { structure: false, data: false },
                    )
                  }
                  disabled={disabled}
                  className="mt-0.5"
                />
                <div className="min-w-0 flex-1">
                  <Label
                    htmlFor={`environment-copy-${group.id}`}
                    className="cursor-pointer text-[13px] font-medium"
                  >
                    {t(group.label)}
                  </Label>
                  <p className="text-[12px] text-muted-foreground">
                    {t(group.description)}
                  </p>
                </div>
              </div>
              {group.data && (
                <div className="flex items-start gap-3 ps-7">
                  <Checkbox
                    id={`environment-copy-${group.id}-data`}
                    checked={picked.structure && picked.data}
                    onCheckedChange={(checked) =>
                      onGroupChange(group.id, { data: checked === true })
                    }
                    disabled={!picked.structure || disabled}
                    className="mt-0.5"
                  />
                  <div className="min-w-0 flex-1">
                    <Label
                      htmlFor={`environment-copy-${group.id}-data`}
                      className={cn(
                        'cursor-pointer text-[13px]',
                        !picked.structure && 'text-muted-foreground',
                      )}
                    >
                      {t(group.data.label)}
                    </Label>
                    {group.data.description && (
                      <p className="text-[12px] text-muted-foreground">
                        {t(group.data.description)}
                      </p>
                    )}
                  </div>
                </div>
              )}
            </div>
          )
        })}
      </div>
      {isCopyEmpty && (
        <p className="text-[12px] text-muted-foreground">
          {t('Pick what to copy, or start from an empty environment.')}
        </p>
      )}
    </div>
  )
}
