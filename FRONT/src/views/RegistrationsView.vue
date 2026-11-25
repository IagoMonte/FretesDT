<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { db, loadRegistrations, loadCarriers, approveRegistration, rejectRegistration } from '../stores/db'
import { pushToast } from '../stores/ui'
import { maskCNPJ } from '../utils/format'
import { usePolling } from '../composables/usePolling'
import CardPanel from '../components/CardPanel.vue'
import EmptyState from '../components/EmptyState.vue'
import FormModal from '../components/FormModal.vue'
import MotivoModal from '../components/MotivoModal.vue'
import NavIcon from '../components/NavIcon.vue'

const TABS = [
  { key: 'pending', label: 'Pendentes', status: 'PENDENTE' },
  { key: 'history', label: 'Histórico', status: 'ATIVO,RECUSADO' },
]
const tab = ref('pending')
const current = computed(() => TABS.find((t) => t.key === tab.value))
const isPending = computed(() => tab.value === 'pending')

const approving = ref(null)
const rejecting = ref(null)
const busy = ref(false)
const approveError = ref('')
const rejectError = ref('')
const form = reactive({ grupo: 'VENDEDOR', modo: 'vincular', transportadora_id: '' })

const activeCarriers = computed(() => db.carriers.filter((c) => c.ativo))
const canCreate = computed(() => !!approving.value?.transportadora_dados?.cnpj)
const suggestedId = computed(() => approving.value?.transportadora_sugerida?.id || '')

const fmtDT = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—')
const typeLabel = (t) => (t === 'TRANSPORTADORA' ? 'Transportadora' : 'Vendedor')
const groupLabel = (g) => (g === 'TRANSPORTADORA' ? 'Transportadora' : g === 'VENDEDOR' ? 'Vendedor' : g)

const reload = (opts) => loadRegistrations(current.value.status, opts)
onMounted(() => { reload(); loadCarriers() })
watch(tab, () => { db.registrations = []; reload() })
usePolling(() => reload({ silent: true }), { interval: 15000, paused: () => !!approving.value || !!rejecting.value || !isPending.value })

function startApprove(c) {
  approveError.value = ''
  const withData = !!c.transportadora_dados?.cnpj
  Object.assign(form, {
    grupo: c.tipo_solicitado,
    modo: c.transportadora_sugerida ? 'vincular' : (withData ? 'criar' : 'vincular'),
    transportadora_id: c.transportadora_sugerida?.id || '',
  })
  approving.value = c
  if (!db.carriers.length) loadCarriers()
}
function closeApprove() { approving.value = null; approveError.value = '' }
watch(() => form.grupo, (g) => { if (g === 'TRANSPORTADORA' && form.modo === 'criar' && !canCreate.value) form.modo = 'vincular' })

async function confirmApprove() {
  if (busy.value || !approving.value) return
  busy.value = true
  approveError.value = ''
  const c = approving.value
  const r = await approveRegistration(c.id, { grupo: form.grupo, modo: form.modo, transportadora_id: form.transportadora_id })
  busy.value = false
  if (!r.ok) {
    approveError.value = r.errors?.transportadora_id?.[0] || r.errors?.grupo?.[0] || r.error
    if (r.status === 409) closeApprove()
    return pushToast('err', approveError.value)
  }
  pushToast('ok', `Cadastro de **${c.nome}** aprovado — já pode entrar com o login **${c.login}**.`)
  closeApprove()
}

function startReject(c) { rejectError.value = ''; rejecting.value = c }
function closeReject() { rejecting.value = null; rejectError.value = '' }
async function confirmReject(motivo) {
  if (busy.value || !rejecting.value) return
  busy.value = true
  rejectError.value = ''
  const c = rejecting.value
  const r = await rejectRegistration(c.id, motivo)
  busy.value = false
  if (!r.ok) {
    rejectError.value = r.errors?.motivo?.[0] || r.error
    if (r.status === 409) { closeReject(); pushToast('err', r.error) }
    return
  }
  pushToast('warn', `Cadastro de **${c.nome}** recusado.`)
  closeReject()
}
</script>

