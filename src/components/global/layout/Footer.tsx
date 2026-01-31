import { ShieldCheck } from 'lucide-react'

/**
 * ConsoleFooter Component
 *
 * A professional footer component for the console layout displaying:
 * - Dynamic copyright year with Appwrite branding
 * - Navigation links (Docs, Status, Legal)
 * - Trust/compliance badges (SOC 2)
 * - Social media icons
 *
 * Props: None
 * State: None (stateless component)
 *
 * Usage:
 * <ConsoleFooter />
 */
export function ConsoleFooter() {
  const currentYear = new Date().getFullYear()

  const resourceLinks = [
    { label: 'Docs', href: 'https://appwrite.io/docs' },
    { label: 'Status', href: 'https://status.appwrite.online' },
  ]

  const legalLinks = [
    { label: 'Terms', href: 'https://appwrite.io/terms' },
    { label: 'Privacy', href: 'https://appwrite.io/privacy' },
  ]

  const socialLinks = [
    { label: 'GitHub', href: 'https://github.com/appwrite', icon: '/icons/github.svg' },
    { label: 'X', href: 'https://x.com/appwrite', icon: '/icons/x.svg' },
    { label: 'YouTube', href: 'https://youtube.com/@appwrite', icon: '/icons/youtube.svg' },
    { label: 'Discord', href: 'https://appwrite.io/discord', icon: '/icons/discord-simple.svg' },
  ]

  return (
    <footer className="@container flex h-[54px] shrink-0 items-center border-t border-border px-3">
      <div className="mx-auto w-full max-w-7xl flex items-center justify-between gap-2 overflow-hidden">
        {/* Left section: Logo, Resource Links, and Social Icons */}
        <div className="flex items-center gap-2 min-w-0 flex-shrink">
          {/* Logo */}
          <div className="flex items-center px-2.5 py-1.5 flex-shrink-0">
            <img
              src="/logo-theme.svg"
              alt="Appwrite"
              className="h-4 w-4 opacity-50"
            />
          </div>

          {/* Separator */}
          <div className="h-4 w-px bg-border flex-shrink-0 hidden sm:block" />

          {/* Resource Links */}
          <nav className="flex items-center flex-shrink-0">
            {resourceLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground whitespace-nowrap"
                >
                  {link.label}
                </a>
                {index < resourceLinks.length - 1 && (
                  <span className="text-border hidden sm:inline">·</span>
                )}
              </div>
            ))}
          </nav>

          {/* Separator */}
          <div className="mx-1 h-4 w-px bg-border flex-shrink-0 hidden md:block" />

          {/* Social Icons */}
          <div className="flex items-center gap-1 flex-shrink-0 hidden md:flex">
            {socialLinks.map((social) => (
              <a
                key={social.label}
                href={social.href}
                target="_blank"
                rel="noopener noreferrer"
                className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label={social.label}
              >
                <img
                  src={social.icon}
                  alt={social.label}
                  className="h-4 w-4 opacity-70 brightness-50 transition-opacity hover:opacity-100 dark:brightness-100 dark:opacity-60"
                />
              </a>
            ))}
          </div>
        </div>

        {/* Right section: Trust badge, Legal Links, and Copyright */}
        <div className="flex items-center gap-2 flex-shrink-0">
          {/* Trust / Compliance badge */}
          <a
            href="https://appwrite.io/docs/advanced/security"
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground hidden lg:flex"
          >
            <ShieldCheck className="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden />
            <span className="whitespace-nowrap font-medium">SOC 2 Certified</span>
          </a>

          {/* Separator */}
          <div className="h-4 w-px bg-border flex-shrink-0 hidden lg:block" />

          {/* Legal Links */}
          <nav className="flex items-center hidden sm:flex">
            {legalLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground whitespace-nowrap"
                >
                  {link.label}
                </a>
                {index < legalLinks.length - 1 && (
                  <span className="text-border">·</span>
                )}
              </div>
            ))}
          </nav>

          {/* Separator */}
          <div className="h-4 w-px bg-border flex-shrink-0 hidden sm:block" />

          {/* Copyright */}
          <span className="px-2.5 py-1.5 text-[13px] text-muted-foreground whitespace-nowrap">
            © {currentYear} Appwrite
          </span>
        </div>
      </div>
    </footer>
  )
}
