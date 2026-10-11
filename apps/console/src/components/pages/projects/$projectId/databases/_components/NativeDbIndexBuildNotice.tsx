import { AlertCircle, Clock } from 'lucide-react'
import {
  formatNativeEstimatedRows,
  formatNativeTableBytes,
  getNativeIndexBuildEstimate,
  type NativeDbEngine,
  type NativeIndexBuildFactor,
} from '@/lib/databases/native-index-build-estimate'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type NativeDbIndexBuildNoticeProps = {
  engine: NativeDbEngine
  estimatedRows?: number | string | null
  totalBytes?: number | string | null
  isLoading?: boolean
  algorithm?: string | null
  unique?: boolean
  hasCondition?: boolean
  placement?: 'inline' | 'footer'
}

const SEVERITY_TITLE_KEY = {
  moderate: 'Index build may take a few minutes on this table',
  significant: 'Index build may take considerable time on this table',
  major: 'Index build may take a long time on this table',
} as const

const ENGINE_DESCRIPTION_KEY = {
  postgres: {
    moderate:
      'Creating an index scans existing rows. Writes to this table are blocked until the build finishes.',
    significant:
      'Creating an index scans existing rows. Writes to this table remain blocked for the full duration, which can be substantial on large tables.',
    major:
      'Creating an index scans existing rows. Expect extended write blocking on this table until the build completes.',
  },
  mysql: {
    moderate:
      'Creating an index scans existing rows. The build runs online but may still take noticeable time on larger tables.',
    significant:
      'Creating an index scans existing rows. The build runs online but may consume significant resources and take considerable time.',
    major:
      'Creating an index scans existing rows. Even with online DDL, builds on tables of this size can run for an extended period.',
  },
} as const

function factorLabel(
  factor: NativeIndexBuildFactor,
  algorithm: string | undefined,
  t: (text: string) => string,
): string {
  switch (factor) {
    case 'unique-index':
      return t(
        'Unique indexes verify every row, which adds time on large tables.',
      )
    case 'slow-algorithm': {
      const algorithmLabel = algorithm?.trim().toUpperCase() || 'GIN'
      return `${algorithmLabel} ${t('indexes take longer to build than B-tree.')}`
    }
    case 'partial-index':
      return t('A partial index may reduce the amount of data scanned.')
    default:
      return ''
  }
}

export function NativeDbIndexBuildNotice({
  engine,
  estimatedRows,
  totalBytes,
  isLoading = false,
  algorithm,
  unique = false,
  hasCondition = false,
  placement = 'inline',
}: NativeDbIndexBuildNoticeProps) {
  const t = useT()

  if (isLoading) return null

  const estimate = getNativeIndexBuildEstimate({
    estimatedRows,
    totalBytes,
    algorithm,
    unique,
    hasCondition,
  })

  if (!estimate) return null

  const Icon = estimate.severity === 'moderate' ? Clock : AlertCircle
  const showStats =
    estimate.estimatedRows != null || estimate.totalBytes != null
  const factorMessages = estimate.factors
    .map((factor) => factorLabel(factor, algorithm ?? undefined, t))
    .filter(Boolean)

  if (placement === 'footer') {
    const statParts: string[] = []
    if (estimate.estimatedRows != null) {
      statParts.push(
        `${t('Estimated rows')}: ${formatNativeEstimatedRows(estimate.estimatedRows)}`,
      )
    }
    if (estimate.totalBytes != null) {
      statParts.push(
        `${t('Total size')}: ${formatNativeTableBytes(estimate.totalBytes)}`,
      )
    }

    return (
      <div
        role="status"
        className={cn(
          'border-t px-6 py-3',
          estimate.severity === 'major'
            ? 'border-amber-500/30 bg-amber-500/[0.08]'
            : estimate.severity === 'significant'
              ? 'border-amber-500/25 bg-amber-500/[0.06]'
              : 'border-amber-500/20 bg-amber-500/[0.04]',
        )}
      >
        <div className="flex gap-2.5">
          <Icon
            className={cn(
              'h-4 w-4 shrink-0 mt-0.5',
              estimate.severity === 'moderate'
                ? 'text-amber-600 dark:text-amber-400'
                : 'text-amber-600 dark:text-amber-300',
            )}
          />
          <div className="min-w-0 space-y-1">
            <p className="text-[12px] font-medium text-amber-950 dark:text-amber-50">
              {t(SEVERITY_TITLE_KEY[estimate.severity])}
            </p>
            <p className="text-[11px] leading-relaxed text-amber-900/80 dark:text-amber-100/75">
              {t(ENGINE_DESCRIPTION_KEY[engine][estimate.severity])}
            </p>
            {statParts.length > 0 ? (
              <p className="text-[11px] tabular-nums text-amber-800/80 dark:text-amber-100/70">
                {statParts.join(' · ')}
              </p>
            ) : null}
            {factorMessages.length > 0 ? (
              <p className="text-[11px] leading-relaxed text-amber-800/75 dark:text-amber-100/65">
                {factorMessages.join(' · ')}
              </p>
            ) : null}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div
      role="status"
      className={cn(
        'rounded-lg border px-4 py-3',
        estimate.severity === 'major'
          ? 'border-amber-500/40 bg-amber-500/[0.07]'
          : estimate.severity === 'significant'
            ? 'border-amber-500/30 bg-amber-500/[0.05]'
            : 'border-amber-500/20 bg-amber-500/[0.03]',
      )}
    >
      <div className="flex gap-3">
        <Icon
          className={cn(
            'h-4 w-4 shrink-0 mt-0.5',
            estimate.severity === 'moderate'
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-amber-600 dark:text-amber-300',
          )}
        />
        <div className="min-w-0 space-y-2">
          <p className="text-[13px] font-medium text-amber-950 dark:text-amber-50">
            {t(SEVERITY_TITLE_KEY[estimate.severity])}
          </p>
          <p className="text-[12px] leading-relaxed text-amber-900/80 dark:text-amber-100/80">
            {t(ENGINE_DESCRIPTION_KEY[engine][estimate.severity])}
          </p>

          {showStats ? (
            <dl className="flex flex-wrap gap-x-4 gap-y-1 pt-0.5">
              {estimate.estimatedRows != null ? (
                <div className="flex items-baseline gap-1.5">
                  <dt className="text-[11px] font-medium uppercase tracking-wide text-amber-800/70 dark:text-amber-200/60">
                    {t('Estimated rows')}
                  </dt>
                  <dd className="text-[12px] tabular-nums text-amber-950 dark:text-amber-50">
                    {formatNativeEstimatedRows(estimate.estimatedRows)}
                  </dd>
                </div>
              ) : null}
              {estimate.totalBytes != null ? (
                <div className="flex items-baseline gap-1.5">
                  <dt className="text-[11px] font-medium uppercase tracking-wide text-amber-800/70 dark:text-amber-200/60">
                    {t('Total size')}
                  </dt>
                  <dd className="text-[12px] tabular-nums text-amber-950 dark:text-amber-50">
                    {formatNativeTableBytes(estimate.totalBytes)}
                  </dd>
                </div>
              ) : null}
            </dl>
          ) : null}

          {factorMessages.length > 0 ? (
            <ul className="space-y-1 pt-0.5">
              {factorMessages.map((message) => (
                <li
                  key={message}
                  className="text-[11px] leading-relaxed text-amber-900/75 dark:text-amber-100/70 before:content-['•'] before:mr-1.5"
                >
                  {message}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </div>
    </div>
  )
}
