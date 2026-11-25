import { getCurrentInstance, onMounted, watch } from 'vue'
import { pushToast } from '../stores/ui'

/**
 * Link das notificações (e-mail/WhatsApp): `?abrir=<código>` abre o registro certo.
 * `buscar(codigo)` -> registro | null (a API já aplica o escopo do perfil: sem acesso = não vem);
 * `abrir(registro)` abre o modal. A query é limpa na hora para o F5 não reabrir.
 */
export function useAbrirPorCodigo({ rotulo, buscar, abrir, cancelado = () => false }) {
  const app = getCurrentInstance()?.appContext.config.globalProperties
  const router = app?.$router
  if (!router) return

  async function tratar() {
    const query = router.currentRoute.value.query
    const codigo = typeof query.abrir === 'string' ? query.abrir.trim() : ''
    if (!codigo) return
    const { abrir: _descartado, ...resto } = query
    await router.replace({ query: resto })
    const registro = await buscar(codigo)
    if (!registro) return pushToast('warn', `${rotulo} **${codigo}** não foi encontrada ou você não tem acesso a ela.`)
    abrir(registro)
    if (cancelado(registro)) pushToast('info', `${rotulo} **${codigo}** está cancelada.`)
  }

  onMounted(tratar)
  watch(() => router.currentRoute.value.query.abrir, tratar)
}
