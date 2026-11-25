import { ref } from 'vue'

/**
 * Marca os campos obrigatórios vazios depois da primeira tentativa de salvar (como a Nova cotação faz).
 * `bad('nome')` -> true quando já tentou salvar e o campo está vazio; `tentar()` liga a marca; `limpar()` desliga (ao fechar o modal).
 */
export function useRequired(form, obrigatorios) {
  const tentou = ref(false)
  const vazio = (k) => { const v = form[k]; return Array.isArray(v) ? !v.length : !String(v ?? '').trim() }
  return {
    bad: (k) => tentou.value && obrigatorios.includes(k) && vazio(k),
    tentar: () => { tentou.value = true },
    limpar: () => { tentou.value = false },
  }
}
