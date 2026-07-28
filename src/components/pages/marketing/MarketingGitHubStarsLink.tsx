import { SheetClose } from '@/components/ui/sheet'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { analyticsAttrs } from '@/lib/analytics-actions'

function GitHubSolidIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

type MarketingGitHubStarsLinkProps = {
  className?: string
  mobile?: boolean
}

export function MarketingGitHubStarsLink({
  className,
  mobile = false,
}: MarketingGitHubStarsLinkProps) {
  const t = useT()
  const { link, stat } = MARKETING_SOCIAL_STATS.github

  const anchor = (
    <a
      href={link}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${t('Appwrite on GitHub')}, ${stat} ${t('stars')}` /* pragma: allowlist secret */}
      {...analyticsAttrs('marketing-nav-github')}
      className={cn(
        mobile
          ? 'flex h-10 w-full items-center justify-start gap-1.5 rounded-md px-3 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground'
          : 'inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
        className,
      )}
    >
      <GitHubSolidIcon className="h-4 w-4 shrink-0" />
      <span>{stat}</span>
    </a>
  )

  if (mobile) {
    return <SheetClose asChild>{anchor}</SheetClose>
  }

  return anchor
}
