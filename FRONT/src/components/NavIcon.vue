<script setup>
defineProps({ name: String, size: { type: Number, default: 18 } })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <template v-if="name === 'dash'">
      <rect x="3" y="3" width="7" height="9" rx="1" /><rect x="14" y="3" width="7" height="5" rx="1" />
      <rect x="14" y="12" width="7" height="9" rx="1" /><rect x="3" y="16" width="7" height="5" rx="1" />
    </template>
    <template v-else-if="name === 'quotes'">
      <path d="M7 3h10l4 4v14H7z" /><path d="M17 3v4h4" /><path d="M10 12h6M10 16h6M10 8h3" />
    </template>
    <template v-else-if="name === 'quote-search'">
      <path d="M7 3h10l4 4v14H7z" /><path d="M17 3v4h4" /><circle cx="13" cy="14" r="3" /><path d="m15.2 16.2 2.3 2.3" />
    </template>
    <template v-else-if="name === 'products'">
      <path d="M9 3h6M10 3v6l-5 9a3 3 0 0 0 3 5h8a3 3 0 0 0 3-5l-5-9V3" /><path d="M8 15h8" />
    </template>
    <template v-else-if="name === 'packagings'">
      <path d="M3 8l9-5 9 5v8l-9 5-9-5z" /><path d="M3 8l9 5 9-5M12 13v8" />
    </template>
    <template v-else-if="name === 'carriers' || name === 'truck'">
      <path d="M1 8h13v8H1z" /><path d="M14 10h4l3 3v3h-7z" /><circle cx="5.5" cy="18" r="1.8" /><circle cx="17.5" cy="18" r="1.8" />
    </template>
    <template v-else-if="name === 'loads'">
      <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /><path d="M8 15h3M13 15h3" />
    </template>
    <template v-else-if="name === 'users'">
      <circle cx="9" cy="8" r="3.5" /><path d="M2 21c0-3.9 3.1-7 7-7s7 3.1 7 7" /><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.4c2.2.8 4 3 4 6.6" />
    </template>
    <template v-else-if="name === 'antt'">
      <path d="M4 20V10M10 20V4M16 20v-8M22 20H2" /><path d="M2 7l6-3 6 5 8-6" />
    </template>
    <template v-else-if="name === 'notifications'">
      <path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8" /><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0" />
    </template>
    <template v-else-if="name === 'registrations'">
      <circle cx="9" cy="8" r="3.5" /><path d="M2 21c0-3.9 3.1-7 7-7 1.3 0 2.5.3 3.5.8" /><path d="m15 18 2.5 2.5L22 16" />
    </template>
    <path v-else-if="name === 'check'" d="M20 6 9 17l-5-5" />
    <path v-else-if="name === 'x'" d="M18 6 6 18M6 6l12 12" />
    <path v-else-if="name === 'plus'" d="M12 5v14M5 12h14" />
    <path v-else-if="name === 'arrow'" d="M5 12h14M13 6l6 6-6 6" />
    <path v-else-if="name === 'menu'" d="M4 6h16M4 12h16M4 18h16" />
    <template v-else-if="name === 'edit'">
      <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
    </template>
    <template v-else-if="name === 'pause'">
      <rect x="6" y="4" width="4" height="16" /><rect x="14" y="4" width="4" height="16" />
    </template>
    <path v-else-if="name === 'play'" d="M6 4l14 8-14 8z" />
    <template v-else-if="name === 'lock'">
      <rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </template>
    <template v-else-if="name === 'unlock'">
      <rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 7.5-2" />
    </template>
    <template v-else-if="name === 'eye'">
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" />
    </template>
    <template v-else-if="name === 'undo'">
      <path d="M9 14 4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" />
    </template>
    <path v-else-if="name === 'trash'" d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6" />
    <template v-else-if="name === 'warn'">
      <path d="M12 3 2 20h20z" /><path d="M12 10v4M12 17.5v.5" />
    </template>
    <template v-else-if="name === 'ok'">
      <circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" />
    </template>
    <template v-else-if="name === 'err'">
      <circle cx="12" cy="12" r="9" /><path d="m15 9-6 6M9 9l6 6" />
    </template>
    <template v-else-if="name === 'info'">
      <circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 7.5v.5" />
    </template>
    <template v-else-if="name === 'ban'">
      <circle cx="12" cy="12" r="9" /><path d="m5.6 5.6 12.8 12.8" />
    </template>
    <template v-else-if="name === 'clock'">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </template>
    <template v-else-if="name === 'power'">
      <path d="M12 3v9" /><path d="M6.4 6.4a8 8 0 1 0 11.2 0" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" />
    </template>
    <template v-else-if="name === 'refresh'">
      <path d="M20 11a8 8 0 0 0-14-4L4 9" /><path d="M4 4v5h5" /><path d="M4 13a8 8 0 0 0 14 4l2-2" /><path d="M20 20v-5h-5" />
    </template>
    <template v-else-if="name === 'inbox'">
      <path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z" />
    </template>
    <template v-else-if="name === 'chart'">
      <path d="M3 3v18h18" /><path d="M7 15v3M12 9v9M17 5v13" />
    </template>
    <template v-else-if="name === 'user'">
      <circle cx="12" cy="8" r="4" /><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
    </template>
  </svg>
</template>
