import { Globe, Monitor, Smartphone, Watch, Tv } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useState } from 'react'

interface PlatformIconProps {
  platform: string
  className?: string
  size?: 'sm' | 'md' | 'lg'
  initialIcon?: 'js' | 'ts'
}

const sizeClasses = {
  sm: 'h-5 w-5',
  md: 'h-6 w-6',
  lg: 'h-8 w-8',
}

// Flutter icon SVG
const FlutterIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M14.314 0L2.3 12 6 15.7 21.684.013h-7.357L14.314 0zm.014 11.072L7.857 17.53l6.47 6.47H21.7l-6.46-6.468 6.46-6.46h-7.37z" />
  </svg>
)

// Apple icon SVG
const AppleIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z" />
  </svg>
)

// Android icon SVG
const AndroidIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M17.523 15.341c-.5 0-.91-.41-.91-.91s.41-.91.91-.91.91.41.91.91-.41.91-.91.91m-11.046 0c-.5 0-.91-.41-.91-.91s.41-.91.91-.91.91.41.91.91-.41.91-.91.91m11.4-6.02l1.97-3.41c.11-.19.05-.43-.14-.54-.19-.11-.43-.05-.54.14l-2 3.46C14.99 8.07 12.57 7.5 12 7.5s-2.99.57-5.16 1.46l-2-3.46c-.11-.19-.35-.25-.54-.14-.19.11-.25.35-.14.54l1.97 3.41C2.79 11.27.5 14.58.5 18.5h23c0-3.92-2.29-7.23-5.63-9.18" />
  </svg>
)

// React Native icon SVG
const ReactNativeIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M14.23 12.004a2.236 2.236 0 0 1-2.235 2.236 2.236 2.236 0 0 1-2.236-2.236 2.236 2.236 0 0 1 2.235-2.236 2.236 2.236 0 0 1 2.236 2.236zm2.648-10.69c-1.346 0-3.107.96-4.888 2.622-1.78-1.653-3.542-2.602-4.887-2.602-.41 0-.783.093-1.106.278-1.375.793-1.683 3.264-.973 6.365C1.98 8.917 0 10.42 0 12.004c0 1.59 1.99 3.097 5.043 4.03-.704 3.113-.39 5.588.988 6.38.32.187.69.275 1.102.275 1.345 0 3.107-.96 4.888-2.624 1.78 1.654 3.542 2.603 4.887 2.603.41 0 .783-.09 1.106-.275 1.374-.792 1.683-3.263.973-6.365C22.02 15.096 24 13.59 24 12.004c0-1.59-1.99-3.097-5.043-4.032.704-3.11.39-5.587-.988-6.38-.318-.184-.688-.277-1.092-.278zm-.005 1.09v.006c.225 0 .406.044.558.127.666.382.955 1.835.73 3.704-.054.46-.142.945-.25 1.44-.96-.236-2.006-.417-3.107-.534-.66-.905-1.345-1.727-2.035-2.447 1.592-1.48 3.087-2.292 4.105-2.295zm-9.77.02c1.012 0 2.514.808 4.11 2.28-.686.72-1.37 1.537-2.02 2.442-1.107.117-2.154.298-3.113.538-.112-.49-.195-.964-.254-1.42-.23-1.868.054-3.32.714-3.707.19-.09.4-.127.563-.132zm4.882 3.05c.455.468.91.992 1.36 1.564-.44-.02-.89-.034-1.345-.034-.46 0-.915.01-1.36.034.44-.572.895-1.096 1.345-1.565zM12 8.1c.74 0 1.477.034 2.202.093.406.582.802 1.203 1.183 1.86.372.64.71 1.29 1.018 1.946-.308.655-.646 1.31-1.013 1.95-.38.66-.773 1.288-1.18 1.87-.728.063-1.466.098-2.21.098-.74 0-1.477-.035-2.202-.093-.406-.582-.802-1.204-1.183-1.86-.372-.64-.71-1.29-1.018-1.946.303-.657.646-1.313 1.013-1.954.38-.66.773-1.286 1.18-1.868.728-.064 1.466-.098 2.21-.098zm-3.635.254c-.24.377-.48.763-.704 1.16-.225.39-.435.782-.635 1.174-.265-.656-.49-1.31-.676-1.947.64-.15 1.315-.283 2.015-.386zm7.26 0c.695.103 1.365.23 2.006.387-.18.632-.405 1.282-.66 1.933-.2-.39-.41-.783-.64-1.174-.225-.392-.465-.774-.705-1.146zm3.063.675c.484.15.944.317 1.375.498 1.732.74 2.852 1.708 2.852 2.476-.005.768-1.125 1.74-2.857 2.475-.42.18-.88.342-1.355.493-.28-.958-.646-1.956-1.1-2.98.45-1.017.81-2.01 1.085-2.964zm-13.395.004c.278.96.645 1.957 1.1 2.98-.45 1.017-.812 2.01-1.086 2.964-.484-.15-.944-.318-1.37-.5-1.732-.737-2.852-1.706-2.852-2.474 0-.768 1.12-1.742 2.852-2.476.42-.18.88-.342 1.356-.494zm11.678 4.28c.265.657.49 1.312.676 1.948-.64.157-1.316.29-2.016.39.24-.375.48-.762.705-1.158.225-.39.435-.788.636-1.18zm-9.945.02c.2.392.41.783.64 1.175.23.39.465.772.705 1.143-.695-.102-1.365-.23-2.006-.386.18-.63.406-1.282.66-1.933zM17.92 16.32c.112.493.2.968.254 1.423.23 1.868-.054 3.32-.714 3.708-.147.09-.338.128-.563.128-1.012 0-2.514-.807-4.11-2.28.686-.72 1.37-1.536 2.02-2.44 1.107-.118 2.154-.3 3.113-.54zm-11.83.01c.96.234 2.006.415 3.107.532.66.905 1.345 1.727 2.035 2.446-1.595 1.483-3.092 2.295-4.11 2.295-.22-.005-.406-.05-.553-.132-.666-.38-.955-1.834-.73-3.703.054-.46.142-.944.25-1.438zm4.56.64c.44.02.89.034 1.345.034.46 0 .915-.01 1.36-.034-.44.572-.895 1.095-1.345 1.565-.455-.47-.91-.993-1.36-1.565z" />
  </svg>
)

