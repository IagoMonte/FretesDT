const ENTRADA = /^\/(login|cadastro)(?:[/?#]|$)/

export function safeNext(value) {
  if (typeof value !== 'string' || !value.startsWith('/')) return null
  if (value[1] === '/' || value[1] === '\\' || /[\u0000-\u001f]/.test(value)) return null
  if (ENTRADA.test(value)) return null
  return value
}
