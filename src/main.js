// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import App from './App.vue'
import './style.css'
import './navigation.css'
import './dialog.css'

const root = document.getElementById('librefinance-app')
if (root) {
  createApp(App).mount(root)
}