// Unity icon SVG
const UnityIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M15.858 19.448l-3.293-1.9-3.293 1.9L3.94 17.648a.785.785 0 0 1-.42-.42L1.72 13.728l3.293-1.9-3.293-1.9 1.8-5.5a.785.785 0 0 1 .42-.42l5.5-1.8 3.293 1.9 3.293-1.9 5.5 1.8a.785.785 0 0 1 .42.42l1.8 5.5-3.293 1.9 3.293 1.9-1.8 5.5a.785.785 0 0 1-.42.42l-5.5 1.8zm-1.132-1.132l4.368-1.424 1.424-4.368-2.944 1.7-.488.282-.488-.282-2.944-1.7 2.944-1.7.488-.282.488.282 2.944 1.7-1.424-4.368-4.368-1.424-2.944 1.7-.488.282-.488-.282-2.944-1.7-1.424 4.368 2.944 1.7.488.282.488-.282 2.944-1.7-2.944-1.7-.488-.282-.488.282-2.944 1.7 1.424 4.368 4.368 1.424 2.944-1.7.488-.282.488.282z" />
  </svg>
)

// Windows icon SVG
const WindowsIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M3 12V6.75l6-1.32v6.48L3 12zm17-9v8.75l-10 .15V5.21L20 3zM3 13l6 .09v7.81l-6-1.15V13zm17 9v-8.47l-10 .15v7.81l10 1.51z" />
  </svg>
)

