export const EIXOS = [2, 3, 4, 5, 6, 7, 8, 9]

export const pisoValido = (q, resposta) => resposta?.piso_valido ?? (!q?.fracionada || !!resposta?.dedicado)

export function anttDelta(antt) {
  const piso = Number(antt?.piso) || 0
  const diff = Number(antt?.diferenca) || 0
  return {
    abs: Math.abs(diff),
    pct: piso ? (Math.abs(diff) / piso) * 100 : 0,
    sinal: diff < 0 ? 'abaixo' : diff > 0 ? 'acima' : 'igual',
  }
}
