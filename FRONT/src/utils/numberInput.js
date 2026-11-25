import { pushToast, toasts } from '../stores/ui'

const MSG = 'Digite apenas números, ponto e vírgula (sem sinal negativo ou letras). Ex.: 12,5'

export function avisoNumero() {
  if (!toasts.some((t) => !t.out && t.parts.map((p) => p.text).join('') === MSG)) pushToast('warn', MSG)
}

const MSG_AMBIGUO = 'Formato de número ambíguo. Use ponto só para milhar e vírgula para decimais. Ex.: 1.234,56'

export function avisoNumeroAmbiguo() {
  if (!toasts.some((t) => !t.out && t.parts.map((p) => p.text).join('') === MSG_AMBIGUO)) pushToast('warn', MSG_AMBIGUO)
}