// Linux icon SVG
const LinuxIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 266 312" fill="currentColor">
    <path d="M128.6640625 79.2793c0 1-1 1-1 1h-1c-1 0-1-1-2-2 0 0-1-1-1-2s0-1 1-1l2 1c1 1 2 2 2 3m-18-10c0-5-2-8-5-8 0 0 0 1-1 1v2h3c0 2 1 3 1 5h2m35-5c2 0 3 2 4 5h2c-1-1-1-2-1-3s0-2-1-3-2-2-3-2c0 0-1 1-2 1 0 1 1 1 1 2m-30 16c-1 0-1 0-1-1s0-2 1-3c2 0 3-1 3-1 1 0 1 1 1 1 0 1-1 2-3 4h-1m-11-1c-4-2-5-5-5-10 0-3 0-5 2-7 1-2 3-3 5-3s3 1 5 3c1 3 2 6 2 9v2h1v-1c1 0 1-2 1-6 0-3 0-6-2-9s-4-5-8-5c-3 0-6 2-7 5-2 4-2.4 7-2.4 12 0 4 1.4 8 5.4 12 1-1 2-1 3-2m125 141c1 0 1-.4 1-1.3 0-2.2-1-4.8-4-7.7-3-3-8-4.9-14-5.7-1-.1-2-.1-2-.1-1-.2-1-.2-2-.2-1-.1-3-.3-4-.5 3-9.3 4-17.5 4-24.7 0-10-2-17-6-23s-8-9-13-10c-1 1-1 1-1 2 5 2 10 6 13 12 3 7 4 13 4 20 0 5.6-1 13.9-5 24.5-4 1.6-8 5.3-11 11.1 0 .9 0 1.4 1 1.4 0 0 1-.9 2-2.6 2-1.7 3-3.4 5-5.1 3-1.7 5-2.6 8-2.6 5 0 10 .7 13 2.1 4 1.3 6 2.7 7 4.3 1 1.5 2 2.9 3 4.2 0 1.3 1 1.9 1 1.9m-92-145c-1-1-1-3-1-5 0-4 0-6 2-9 2-2 4-3 6-3 3 0 5 2 7 4 1 3 2 5 2 8 0 5-2 8-6 9 0 0 1 1 2 1 2 0 3 1 5 2 1-6 2-10 2-15 0-6-1-10-3-13-3-3-6-4-10-4-3 0-6 1-9 3-2 3-3 5-3 8 0 5 1 9 3 13 1 0 2 1 3 1m12 16c-13 9-23 13-31 13-7 0-14-3-20-8 1 2 2 4 3 5l6 6c4 4 9 6 14 6 7 0 15-4 25-11l9-6c2-2 4-4 4-7 0-1 0-2-1-2-1-2-6-5-16-8-9-4-16-6-20-6-3 0-8 2-15 6-6 4-10 8-10 12 0 0 1 1 2 3 6 5 12 8 18 8 8 0 18-4 31-14v2c1 0 1 1 1 1m23 202c4 7.52 11 11.3 19 11.3 2 0 4-.3 6-.9 2-.4 4-1.1 5-1.9 1-.7 2-1.4 3-2.2 2-.7 2-1.2 3-1.7l17-14.7c4-3.19 8-5.98 13-8.4 4-2.4 8-4 10-4.9 3-.8 5-2 7-3.6 1-1.5 2-3.4 2-5.8 0-2.9-2-5.1-4-6.7s-4-2.7-6-3.4-4-2.3-7-5c-2-2.6-4-6.2-5-10.9l-1-5.8c-1-2.7-1-4.7-2-5.8 0-.3 0-.4-1-.4s-3 .9-4 2.6c-2 1.7-4 3.6-6 5.6-1 2-4 3.8-6 5.5-3 1.7-6 2.6-8 2.6-8 0-12-2.2-15-6.5-2-3.2-3-6.9-4-11.1-2-1.7-3-2.6-5-2.6-5 0-7 5.2-7 15.7v31.1c0 .9-1 2.9-1 6-1 3.1-1 6.62-1 10.6l-2 11.1v.17m-145-5.29c9.3 1.36 20 4.27 32.1 8.71 12.1 4.4 19.5 6.7 22.2 6.7 7 0 12.8-3.1 17.6-9.09 1-1.94 1-4.22 1-6.84 0-9.45-5.7-21.4-17.1-35.9l-6.8-9.1c-1.4-1.9-3.1-4.8-5.3-8.7-2.1-3.9-4-6.9-5.5-9-1.3-2.3-3.4-4.6-6.1-6.9-2.6-2.3-5.6-3.8-8.9-4.6-4.2.8-7.1 2.2-8.5 4.1s-2.2 4-2.4 6.2c-.3 2.1-.9 3.5-1.9 4.2-1 .6-2.7 1.1-5 1.6-.5 0-1.4 0-2.7.1h-2.7c-5.3 0-8.9.6-10.8 1.6-2.5 2.9-3.8 6.2-3.8 9.7 0 1.6.4 4.3 1.2 8.1.8 3.7 1.2 6.7 1.2 8.8 0 4.1-1.2 8.2-3.7 12.3-2.5 4.3-3.8 7.5-3.8 9.78 1 3.88 7.6 6.61 19.7 8.21m33.3-90.9c0-6.9 1.8-14.5 5.5-23.5 3.6-9 7.2-15 10.7-19-.2-1-.7-1-1.5-1l-1-1c-2.9 3-6.4 10-10.6 20-4.2 9-6.4 17.3-6.4 23.4 0 4.5 1.1 8.4 3.1 11.8 2.2 3.3 7.5 8.1 15.9 14.2l10.6 6.9c11.3 9.8 17.3 16.6 17.3 20.6 0 2.1-1 4.2-4 6.5-2 2.4-4.7 3.6-7 3.6-.2 0-.3.2-.3.7 0 .1 1 2.1 3.1 6 4.2 5.7 13.2 8.5 25.2 8.5 22 0 39-9 52-27 0-5 0-8.1-1-9.4v-3.7c0-6.5 1-11.4 3-14.6s4-4.7 7-4.7c2 0 4 .7 6 2.2 1-7.7 1-14.4 1-20.4 0-9.1 0-16.6-2-23.6-1-6-3-11-5-15l-6-9c-2-3-3-6-5-9-1-4-2-7-2-12-3-5-5-10-8-15-2-5-4-10-6-14l-9 7c-10 7-18 10-25 10-6 0-11-1-14-5l-6-5c0 3-1 7-3 11l-6.3 12c-2.8 7-4.3 11-4.6 14-.4 2-.7 4-.9 4l-7.5 15c-8.1 15-12.2 28.9-12.2 40.4 0 2.3.2 4.7.6 7.1-4.5-3.1-6.7-7.4-6.7-13m71.6 94.6c-13 0-23 1.76-30 5.25v-.3c-5 6-10.6 9.1-18.4 9.1-4.9 0-12.6-1.9-23-5.7-10.5-3.6-19.8-6.36-27.9-8.18-.8-.23-2.6-.57-5.5-1.03-2.8-.45-5.4-.91-7.7-1.37-2.1-.45-4.5-1.13-7.1-2.05-2.5-.79-4.5-1.82-6-3.07-1.38-1.26-2.06-2.68-2.06-4.27 0-1.6.34-3.31 1.02-5.13.64-1.1 1.34-2.2 2.04-3.2.7-1.1 1.3-2.1 1.7-3.1.6-.9 1-1.8 1.4-2.8.4-.9.8-1.8 1-2.9.2-1 .4-2 .4-3s-.4-4-1.2-9.3c-.8-5.2-1.2-8.5-1.2-9.9 0-4.4 1-7.9 3.2-10.4s4.3-3.8 6.5-3.8h11.5c.9 0 2.3-.5 4.4-1.7.7-1.6 1.3-2.9 1.7-4.1.5-1.2.7-2.1.9-2.5.2-.6.4-1.2.6-1.7.4-.7.9-1.5 1.6-2.3-.8-1-1.2-2.3-1.2-3.9 0-1.1 0-2.1.2-2.7 0-3.6 1.7-8.7 5.3-15.4l3.5-6.3c2.9-5.4 5.1-9.4 6.7-13.4 1.7-4 3.5-10 5.5-18 1.6-7 5.4-14 11.4-21l7.5-9c5.2-6 8.6-11 10.5-15s2.9-9 2.9-13c0-2-.5-8-1.6-18-1-10-1.5-20-1.5-29 0-7 .6-12 1.9-17s3.6-10 7-14c3-4 7-8 13-10s13-3 21-3c3 0 6 0 9 1 3 0 7 1 12 3 4 2 8 4 11 7 4 3 7 8 10 13 2 6 4 12 5 20 1 5 1 10 2 17 0 6 1 10 1 13 1 3 1 7 2 12 1 4 2 8 4 11 2 4 4 8 7 12 3 5 7 10 11 16 9 10 16 21 20 32 5 10 8 23 8 36.9 0 6.9-1 13.6-3 20.1 2 0 3 .8 4 2.2s2 4.4 3 9.1l1 7.4c1 2.2 2 4.3 5 6.1 2 1.8 4 3.3 7 4.5 2 1 5 2.4 7 4.2 2 2 3 4.1 3 6.3 0 3.4-1 5.9-3 7.7-2 2-4 3.4-7 4.3-2 1-6 3-12 5.82-5 2.96-10 6.55-15 10.8l-10 8.51c-4 3.9-8 6.7-11 8.4-3 1.8-7 2.7-11 2.7l-7-.8c-8-2.1-13-6.1-16-12.2-16-1.94-29-2.9-37-2.9" />
  </svg>
)

