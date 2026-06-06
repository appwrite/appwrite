import { useTheme } from 'next-themes'
import { Sun, Moon, Contrast } from 'lucide-react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { cn } from '@/lib/utils'

type ThemeToggleProps = {
  /** Compact icon group for header toolbar; default includes label for menus. */
  variant?: 'menu' | 'header'
}

function ThemeToggleGroup({ className }: { className?: string }) {
  const { theme, setTheme } = useTheme()

  return (
    <ToggleGroup
      type="single"
      value={theme}
      onValueChange={(value) => {
        if (value) setTheme(value)
      }}
      className={cn('rounded-lg bg-muted/50 p-0.5', className)}
    >
      <ToggleGroupItem
        value="light"
        aria-label="Light theme"
        className="h-7 w-7 rounded-md data-[state=on]:bg-accent data-[state=on]:text-foreground"
      >
        <Sun className="h-4 w-4" />
      </ToggleGroupItem>
      <ToggleGroupItem
        value="dark"
        aria-label="Dark theme"
        className="h-7 w-7 rounded-md data-[state=on]:bg-accent data-[state=on]:text-foreground"
      >
        <Moon className="h-4 w-4" />
      </ToggleGroupItem>
      <ToggleGroupItem
        value="system"
        aria-label="System theme"
        className="h-7 w-7 rounded-md data-[state=on]:bg-accent data-[state=on]:text-foreground"
      >
        <Contrast className="h-4 w-4" />
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

export function ThemeToggle({ variant = 'menu' }: ThemeToggleProps) {
  if (variant === 'header') {
    return (
      <div className="shrink-0">
        <ThemeToggleGroup />
      </div>
    )
  }

  return (
    <div className="flex items-center justify-between px-2 py-2">
      <span className="text-sm text-muted-foreground">Theme</span>
      <ThemeToggleGroup />
    </div>
  )
}
