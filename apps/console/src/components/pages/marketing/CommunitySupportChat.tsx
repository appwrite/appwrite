import type { ReactNode } from 'react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHAT_LINK_CLASS = 'link-neutral'

const INCOMING_NAME_CLASS = 'text-[13px] font-medium text-[#19191D]'
const INCOMING_TIME_CLASS = 'text-[11px] text-[#616161]'
const INCOMING_BODY_CLASS = 'mt-1 text-[13px] leading-6 text-[#333333]'

type ChatMessage = {
  id: string
  name: string
  time: string
  avatar: string
  fallback: string
  isReply?: boolean
  content: ReactNode
}

const CHAT_MESSAGES: ChatMessage[] = [
  {
    id: 'walter-1',
    name: "Walter O'Brien",
    time: '8:32 AM',
    avatar: '/images/community/avatars/walter.avif',
    fallback: 'WO',
    content:
      'Hello devs! I am getting a CORS error when sending a request to the backend. Can you help me?',
  },
  {
    id: 'steven-1',
    name: 'Steven',
    time: '8:38 AM',
    avatar: '/images/avatars/steven.avif',
    fallback: 'S',
    isReply: true,
    content: (
      <>
        Hey Walter! Is this the message you get{' '}
        <BlogPageAnchor
          href="/blog/post/cors-error"
          className={CHAT_LINK_CLASS}
        >
          &quot;Access blocked by CORS policy&quot;
        </BlogPageAnchor>
        ?
      </>
    ),
  },
  {
    id: 'walter-2',
    name: "Walter O'Brien",
    time: '9:05 AM',
    avatar: '/images/community/avatars/walter.avif',
    fallback: 'WO',
    content: 'Yes!',
  },
  {
    id: 'steven-2',
    name: 'Steven',
    time: '9:08 AM',
    avatar: '/images/avatars/steven.avif',
    fallback: 'S',
    isReply: true,
    content: (
      <>
        You should be able to debug this with a few steps. Just follow this blog:{' '}
        <BlogPageAnchor href="/blog/post/cors-error" className={CHAT_LINK_CLASS}>
          /blog/post/cors-error
        </BlogPageAnchor>
        . Let me know if this helps 🙂
      </>
    ),
  },
]

function ChatBubble({ message }: { message: ChatMessage }) {
  const t = useT()
  const isReply = message.isReply

  return (
    <li className={cn('flex', isReply ? 'justify-end' : 'justify-start')}>
      <div
        className={cn(
          'relative max-w-[min(340px,100%)] rounded-lg p-3 backdrop-blur-[10px]',
          isReply
            ? [
                'border border-[hsl(340_55%_80%)] bg-[hsl(330_28%_88%)] shadow-[0_1px_3px_rgba(0,0,0,0.06)]',
                'dark:border-[hsl(340_62%_86%)] dark:bg-[hsl(330_26%_91%)] dark:shadow-none',
                'before:pointer-events-none before:absolute before:end-[23px] before:-top-[15px] before:block before:h-4 before:w-[23px] before:rotate-180 before:bg-[hsl(340_55%_80%)] before:[clip-path:polygon(50%_100%,0_0,100%_0)]',
                'before:dark:bg-[hsl(340_62%_86%)]',
                'after:pointer-events-none after:absolute after:end-6 after:-top-[14px] after:block after:h-[15px] after:w-[21px] after:rotate-180 after:bg-[hsl(330_28%_88%)] after:[clip-path:polygon(50%_100%,0_0,100%_0)]',
                'after:dark:bg-[hsl(330_26%_91%)]',
              ]
            : [
                'border border-border bg-white shadow-[0_1px_4px_rgba(0,0,0,0.08)]',
                'dark:border-transparent dark:bg-[hsl(0_0%_99%)] dark:shadow-sm',
                'before:pointer-events-none before:absolute before:start-[23px] before:top-[calc(100%-1px)] before:block before:h-4 before:w-[23px] before:bg-white before:[clip-path:polygon(50%_100%,0_0,100%_0)]',
                'before:dark:bg-[hsl(0_0%_99%)]',
              ],
        )}
      >
        <div className="flex gap-3">
          <Avatar className="size-10 shrink-0 ring-1 ring-black/[0.06] dark:ring-transparent">
            <AvatarImage src={message.avatar} alt="" />
            <AvatarFallback className="text-[11px]">{message.fallback}</AvatarFallback>
          </Avatar>
          <div className="min-w-0">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
              <span className={INCOMING_NAME_CLASS}>{message.name}</span>
              <time className={INCOMING_TIME_CLASS}>{message.time}</time>
            </div>
            <p className={INCOMING_BODY_CLASS}>
              {typeof message.content === 'string' ? t(message.content) : message.content}
            </p>
          </div>
        </div>
      </div>
    </li>
  )
}

export function CommunitySupportChat() {
  return (
    <div className="rounded-xl border border-border bg-muted p-4 shadow-sm dark:bg-card/40 dark:shadow-none sm:p-6">
      <ul className="flex flex-col gap-5 sm:gap-8">
        {CHAT_MESSAGES.map((message) => (
          <ChatBubble key={message.id} message={message} />
        ))}
      </ul>
    </div>
  )
}
