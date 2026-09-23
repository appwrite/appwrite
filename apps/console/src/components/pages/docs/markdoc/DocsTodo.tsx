import { Construction } from 'lucide-react'
import type { ReactNode } from 'react'

/**
 * Work-in-progress marker for unfinished docs (pending screenshots, Console
 * steps, unverified values). Deliberately loud so it never blends into the
 * page. Find every instance with `grep -rn "docs_todo" src/content`.
 */
export function DocsTodo({
  kind,
  id,
  children,
}: {
  kind: string
  id: string
  children?: ReactNode
}) {
  return (
    <div
      className="not-prose my-6 rounded-lg border-2 border-dashed border-amber-500 bg-amber-500/10 px-4 py-3 text-[14px] leading-[1.6] text-foreground [&_code]:rounded [&_code]:bg-amber-500/15 [&_code]:px-1 [&_code]:font-mono [&_code]:text-[13px] [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:ps-5 [&_p+p]:mt-2 [&_strong]:font-semibold"
      data-docs-todo={`${kind}:${id}`}
    >
      <div className="mb-1.5 flex items-center gap-1.5 font-mono text-[12px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
        <Construction className="size-4" />
        Docs TODO · {kind} · {id}
      </div>
      {children}
    </div>
  )
}
