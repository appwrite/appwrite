import { useId } from 'react'

/** Nun, Gimel, He, Pe: "a great miracle happened here", as the dreidel reads in Israel. */
const LETTERS = ['נ', 'ג', 'ה', 'פ'] as const
const FACE_WIDTH = 9
/** Enough faces to cover the strip's full scroll in `hanukkah-dreidel-faces`. */
const FACES = 44
const BODY = 'M-6-23H6v11L.8-.6Q0 .4-.8-.6L-6-12Z'

/**
 * Dreidel standing on its tip at (`x`, `y`). Faces scroll past to read as a
 * spin; `topple` slows it down until it falls on פ, then starts over.
 * Without `topple` it spins steadily, e.g. hanging from a string.
 */
export function Dreidel({
  x = 0,
  y = 0,
  scale = 1,
  topple = false,
  delay = '0s',
}: {
  x?: number
  y?: number
  scale?: number
  topple?: boolean
  delay?: string
}) {
  const id = useId().replace(/:/g, '')

  return (
    <g
      className={topple ? 'hanukkah-dreidel' : undefined}
      style={{
        transformBox: 'view-box',
        transformOrigin: `${x}px ${y}px`,
        animationDelay: delay,
      }}
    >
      <g transform={`translate(${x} ${y}) scale(${scale})`}>
        <defs>
          <clipPath id={`${id}-body`}>
            <path d={BODY} />
          </clipPath>
          <linearGradient id={`${id}-shade`}>
            <stop offset="0%" stopColor="#000" stopOpacity="0.45" />
            <stop offset="30%" stopColor="#000" stopOpacity="0" />
            <stop offset="42%" stopColor="#FFF" stopOpacity="0.22" />
            <stop offset="58%" stopColor="#FFF" stopOpacity="0" />
            <stop offset="100%" stopColor="#000" stopOpacity="0.45" />
          </linearGradient>
          <linearGradient id={`${id}-gold`}>
            <stop offset="0%" stopColor="#A16207" />
            <stop offset="45%" stopColor="#FDE68A" />
            <stop offset="100%" stopColor="#A16207" />
          </linearGradient>
        </defs>
        <rect
          x="-1.2"
          y="-30.6"
          width="2.4"
          height="7"
          rx="0.9"
          fill={`url(#${id}-gold)`}
        />
        <path d="M-6-23H6l-1-1.5H-5Z" fill="#60A5FA" />
        <g clipPath={`url(#${id}-body)`}>
          <g
            className={
              topple ? 'hanukkah-dreidel-faces' : 'hanukkah-dreidel-spin'
            }
            style={{ animationDelay: delay }}
          >
            {Array.from({ length: FACES }, (_, index) => {
              const left = -6 + index * FACE_WIDTH
              return (
                <g key={index}>
                  <rect
                    x={left}
                    y="-24"
                    width={FACE_WIDTH}
                    height="25"
                    fill={index % 2 === 0 ? '#2563EB' : '#1D4ED8'}
                  />
                  <path
                    d={`M${left} -24v25`}
                    stroke="#1E3A8A"
                    strokeWidth="0.4"
                  />
                  <text
                    x={left + FACE_WIDTH / 2}
                    y="-14.6"
                    textAnchor="middle"
                    fontSize="7.5"
                    fontWeight="700"
                    fontFamily="'Noto Sans Hebrew', 'Arial Hebrew', sans-serif"
                    fill="#FDE68A"
                  >
                    {LETTERS[index % LETTERS.length]}
                  </text>
                </g>
              )
            })}
          </g>
          <path d="M-6-12H6L.8-.6Q0 .4-.8-.6Z" fill="#000" fillOpacity="0.22" />
          <path d={BODY} fill={`url(#${id}-shade)`} />
        </g>
      </g>
    </g>
  )
}
