import { Badge } from '@/components/ui/badge'

/**
 * ConsoleFooter Component
 *
 * A footer component for the console layout displaying:
 * - Dynamic copyright year
 * - Status badge (Generally Available)
 * - Navigation links (Docs, Terms, Privacy, Cookies)
 *
 * Props: None
 * State: None (stateless component)
 *
 * Usage:
 * <ConsoleFooter />
 */
export function ConsoleFooter() {
  const currentYear = new Date().getFullYear()

  const links = [
    { label: 'Docs', href: 'https://appwrite.io/docs' },
    { label: 'Terms', href: 'https://appwrite.io/terms' },
    { label: 'Privacy', href: 'https://appwrite.io/privacy' },
    { label: 'Cookies', href: 'https://appwrite.io/cookies' },
  ]

  return (
    <footer className="@container bg-background/50 px-7 @[640px]:px-5">
      <div className="border-t border-border py-3">
        <div className="flex flex-col items-center justify-between gap-3 @[640px]:flex-row">
          {/* Left section: Copyright and Status */}
          <div className="flex flex-wrap items-center justify-center gap-3 text-[11px] text-muted-foreground @[640px]:justify-start">
            <span>© {currentYear} Appwrite. All rights reserved.</span>
            <Badge
              variant="outline"
              className="border-emerald-500/30 bg-emerald-500/10 text-[11px] text-emerald-600 dark:text-emerald-400"
            >
              <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-emerald-500" />
              Generally Available
            </Badge>
          </div>

          {/* Right section: Navigation Links */}
          <nav className="flex items-center gap-4">
            {links.map((link) => (
              <a
                key={link.label}
                href={link.href}
                target="_blank"
                rel="noopener noreferrer"
                className="text-[11px] text-muted-foreground transition-colors hover:text-foreground"
              >
                {link.label}
              </a>
            ))}
          </nav>
        </div>
      </div>
    </footer>
  )
}