<template>
  <CardPanel title="Cadastros" :count="isPending ? `${db.pendingCount} pendentes` : `${db.registrations.length} decididos`" flush>
    <div class="reg-tabs" role="tablist" aria-label="Situação dos cadastros">
      <button v-for="t in TABS" :id="`rg-tab-${t.key}`" :key="t.key" type="button" role="tab" class="reg-tab" :class="{ on: tab === t.key }"
        :aria-selected="tab === t.key" aria-controls="rg-panel" :tabindex="tab === t.key ? 0 : -1" :data-tab="t.key" @click="tab = t.key">
        {{ t.label }}<span v-if="t.key === 'pending' && db.pendingCount" class="reg-cnt">{{ db.pendingCount }}</span>
      </button>
    </div>

    <div id="rg-panel" role="tabpanel" :aria-labelledby="`rg-tab-${tab}`" class="reg-body">
      <div v-if="db.registrations.length" class="reg-list">
        <article v-for="c in db.registrations" :key="c.id" class="reg-card" :data-reg="c.login">
          <header class="reg-h">
            <div class="reg-who"><b class="strong">{{ c.nome }}</b><span class="mut mono">{{ c.login }}</span></div>
            <span v-if="isPending" class="pill sm" :class="c.tipo_solicitado === 'TRANSPORTADORA' ? 'st-tra' : 'st-agu'">{{ typeLabel(c.tipo_solicitado) }}</span>
            <span v-else class="pill sm" :class="c.situacao === 'RECUSADO' ? 'st-can' : 'st-ent'">{{ c.situacao === 'RECUSADO' ? 'Recusado' : 'Aprovado' }}</span>
          </header>
          <dl class="reg-dl">
            <div><dt>E-mail</dt><dd>{{ c.email || '—' }}</dd></div>
            <div><dt>Solicitado em</dt><dd>{{ fmtDT(c.solicitado_em) }}</dd></div>
            <template v-if="c.transportadora_dados">
              <div><dt>Razão social</dt><dd>{{ c.transportadora_dados.razao_social }}</dd></div>
              <div v-if="c.transportadora_dados.nome_fantasia"><dt>Nome fantasia</dt><dd>{{ c.transportadora_dados.nome_fantasia }}</dd></div>
              <div><dt>CNPJ</dt><dd class="mono">{{ maskCNPJ(c.transportadora_dados.cnpj) }}</dd></div>
            </template>
            <template v-if="!isPending">
              <div><dt>Decidido em</dt><dd>{{ fmtDT(c.decidido_em) }}<template v-if="c.decidido_por"> por {{ c.decidido_por.nome }}</template></dd></div>
              <div v-if="c.situacao === 'ATIVO'"><dt>Acesso</dt><dd>{{ c.grupos.map(groupLabel).join(', ') }}<template v-if="c.transportadora"> · {{ c.transportadora.nome_fantasia }}</template></dd></div>
              <div v-if="c.motivo_recusa"><dt>Motivo</dt><dd>{{ c.motivo_recusa }}</dd></div>
            </template>
          </dl>
          <p v-if="isPending && c.transportadora_sugerida" class="reg-hint"><NavIcon name="info" :size="15" /><span>Já existe a transportadora <b>{{ c.transportadora_sugerida.nome_fantasia }}</b> com este CNPJ.</span></p>
          <footer v-if="isPending" class="reg-acts">
            <button type="button" class="btn btn-green btn-sm" data-act="approve" :aria-label="`Aprovar cadastro de ${c.nome}`" @click="startApprove(c)"><NavIcon name="check" :size="14" /> Aprovar</button>
            <button type="button" class="btn btn-line btn-sm reg-no" data-act="reject" :aria-label="`Recusar cadastro de ${c.nome}`" @click="startReject(c)"><NavIcon name="x" :size="14" /> Recusar</button>
          </footer>
        </article>
      </div>
      <EmptyState v-else icon="registrations" :message="db.loading.registrations ? 'Carregando…' : isPending ? 'Nenhum cadastro aguardando aprovação.' : 'Nenhum cadastro decidido ainda.'" />
    </div>
  </CardPanel>

  <FormModal v-if="approving" title="Aprovar cadastro" :sub="approving.nome" @close="closeApprove">
    <div class="fgrid">
      <fieldset class="field c12">
        <legend class="lbl">Grupo de acesso <i>*</i></legend>
        <div class="chipz">
          <label :class="{ on: form.grupo === 'VENDEDOR' }"><input v-model="form.grupo" type="radio" name="ap-grupo" value="VENDEDOR" />Vendedor</label>
          <label :class="{ on: form.grupo === 'TRANSPORTADORA' }"><input v-model="form.grupo" type="radio" name="ap-grupo" value="TRANSPORTADORA" />Transportadora</label>
        </div>
      </fieldset>

      <fieldset v-if="form.grupo === 'TRANSPORTADORA'" class="field c12">
        <legend class="lbl">Transportadora <i>*</i></legend>
        <label class="reg-opt" :class="{ on: form.modo === 'vincular' }">
          <input v-model="form.modo" type="radio" name="ap-modo" value="vincular" />
          <span><b>Vincular a uma existente</b><small class="mut">O usuário passa a responder pela transportadora escolhida.</small></span>
        </label>
        <div v-if="form.modo === 'vincular'" class="reg-sub">
          <label for="ap-transp" class="lbl">Transportadora existente</label>
          <select id="ap-transp" v-model="form.transportadora_id" class="inp" :class="{ invalid: approveError }" :aria-invalid="approveError ? 'true' : undefined" aria-describedby="ap-err">
            <option value="">— selecionar —</option>
            <option v-for="t in activeCarriers" :key="t.id" :value="t.id">{{ t.nome_fantasia }} · {{ maskCNPJ(t.cnpj) }}{{ t.id === suggestedId ? ' (mesmo CNPJ)' : '' }}</option>
          </select>
        </div>
        <label class="reg-opt" :class="{ on: form.modo === 'criar', off: !canCreate }">
          <input v-model="form.modo" type="radio" name="ap-modo" value="criar" :disabled="!canCreate" />
          <span><b>Criar nova com os dados do cadastro</b>
            <small v-if="canCreate" class="mut">{{ approving.transportadora_dados.nome_fantasia || approving.transportadora_dados.razao_social }} · CNPJ {{ maskCNPJ(approving.transportadora_dados.cnpj) }}</small>
            <small v-else class="mut">O cadastro não trouxe dados de transportadora.</small></span>
        </label>
      </fieldset>
      <div v-if="approveError" id="ap-err" class="ferr c12" role="alert">{{ approveError }}</div>
    </div>
    <template #footer>
      <button type="button" class="btn btn-line" @click="closeApprove">Cancelar</button>
      <button type="button" class="btn btn-green" data-act="confirm-approve" :disabled="busy" :aria-busy="busy" @click="confirmApprove"><NavIcon name="check" :size="14" /> Aprovar cadastro</button>
    </template>
  </FormModal>

  <MotivoModal v-if="rejecting" title="Recusar cadastro" :sub="rejecting.nome" label="Recusar cadastro" :busy="busy" :error="rejectError"
    @close="closeReject" @confirm="confirmReject" />
</template>
