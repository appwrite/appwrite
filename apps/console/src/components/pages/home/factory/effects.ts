import {
  BufferAttribute,
  BufferGeometry,
  Color,
  DynamicDrawUsage,
  InstancedBufferAttribute,
  InstancedMesh,
  Matrix4,
  PlaneGeometry,
  Points,
  Quaternion,
  Vector3,
  type ShaderMaterial,
} from 'three'

/**
 * Immediate-mode effect layers. Every frame each system pushes the lights
 * and contact shadows it needs; one draw call per layer renders them all.
 */

export class GlowLayer {
  readonly points: Points
  private readonly positions: Float32Array
  private readonly colors: Float32Array
  private readonly sizes: Float32Array
  private count = 0

  constructor(
    material: ShaderMaterial,
    private readonly capacity = 512,
  ) {
    this.positions = new Float32Array(capacity * 3)
    this.colors = new Float32Array(capacity * 4)
    this.sizes = new Float32Array(capacity)
    const geometry = new BufferGeometry()
    geometry.setAttribute(
      'position',
      new BufferAttribute(this.positions, 3).setUsage(DynamicDrawUsage),
    )
    geometry.setAttribute(
      'aColor',
      new BufferAttribute(this.colors, 4).setUsage(DynamicDrawUsage),
    )
    geometry.setAttribute(
      'aSize',
      new BufferAttribute(this.sizes, 1).setUsage(DynamicDrawUsage),
    )
    this.points = new Points(geometry, material)
    this.points.frustumCulled = false
    this.points.renderOrder = 10
  }

  begin() {
    this.count = 0
  }

  add(
    x: number,
    y: number,
    z: number,
    size: number,
    color: Color,
    alpha: number,
  ) {
    if (alpha <= 0.002 || this.count >= this.capacity) return
    const i = this.count++
    this.positions[i * 3] = x
    this.positions[i * 3 + 1] = y
    this.positions[i * 3 + 2] = z
    this.colors[i * 4] = color.r
    this.colors[i * 4 + 1] = color.g
    this.colors[i * 4 + 2] = color.b
    this.colors[i * 4 + 3] = Math.min(alpha, 1)
    this.sizes[i] = size
  }

  end() {
    const geometry = this.points.geometry
    geometry.setDrawRange(0, this.count)
    for (const name of ['position', 'aColor', 'aSize'] as const) {
      const attribute = geometry.getAttribute(name) as BufferAttribute
      attribute.clearUpdateRanges()
      attribute.addUpdateRange(0, this.count * attribute.itemSize)
      attribute.needsUpdate = true
    }
  }
}

const scratchMatrix = new Matrix4()
const scratchQuaternion = new Quaternion()
const scratchPosition = new Vector3()
const scratchScale = new Vector3()

/** Soft contact shadows under carriers, for devices without shadow maps. */
export class ShadowLayer {
  readonly mesh: InstancedMesh
  private readonly alpha: InstancedBufferAttribute
  private count = 0

  constructor(
    material: ShaderMaterial,
    private readonly capacity = 64,
  ) {
    const geometry = new PlaneGeometry(1, 1)
    geometry.rotateX(-Math.PI / 2)
    this.alpha = new InstancedBufferAttribute(
      new Float32Array(capacity),
      1,
    ).setUsage(DynamicDrawUsage)
    geometry.setAttribute('aAlpha', this.alpha)
    this.mesh = new InstancedMesh(geometry, material, capacity)
    this.mesh.instanceMatrix.setUsage(DynamicDrawUsage)
    this.mesh.frustumCulled = false
    this.mesh.renderOrder = 2
    this.mesh.count = 0
  }

  begin() {
    this.count = 0
  }

  add(
    x: number,
    y: number,
    z: number,
    width: number,
    depth: number,
    alpha: number,
  ) {
    if (alpha <= 0.002 || this.count >= this.capacity) return
    const i = this.count++
    scratchPosition.set(x, y, z)
    scratchQuaternion.identity()
    scratchScale.set(width, 1, depth)
    scratchMatrix.compose(scratchPosition, scratchQuaternion, scratchScale)
    this.mesh.setMatrixAt(i, scratchMatrix)
    this.alpha.setX(i, alpha)
  }

  end() {
    this.mesh.count = this.count
    this.mesh.instanceMatrix.needsUpdate = true
    this.alpha.needsUpdate = true
  }
}
