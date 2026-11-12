import { Lock, UserPlus, Users } from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Users,
    title: 'Create a team',
    description:
      'Group users who share access, like a workspace or an organization in your app.',
  },
  {
    icon: UserPlus,
    title: 'Invite members',
    description:
      'Add users by email and give them roles such as owner or editor.',
  },
  {
    icon: Lock,
    title: 'Grant access',
    description: 'Use team roles in permissions on rows, files, and functions.',
  },
]

/** `ring` indexes RING_RADIUS; `angle` is in degrees, clockwise from the right. */
const MEMBERS: {
  initials: string
  ring: 0 | 1
  angle: number
  role?: string
}[] = [
  { initials: 'AK', ring: 0, angle: 200, role: 'owner' },
  { initials: 'MR', ring: 0, angle: 20 },
  { initials: 'JL', ring: 0, angle: 110 },
  { initials: 'SO', ring: 1, angle: 160 },
  { initials: 'DV', ring: 1, angle: 245, role: 'editor' },
  { initials: 'TN', ring: 1, angle: 300 },
  { initials: 'EB', ring: 1, angle: 345 },
]

const RING_RADIUS = [84, 128]

/** Decorative team: members orbiting a team, plus the permissions it unlocks. */
function TeamVisual() {
  return (
    <ProductEmptyStateVisual className="h-64 w-[420px]">
      {RING_RADIUS.map((radius, index) => (
        <span
          key={radius}
          className={cn(
            'absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-foreground/15',
            index === 1 && 'border-dashed',
          )}
          style={{ width: radius * 2, height: radius * 2 }}
        />
      ))}

      <span className="absolute left-1/2 top-1/2 flex h-14 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-2xl border border-[color-mix(in_oklch,var(--brand-cta)_40%,transparent)] bg-card text-[var(--brand-cta)] shadow-lg ring-8 ring-[color-mix(in_oklch,var(--brand-cta)_10%,transparent)]">
        <Users className="h-6 w-6" />
      </span>

      {MEMBERS.map((member) => {
        const radians = (member.angle * Math.PI) / 180
        const radius = RING_RADIUS[member.ring]
        return (
          <span
            key={member.initials}
            className="absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center"
            style={{
              left: `calc(50% + ${Math.cos(radians) * radius}px)`,
              top: `calc(50% + ${Math.sin(radians) * radius}px)`,
            }}
          >
            <span
              className={cn(
                'flex items-center justify-center rounded-full border-2 border-background bg-muted font-mono font-medium text-muted-foreground shadow-sm',
                member.ring === 0
                  ? 'h-9 w-9 text-[11px]'
                  : 'h-7 w-7 text-[9px]',
              )}
            >
              {member.initials}
            </span>
            {member.role ? (
              <span className="mt-1 rounded border border-border bg-popover px-1 font-mono text-[9px] leading-4 text-muted-foreground shadow-sm">
                {member.role}
              </span>
            ) : null}
          </span>
        )
      })}

      <div
        dir="ltr"
        className="absolute -bottom-4 -end-40 rounded-lg text-start border border-border bg-popover px-3 py-2 font-mono text-[10px] leading-5 shadow-lg"
      >
        <div>
          <span className="text-muted-foreground">Permission.</span>
          <span className="text-foreground">read</span>
          <span className="text-muted-foreground">(Role.team(</span>
          <span className="text-[var(--brand-cta)]">"design"</span>
          <span className="text-muted-foreground">))</span>
        </div>
        <div>
          <span className="text-muted-foreground">Permission.</span>
          <span className="text-foreground">update</span>
          <span className="text-muted-foreground">(Role.team(</span>
          <span className="text-[var(--brand-cta)]">"design"</span>
          <span className="text-muted-foreground">, </span>
          <span className="text-[var(--brand-cta)]">"editor"</span>
          <span className="text-muted-foreground">))</span>
        </div>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function TeamsEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl(
    '/docs/products/auth/teams',
    features.marketing,
  )

  return (
    <div className="mx-auto w-full max-w-4xl py-10 sm:py-14">
      <ProductEmptyStateHero
        visual={<TeamVisual />}
        icon={Users}
        title={t('Create your first team')}
        description={t(
          'Teams let groups of users share access to your data. Use them for multi-tenancy, workspaces, organizations, or any shared space in your app.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create team')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
