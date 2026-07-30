/** startFrom offsets (30fps) into typing-keyboard.wav - detected from source peaks. */
export const TYPING_KEYSTROKE_OFFSETS = [
  7, 10, 11, 13, 14, 15, 17, 18, 20, 22, 23, 25, 26, 27, 31, 34, 35, 36, 38,
  40, 41, 42, 43, 45, 46, 47, 48, 49, 52, 54, 55, 58, 59, 60, 61, 62, 63, 64,
  65, 67, 68, 71, 72, 74, 75, 78, 79, 81, 82, 85, 86, 91, 93, 94, 95, 99, 100,
  102, 104, 105, 107, 108, 109, 110, 111, 112, 114, 120, 123, 124, 126, 127,
  129, 130, 132, 133, 134, 136, 139, 141, 142, 143, 145, 146, 148, 149, 151,
  152, 155, 156, 159, 160, 162, 164, 168, 170, 171, 172, 185, 189, 190, 194,
  195, 196, 197, 198, 202, 206, 209, 214, 216, 220, 222, 223, 225, 228, 229,
  233,
] as const

export function getTypingKeystrokeOffset(index: number, char: string) {
  const code = char.charCodeAt(0)
  const slot = (index * 5 + code * 3) % TYPING_KEYSTROKE_OFFSETS.length
  return TYPING_KEYSTROKE_OFFSETS[slot]
}
