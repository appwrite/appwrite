import type { ReactNode } from 'react'

export function StepSection({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: ReactNode
}) {
  return (
    <section className="space-y-5">
      <div>
        <h2 className="text-[16px] font-semibold text-foreground">{title}</h2>
        <p className="mt-1 text-[13px] text-muted-foreground">{description}</p>
      </div>
      {children}
    </section>
  )
}
