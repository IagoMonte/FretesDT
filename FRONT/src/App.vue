<script setup>
import ModalHost from './components/ModalHost.vue'
import ToastHost from './components/ToastHost.vue'
</script>

<template>
  <router-view />
  <ModalHost />
  <ToastHost />
</template>
