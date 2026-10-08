/**
 * Domain Target Card
 *
 * Card-style selection for domain behaviour (Active, Branch, Redirect).
 * Matches the layout pattern of FunctionDomainCard in the function wizard.
 */

import { Rocket, GitBranch, ArrowRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'

export type DomainBehaviour = 'active' | 'branch' | 'redirect'

const STATUS_CODES = [
  { value: '301', label: '301', description: 'Moved Permanently' },
  { value: '302', label: '302', description: 'Found (temporary)' },
  { value: '307', label: '307', description: 'Temporary Redirect' },
  { value: '308', label: '308', description: 'Permanent Redirect' },
]

export interface DomainTargetCardProps {
  behaviour: DomainBehaviour
  onBehaviourChange: (v: DomainBehaviour) => void
  /** Branch name when behaviour is 'branch' */
  branch?: string
  onBranchChange?: (v: string) => void
  /** Redirect URL when behaviour is 'redirect' */
  redirectUrl?: string
  onRedirectUrlChange?: (v: string) => void
  /** Status code when behaviour is 'redirect' */
  statusCode?: string
  onStatusCodeChange?: (v: string) => void
  /** Repository context for searchable branch selector */
  projectId?: string
  installationId?: string | null
  providerRepositoryId?: string | null
  /** Whether repo is connected (needed for branch option) */
  hasRepository?: boolean
  disabled?: boolean
}

export function DomainTargetCard({
  behaviour,
  onBehaviourChange,
  branch = '',
  onBranchChange,
  redirectUrl = '',
  onRedirectUrlChange,
  statusCode = '302',
  onStatusCodeChange,
  projectId,
  installationId,
  providerRepositoryId,
  hasRepository = false,
  disabled = false,
}: DomainTargetCardProps) {
  const t = useT()
  const branchDisabled = !hasRepository
  const redirectDisabled = false

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Target')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <button
            type="button"
            onClick={() => onBehaviourChange('active')}
            disabled={disabled}
            className={cn(
              'text-start rounded-lg border p-4 transition-all cursor-pointer',
              behaviour === 'active'
                ? 'border-foreground bg-primary/5'
                : 'border-border hover:border-muted-foreground/50',
              disabled && 'opacity-50 cursor-not-allowed',
            )}
          >
            <Rocket className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">
              {t('Active deployment')}
            </div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              {t('Serves the active deployment')}
            </p>
          </button>

          <button
            type="button"
            onClick={() => !branchDisabled && onBehaviourChange('branch')}
            disabled={disabled || branchDisabled}
            title={branchDisabled ? t('Connect repository first') : undefined}
            className={cn(
              'text-start rounded-lg border p-4 transition-all cursor-pointer',
              behaviour === 'branch'
                ? 'border-foreground bg-primary/5'
                : 'border-border hover:border-muted-foreground/50',
              (disabled || branchDisabled) && 'opacity-50 cursor-not-allowed',
            )}
          >
            <GitBranch className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">{t('Branch')}</div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              {t('Serve a specific branch')}
            </p>
          </button>

          <button
            type="button"
            onClick={() => onBehaviourChange('redirect')}
            disabled={disabled || redirectDisabled}
            className={cn(
              'text-start rounded-lg border p-4 transition-all cursor-pointer',
              behaviour === 'redirect'
                ? 'border-foreground bg-primary/5'
                : 'border-border hover:border-muted-foreground/50',
              (disabled || redirectDisabled) && 'opacity-50 cursor-not-allowed',
            )}
          >
            <ArrowRight className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">{t('Redirect')}</div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              {t('Redirect to another URL')}
            </p>
          </button>
        </div>

        {behaviour === 'branch' && hasRepository && onBranchChange && (
          <BranchSelector
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            value={branch}
            onChange={onBranchChange}
            label={t('Branch')}
            placeholder={t('Select branch')}
            disabled={disabled}
            className="max-w-[280px]"
          />
        )}

        {behaviour === 'redirect' &&
          onRedirectUrlChange &&
          onStatusCodeChange && (
            <div className="space-y-3">
              <div>
                <Label className="text-[12px]">{t('Redirect URL')}</Label>
                <Input
                  placeholder="https://example.com"
                  value={redirectUrl}
                  onChange={(e) => onRedirectUrlChange(e.target.value)}
                  className="font-mono mt-1.5"
                  disabled={disabled}
                />
              </div>
              <div>
                <Label className="text-[12px]">{t('Status code')}</Label>
                <p className="text-[11px] text-muted-foreground mt-0.5 mb-1.5">
                  {t('301/308 permanent, 302/307 temporary')}
                </p>
                <Select
                  value={statusCode}
                  onValueChange={onStatusCodeChange}
                  disabled={disabled}
                >
                  <SelectTrigger className="mt-1.5 h-9 min-w-[240px] [&_[data-slot=select-value]]:line-clamp-none">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {STATUS_CODES.map((s) => (
                      <SelectItem key={s.value} value={s.value}>
                        {s.label} - {t(s.description)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
      </div>
    </div>
  )
}