// JavaScript icon SVG (rounded square)
const JavaScriptIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path
      fillRule="evenodd"
      d="M4 0h16a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4zm18.034 18.276c-.175-1.095-.888-2.015-3.003-2.873-.736-.345-1.554-.585-1.797-1.14-.091-.33-.105-.51-.046-.705.15-.646.915-.84 1.515-.66.39.12.75.42.976.9 1.034-.676 1.034-.676 1.755-1.125-.27-.42-.404-.601-.586-.78-.63-.705-1.469-1.065-2.834-1.034l-.705.089c-.676.165-1.32.525-1.71 1.005-1.14 1.291-.81 3.541.39 4.471 1.365 1.02 3.361 1.244 3.616 2.205.24 1.17-.87 1.545-1.966 1.41-.811-.18-1.26-.586-1.755-1.336l-1.83 1.051c.21.48.45.689.81 1.109 1.74 1.756 6.09 1.666 6.871-1.004.029-.09.24-.705.074-1.65l.046.067zM13.051 11.031h-2.248c0 1.938-.009 3.864-.009 5.805 0 1.232.063 2.363-.138 2.711-.33.689-1.18.601-1.566.48-.396-.196-.597-.466-.83-.855-.063-.105-.11-.196-.127-.196l-1.825 1.125c.305.63.75 1.172 1.324 1.517.855.51 2.004.675 3.207.405.783-.226 1.458-.691 1.811-1.411.51-.93.402-2.07.397-3.346.012-2.054 0-4.109 0-6.179l.004-.056z"
    />
  </svg>
)

