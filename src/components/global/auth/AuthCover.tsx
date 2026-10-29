import { CONSOLE_AUTH_COVER, PARTNER_AUTH_COVER, type AuthAudience } from '@/lib/auth-audience'

export function AuthCover({ audience }: { audience: AuthAudience }) {
  const src = audience === 'partner' ? PARTNER_AUTH_COVER : CONSOLE_AUTH_COVER
  const size =
    audience === 'partner'
      ? { width: 908, height: 1024 }
      : { width: 600, height: 600 }

  return (
    <div className="hidden min-h-[600px] bg-background md:block">
      <img
        alt=""
        className="h-full w-full object-cover"
        height={size.height}
        src={src}
        width={size.width}
      />
    </div>
  )
}
