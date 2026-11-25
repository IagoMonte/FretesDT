export function parseBold(text) {
  return String(text ?? '')
    .split('**')
    .map((part, i) => ({ text: part, bold: i % 2 === 1 }))
    .filter((p) => p.text !== '')
}