// TypeScript icon SVG (rounded square)
const TypeScriptIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path
      fillRule="evenodd"
      d="M4 0h16a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4zm14.488 9.75c.612 0 1.154.037 1.627.111a6.38 6.38 0 0 1 1.306.34v2.458a3.95 3.95 0 0 0-.643-.361 5.093 5.093 0 0 0-.717-.26 5.453 5.453 0 0 0-1.426-.2c-.3 0-.573.028-.819.086a2.1 2.1 0 0 0-.623.242c-.17.104-.3.229-.393.374a.888.888 0 0 0-.14.49c0 .196.053.373.156.529.104.156.252.304.443.444s.423.276.696.41c.273.135.582.274.926.416.47.197.892.407 1.266.628.374.222.695.473.963.753.268.279.472.598.614.957.142.359.214.776.214 1.253 0 .657-.125 1.21-.373 1.656a3.033 3.033 0 0 1-1.012 1.085 4.38 4.38 0 0 1-1.487.596c-.566.12-1.163.18-1.79.18a9.916 9.916 0 0 1-1.84-.164 5.544 5.544 0 0 1-1.512-.493v-2.63a5.033 5.033 0 0 0 3.237 1.2c.333 0 .624-.03.872-.09.249-.06.456-.144.623-.25.166-.108.29-.234.373-.38a1.023 1.023 0 0 0-.074-1.089 2.12 2.12 0 0 0-.537-.5 5.597 5.597 0 0 0-.807-.444 8.983 8.983 0 0 0-1.006-.436c-.918-.383-1.602-.852-2.053-1.405-.45-.553-.676-1.222-.676-2.005 0-.614.123-1.141.369-1.582.246-.441.58-.804 1.004-1.089a4.494 4.494 0 0 1 1.47-.63 5.933 5.933 0 0 1 1.77-.257zM3.375 9.938h9.563v2.166H9.506v9.646H6.789V12.104H3.375z"
    />
  </svg>
)

