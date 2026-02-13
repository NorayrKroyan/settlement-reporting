import { createRouter, createWebHistory } from 'vue-router'

import SettlementPage from '../pages/SettlementPage.vue'
import SettlementViewer from '../pages/SettlementViewer.vue'
import InboundLoadMatchingPage from '../pages/InboundLoadMatchingPage.vue'


export default createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'home', component: SettlementPage },

        { path: '/settlements', redirect: '/' },
        { path: '/settlements/viewer', name: 'settlements.viewer', component: SettlementViewer },

        {
            path: '/inbound-load-matching',
            name: 'inbound-load-matching',
            component: InboundLoadMatchingPage,
        },

        { path: '/:pathMatch(.*)*', redirect: '/' },

    ],
})
