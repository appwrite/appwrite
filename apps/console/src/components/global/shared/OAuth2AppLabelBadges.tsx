import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type KnownLabel = {
  label: string
  variant: 'success' | 'info' | 'warning'
}

/**
 * App labels with a user-facing meaning. `official`, `verified`, and
 * `suggested` are curated by Appwrite; `oauth-dcr` marks clients that
 * registered themselves through the dynamic client registration endpoint.
 */
const KNOWN_LABELS: Record<string, KnownLabel> = {
  official: { label: 'Official', variant: 'success' },
  verified: { label: 'Verified', variant: 'success' },
  suggested: { label: 'Suggested app', variant: 'info' },
  'oauth-dcr': { label: 'Self-registered', variant: 'warning' },
}

/** Official apps are managed by Appwrite; the API rejects updates to them. */
export function isOAuth2AppReadOnly(
  labels: string[] | null | undefined,
): boolean {
  return (labels ?? []).includes('official')
}

export function OAuth2AppLabelBadges({
  labels,
  className,
}: {
  labels: string[] | null | undefined
  className?: string
}) {
  const t = useT()
  const known = (labels ?? []).filter((label) => label in KNOWN_LABELS)
  if (known.length === 0) return null

  return (
    <span className={cn('inline-flex flex-wrap items-center gap-1', className)}>
      {known.map((label) => {
        const meta = KNOWN_LABELS[label]
        return (
          <Badge
            key={label}
            variant={meta.variant}
            className="text-[10px] shrink-0"
          >
            {t(meta.label)}
          </Badge>
        )
      })}
    </span>
  )
}
