import { ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface ConsoleLinkProps {
  href: string
  label: string
}

export function ConsoleLink({ href, label }: ConsoleLinkProps) {
  return (
    <Button asChild variant="outline" size="sm" className="h-9 text-[13px]">
      <a href={href} target="_blank" rel="noopener noreferrer">
        <ExternalLink className="h-4 w-4 me-1.5" />
        {label}
      </a>
    </Button>
  )
}
