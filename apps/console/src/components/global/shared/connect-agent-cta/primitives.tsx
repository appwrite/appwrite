import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

export const AGENT_BRANDS = [
  { src: '/icons/cursor-ai.svg', label: 'Cursor' },
  { src: '/icons/claude.svg', label: 'Claude Code' },
  { src: '/icons/codex.svg', label: 'Codex' },
  { src: '/icons/vscode.svg', label: 'VS Code' },
] as const

export function AgentMarks({
  className,
  size = 'md',
}: {
  className?: string
  size?: 'sm' | 'md' | 'lg'
}) {
  const box = size === 'sm' ? 'h-7 w-7' : size === 'lg' ? 'h-11 w-11' : 'h-8 w-8'
  const icon = size === 'sm' ? 'h-3 w-3' : size === 'lg' ? 'h-5 w-5' : 'h-3.5 w-3.5'
  return (
    <div className={cn('flex items-center', className)} aria-hidden>
      {AGENT_BRANDS.map((brand, index) => (
        <span
          key={brand.label}
          className={cn(
            'flex items-center justify-center rounded-full border border-border bg-background',
            box,
            index > 0 && '-ms-1.5',
          )}
        >
          <img
            src={brand.src}
            alt=""
            className={cn(icon, PUBLIC_ICON_MUTED_CLASSES)}
          />
        </span>
      ))}
    </div>
  )
}
