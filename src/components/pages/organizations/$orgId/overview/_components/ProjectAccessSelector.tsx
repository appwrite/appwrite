/**
 * Row editor for per-project access: "this member is a {role} on {project}".
 *
 * Each row becomes one encoded `project-{projectId}-{roleName}` membership role
 * (see `@/lib/console-project-roles`). Projects already chosen in another row are
 * filtered out so a member cannot be given two conflicting roles on one project.
 */

import { Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ProjectSelector } from '@/components/global/shared/ProjectSelector'
import {
  PROJECT_ROLE_VALUES,
  type ProjectAccessEntry,
  type ProjectRoleName,
} from '@/lib/console-project-roles'
import { useT } from '@/lib/i18n/translate'

const PROJECT_ROLE_LABELS: Record<ProjectRoleName, string> = {
  owner: 'Owner',
  developer: 'Developer',
  editor: 'Editor',
  analyst: 'Analyst',
}

export const DEFAULT_PROJECT_ROLE: ProjectRoleName = 'developer'

interface ProjectAccessSelectorProps {
  orgId: string
  value: ProjectAccessEntry[]
  onChange: (rows: ProjectAccessEntry[]) => void
}

export function ProjectAccessSelector({
  orgId,
  value,
  onChange,
}: ProjectAccessSelectorProps) {
  const t = useT()

  const updateRow = (index: number, patch: Partial<ProjectAccessEntry>) => {
    onChange(value.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  const removeRow = (index: number) => {
    onChange(value.filter((_, i) => i !== index))
  }

  const addRow = () => {
    onChange([...value, { projectId: '', roleName: DEFAULT_PROJECT_ROLE }])
  }

  return (
    <div className="space-y-2">
      {value.map((row, index) => {
        // Projects taken by other rows are dropped from this one's list so a
        // member cannot be given two roles on the same project.
        const takenByOtherRows = value
          .filter((_, i) => i !== index)
          .map((r) => r.projectId)
          .filter(Boolean)

        return (
          <div key={index} className="flex items-end gap-2">
            <div className="min-w-0 flex-1 space-y-1.5">
              {index === 0 && (
                <Label className="text-[13px] font-medium">
                  {t('Project')}
                </Label>
              )}
              <ProjectSelector
                orgTeamId={orgId}
                value={row.projectId}
                onSelectProject={(projectId) =>
                  updateRow(index, { projectId })
                }
                excludeProjectIds={takenByOtherRows}
                showProjectId
                triggerClassName="h-9 w-full text-[13px]"
                // Few projects per org, so the reserved height reads as empty space.
                listClassName="min-h-0"
              />
            </div>

            <div className="w-36 shrink-0 space-y-1.5">
              {index === 0 && (
                <Label className="text-[13px] font-medium">{t('Role')}</Label>
              )}
              <Select
                value={row.roleName}
                onValueChange={(roleName: ProjectRoleName) =>
                  updateRow(index, { roleName })
                }
              >
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PROJECT_ROLE_VALUES.map((role) => (
                    <SelectItem key={role} value={role}>
                      {t(PROJECT_ROLE_LABELS[role])}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <Button
              variant="ghost"
              size="sm"
              className="h-9 w-9 shrink-0 p-0"
              aria-label={t('Remove project')}
              onClick={() => removeRow(index)}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </div>
        )
      })}

      <Button
        variant="outline"
        size="sm"
        className="h-9 text-[13px]"
        onClick={addRow}
      >
        <Plus className="me-1.5 h-4 w-4" />
        {t('Add project')}
      </Button>
    </div>
  )
}
