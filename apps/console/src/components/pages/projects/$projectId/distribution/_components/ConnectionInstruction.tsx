import type { ReactNode } from 'react'

interface ConnectionInstructionProps {
  index: number
  title: string
  children: ReactNode
}

export function ConnectionInstruction({
  index,
  title,
  children,
}: ConnectionInstructionProps) {
  return (
    <li className="flex gap-3">
      <span className="mt-0.5 w-4 shrink-0 tabular-nums text-muted-foreground">
        {index}.
      </span>
      <div className="space-y-1">
        <p className="font-medium">{title}</p>
        <p className="text-muted-foreground">{children}</p>
      </div>
    </li>
  )
}
