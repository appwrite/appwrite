import { createFileRoute, redirect } from '@tanstack/react-router'

const DISCORD_INVITE_URL = 'https://discord.com/invite/appwrite'

export const Route = createFileRoute('/discord')({
  beforeLoad: () => {
    throw redirect({
      href: DISCORD_INVITE_URL,
      statusCode: 302,
      replace: true,
    })
  },
})
