import { createApp, watch } from 'vue'
import App from './App.vue'
import { createAppRouter } from './router'
import { auth, restore } from './stores/session'
import './styles/main.css'

restore().finally(() => {
  const router = createAppRouter()
  watch(() => auth.session, (s) => { if (!s) router.push({ name: 'login' }) })
  createApp(App).use(router).mount('#app')
})