// Get the base platform icon (without Flutter badge)
function getBasePlatformIcon(platform: string, size: string) {
  const normalized = platform.toLowerCase()

  // Handle Flutter platforms - extract the underlying platform
  if (normalized.startsWith('flutter-')) {
    const basePlatform = normalized.replace('flutter-', '')
    return getBasePlatformIcon(basePlatform, size)
  }

  // Handle React Native platforms
  if (normalized.startsWith('react-native-')) {
    const basePlatform = normalized.replace('react-native-', '')
    return getBasePlatformIcon(basePlatform, size)
  }

  // Handle Apple platforms
  if (normalized.startsWith('apple-')) {
    const appleType = normalized.replace('apple-', '')
    switch (appleType) {
      case 'ios':
        return (
          <Smartphone
            className={sizeClasses[size as keyof typeof sizeClasses]}
          />
        )
      case 'macos':
        return (
          <Monitor className={sizeClasses[size as keyof typeof sizeClasses]} />
        )
      case 'watchos':
        return (
          <Watch className={sizeClasses[size as keyof typeof sizeClasses]} />
        )
      case 'tvos':
        return <Tv className={sizeClasses[size as keyof typeof sizeClasses]} />
      default:
        return (
          <AppleIcon
            className={sizeClasses[size as keyof typeof sizeClasses]}
          />
        )
    }
  }

  // Base platforms
  switch (normalized) {
    case 'web':
      // Return a special component that handles JS/TS rotation on hover
      return null // Will be handled in PlatformIcon component
    case 'android':
      return (
        <AndroidIcon
          className={sizeClasses[size as keyof typeof sizeClasses]}
        />
      )
    case 'apple':
      return (
        <AppleIcon className={sizeClasses[size as keyof typeof sizeClasses]} />
      )
    case 'flutter':
      return (
        <FlutterIcon
          className={sizeClasses[size as keyof typeof sizeClasses]}
        />
      )
    case 'react-native':
      return (
        <ReactNativeIcon
          className={sizeClasses[size as keyof typeof sizeClasses]}
        />
      )
    case 'ios':
      return (
        <Smartphone className={sizeClasses[size as keyof typeof sizeClasses]} />
      )
    case 'linux':
      return (
        <LinuxIcon className={sizeClasses[size as keyof typeof sizeClasses]} />
      )
    case 'macos':
    case 'mac':
      return (
        <Monitor className={sizeClasses[size as keyof typeof sizeClasses]} />
      )
    case 'windows':
    case 'win':
      return (
        <WindowsIcon
          className={sizeClasses[size as keyof typeof sizeClasses]}
        />
      )
    case 'unity':
      return (
        <UnityIcon className={sizeClasses[size as keyof typeof sizeClasses]} />
      )
    default:
      return <Globe className={sizeClasses[size as keyof typeof sizeClasses]} />
  }
}

// Check if platform is Flutter-based
function isFlutterPlatform(platform: string): boolean {
  return platform.toLowerCase().startsWith('flutter-')
}

// Check if platform is React Native-based
function isReactNativePlatform(platform: string): boolean {
  return platform.toLowerCase().startsWith('react-native-')
}

