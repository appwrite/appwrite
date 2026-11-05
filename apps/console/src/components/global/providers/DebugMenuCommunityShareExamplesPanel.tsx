import {
  COMMUNITY_SUPPORT_SHARE_TEXTS,
  type CommunitySupportShareAudience,
} from '@/lib/community/support-prompt'
import type { ConsoleProfileId } from '@/lib/console-profiles'
import { cn } from '@/lib/utils'

const GROUPS: {
  audience: CommunitySupportShareAudience
  title: string
  description: string
}[] = [
  {
    audience: 'all',
    title: 'All profiles',
    description: 'Shown for both Cloud and self-hosted.',
  },
  {
    audience: 'cloud',
    title: 'Cloud only',
    description: 'Shown when the active profile is Cloud.',
  },
  {
    audience: 'self-hosted',
    title: 'Self-hosted only',
    description: 'Shown when the active profile is self-hosted.',
  },
]

function audienceApplies(
  audience: CommunitySupportShareAudience,
  profileId: ConsoleProfileId,
) {
  return audience === 'all' || audience === profileId
}

/**
 * Debug-only (English + LTR): review community support wizard X share examples.
 */
export function DebugMenuCommunityShareExamplesPanel({
  activeProfileId,
}: {
  activeProfileId: ConsoleProfileId
}) {
  return (
    <div
      className="space-y-4 px-1"
      aria-label="Community support X share examples"
      dir="ltr"
      lang="en"
    >
      <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/90">
        Example posts for the community support wizard X card. Runtime filtering
        uses the active console profile (
        <span className="font-medium text-foreground">{activeProfileId}</span>
        ).
      </p>

      {GROUPS.map((group) => {
        const items = COMMUNITY_SUPPORT_SHARE_TEXTS.filter(
          (item) => item.audience === group.audience,
        )
        const active = audienceApplies(group.audience, activeProfileId)

        return (
          <section key={group.audience} className="space-y-2">
            <div className="flex items-start justify-between gap-3 px-1">
              <div className="min-w-0">
                <p className="text-[12px] font-semibold text-foreground">
                  {group.title}
                  <span className="ms-1.5 font-normal tabular-nums text-muted-foreground">
                    ({items.length})
                  </span>
                </p>
                <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
                  {group.description}
                </p>
              </div>
              <span
                className={cn(
                  'shrink-0 rounded-md border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider',
                  active
                    ? 'border-[color-mix(in_srgb,var(--network-globe-edge)_35%,var(--border))] bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] text-foreground'
                    : 'border-border/60 text-muted-foreground',
                )}
              >
                {active ? 'Active' : 'Hidden'}
              </span>
            </div>

            <ul className="space-y-2">
              {items.map((item, index) => (
                <li
                  key={`${group.audience}-${index}`}
                  className={cn(
                    'rounded-lg border px-3 py-2.5 text-[12px] leading-relaxed',
                    active
                      ? 'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/30 text-foreground'
                      : 'border-border/50 bg-muted/10 text-muted-foreground',
                  )}
                >
                  <p className="whitespace-pre-wrap">{item.text}</p>
                  <p className="mt-1.5 text-[10px] tabular-nums text-muted-foreground">
                    {item.text.length}/280
                  </p>
                </li>
              ))}
            </ul>
          </section>
        )
      })}
    </div>
  )
}
