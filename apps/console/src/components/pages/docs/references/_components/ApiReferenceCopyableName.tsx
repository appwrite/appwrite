'use client'

import { CopyableId } from '@/components/global/shared/CopyableId'
import { cn } from '@/lib/utils'

type ApiReferenceCopyableNameProps = {
  name: string
  className?: string
  textClassName?: string
}

export function ApiReferenceCopyableName({
  name,
  className,
  textClassName,
}: ApiReferenceCopyableNameProps) {
  return (
    <CopyableId
      id={name}
      variant="inline"
      size="md"
      showCopyOnHover
      copyToastLabel="Name"
      constrainToContainer
      className={cn(
        'min-w-0 max-w-full font-mono px-0 py-0 text-start',
        textClassName,
        className,
      )}
    />
  )
}
