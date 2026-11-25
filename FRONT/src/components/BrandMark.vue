<script setup>
const props = defineProps({ variant: { type: String, default: 'login' } })
const red = props.variant === 'side' ? ['#F0142C', '#D6001C'] : ['#E40016', '#B00014']
const a = `bm-${props.variant}-a`
const b = `bm-${props.variant}-b`
</script>

<template>
  <svg class="mk" viewBox="0 0 72 72" aria-label="FretesDT">
    <defs>
      <linearGradient :id="a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#009047" /><stop offset="1" stop-color="#00693C" /></linearGradient>
      <linearGradient :id="b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" :stop-color="red[0]" /><stop offset="1" :stop-color="red[1]" /></linearGradient>
    </defs>
    <path d="M40 10a22 22 0 1 1 0 44" fill="none" :stroke="`url(#${b})`" stroke-width="13" stroke-linecap="round" />
    <path d="M40 54a22 22 0 0 1 0-44" fill="none" :stroke="`url(#${a})`" stroke-width="13" stroke-linecap="round" />
    <rect x="12" y="30" width="13" height="34" rx="3" :fill="`url(#${b})`" />
  </svg>
</template>
