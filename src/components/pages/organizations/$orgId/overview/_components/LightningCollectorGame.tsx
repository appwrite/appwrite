import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Play, RotateCcw, Trophy } from 'lucide-react'
import { cn } from '@/lib/utils'

type Entity = {
  id: number
  x: number
  y: number
  width: number
  height: number
  type: 'hazard' | 'reward'
  vy?: number
}

const APPWRITE_LOGO_SRC = '/logo.svg'

const LIGHTNING_SVG = encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#22c55e" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 11 14 9 22 19 10 11 10 13 2"/></svg>`,
)

const GAME_WIDTH = 720
const GAME_HEIGHT = 200
const GROUND_HEIGHT = 32
const PLAYER_SIZE = 28
const PLAYER_X = 60

export function LightningCollectorGame() {
  const [isOpen, setIsOpen] = useState(false)
  const [isRunning, setIsRunning] = useState(false)
  const [score, setScore] = useState(0)
  const [highScore, setHighScore] = useState(0)
  const [status, setStatus] = useState<'idle' | 'running' | 'gameover'>('idle')
  const [, forceRender] = useState(0)

  const playerRef = useRef({ y: 0, velocity: 0 })
  const entitiesRef = useRef<Entity[]>([])
  const animationRef = useRef<number | null>(null)
  const lastHazardRef = useRef<number>(0)
  const lastRewardRef = useRef<number>(0)
  const lastFrameRef = useRef<number | null>(null)

  const resetGame = () => {
    entitiesRef.current = []
    playerRef.current = { y: 0, velocity: 0 }
    lastHazardRef.current = 0
    lastRewardRef.current = 0
    lastFrameRef.current = null
    setScore(0)
    setStatus('running')
    setIsRunning(true)
  }

  const endGame = () => {
    setIsRunning(false)
    setStatus('gameover')
    setHighScore((prev) => Math.max(prev, score))
  }

  const handleJump = () => {
    if (status === 'idle' || status === 'gameover') {
      resetGame()
      return
    }

    if (!isRunning) return
    const player = playerRef.current
    if (player.y === 0) {
      player.velocity = 10
    }
  }

  const spawnEntity = (type: Entity['type']) => {
    if (type === 'hazard') {
      const startY = 140 + Math.random() * 40 // spawn higher up
      const fallSpeed = 2 + Math.random() * 1.5
      entitiesRef.current.push({
        id: Math.random(),
        type,
        x: GAME_WIDTH + 20,
        y: startY,
        width: 26,
        height: 26,
        vy: fallSpeed,
      })
      return
    }

    const baseY = 16
    entitiesRef.current.push({
      id: Math.random(),
      type,
      x: GAME_WIDTH + 20,
      y: baseY,
      width: 24,
      height: 24,
    })
  }

  const updateLoop = (timestamp: number) => {
    if (!isRunning) return

    if (lastFrameRef.current == null) {
      lastFrameRef.current = timestamp
    }
    const delta = Math.min(32, timestamp - lastFrameRef.current)
    lastFrameRef.current = timestamp

    // Slightly increase speed as score grows
    const speed = 3 + Math.min(4, score / 150)

    // Handle player physics (simple jump + gravity)
    const player = playerRef.current
    player.y = Math.max(0, player.y + player.velocity * (delta / 16))
    player.velocity = player.velocity + -0.55 * (delta / 16)
    if (player.y <= 0 && player.velocity < 0) {
      player.y = 0
      player.velocity = 0
    }

    // Spawn hazards (lightning) and rewards (Appwrite logos)
    if (timestamp - lastHazardRef.current > 1100 + Math.random() * 600) {
      spawnEntity('hazard')
      lastHazardRef.current = timestamp
    }
    if (timestamp - lastRewardRef.current > 1500 + Math.random() * 900) {
      spawnEntity('reward')
      lastRewardRef.current = timestamp
    }

    // Move and filter entities
    entitiesRef.current = entitiesRef.current
      .map((entity) => {
        const nextX = entity.x - speed * (delta / 16)
        const nextY =
          entity.type === 'hazard'
            ? Math.max(0, entity.y - (entity.vy || 0) * (delta / 16))
            : entity.y
        return { ...entity, x: nextX, y: nextY }
      })
      .filter((entity) => entity.x + entity.width > 0)

    // Collision detection
    const playerBottom = GROUND_HEIGHT + player.y
    const playerRect = {
      x: PLAYER_X,
      y: playerBottom,
      width: PLAYER_SIZE,
      height: PLAYER_SIZE,
    }

    let collected = 0
    for (const entity of entitiesRef.current) {
      const entityRect = {
        x: entity.x,
        y: GROUND_HEIGHT + entity.y,
        width: entity.width,
        height: entity.height,
      }

      const collides =
        playerRect.x < entityRect.x + entityRect.width &&
        playerRect.x + playerRect.width > entityRect.x &&
        playerRect.y < entityRect.y + entityRect.height &&
        playerRect.y + playerRect.height > entityRect.y

      if (collides) {
        if (entity.type === 'hazard') {
          endGame()
          forceRender((n) => n + 1)
          return
        }
        if (entity.type === 'reward') {
          collected += 5
          entity.width = 0
          entity.height = 0
        }
      }
    }

    if (collected > 0) {
      setScore((prev) => prev + collected)
    }

    entitiesRef.current = entitiesRef.current.filter(
      (entity) => entity.width > 0 && entity.height > 0,
    )

    // Trigger paint
    forceRender((n) => n + 1)

    animationRef.current = requestAnimationFrame(updateLoop)
  }

  useEffect(() => {
    if (!isRunning) {
      if (animationRef.current) {
        cancelAnimationFrame(animationRef.current)
        animationRef.current = null
      }
      return
    }

    animationRef.current = requestAnimationFrame(updateLoop)

    return () => {
      if (animationRef.current) {
        cancelAnimationFrame(animationRef.current)
      }
    }

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isRunning])

  useEffect(() => {
    if (!isOpen) {
      setIsRunning(false)
      setStatus('idle')
      entitiesRef.current = []
      playerRef.current = { y: 0, velocity: 0 }
    }
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return
    const listener = (event: KeyboardEvent) => {
      if (event.code === 'Space' || event.code === 'ArrowUp') {
        event.preventDefault()
        handleJump()
      }
      if (event.code === 'KeyR') {
        resetGame()
      }
    }
    window.addEventListener('keydown', listener)
    return () => window.removeEventListener('keydown', listener)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, status])

  return (
    <div className="mb-4 overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-start justify-between gap-4 px-6 py-4">
        <div className="min-w-0 flex-1 space-y-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            Lightning Collector
          </h3>
          <p className="text-[13px] text-muted-foreground leading-relaxed">
            Collect Appwrite tokens and avoid lightning hazards. Endurance
            mini-game for debug sessions.
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          {highScore > 0 && (
            <Badge variant="info" className="text-[10px] shrink-0 gap-1">
              <Trophy className="h-3 w-3" />
              Best {highScore}
            </Badge>
          )}
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setIsOpen((prev) => !prev)}
          >
            {isOpen ? 'Collapse' : 'Open'}
          </Button>
        </div>
      </div>

      {isOpen && (
        <>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <dl className="flex flex-wrap items-center gap-x-6 gap-y-1 text-[13px]">
                <div className="flex items-baseline gap-2">
                  <dt className="text-muted-foreground">Score</dt>
                  <dd className="font-medium tabular-nums text-foreground">
                    {score}
                  </dd>
                </div>
                <div className="hidden text-muted-foreground sm:block">
                  <span className="text-border">|</span>
                  <span className="ml-6">
                    Space or ↑ to jump · R to restart · click arena to jump
                  </span>
                </div>
              </dl>
              <Button
                size="sm"
                variant="outline"
                onClick={resetGame}
                className="h-9 gap-1.5 text-[13px]"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                Restart
              </Button>
            </div>

            <div
              className={cn(
                'relative w-full overflow-hidden rounded-lg border border-border bg-muted/20',
              )}
              style={{ height: GAME_HEIGHT }}
              onClick={handleJump}
              role="application"
              aria-label="Lightning Collector game arena"
            >
              <div
                className="pointer-events-none absolute inset-0 opacity-[0.35]"
                style={{
                  backgroundImage:
                    'linear-gradient(to right, hsl(var(--border)) 1px, transparent 1px)',
                  backgroundSize: '48px 100%',
                }}
              />

              <div
                className="absolute inset-x-0 border-t border-border/80 bg-muted/40"
                style={{ height: GROUND_HEIGHT, bottom: 0 }}
              />

              <div
                className="absolute rounded-sm border border-border bg-foreground shadow-sm"
                style={{
                  width: PLAYER_SIZE,
                  height: PLAYER_SIZE,
                  left: PLAYER_X,
                  bottom: GROUND_HEIGHT + playerRef.current.y,
                }}
              />

              {entitiesRef.current.map((entity) => (
                <div
                  key={entity.id}
                  className="absolute"
                  style={{
                    width: entity.width,
                    height: entity.height,
                    left: entity.x,
                    bottom: GROUND_HEIGHT + entity.y,
                    backgroundImage:
                      entity.type === 'hazard'
                        ? `url("data:image/svg+xml;utf8,${LIGHTNING_SVG}")`
                        : `url("${APPWRITE_LOGO_SRC}")`,
                    backgroundRepeat: 'no-repeat',
                    backgroundSize: 'contain',
                    opacity: entity.type === 'hazard' ? 0.95 : 0.9,
                  }}
                />
              ))}

              {status !== 'running' && (
                <div className="absolute inset-0 flex items-center justify-center bg-background/80 backdrop-blur-[2px]">
                  <div className="max-w-xs px-6 text-center">
                    <p className="text-[13px] font-semibold text-foreground">
                      {status === 'gameover'
                        ? 'Run ended'
                        : 'Ready to start'}
                    </p>
                    <p className="mt-1.5 text-[12px] leading-relaxed text-muted-foreground">
                      {status === 'gameover'
                        ? `Final score: ${score}. Start another run to beat your best.`
                        : 'Collect tokens and avoid lightning. Use Space, ↑, or click to jump.'}
                    </p>
                    <div className="mt-4 flex items-center justify-center gap-2">
                      <Button
                        size="sm"
                        onClick={resetGame}
                        className="h-9 gap-1.5 text-[13px]"
                      >
                        <Play className="h-3.5 w-3.5" />
                        {status === 'gameover' ? 'Play again' : 'Start run'}
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-9 text-[13px]"
                        onClick={() => setIsOpen(false)}
                      >
                        Collapse
                      </Button>
                    </div>
                  </div>
                </div>
              )}
            </div>

            <p className="text-[12px] text-muted-foreground sm:hidden">
              Space or ↑ to jump · R to restart · tap arena to jump
            </p>
          </div>
        </>
      )}
    </div>
  )
}
