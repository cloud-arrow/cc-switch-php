/**
 * CC Switch SPA — Alpine.js application.
 */
function ccSwitch() {
    return {
        // Navigation
        currentTab: 'providers',
        currentApp: 'claude',
        apps: ['claude', 'codex', 'gemini', 'opencode', 'openclaw'],

        // Loading state
        loading: false,

        // Providers
        providers: [],
        showAddProvider: false,
        editingProvider: null,
        providerForm: { name: '', category: '', settings_config: '', notes: '', meta: '' },

        // MCP
        mcpServers: [],
        showAddMcp: false,
        mcpForm: { name: '', command: '', args: '' },

        // Proxy
        proxyStatus: { running: false },
        proxyConfig: null,
        proxyHealth: [],

        // Skills
        skills: [],
        showInstallSkill: false,
        skillForm: { name: '', source: '' },

        // Prompts
        prompts: [],
        showAddPrompt: false,
        editingPrompt: null,
        promptForm: { title: '', content: '' },

        // Settings
        settings: null,

        // Usage
        usageFilter: { app: 'claude', period: 'today' },
        usageStats: {},
        usageLogs: [],

        // Toast
        toast: { show: false, message: '', type: 'info' },

        // ===== Init =====
        async init() {
            await this.loadProviders();
        },

        // ===== Navigation =====
        async switchTab(tab) {
            this.currentTab = tab;
            this.loading = true;
            try {
                switch (tab) {
                    case 'providers': await this.loadProviders(); break;
                    case 'mcp': await this.loadMcp(); break;
                    case 'proxy': await this.loadProxyAll(); break;
                    case 'skills': await this.loadSkills(); break;
                    case 'prompts': await this.loadPrompts(); break;
                    case 'settings': await this.loadSettings(); break;
                    case 'usage': await this.loadUsageAll(); break;
                }
            } catch (e) {
                this.showToast('Failed to load: ' + e.message, 'error');
            }
            this.loading = false;
        },

        async switchApp(app) {
            this.currentApp = app;
            this.loading = true;
            try {
                if (this.currentTab === 'providers') await this.loadProviders();
                else if (this.currentTab === 'prompts') await this.loadPrompts();
                else if (this.currentTab === 'proxy') await this.loadProxyAll();
            } catch (e) {
                this.showToast('Failed to load: ' + e.message, 'error');
            }
            this.loading = false;
        },

        // ===== Provider Methods =====
        async loadProviders() {
            const data = await this.api('GET', `/api/providers/${this.currentApp}`);
            this.providers = Array.isArray(data) ? data : [];
            this.loading = false;
        },

        async switchProvider(id) {
            await this.api('POST', `/api/providers/${this.currentApp}/${id}/switch`);
            this.showToast('Provider switched', 'success');
            await this.loadProviders();
        },

        async deleteProvider(id) {
            if (!confirm('Delete this provider?')) return;
            await this.api('DELETE', `/api/providers/${this.currentApp}/${id}`);
            this.showToast('Provider deleted', 'success');
            await this.loadProviders();
        },

        editProvider(p) {
            this.editingProvider = p;
            this.providerForm = {
                name: p.name || '',
                category: p.category || '',
                settings_config: typeof p.settings_config === 'object'
                    ? JSON.stringify(p.settings_config, null, 2)
                    : (p.settings_config || ''),
                notes: p.notes || '',
                meta: typeof p.meta === 'object'
                    ? JSON.stringify(p.meta, null, 2)
                    : (p.meta || ''),
            };
        },

        async saveProvider() {
            const data = {
                name: this.providerForm.name,
                category: this.providerForm.category || null,
                settings_config: this.providerForm.settings_config || '{}',
                notes: this.providerForm.notes || null,
                meta: this.providerForm.meta || '{}',
            };

            if (this.editingProvider) {
                await this.api('PUT', `/api/providers/${this.currentApp}/${this.editingProvider.id}`, data);
                this.showToast('Provider updated', 'success');
            } else {
                await this.api('POST', `/api/providers/${this.currentApp}`, data);
                this.showToast('Provider added', 'success');
            }

            this.closeProviderForm();
            await this.loadProviders();
        },

        closeProviderForm() {
            this.showAddProvider = false;
            this.editingProvider = null;
            this.providerForm = { name: '', category: '', settings_config: '', notes: '', meta: '' };
        },

        // ===== MCP Methods =====
        async loadMcp() {
            const data = await this.api('GET', '/api/mcp');
            this.mcpServers = Array.isArray(data) ? data : [];
            this.loading = false;
        },

        async addMcp() {
            const args = this.mcpForm.args
                ? this.mcpForm.args.split(',').map(s => s.trim()).filter(Boolean)
                : [];
            await this.api('POST', '/api/mcp', {
                name: this.mcpForm.name,
                command: this.mcpForm.command,
                args: args,
            });
            this.showAddMcp = false;
            this.mcpForm = { name: '', command: '', args: '' };
            this.showToast('MCP server added', 'success');
            await this.loadMcp();
        },

        async deleteMcp(id) {
            if (!confirm('Delete this MCP server?')) return;
            await this.api('DELETE', `/api/mcp/${id}`);
            this.showToast('MCP server deleted', 'success');
            await this.loadMcp();
        },

        async syncMcp() {
            await this.api('POST', '/api/mcp/sync');
            this.showToast('MCP synced', 'success');
            await this.loadMcp();
        },

        // ===== Proxy Methods =====
        async loadProxyAll() {
            await Promise.all([
                this.loadProxyStatus(),
                this.loadProxyConfig(),
                this.loadProxyHealth(),
            ]);
            this.loading = false;
        },

        async loadProxyStatus() {
            try {
                const data = await this.api('GET', '/api/proxy/status');
                this.proxyStatus = data || { running: false };
            } catch {
                this.proxyStatus = { running: false };
            }
        },

        async loadProxyConfig() {
            try {
                const data = await this.api('GET', `/api/proxy/config/${this.currentApp}`);
                this.proxyConfig = data || {};
            } catch {
                this.proxyConfig = {};
            }
        },

        async loadProxyHealth() {
            try {
                const data = await this.api('GET', `/api/proxy/health/${this.currentApp}`);
                this.proxyHealth = Array.isArray(data?.circuit_breaker) ? data.circuit_breaker : [];
            } catch {
                this.proxyHealth = [];
            }
        },

        async startProxy() {
            await this.api('POST', '/api/proxy/start');
            this.showToast('Proxy started', 'success');
            await this.loadProxyStatus();
        },

        async stopProxy() {
            await this.api('POST', '/api/proxy/stop');
            this.showToast('Proxy stopped', 'success');
            await this.loadProxyStatus();
        },

        async saveProxyConfig() {
            if (!this.proxyConfig) return;
            await this.api('PUT', `/api/proxy/config/${this.currentApp}`, this.proxyConfig);
            this.showToast('Proxy config saved', 'success');
        },

        // ===== Skills Methods =====
        async loadSkills() {
            const data = await this.api('GET', '/api/skills');
            this.skills = Array.isArray(data) ? data : [];
            this.loading = false;
        },

        async installSkill() {
            await this.api('POST', '/api/skills/install', {
                name: this.skillForm.name,
                source: this.skillForm.source,
            });
            this.showInstallSkill = false;
            this.skillForm = { name: '', source: '' };
            this.showToast('Skill installed', 'success');
            await this.loadSkills();
        },

        async deleteSkill(id) {
            if (!confirm('Delete this skill?')) return;
            await this.api('DELETE', `/api/skills/${id}`);
            this.showToast('Skill deleted', 'success');
            await this.loadSkills();
        },

        async syncSkills() {
            await this.api('POST', '/api/skills/sync');
            this.showToast('Skills synced', 'success');
            await this.loadSkills();
        },

        // ===== Prompts Methods =====
        async loadPrompts() {
            const data = await this.api('GET', `/api/prompts/${this.currentApp}`);
            this.prompts = Array.isArray(data) ? data : [];
            this.loading = false;
        },

        editPrompt(p) {
            this.editingPrompt = p;
            this.promptForm = {
                title: p.title || p.name || '',
                content: p.content || '',
            };
        },

        async savePrompt() {
            const data = {
                title: this.promptForm.title,
                content: this.promptForm.content,
            };

            if (this.editingPrompt) {
                await this.api('PUT', `/api/prompts/${this.currentApp}/${this.editingPrompt.id}`, data);
                this.showToast('Prompt updated', 'success');
            } else {
                await this.api('POST', `/api/prompts/${this.currentApp}`, data);
                this.showToast('Prompt added', 'success');
            }

            this.closePromptForm();
            await this.loadPrompts();
        },

        async deletePrompt(id) {
            if (!confirm('Delete this prompt?')) return;
            await this.api('DELETE', `/api/prompts/${this.currentApp}/${id}`);
            this.showToast('Prompt deleted', 'success');
            await this.loadPrompts();
        },

        closePromptForm() {
            this.showAddPrompt = false;
            this.editingPrompt = null;
            this.promptForm = { title: '', content: '' };
        },

        // ===== Settings Methods =====
        async loadSettings() {
            try {
                const data = await this.api('GET', '/api/settings');
                this.settings = data || {};
            } catch {
                this.settings = {};
            }
            this.loading = false;
        },

        async saveSettings() {
            await this.api('PUT', '/api/settings', this.settings);
            this.showToast('Settings saved', 'success');
        },

        // ===== Usage Methods =====
        async loadUsageAll() {
            await Promise.all([this.loadUsageStats(), this.loadUsageLogs()]);
            this.loading = false;
        },

        async loadUsageStats() {
            const params = new URLSearchParams({
                app: this.usageFilter.app,
                period: this.usageFilter.period,
            });
            try {
                const data = await this.api('GET', `/api/usage/stats?${params}`);
                this.usageStats = data || {};
            } catch {
                this.usageStats = {};
            }
        },

        async loadUsageLogs() {
            const params = new URLSearchParams({
                app: this.usageFilter.app,
            });
            try {
                const data = await this.api('GET', `/api/usage/logs?${params}`);
                this.usageLogs = Array.isArray(data) ? data : [];
            } catch {
                this.usageLogs = [];
            }
        },

        // ===== Utilities =====
        async api(method, url, body) {
            const opts = {
                method,
                headers: { 'Content-Type': 'application/json' },
            };
            if (body && (method === 'POST' || method === 'PUT')) {
                opts.body = JSON.stringify(body);
            }
            const res = await fetch(url, opts);
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || `HTTP ${res.status}`);
            }
            return res.json().catch(() => null);
        },

        showToast(message, type = 'info') {
            this.toast = { show: true, message, type };
            setTimeout(() => { this.toast.show = false; }, 3000);
        },

        formatTokens(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
            return String(n);
        },

        formatTime(ts) {
            if (!ts) return '-';
            const d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
            return d.toLocaleString();
        },
    };
}
