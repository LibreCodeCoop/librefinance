// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createApp } from 'vue'
import AdminPage from './AdminPage.vue'

const root = document.getElementById('librefinance-administration')
if (root) createApp(AdminPage).mount(root)
