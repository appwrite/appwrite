/**
 * Connect VCS Link
 *
 * A "Connect to GitHub" link that offers the user's existing installations
 * before sending them to the provider. Without a project it stays a plain link.
 */

import type { ComponentProps, MouseEvent } from 'react'
import { openInstallationPicker } from '@/lib/vcs/installation-picker'
import type { VcsProviderId } from '@/lib/vcs/providers'

export interface ConnectVcsLinkProps extends ComponentProps<'a'> {
  href: string
  projectId: string | null | undefined
  provider: VcsProviderId
}

export function ConnectVcsLink({
  projectId,
  provider,
  onClick,
  ...props
}: ConnectVcsLinkProps) {
  const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
    onClick?.(event)
    // Modified clicks keep their browser meaning (open in a new tab, etc.).
    if (
      event.defaultPrevented ||
      !projectId ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return
    }
    event.preventDefault()
    openInstallationPicker({
      projectId,
      provider,
      href: props.href,
      target: props.target,
    })
  }

  return <a {...props} onClick={handleClick} />
}
