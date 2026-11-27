import { Badge } from '@/components/ui/badge'
import type { DistributionCheckIssue } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { CheckSeverityBadge } from './status-badges'

export function CheckIssues({ issues }: { issues: DistributionCheckIssue[] }) {
  const t = useT()
  if (issues.length === 0) return null
  return (
    <ul className="divide-y divide-border">
      {issues.map((issue, index) => (
        <li
          key={`${issue.code}-${issue.field}-${index}`}
          className="flex items-start gap-3 px-4 py-3"
        >
          <CheckSeverityBadge status={issue.severity} />
          <div className="min-w-0 flex-1 space-y-1">
            <p className="text-[13px] text-foreground break-words">
              {issue.message}
            </p>
            {issue.remediation ? (
              <p className="text-[13px] text-muted-foreground break-words">
                {issue.remediation}
              </p>
            ) : null}
            {issue.field ? (
              <p className="font-mono text-[11px] text-muted-foreground break-all">
                <span dir="ltr">{issue.field}</span>
              </p>
            ) : null}
          </div>
          {issue.manualActionRequired ? (
            <Badge variant="info" className="text-[10px] shrink-0">
              {t('Manual action required')}
            </Badge>
          ) : null}
        </li>
      ))}
    </ul>
  )
}
