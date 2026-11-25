<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { auth } from '../stores/session'
import { db, loadCarriers, loadNotifHistory, loadNotifPrefs, resendNotif, saveNotifPrefs, sendNotifTest } from '../stores/db'
import { pushToast } from '../stores/ui'
import { fmtPhone, fmtRegistros } from '../utils/format'
import { CHANNELS, CHANNEL_LABELS, GROUP_LABELS, STATUS_LABELS, e164, eventLabel, groupsOf } from '../utils/notifications'
import CardPanel from '../components/CardPanel.vue'
import EmptyState from '../components/EmptyState.vue'
import NavIcon from '../components/NavIcon.vue'

const session = auth.session
const tab = ref('matriz')
const saving = ref(false)
const sending = ref(false)
const resending = ref(null)

const key = (l) => `${l.evento}|${l.canal}|${l.grupo_codigo}`
const draft = reactive({})
const resetDraft = () => {
  for (const k of Object.keys(draft)) delete draft[k]
  for (const l of db.notifPrefs) draft[key(l)] = l.ativo
}
watch(() => db.notifPrefs, resetDraft, { immediate: true })

const events = computed(() => [...new Set(db.notifPrefs.map((l) => l.evento))])
const has = (evento, canal, grupo) => key({ evento, canal, grupo_codigo: grupo }) in draft
const changes = computed(() => db.notifPrefs.filter((l) => draft[key(l)] !== l.ativo).map((l) => ({ ...l, ativo: !!draft[key(l)] })))

async function savePrefs() {
  if (saving.value || !changes.value.length) return
  saving.value = true
  const r = await saveNotifPrefs(changes.value)
  saving.value = false
  r.ok ? pushToast('ok', 'Preferências salvas.') : pushToast('err', r.error)
}

const contacts = computed(() => db.carriers.filter((c) => c.ativo !== false).map((c) => ({ ...c, wa: e164(c.telefone) })))

const flt = reactive({ status: '', canal: '', evento: '' })
const eventOptions = computed(() => Object.keys(db.notifPrefs.length ? Object.fromEntries(events.value.map((e) => [e, 1])) : {}))

async function loadHistory(avisar = false) {
  const r = await loadNotifHistory(flt)
  const falhas = db.notifHistory.filter((n) => n.status === 'FALHA').length
  if (r.ok && avisar && falhas) pushToast('warn', `Há **${falhas}** ${falhas === 1 ? 'notificação com falha' : 'notificações com falha'}: use **Reenviar** depois de corrigir o contato ou o SMTP.`)
}

function open(t) {
  tab.value = t
  if (t === 'historico') loadHistory(true)
  if (t === 'contatos' && session.can('TRANSPORTADORA')) loadCarriers()
}

async function resend(n) {
  if (resending.value) return
  resending.value = n.id
  const r = await resendNotif(n.id)
  resending.value = null
  if (!r.ok) return pushToast('err', r.error)
  if (r.data.status === 'ENVIADO') pushToast('ok', `Notificação de **${r.data.registro_codigo}** reenviada.`)
  else if (r.data.status === 'PENDENTE') pushToast('info', 'Reenvio na fila: o envio sai em instantes.')
  else pushToast('err', `O reenvio falhou: ${r.data.erro || 'erro desconhecido'}.`)
}

const test = reactive({ canal: 'EMAIL', destino: '' })
async function sendTest() {
  if (sending.value) return
  sending.value = true
  const r = await sendNotifTest(test)
  sending.value = false
  if (!r.ok) return pushToast('err', r.error)
  if (r.data.status === 'FALHA') return pushToast('err', `Falha no teste: ${r.data.erro || 'erro desconhecido'}.`)
  pushToast('ok', `Teste enviado para **${r.data.endereco}**.`)
}

