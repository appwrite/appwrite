import {
  InitialsAvatar,
  PhotoAvatar,
  type InitialsAvatarProps,
} from '@/components/global/shared/Avatar'
import type { LaunchEventOnlineUser } from '@/lib/init/types'

type InitPresenceUserAvatarProps = {
  user: LaunchEventOnlineUser
  displayName: string
  isSelf?: boolean
  identityVisible?: boolean
  size?: InitialsAvatarProps['size']
  className?: string
}

export function shouldShowInitPresenceUserPhoto(
  user: LaunchEventOnlineUser,
  options?: { isSelf?: boolean; identityVisible?: boolean },
): boolean {
  if (!user.identityHidden) return true
  return Boolean(options?.isSelf && options?.identityVisible)
}

export function InitPresenceUserAvatar({
  user,
  displayName,
  isSelf = false,
  identityVisible = true,
  size = 'sm',
  className,
}: InitPresenceUserAvatarProps) {
  const showPhoto = shouldShowInitPresenceUserPhoto(user, { isSelf, identityVisible })

  if (!showPhoto) {
    return (
      <InitialsAvatar
        name={isSelf ? displayName : user.name}
        size={size}
        className={className}
      />
    )
  }

  const photoUserId = user.ownerId ?? user.id
  return (
    <PhotoAvatar
      userId={photoUserId}
      name={displayName}
      size={size}
      className={className}
    />
  )
}
