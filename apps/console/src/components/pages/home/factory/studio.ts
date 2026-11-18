import {
  BackSide,
  BoxGeometry,
  Color,
  DoubleSide,
  Mesh,
  MeshBasicMaterial,
  PMREMGenerator,
  PlaneGeometry,
  Scene,
  type Texture,
  type WebGLRenderer,
} from 'three'

/**
 * The photo studio the machine is lit in, rendered once into an environment
 * map. Metal and glass have no color of their own; what makes them read as
 * metal and glass is what they mirror, so the studio is built like a real
 * product shoot: a large soft ceiling, a bright band behind the machine that
 * every flat top and cover glass throws back at the camera, a low fill in
 * front so faces toward the camera never go black, and a dark room around it
 * all (a bright one on light pages).
 */
export function createStudioEnvironment(
  renderer: WebGLRenderer,
  dark: boolean,
): Texture {
  const scene = new Scene()
  const disposables: { dispose: () => void }[] = []

  const emitter = (intensity: number) => {
    const material = new MeshBasicMaterial({
      color: new Color().setScalar(intensity),
      side: DoubleSide,
    })
    disposables.push(material)
    return material
  }

  /** A softbox of `width` x `height` at `position`, facing the studio's center. */
  const softbox = (
    width: number,
    height: number,
    intensity: number,
    position: [number, number, number],
  ) => {
    const geometry = new PlaneGeometry(width, height)
    disposables.push(geometry)
    const mesh = new Mesh(geometry, emitter(intensity))
    mesh.position.set(...position)
    mesh.lookAt(0, 0, 0)
    scene.add(mesh)
  }

  const roomGeometry = new BoxGeometry(70, 36, 70)
  const roomMaterial = new MeshBasicMaterial({
    color: new Color().setScalar(dark ? 0.03 : 0.7),
    side: BackSide,
  })
  disposables.push(roomGeometry, roomMaterial)
  const room = new Mesh(roomGeometry, roomMaterial)
  room.position.y = 12
  scene.add(room)

  // Ceiling: broad and soft, lights every top face.
  softbox(36, 16, dark ? 1.15 : 2.1, [0, 16, 0])
  // Behind the machine: the band flat tops and cover glass mirror toward the camera.
  softbox(48, 9, dark ? 1.25 : 2.6, [0, 11, -22])
  // A hard strip inside it, for one crisp glint on polished parts.
  softbox(42, 0.45, dark ? 5 : 5, [0, 12.6, -21.6])
  // Low fill behind the camera, so faces toward it keep their shape.
  softbox(44, 12, dark ? 0.95 : 1.3, [0, 2, 26])
  // Kicker on the side the camera looks from.
  softbox(12, 12, dark ? 0.85 : 1.1, [-27, 7, 4])
  // A bounce card on the floor in front: what glass and metal facing the camera mirror.
  softbox(34, 11, dark ? 1.1 : 1.6, [0, -5.6, 14])
  // The floor's bounce.
  softbox(70, 70, dark ? 0.1 : 0.85, [0, -5.9, 0.01])

  const pmrem = new PMREMGenerator(renderer)
  const texture = pmrem.fromScene(scene, 0.02).texture
  pmrem.dispose()
  for (const item of disposables) item.dispose()
  return texture
}
