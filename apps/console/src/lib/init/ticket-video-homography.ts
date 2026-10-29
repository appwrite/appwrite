import type { TicketVideoPoint } from '@/lib/init/ticket-video-perspective'

export type InitTicketVideoPerspectiveBlitter = {
  draw: (
    ctx: CanvasRenderingContext2D,
    quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
    depths: [number, number, number, number],
    textureVMax?: number,
    face?: 'front' | 'back',
  ) => void
  dispose: () => void
}

const VERTEX_SHADER = `
attribute vec2 a_uv;
attribute vec3 a_position;
uniform vec2 u_canvasSize;
varying vec4 v_texCoord;

void main() {
  vec2 ndc = vec2(
    (a_position.x / u_canvasSize.x) * 2.0 - 1.0,
    1.0 - (a_position.y / u_canvasSize.y) * 2.0
  );
  float w = a_position.z;
  gl_Position = vec4(ndc.x * w, ndc.y * w, 0.0, w);
  v_texCoord = vec4(a_uv * w, w);
}
`

const FRAGMENT_SHADER = `
precision mediump float;
uniform sampler2D u_texture;
varying vec4 v_texCoord;

void main() {
  gl_FragColor = texture2DProj(u_texture, v_texCoord);
}
`

function compileShader(gl: WebGLRenderingContext, type: number, source: string) {
  const shader = gl.createShader(type)
  if (!shader) return null
  gl.shaderSource(shader, source)
  gl.compileShader(shader)
  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    gl.deleteShader(shader)
    return null
  }
  return shader
}

function createProgram(gl: WebGLRenderingContext) {
  const vertexShader = compileShader(gl, gl.VERTEX_SHADER, VERTEX_SHADER)
  const fragmentShader = compileShader(gl, gl.FRAGMENT_SHADER, FRAGMENT_SHADER)
  if (!vertexShader || !fragmentShader) return null

  const program = gl.createProgram()
  if (!program) return null
  gl.attachShader(program, vertexShader)
  gl.attachShader(program, fragmentShader)
  gl.linkProgram(program)
  gl.deleteShader(vertexShader)
  gl.deleteShader(fragmentShader)

  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    gl.deleteProgram(program)
    return null
  }
  return program
}

function uploadTicketTexture(
  gl: WebGLRenderingContext,
  image: CanvasImageSource,
) {
  const texture = gl.createTexture()
  if (!texture) return null

  gl.bindTexture(gl.TEXTURE_2D, texture)
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)

  try {
    gl.texImage2D(
      gl.TEXTURE_2D,
      0,
      gl.RGBA,
      gl.RGBA,
      gl.UNSIGNED_BYTE,
      image as TexImageSource,
    )
  } catch {
    gl.deleteTexture(texture)
    return null
  }

  return texture
}

type QuadCorner = {
  point: TicketVideoPoint
  u: number
  v: number
  depth: number
}

function signedTriangleArea(
  a: TicketVideoPoint,
  b: TicketVideoPoint,
  c: TicketVideoPoint,
) {
  return (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x)
}

function lerp(a: number, b: number, t: number) {
  return a + (b - a) * t
}

function bilinearQuadCorner(
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  depths: [number, number, number, number],
  u: number,
  v: number,
  vTop: number,
  vBottom: number,
): QuadCorner {
  const [tl, tr, br, bl] = quad
  const [tlW, trW, brW, blW] = depths
  const point = {
    x: lerp(lerp(tl.x, tr.x, u), lerp(bl.x, br.x, u), v),
    y: lerp(lerp(tl.y, tr.y, u), lerp(bl.y, br.y, u), v),
  }
  const depth = lerp(lerp(tlW, trW, u), lerp(blW, brW, u), v)
  return {
    point,
    u,
    v: lerp(vTop, vBottom, v),
    depth,
  }
}

/** Pick the diagonal that avoids a perspective UV fold (mirrored corner ghost). */
export function triangulateTicketVideoQuad(
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  depths: [number, number, number, number],
  vTop: number,
  vBottom: number,
): QuadCorner[] {
  const [tl, tr, br, bl] = quad
  const [tlW, trW, brW, blW] = depths

  const c0: QuadCorner = { point: tl, u: 0, v: vTop, depth: tlW }
  const c1: QuadCorner = { point: tr, u: 1, v: vTop, depth: trW }
  const c2: QuadCorner = { point: br, u: 1, v: vBottom, depth: brW }
  const c3: QuadCorner = { point: bl, u: 0, v: vBottom, depth: blW }

  const diagTlBr: QuadCorner[] = [c0, c1, c2, c0, c2, c3]
  const diagTrBl: QuadCorner[] = [c0, c1, c3, c1, c2, c3]

  const score = (tri: QuadCorner[]) => {
    const a1 = signedTriangleArea(tri[0].point, tri[1].point, tri[2].point)
    const a2 = signedTriangleArea(tri[3].point, tri[4].point, tri[5].point)
    if (a1 === 0 || a2 === 0) return 0
    if (a1 * a2 < 0) return -1
    return Math.min(Math.abs(a1), Math.abs(a2))
  }

  const tlBrScore = score(diagTlBr)
  const trBlScore = score(diagTrBl)

  if (trBlScore > tlBrScore) return diagTrBl
  return diagTlBr
}

