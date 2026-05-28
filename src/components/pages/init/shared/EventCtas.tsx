import type { LaunchEvent } from '@/lib/init/types'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Ticket } from 'lucide-react'

export function EventCtaButton({
  cta,
  variant,
  size = 'sm',
}: {
  cta: LaunchEvent['primaryCta']
  variant: 'brandCta' | 'outline'
  size?: 'sm' | 'default' | 'lg'
}) {
  const showTicketIcon = cta.label.toLowerCase().includes('ticket')

  const content = (
    <>
      {showTicketIcon ? <Ticket className="size-4" /> : null}
      {cta.label}
    </>
  )

  const sizeClass =
    size === 'lg' ? 'h-10 text-[14px]' : size === 'default' ? 'h-9 text-[13px]' : 'h-8 text-[13px]'

  if (cta.to) {
    return (
      <Button variant={variant} size={size} className={sizeClass} asChild>
        <Link
          to={cta.to}
          search={cta.redirect ? { redirect: cta.redirect } : undefined}
        >
          {content}
        </Link>
      </Button>
    )
  }

  if (cta.href) {
    return (
      <Button variant={variant} size={size} className={sizeClass} asChild>
        <a
          href={cta.href}
          target={cta.external !== false ? '_blank' : undefined}
          rel={cta.external !== false ? 'noopener noreferrer' : undefined}
        >
          {content}
        </a>
      </Button>
    )
  }

  return (
    <Button variant={variant} size={size} className={sizeClass}>
      {content}
    </Button>
  )
}