export function PlatformIcon({
  platform,
  className,
  size = 'md',
  initialIcon,
}: PlatformIconProps) {
  const normalized = platform.toLowerCase()
  const isFlutter = isFlutterPlatform(normalized)
  const isReactNative = isReactNativePlatform(normalized)
  const isWeb = normalized === 'web'

  const baseIcon = getBasePlatformIcon(normalized, size)

  // Flutter and React Native badge size - bigger than other badges
  const frameworkBadgeSize = {
    sm: 'h-3.5 w-3.5',
    md: 'h-4.5 w-4.5',
    lg: 'h-6 w-6',
  }[size]

  // Special handling for web platform - JS/TS 3D cube rotation on hover
  if (isWeb) {
    const iconClass = sizeClasses[size as keyof typeof sizeClasses]
    // Determine which icon shows first - use prop if provided, otherwise default to JS
    const showTSFirst = initialIcon === 'ts'
    const [isFlipped, setIsFlipped] = useState(false)

    const handleMouseEnter = () => {
      setIsFlipped(!isFlipped)
    }

    const getTransform = () => {
      if (showTSFirst) {
        return isFlipped ? 'rotateY(-180deg)' : 'rotateY(0deg)'
      } else {
        return isFlipped ? 'rotateY(180deg)' : 'rotateY(0deg)'
      }
    }

    return (
      <div
        className={cn('relative group', iconClass, className)}
        style={{ perspective: '1000px', perspectiveOrigin: 'center center' }}
        onMouseEnter={handleMouseEnter}
      >
        <div
          className="relative w-full h-full transition-transform duration-700 ease-in-out"
          style={{
            transform: getTransform(),
            transformStyle: 'preserve-3d',
            WebkitTransformStyle: 'preserve-3d',
          }}
        >
          {/* JavaScript icon */}
          <div
            className="absolute inset-0 flex items-center justify-center"
            style={{
              backfaceVisibility: 'hidden',
              WebkitBackfaceVisibility: 'hidden',
              transform: showTSFirst ? 'rotateY(180deg)' : 'rotateY(0deg)',
            }}
          >
            <JavaScriptIcon className={iconClass} />
          </div>
          {/* TypeScript icon */}
          <div
            className="absolute inset-0 flex items-center justify-center"
            style={{
              backfaceVisibility: 'hidden',
              WebkitBackfaceVisibility: 'hidden',
              transform: showTSFirst ? 'rotateY(0deg)' : 'rotateY(180deg)',
            }}
          >
            <TypeScriptIcon className={iconClass} />
          </div>
        </div>
      </div>
    )
  }

  if (isFlutter) {
    const mainIcon = baseIcon ?? (
      <Globe className={sizeClasses[size as keyof typeof sizeClasses]} />
    )
    return (
      <div className={cn('relative', className)}>
        {mainIcon}
        {/* Flutter badge in corner - bigger and no color */}
        <div
          className={cn(
            'absolute -bottom-1 -end-1 flex items-center justify-center rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700',
            frameworkBadgeSize,
          )}
        >
          <FlutterIcon
            className={cn('text-muted-foreground/60', {
              'h-2 w-2': size === 'sm',
              'h-2.5 w-2.5': size === 'md',
              'h-3.5 w-3.5': size === 'lg',
            })}
          />
        </div>
      </div>
    )
  }

  if (isReactNative) {
    return (
      <div className={cn('relative', className)}>
        {baseIcon}
        {/* React Native badge in corner - bigger and no color */}
        <div
          className={cn(
            'absolute -bottom-1 -end-1 flex items-center justify-center rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700',
            frameworkBadgeSize,
          )}
        >
          <ReactNativeIcon
            className={cn('text-foreground', {
              'h-2 w-2': size === 'sm',
              'h-2.5 w-2.5': size === 'md',
              'h-3.5 w-3.5': size === 'lg',
            })}
          />
        </div>
      </div>
    )
  }

  return <div className={cn(className)}>{baseIcon}</div>
}