const SUBDIV_COLS = 16
const SUBDIV_ROWS = 12

function subdivideTicketVideoQuad(
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  depths: [number, number, number, number],
  vTop: number,
  vBottom: number,
): QuadCorner[] {
  const corners: QuadCorner[] = []

  for (let row = 0; row < SUBDIV_ROWS; row += 1) {
    for (let col = 0; col < SUBDIV_COLS; col += 1) {
      const u0 = col / SUBDIV_COLS
      const u1 = (col + 1) / SUBDIV_COLS
      const v0 = row / SUBDIV_ROWS
      const v1 = (row + 1) / SUBDIV_ROWS

      const c00 = bilinearQuadCorner(quad, depths, u0, v0, vTop, vBottom)
      const c10 = bilinearQuadCorner(quad, depths, u1, v0, vTop, vBottom)
      const c11 = bilinearQuadCorner(quad, depths, u1, v1, vTop, vBottom)
      const c01 = bilinearQuadCorner(quad, depths, u0, v1, vTop, vBottom)

      corners.push(c00, c10, c01, c10, c11, c01)
    }
  }

  return corners
}

/**
 * Perspective-correct ticket draw via WebGL. Subdivided quads avoid UV folds and mesh creases.
 */
export function createInitTicketVideoPerspectiveBlitter(
  width: number,
  height: number,
  frontImage: CanvasImageSource,
  backImage?: CanvasImageSource,
): InitTicketVideoPerspectiveBlitter | null {
  if (typeof document === 'undefined') return null

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height

  const gl = canvas.getContext('webgl', {
    alpha: true,
    premultipliedAlpha: true,
    antialias: true,
  })
  if (!gl) return null

  const program = createProgram(gl)
  const frontTexture = uploadTicketTexture(gl, frontImage)
  const backTexture = backImage ? uploadTicketTexture(gl, backImage) : null
  if (!program || !frontTexture) {
    return null
  }

  const positionLoc = gl.getAttribLocation(program, 'a_position')
  const uvLoc = gl.getAttribLocation(program, 'a_uv')
  const canvasSizeLoc = gl.getUniformLocation(program, 'u_canvasSize')
  const textureLoc = gl.getUniformLocation(program, 'u_texture')

  const buffer = gl.createBuffer()
  if (!buffer) return null

  gl.useProgram(program)
  gl.uniform1i(textureLoc, 0)
  gl.uniform2f(canvasSizeLoc, width, height)
  gl.enable(gl.BLEND)
  gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA)

  const draw = (
    ctx: CanvasRenderingContext2D,
    quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
    depths: [number, number, number, number],
    textureVMax = 1,
    face: 'front' | 'back' = 'front',
  ) => {
    const vTop = 1
    const vBottom = 1 - textureVMax
    const corners = subdivideTicketVideoQuad(quad, depths, vTop, vBottom)
    const vertices = new Float32Array(corners.length * 5)

    corners.forEach((corner, index) => {
      const offset = index * 5
      vertices[offset] = corner.point.x
      vertices[offset + 1] = corner.point.y
      vertices[offset + 2] = corner.depth
      vertices[offset + 3] = corner.u
      vertices[offset + 4] = corner.v
    })

    gl.viewport(0, 0, width, height)
    gl.clearColor(0, 0, 0, 0)
    gl.clear(gl.COLOR_BUFFER_BIT)

    gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
    gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.DYNAMIC_DRAW)

    const stride = 20
    gl.enableVertexAttribArray(positionLoc)
    gl.vertexAttribPointer(positionLoc, 3, gl.FLOAT, false, stride, 0)
    gl.enableVertexAttribArray(uvLoc)
    gl.vertexAttribPointer(uvLoc, 2, gl.FLOAT, false, stride, 12)

    gl.activeTexture(gl.TEXTURE0)
    const texture =
      face === 'back' && backTexture ? backTexture : frontTexture
    gl.bindTexture(gl.TEXTURE_2D, texture)
    gl.drawArrays(gl.TRIANGLES, 0, corners.length)

    ctx.drawImage(canvas, 0, 0)
  }

  const dispose = () => {
    gl.deleteTexture(frontTexture)
    if (backTexture) gl.deleteTexture(backTexture)
    gl.deleteBuffer(buffer)
    gl.deleteProgram(program)
    canvas.width = 0
    canvas.height = 0
  }

  return { draw, dispose }
}
