<script setup>
import NavIcon from './NavIcon.vue'
defineProps({
  load: { type: Object, required: true },
  canEdit: Boolean, canCancel: Boolean, canMove: Boolean, canDeliver: Boolean, readOnlyProfile: Boolean,
  hasActions: Boolean, final: Boolean,
})
defineEmits(['edit', 'transit', 'deliver', 'giveback', 'cancel', 'readonly'])
</script>

<template>
  <template v-if="hasActions">
    <button v-if="canEdit && load.status !== 'CANCELADO'" class="ibtn ed" title="Editar" aria-label="Editar" @click="$emit('edit', load)"><NavIcon name="edit" :size="16" /></button>
    <button v-if="canMove && load.status === 'AGUARDANDO_CARREGAMENTO'" class="ibtn ok" data-act="transit-load" title="Marcar em trânsito" aria-label="Marcar em trânsito" @click="$emit('transit', load)"><NavIcon name="truck" :size="16" /></button>
    <button v-if="canDeliver && load.status === 'EM_TRANSITO'" class="ibtn ok" data-act="deliver-load" title="Marcar como entregue" aria-label="Marcar como entregue" @click="$emit('deliver', load)"><NavIcon name="check" :size="16" /></button>
    <span v-if="load.status === 'ENTREGUE' && !canEdit" class="prz-ok" role="img" aria-label="Entregue"><NavIcon name="check" :size="12" /></span>
    <button v-if="canEdit && load.status === 'ENTREGUE'" class="ibtn ey" data-act="return-load" title="Registrar devolução" aria-label="Registrar devolução" @click="$emit('giveback', load)"><NavIcon name="undo" :size="16" /></button>
    <button v-if="canCancel && !final" class="ibtn dl" data-act="cancel-load" title="Cancelar" aria-label="Cancelar" @click="$emit('cancel', load)"><NavIcon name="x" :size="16" /></button>
  </template>
  <button v-else-if="readOnlyProfile" class="ibtn ey" title="Somente visualização" aria-label="Somente visualização" style="cursor:help" @click="$emit('readonly')"><NavIcon name="eye" :size="16" /></button>
</template>
