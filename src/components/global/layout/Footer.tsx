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
    {
      label: 'GitHub',
      href: 'https://github.com/appwrite',
      icon: '/icons/github.svg',
    },
    { label: 'X', href: 'https://x.com/appwrite', icon: '/icons/x.svg' },
    {
      label: 'YouTube',
      href: 'https://youtube.com/@appwrite',
      icon: '/icons/youtube.svg',
    },
    {
      label: 'Discord',
      href: 'https://appwrite.io/discord',
      icon: '/icons/discord-simple.svg',
    },
  ]

  return (
    <footer className="@container flex h-[54px] shrink-0 items-center border-t border-border px-3">
      <div className="mx-auto w-full max-w-7xl flex items-center justify-between gap-2 overflow-visible">
        {/* Left section: Logo, Resource Links, and Social Icons */}
        <div className="flex items-center gap-2 min-w-0 flex-shrink">
          {/* Logo - inline SVG with currentColor so theme (black/white) works on Safari/iOS */}
          <div className="flex items-center px-2.5 py-1.5 flex-shrink-0 text-foreground opacity-60">
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
              className="h-4 w-4 shrink-0"
              aria-hidden
            >
              <path
                fill="currentColor"
                d="M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z"
              />
              <path
                fill="currentColor"
                d="M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z"
              />
            </svg>
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
            <ShieldCheck
              className="h-3.5 w-3.5 shrink-0 opacity-70"
              aria-hidden
            />
            <span className="whitespace-nowrap font-medium">
              SOC 2 Certified
            </span>
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
