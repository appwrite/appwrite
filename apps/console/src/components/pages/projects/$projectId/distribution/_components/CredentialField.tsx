import type { ReactNode } from 'react'
import { Label } from '@/components/ui/label'

interface CredentialFieldProps {
  id: string
  label: string
  error?: string | null
  hint?: string
  children: ReactNode
}

export function CredentialField({
  id,
  label,
  error,
  hint,
  children,
}: CredentialFieldProps) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-[12px] font-medium">
        {label}
      </Label>
      {children}
      {error ? (
        <p className="text-[12px] text-destructive">{error}</p>
      ) : hint ? (
        <p className="text-[12px] text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  )
}
