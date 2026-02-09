/**
 * Branch Selector Component
 *
 * A reusable dropdown for selecting a Git branch from a repository.
 * Used in function settings and site creation wizards.
 */

import { useMemo, useEffect } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { GitBranch, Info, Loader2 } from 'lucide-react'
import { useRepositoryBranches } from '@/lib/react-query/hooks'

interface BranchSelectorProps {
  projectId: string | undefined
  installationId: string | null | undefined
  providerRepositoryId: string | null | undefined
  value: string
  onChange: (branch: string) => void
  label?: string
  /** Optional tooltip text shown next to the label (e.g. for sites: production branch explanation) */
  labelTooltip?: string
  placeholder?: string
  disabled?: boolean
  className?: string
}

export function BranchSelector({
  projectId,
  installationId,
  providerRepositoryId,
  value,
  onChange,
  label = 'Branch',
  labelTooltip,
  placeholder = 'Select branch',
  disabled = false,
  className,
}: BranchSelectorProps) {
  const labelContent = (
    <>
      {label}
      {labelTooltip && (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="inline-flex ml-1.5 align-middle text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
              aria-label="More info"
            >
              <Info className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-[240px] z-[200]">
            {labelTooltip}
          </TooltipContent>
        </Tooltip>
      )}
    </>
  )

  // Fetch branches
  const { data: branchesData, isLoading: branchesLoading } =
    useRepositoryBranches(
      projectId,
      installationId || null,
      providerRepositoryId || null,
    )

  // Sort branches: main/master first, then alphabetically
  const sortedBranches = useMemo(() => {
    if (!branchesData?.branches) return []
    const branches = [...branchesData.branches]
    branches.sort((a, b) => {
      if (a.name === 'main' || a.name === 'master') return -1
      if (b.name === 'main' || b.name === 'master') return 1
      return a.name.localeCompare(b.name)
    })
    return branches
  }, [branchesData])

  // Set default branch when loaded
  useEffect(() => {
    if (sortedBranches.length > 0 && !value) {
      const defaultBranch = sortedBranches.find(
        (b) => b.name === 'main' || b.name === 'master',
      )
      onChange(defaultBranch?.name || sortedBranches[0].name)
    }
  }, [sortedBranches, value, onChange])

  if (branchesLoading) {
    return (
      <div className={className}>
        {label && (
          <Label className="text-[13px] mb-2 block">{labelContent}</Label>
        )}
        <div className="flex items-center gap-2">
          <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          <span className="text-[13px] text-muted-foreground">
            Loading branches...
          </span>
        </div>
      </div>
    )
  }

  if (sortedBranches.length > 0) {
    return (
      <div className={className}>
        {label && (
          <Label htmlFor="branch-selector" className="text-[13px] mb-2 block">
            {labelContent}
          </Label>
        )}
        <Select value={value} onValueChange={onChange} disabled={disabled}>
          <SelectTrigger id="branch-selector" className="h-9 text-[13px]">
            <SelectValue placeholder={placeholder} />
          </SelectTrigger>
          <SelectContent>
            {sortedBranches.map((branch) => (
              <SelectItem key={branch.name} value={branch.name}>
                <div className="flex items-center gap-2">
                  <GitBranch className="h-3.5 w-3.5 text-muted-foreground" />
                  {branch.name}
                </div>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    )
  }

  // Fallback to text input if no branches loaded
  return (
    <div className={className}>
      {label && (
        <Label htmlFor="branch-input" className="text-[13px] mb-2 block">
          {labelContent}
        </Label>
      )}
      <Input
        id="branch-input"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="main"
        disabled={disabled}
        className="h-9 font-mono text-[13px]"
      />
    </div>
  )
}