const when = (iso) => {
  const m = String(iso ?? '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : '—'
}
const pill = (s) => ({ ENVIADO: 'st-ent', FALHA: 'st-can', PENDENTE: 'st-agu' })[s] || 'st-agu'

onMounted(loadNotifPrefs)
</script>

<template>
  <div class="chipz" role="tablist" aria-label="Seções de notificações" style="margin-bottom:14px">
    <label v-for="t in [['matriz', 'Quem recebe o quê'], ['contatos', 'Telefones e WhatsApp'], ['historico', 'Histórico e teste']]" :key="t[0]" :class="{ on: tab === t[0] }">
      <input type="radio" name="nt-tab" :value="t[0]" :checked="tab === t[0]" role="tab" :aria-selected="tab === t[0]" :data-tab="t[0]" @click="open(t[0])" />{{ t[1] }}
    </label>
  </div>

  <CardPanel v-if="tab === 'matriz'" title="Quem recebe o quê" :count="`${events.length} eventos`" flush>
    <template v-if="session.can('NOTIFICACAO', 'alterar')" #actions>
      <button class="btn btn-primary btn-sm" data-act="save-prefs" :disabled="!changes.length || saving" :aria-busy="saving" @click="savePrefs">
        <NavIcon name="check" :size="14" /> Salvar alterações<template v-if="changes.length"> ({{ changes.length }})</template>
      </button>
    </template>
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Matriz de notificações">
      <table class="tbl" style="min-width:820px">
        <thead>
          <tr>
            <th rowspan="2">Evento</th>
            <th v-for="c in CHANNELS" :key="c" :colspan="groupsOf(c).length" style="text-align:center">{{ CHANNEL_LABELS[c] }}</th>
          </tr>
          <tr><template v-for="c in CHANNELS" :key="c"><th v-for="g in groupsOf(c)" :key="c + g" style="text-align:center">{{ GROUP_LABELS[g] }}</th></template></tr>
        </thead>
        <tbody>
          <tr v-for="e in events" :key="e">
            <td><b class="strong">{{ eventLabel(e) }}</b></td>
            <template v-for="c in CHANNELS" :key="c">
              <td v-for="g in groupsOf(c)" :key="c + g" style="text-align:center">
                <input v-if="has(e, c, g)" v-model="draft[`${e}|${c}|${g}`]" type="checkbox" :data-pref="`${e}|${c}|${g}`"
                  :disabled="!session.can('NOTIFICACAO', 'alterar')" :aria-label="`${eventLabel(e)}: ${GROUP_LABELS[g]} por ${CHANNEL_LABELS[c]}`" />
                <span v-else class="mut" aria-hidden="true">—</span>
              </td>
            </template>
          </tr>
          <tr v-if="!events.length"><td colspan="10"><EmptyState icon="notifications" :message="db.loading.notifPrefs ? 'Carregando…' : 'Nenhuma configuração disponível.'" /></td></tr>
        </tbody>
      </table>
    </div>
    <p class="mut" style="font-size:12px;padding:10px 14px">Cancelamentos não são enviados às pessoas da transportadora e as respostas não vazam para a concorrência: essas combinações nem aparecem aqui. O grupo de WhatsApp do cadastro recebe, só no WhatsApp, o que é da própria transportadora (cotação encaminhada, resposta dela, fechamento, cancelamento e status da carga).</p>
  </CardPanel>

  <CardPanel v-else-if="tab === 'contatos'" title="Telefones e WhatsApp" :count="`${contacts.length} transportadoras`" flush>
    <div class="tbl-wrap" role="region" tabindex="0" aria-label="Contatos das transportadoras">
      <table class="tbl" style="min-width:720px">
        <thead><tr><th>Transportadora</th><th>Telefone cadastrado</th><th>WhatsApp (E.164)</th><th>Grupo de WhatsApp</th><th>E-mail</th></tr></thead>
        <tbody>
          <tr v-for="c in contacts" :key="c.id" :data-carrier="c.id">
            <td><b class="strong">{{ c.nome_fantasia }}</b></td>
            <td>{{ c.telefone ? fmtPhone(c.telefone) : '—' }}</td>
            <td><span v-if="c.wa" class="mono">{{ c.wa }}</span><span v-else class="pill sm st-can">Sem WhatsApp válido</span></td>
            <td><span v-if="c.whatsapp_grupo_id" class="mono">{{ c.whatsapp_grupo_id }}</span><span v-else class="mut">Sem grupo</span></td>
            <td><span v-if="c.email">{{ c.email }}</span><span v-else class="pill sm st-can">Sem e-mail</span></td>
          </tr>
          <tr v-if="!contacts.length"><td colspan="5"><EmptyState icon="carriers" :message="db.loading.carriers ? 'Carregando…' : 'Nenhuma transportadora ativa.'" /></td></tr>
        </tbody>
      </table>
    </div>
    <p class="mut" style="font-size:12px;padding:10px 14px">O contato vem do cadastro de Transportadoras. Sem WhatsApp válido, a transportadora recebe só o e-mail e o sistema avisa na hora do encaminhamento.</p>
  </CardPanel>

  <template v-else>
    <CardPanel title="Enviar teste" count="confere SMTP e WhatsApp">
      <div class="fgrid">
        <div class="field c3"><label for="nt-canal">Canal</label>
          <select id="nt-canal" v-model="test.canal" class="inp"><option v-for="c in CHANNELS" :key="c" :value="c">{{ CHANNEL_LABELS[c] }}</option></select></div>
        <div class="field c6"><label for="nt-destino">{{ test.canal === 'EMAIL' ? 'E-mail de destino' : 'Telefone com DDD' }}</label>
          <input id="nt-destino" v-model="test.destino" class="inp" :type="test.canal === 'EMAIL' ? 'email' : 'tel'" autocomplete="off" :placeholder="test.canal === 'EMAIL' ? 'voce@empresa.com.br' : '(19) 99999-0001'" /></div>
        <div v-if="session.canAction('NOTIFICACAO.ENVIAR_TESTE')" class="field c3"><label>&nbsp;</label>
          <button class="btn btn-line" data-act="send-test" :disabled="sending" :aria-busy="sending" @click="sendTest">Enviar teste</button></div>
      </div>
    </CardPanel>

    <CardPanel title="Histórico de envios" :count="fmtRegistros(db.notifHistory.length)" flush>
      <div class="filt" data-filters>
        <select v-model="flt.status" class="inp" aria-label="Filtrar por situação" data-flt="status" @change="loadHistory()">
          <option value="">Todas as situações</option><option v-for="(l, v) in STATUS_LABELS" :key="v" :value="v">{{ l }}</option>
        </select>
        <select v-model="flt.canal" class="inp" aria-label="Filtrar por canal" data-flt="canal" @change="loadHistory()">
          <option value="">Todos os canais</option><option v-for="c in CHANNELS" :key="c" :value="c">{{ CHANNEL_LABELS[c] }}</option>
        </select>
        <select v-model="flt.evento" class="inp" aria-label="Filtrar por evento" data-flt="evento" @change="loadHistory()">
          <option value="">Todos os eventos</option><option v-for="e in eventOptions" :key="e" :value="e">{{ eventLabel(e) }}</option>
        </select>
      </div>
      <div class="tbl-wrap" role="region" tabindex="0" aria-label="Histórico de notificações">
        <table class="tbl" style="min-width:900px">
          <thead><tr><th class="act">Ações</th><th>Quando</th><th>Evento</th><th>Registro</th><th>Canal</th><th>Destino</th><th>Situação</th><th>Erro</th></tr></thead>
          <tbody>
            <tr v-for="n in db.notifHistory" :key="n.id" :data-notif="n.id">
              <td class="act"><div class="wrap">
                <button v-if="n.status === 'FALHA' && session.canAction('NOTIFICACAO.REENVIAR')" class="ibtn ok" data-act="resend" title="Reenviar" aria-label="Reenviar"
                  :disabled="resending === n.id" @click="resend(n)"><NavIcon name="undo" :size="16" /></button>
              </div></td>
              <td>{{ when(n.criado_em) }}</td>
              <td>{{ eventLabel(n.evento) }}</td>
              <td class="mono">{{ n.registro_codigo || '—' }}</td>
              <td>{{ CHANNEL_LABELS[n.canal] }}</td>
              <td>{{ n.destino_nome }}<span class="mut"> · {{ n.endereco || 'sem endereço' }}</span></td>
              <td><span class="pill sm" :class="pill(n.status)">{{ STATUS_LABELS[n.status] }}</span></td>
              <td style="white-space:normal;max-width:260px">{{ n.erro || '' }}</td>
            </tr>
            <tr v-if="!db.notifHistory.length"><td colspan="8"><EmptyState icon="notifications" :message="db.loading.notifHistory ? 'Carregando…' : 'Nenhuma notificação para esse filtro.'" /></td></tr>
          </tbody>
        </table>
      </div>
    </CardPanel>
  </template>
</template>
