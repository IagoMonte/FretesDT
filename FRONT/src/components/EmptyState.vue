<script setup>
import { db, reloadList } from '../stores/db'
import NavIcon from './NavIcon.vue'

defineProps({ icon: { type: String, default: 'inbox' }, message: String, listKey: String })
</script>

<template>
  <div v-if="listKey && db.loadError[listKey]" class="empty" role="alert">
    <div class="big"><NavIcon name="warn" :size="36" /></div>
    Não foi possível carregar a lista. {{ db.loadError[listKey] }}
    <div style="margin-top:10px"><button class="btn btn-line btn-sm" type="button" data-act="retry-list" @click="reloadList(listKey)">Tentar de novo</button></div>
  </div>
  <div v-else class="empty"><div class="big"><NavIcon :name="icon" :size="36" /></div>{{ message }}</div>
</template>
