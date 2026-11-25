import { createRouter, createWebHistory, createMemoryHistory } from 'vue-router'
import { pushToast } from '../stores/ui'
import { auth } from '../stores/session'

export const routes = [
  { path: '/login', name: 'login', component: () => import('../views/LoginView.vue') },
  { path: '/cadastro', name: 'signup', component: () => import('../views/SignupView.vue') },
  {
    path: '/',
    component: () => import('../layouts/AppShell.vue'),
    children: [
      { path: '', name: 'root', redirect: () => ({ name: auth.session?.home || 'login' }) },
      { path: 'dash', name: 'dash', component: () => import('../views/DashView.vue'), meta: { res: 'PAINEL', title: ['Painel', 'Operacional'] } },
      { path: 'quotes', name: 'quotes', component: () => import('../views/QuotesView.vue'), meta: { res: 'COTACAO', title: ['Solicitar', 'Cotação'] } },
      { path: 'quote-search', name: 'quote-search', component: () => import('../views/QuoteSearchView.vue'), meta: { res: 'COTACAO', title: ['Consultar', 'Cotação'] } },
      { path: 'products', name: 'products', component: () => import('../views/ProductsView.vue'), meta: { res: 'PRODUTO', title: ['Cadastro', 'Produtos'] } },
      { path: 'packagings', name: 'packagings', component: () => import('../views/PackagingView.vue'), meta: { res: 'EMBALAGEM', title: ['Cadastro', 'Embalagens'] } },
      { path: 'carriers', name: 'carriers', component: () => import('../views/CarriersView.vue'), meta: { res: 'TRANSPORTADORA', title: ['Cadastro de', 'Transportadoras'] } },
      { path: 'antt', name: 'antt', component: () => import('../views/AnttView.vue'), meta: { res: 'ANTT', title: ['Piso Mínimo', 'ANTT'] } },
      { path: 'users', name: 'users', component: () => import('../views/UsersView.vue'), meta: { res: 'USUARIO', title: ['Usuários e', 'Acessos'] } },
      { path: 'groups', name: 'groups', component: () => import('../views/GroupsView.vue'), meta: { res: 'GRUPO', title: ['Grupos de', 'Acesso'] } },
      { path: 'registrations', name: 'registrations', component: () => import('../views/RegistrationsView.vue'), meta: { res: 'USUARIO', action: 'USUARIO.APROVAR_CADASTRO', title: ['Aprovação de', 'Cadastros'] } },
      { path: 'notificacoes', name: 'notifications', component: () => import('../views/NotificationsView.vue'), meta: { res: 'NOTIFICACAO', title: ['Notificações', 'WhatsApp e e-mail'] } },
      { path: 'loads', name: 'loads', component: () => import('../views/LoadsView.vue'), meta: { res: 'PROGRAMACAO', title: ['Programação de', 'Cargas'] } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'notfound', component: { render: () => null } },
]

export function createAppRouter(memory = false) {
  const router = createRouter({
    history: memory ? createMemoryHistory() : createWebHistory(),
    routes,
  })
  router.beforeEach((to) => {
    const s = auth.session
    if (to.name === 'notfound') {
      if (!s) return { name: 'login' }
      pushToast('warn', 'Página não encontrada. Você foi levado à tela inicial.')
      return { name: s.home }
    }
    if (to.name === 'login' || to.name === 'signup') return s ? { name: s.home } : true
    if (!s) return to.fullPath === '/' ? { name: 'login' } : { name: 'login', query: { next: to.fullPath } }
    const res = to.meta.res
    if ((res && !s.can(res)) || (to.meta.action && !s.canAction(to.meta.action))) {
      pushToast('warn', 'Você não tem acesso a essa tela.')
      return { name: s.home }
    }
    return true
  })
  return router
}
