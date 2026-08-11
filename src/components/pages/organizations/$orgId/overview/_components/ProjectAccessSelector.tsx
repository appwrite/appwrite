/**
 * Row editor for per-project access: "this member is a {role} on {project}".
 *
 * Each row becomes one encoded `project-{projectId}-{roleName}` membership role
 * (see `@/lib/console-project-roles`). Projects already chosen in another row are
 * filtered out so a member cannot be given two conflicting roles on one project.
 */

import { useMemo, useState } from 'react'
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
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { useProjectsForTeam } from '@/lib/react-query/hooks/projects'
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
  const [search, setSearch] = useState('')
  const { projects, isFetching } = useProjectsForTeam(orgId, 0, 25, search)

  const projectNameById = useMemo(() => {
    const map = new Map<string, string>()
    for (const project of projects) map.set(project.$id, project.name)
    return map
  }, [projects])

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
        // Exclude projects taken by *other* rows, but keep this row's own
        // selection so the trigger can still resolve its name.
        const taken = new Set(
          value.filter((_, i) => i !== index).map((r) => r.projectId),
        )
        const items = projects
          .filter((p) => !taken.has(p.$id))
          .map((p) => ({ value: p.$id, label: p.name }))

        // An already-saved project may not be in the current (searched or
        // paginated) page; keep it selectable so editing never drops a row.
        if (row.projectId && !items.some((i) => i.value === row.projectId)) {
          items.unshift({
            value: row.projectId,
            label: projectNameById.get(row.projectId) ?? row.projectId,
          })
        }

        return (
          <div key={index} className="flex items-end gap-2">
            <div className="min-w-0 flex-1 space-y-1.5">
              {index === 0 && (
                <Label className="text-[13px] font-medium">
                  {t('Project')}
                </Label>
              )}
              <SearchableSelect
                value={row.projectId}
                onValueChange={(projectId) => updateRow(index, { projectId })}
                items={items}
                placeholder={t('Select project')}
                searchPlaceholder={t('Search projects')}
                emptyMessage={t('No projects found')}
                onSearchChange={setSearch}
                isFetching={isFetching}
                showPlaceholderWhenEmpty
                triggerClassName="h-9 w-full text-[13px]"
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
