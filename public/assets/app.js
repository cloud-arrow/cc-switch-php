/**
 * CC Switch SPA — Alpine.js application (Tailwind UI redesign).
 */

// MCP Presets
const MCP_PRESETS = [
    { id: 'fetch', name: 'mcp-server-fetch', command: 'uvx', args: 'mcp-server-fetch', tags: 'stdio,http,web', homepage: 'https://github.com/modelcontextprotocol/servers', docs: 'https://github.com/modelcontextprotocol/servers/tree/main/src/fetch' },
    { id: 'time', name: '@modelcontextprotocol/server-time', command: 'npx', args: '-y,@modelcontextprotocol/server-time', tags: 'stdio,time,utility', homepage: 'https://github.com/modelcontextprotocol/servers', docs: 'https://github.com/modelcontextprotocol/servers/tree/main/src/time' },
    { id: 'memory', name: '@modelcontextprotocol/server-memory', command: 'npx', args: '-y,@modelcontextprotocol/server-memory', tags: 'stdio,memory,graph', homepage: 'https://github.com/modelcontextprotocol/servers', docs: 'https://github.com/modelcontextprotocol/servers/tree/main/src/memory' },
    { id: 'sequential-thinking', name: '@modelcontextprotocol/server-sequential-thinking', command: 'npx', args: '-y,@modelcontextprotocol/server-sequential-thinking', tags: 'stdio,thinking,reasoning', homepage: 'https://github.com/modelcontextprotocol/servers', docs: 'https://github.com/modelcontextprotocol/servers/tree/main/src/sequentialthinking' },
    { id: 'context7', name: '@upstash/context7-mcp', command: 'npx', args: '-y,@upstash/context7-mcp', tags: 'stdio,docs,search', homepage: 'https://context7.com', docs: 'https://github.com/upstash/context7/blob/master/README.md' },
];

function ccSwitch() {
    return {
        // ===== Navigation =====
        currentView: 'providers',
        currentApp: 'claude',
        apps: ['claude', 'codex', 'gemini', 'opencode', 'openclaw'],

        // ===== Theme =====
        theme: 'dark',

        // ===== Settings =====
        settingsTab: 'general',
        settingsData: { language: 'en', proxyUrl: '', syncUrl: '', syncUser: '', syncPass: '' },

        // ===== Loading =====
        loading: false,

        // ===== Providers =====
        providers: [],
        currentProvider: null,
        presets: [],
        selectedPreset: null,
        editingProvider: null,
        providerForm: {
            name: '', category: '', notes: '', website_url: '', icon: '', icon_color: '', rawJson: '{}',
            claude: { apiKey: '', baseUrl: '', model: '', reasoningModel: '', haikuModel: '', sonnetModel: '', opusModel: '' },
            codex: { apiKey: '', model: '', tomlConfig: '' },
            gemini: { apiKey: '', baseUrl: '', model: '' },
            opencode: { npmPackage: '@ai-sdk/openai', apiKey: '', baseUrl: '', model: '', extraOptions: [] },
            openclaw: { api: 'openai-completions', baseUrl: '', apiKey: '', models: [] },
        },
        showIconPicker: false,
        iconSearch: '',

        // ===== MCP =====
        mcpServers: [],
        editingMcp: null,
        mcpForm: { id: '', name: '', command: '', args: '', description: '', tags: '', homepage: '', docs: '', enabled_claude: true, enabled_codex: false, enabled_gemini: false, enabled_opencode: false },
        MCP_PRESETS: MCP_PRESETS,

        // ===== Proxy =====
        proxyStatus: { running: false },
        proxyConfig: null,
        proxyHealth: [],
        proxyTakeover: {},

        // ===== Skills =====
        skills: [],
        showInstallSkill: false,
        skillForm: { repo_owner: '', repo_name: '', directory: '' },
        skillRepos: [],
        discoveredSkills: [],
        newRepoOwner: '',
        newRepoName: '',

        // ===== Prompts =====
        prompts: [],
        editingPrompt: null,
        activePrompt: null,
        promptForm: { title: '', description: '', content: '' },

        // ===== Usage =====
        usageFilter: { app: 'claude', period: 'today' },
        usageStats: {},
        usageLogs: [],

        // ===== Backups =====
        backups: [],

        // ===== Toast Queue =====
        toasts: [],
        toastCounter: 0,

        // ================================================================
        // INIT
        // ================================================================
        async init() {
            this.theme = localStorage.getItem('cc-theme') || 'dark';
            this.applyTheme();
            await this.loadProviders();
            this.loadPresets();
            this.loadProxyTakeoverStatus();
        },

        // ================================================================
        // NAVIGATION
        // ================================================================
        async switchApp(app) {
            this.currentApp = app;
            this.loading = true;
            try {
                if (this.currentView === 'providers') {
                    await this.loadProviders();
                    this.loadPresets();
                } else if (this.currentView === 'prompts') {
                    await this.loadPrompts();
                }
            } catch (e) {
                this.showToast('Failed: ' + e.message, 'error');
            }
            this.loading = false;
        },

        // ================================================================
        // THEME
        // ================================================================
        setTheme(t) {
            this.theme = t;
            localStorage.setItem('cc-theme', t);
            this.applyTheme();
        },

        applyTheme() {
            const html = document.documentElement;
            if (this.theme === 'light') {
                html.classList.remove('dark');
            } else if (this.theme === 'dark') {
                html.classList.add('dark');
            } else {
                // auto
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    html.classList.add('dark');
                } else {
                    html.classList.remove('dark');
                }
            }
        },

        // ================================================================
        // APP HELPERS
        // ================================================================
        getAppDisplayName(app) {
            const names = { claude: 'Claude', codex: 'Codex', gemini: 'Gemini', opencode: 'OpenCode', openclaw: 'OpenClaw' };
            return names[app] || app;
        },

        getAppIcon(app) {
            if (typeof getProviderIcon === 'function') {
                const icons = { claude: 'claude', codex: 'openai', gemini: 'gemini', opencode: 'opencode-logo-light', openclaw: 'claw' };
                return getProviderIcon(icons[app] || app) || '';
            }
            return '';
        },

        getProviderIconSvg(key) {
            if (typeof getProviderIcon === 'function') {
                return getProviderIcon(key);
            }
            return null;
        },

        getInitials(name) {
            if (typeof window.getInitials === 'function') return window.getInitials(name);
            if (!name) return '?';
            return name.split(/[\s\-_]+/).map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase() || '?';
        },

        filteredIcons() {
            const allKeys = typeof window.CC_ICONS === 'object' ? Object.keys(window.CC_ICONS) : [];
            if (!this.iconSearch) return allKeys;
            const q = this.iconSearch.toLowerCase();
            return allKeys.filter(k => k.includes(q));
        },

        // ================================================================
        // PROVIDERS
        // ================================================================
        async loadProviders() {
            this.loading = true;
            try {
                const data = await this.api('GET', `/api/providers/${this.currentApp}`);
                this.providers = Array.isArray(data) ? data : [];
                this.currentProvider = this.providers.find(p => p.is_current) || null;
            } catch (e) {
                this.providers = [];
                this.currentProvider = null;
            }
            this.loading = false;
            this.$nextTick(() => this.initSortable());
        },

        async loadPresets() {
            try {
                const data = await this.api('GET', `/api/providers/presets/${this.currentApp}`);
                this.presets = Array.isArray(data) ? data : [];
            } catch {
                this.presets = [];
            }
        },

        extractUrl(provider) {
            if (!provider.settings_config) return provider.website_url || '';
            try {
                const cfg = typeof provider.settings_config === 'string' ? JSON.parse(provider.settings_config) : provider.settings_config;
                // Claude: env.ANTHROPIC_BASE_URL
                if (cfg.env) {
                    return cfg.env.ANTHROPIC_BASE_URL || cfg.env.GOOGLE_GEMINI_BASE_URL || cfg.env.GOOGLE_API_BASE || '';
                }
                // OpenCode/OpenClaw
                if (cfg.baseUrl) return cfg.baseUrl;
                if (cfg.options?.baseURL) return cfg.options.baseURL;
                return provider.website_url || '';
            } catch {
                return provider.website_url || '';
            }
        },

        selectPreset(preset) {
            this.selectedPreset = preset;
            if (!preset) {
                this.resetProviderForm();
                return;
            }
            this.providerForm.name = preset.name || '';
            this.providerForm.website_url = preset.websiteUrl || '';
            this.providerForm.category = preset.category || '';
            this.providerForm.icon = preset.icon || '';
            this.providerForm.icon_color = preset.iconColor || '';

            if (this.currentApp === 'claude') {
                const env = preset.settingsConfig?.env || {};
                this.providerForm.claude.apiKey = env.ANTHROPIC_AUTH_TOKEN || env.ANTHROPIC_API_KEY || '';
                this.providerForm.claude.baseUrl = env.ANTHROPIC_BASE_URL || '';
                this.providerForm.claude.model = env.ANTHROPIC_MODEL || '';
                this.providerForm.claude.reasoningModel = env.ANTHROPIC_REASONING_MODEL || '';
                this.providerForm.claude.haikuModel = env.ANTHROPIC_DEFAULT_HAIKU_MODEL || '';
                this.providerForm.claude.sonnetModel = env.ANTHROPIC_DEFAULT_SONNET_MODEL || '';
                this.providerForm.claude.opusModel = env.ANTHROPIC_DEFAULT_OPUS_MODEL || '';
            } else if (this.currentApp === 'codex') {
                const auth = preset.auth || {};
                this.providerForm.codex.apiKey = auth.OPENAI_API_KEY || '';
                this.providerForm.codex.tomlConfig = preset.config || '';
                this.providerForm.codex.model = '';
            } else if (this.currentApp === 'gemini') {
                const env = preset.settingsConfig?.env || {};
                this.providerForm.gemini.apiKey = env.GOOGLE_API_KEY || env.GEMINI_API_KEY || '';
                this.providerForm.gemini.baseUrl = env.GOOGLE_GEMINI_BASE_URL || '';
                this.providerForm.gemini.model = env.GEMINI_MODEL || '';
            } else if (this.currentApp === 'opencode') {
                const cfg = preset.settingsConfig || {};
                this.providerForm.opencode.npmPackage = cfg.npmPackage || '@ai-sdk/openai';
                this.providerForm.opencode.apiKey = cfg.options?.apiKey || '';
                this.providerForm.opencode.baseUrl = cfg.options?.baseURL || '';
                this.providerForm.opencode.model = '';
                this.providerForm.opencode.extraOptions = [];
            } else if (this.currentApp === 'openclaw') {
                const cfg = preset.settingsConfig || {};
                this.providerForm.openclaw.api = cfg.api || 'openai-completions';
                this.providerForm.openclaw.baseUrl = cfg.baseUrl || '';
                this.providerForm.openclaw.apiKey = cfg.apiKey || '';
                this.providerForm.openclaw.models = (cfg.models || []).map(m => ({ id: m.id || '', name: m.name || '', contextWindow: m.contextWindow || 0 }));
            }

            // Set raw JSON
            this.providerForm.rawJson = JSON.stringify(preset.settingsConfig || preset.config || {}, null, 2);
        },

        openAddProvider() {
            this.editingProvider = null;
            this.selectedPreset = null;
            this.resetProviderForm();
            this.currentView = 'providerForm';
        },

        editProvider(p) {
            this.editingProvider = p;
            this.selectedPreset = null;
            this.providerForm.name = p.name || '';
            this.providerForm.category = p.category || '';
            this.providerForm.notes = p.notes || '';
            this.providerForm.website_url = p.website_url || '';
            this.providerForm.icon = p.icon || '';
            this.providerForm.icon_color = p.icon_color || '';

            // Parse settings_config into app-specific fields
            const cfg = this.parseConfig(p.settings_config);
            this.providerForm.rawJson = typeof p.settings_config === 'string' ? p.settings_config : JSON.stringify(p.settings_config || {}, null, 2);

            if (this.currentApp === 'claude') {
                const env = cfg.env || {};
                this.providerForm.claude = {
                    apiKey: env.ANTHROPIC_AUTH_TOKEN || env.ANTHROPIC_API_KEY || '',
                    baseUrl: env.ANTHROPIC_BASE_URL || '',
                    model: env.ANTHROPIC_MODEL || '',
                    reasoningModel: env.ANTHROPIC_REASONING_MODEL || '',
                    haikuModel: env.ANTHROPIC_DEFAULT_HAIKU_MODEL || '',
                    sonnetModel: env.ANTHROPIC_DEFAULT_SONNET_MODEL || '',
                    opusModel: env.ANTHROPIC_DEFAULT_OPUS_MODEL || '',
                };
            } else if (this.currentApp === 'codex') {
                this.providerForm.codex = {
                    apiKey: cfg.auth?.OPENAI_API_KEY || '',
                    model: '',
                    tomlConfig: cfg.config || '',
                };
            } else if (this.currentApp === 'gemini') {
                const env = cfg.env || {};
                this.providerForm.gemini = {
                    apiKey: env.GOOGLE_API_KEY || env.GEMINI_API_KEY || '',
                    baseUrl: env.GOOGLE_GEMINI_BASE_URL || '',
                    model: env.GEMINI_MODEL || '',
                };
            } else if (this.currentApp === 'opencode') {
                this.providerForm.opencode = {
                    npmPackage: cfg.npmPackage || '@ai-sdk/openai',
                    apiKey: cfg.options?.apiKey || '',
                    baseUrl: cfg.options?.baseURL || '',
                    model: cfg.model || '',
                    extraOptions: Object.entries(cfg.options || {}).filter(([k]) => !['apiKey', 'baseURL'].includes(k)).map(([key, value]) => ({ key, value: String(value) })),
                };
            } else if (this.currentApp === 'openclaw') {
                this.providerForm.openclaw = {
                    api: cfg.api || 'openai-completions',
                    baseUrl: cfg.baseUrl || '',
                    apiKey: cfg.apiKey || '',
                    models: (cfg.models || []).map(m => ({ id: m.id || '', name: m.name || '', contextWindow: m.contextWindow || 0 })),
                };
            }

            this.currentView = 'providerForm';
        },

        buildSettingsConfig() {
            const app = this.currentApp;
            if (app === 'claude') {
                const c = this.providerForm.claude;
                const env = {};
                if (c.apiKey) env.ANTHROPIC_AUTH_TOKEN = c.apiKey;
                if (c.baseUrl) env.ANTHROPIC_BASE_URL = c.baseUrl;
                if (c.model) env.ANTHROPIC_MODEL = c.model;
                if (c.reasoningModel) env.ANTHROPIC_REASONING_MODEL = c.reasoningModel;
                if (c.haikuModel) env.ANTHROPIC_DEFAULT_HAIKU_MODEL = c.haikuModel;
                if (c.sonnetModel) env.ANTHROPIC_DEFAULT_SONNET_MODEL = c.sonnetModel;
                if (c.opusModel) env.ANTHROPIC_DEFAULT_OPUS_MODEL = c.opusModel;
                return JSON.stringify({ env });
            } else if (app === 'codex') {
                const c = this.providerForm.codex;
                const auth = {};
                if (c.apiKey) auth.OPENAI_API_KEY = c.apiKey;
                return JSON.stringify({ auth, config: c.tomlConfig || '' });
            } else if (app === 'gemini') {
                const c = this.providerForm.gemini;
                const env = {};
                if (c.apiKey) env.GEMINI_API_KEY = c.apiKey;
                if (c.baseUrl) env.GOOGLE_GEMINI_BASE_URL = c.baseUrl;
                if (c.model) env.GEMINI_MODEL = c.model;
                return JSON.stringify({ env });
            } else if (app === 'opencode') {
                const c = this.providerForm.opencode;
                const options = {};
                if (c.apiKey) options.apiKey = c.apiKey;
                if (c.baseUrl) options.baseURL = c.baseUrl;
                c.extraOptions.forEach(o => { if (o.key) options[o.key] = o.value; });
                return JSON.stringify({ npmPackage: c.npmPackage, options, model: c.model || undefined });
            } else if (app === 'openclaw') {
                const c = this.providerForm.openclaw;
                return JSON.stringify({
                    api: c.api, baseUrl: c.baseUrl, apiKey: c.apiKey,
                    models: c.models.filter(m => m.id),
                });
            }
            return this.providerForm.rawJson || '{}';
        },

        parseConfig(raw) {
            if (!raw) return {};
            try {
                return typeof raw === 'string' ? JSON.parse(raw) : raw;
            } catch { return {}; }
        },

        async saveProvider() {
            const settingsConfig = this.buildSettingsConfig();
            const data = {
                name: this.providerForm.name,
                category: this.providerForm.category || null,
                settings_config: settingsConfig,
                notes: this.providerForm.notes || null,
                website_url: this.providerForm.website_url || null,
                icon: this.providerForm.icon || null,
                icon_color: this.providerForm.icon_color || null,
                meta: '{}',
            };

            try {
                if (this.editingProvider) {
                    await this.api('PUT', `/api/providers/${this.currentApp}/${this.editingProvider.id}`, data);
                    this.showToast('Provider updated', 'success');
                } else {
                    await this.api('POST', `/api/providers/${this.currentApp}`, data);
                    this.showToast('Provider added', 'success');
                }
                this.closeProviderForm();
                await this.loadProviders();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        closeProviderForm() {
            this.currentView = 'providers';
            this.editingProvider = null;
            this.selectedPreset = null;
            this.showIconPicker = false;
            this.resetProviderForm();
        },

        resetProviderForm() {
            this.providerForm = {
                name: '', category: '', notes: '', website_url: '', icon: '', icon_color: '', rawJson: '{}',
                claude: { apiKey: '', baseUrl: '', model: '', reasoningModel: '', haikuModel: '', sonnetModel: '', opusModel: '' },
                codex: { apiKey: '', model: '', tomlConfig: '' },
                gemini: { apiKey: '', baseUrl: '', model: '' },
                opencode: { npmPackage: '@ai-sdk/openai', apiKey: '', baseUrl: '', model: '', extraOptions: [] },
                openclaw: { api: 'openai-completions', baseUrl: '', apiKey: '', models: [] },
            };
        },

        async switchProvider(id) {
            try {
                await this.api('POST', `/api/providers/${this.currentApp}/${id}/switch`);
                this.showToast('Provider switched', 'success');
                await this.loadProviders();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async deleteProvider(id) {
            if (!confirm('Delete this provider?')) return;
            try {
                await this.api('DELETE', `/api/providers/${this.currentApp}/${id}`);
                this.showToast('Provider deleted', 'success');
                await this.loadProviders();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        // ================================================================
        // SORTABLE (Drag & Drop)
        // ================================================================
        initSortable() {
            const el = this.$refs.providerList;
            if (!el || !window.Sortable) return;
            if (el._sortable) el._sortable.destroy();
            el._sortable = new Sortable(el, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                chosenClass: 'sortable-chosen',
                onEnd: async (evt) => {
                    // Reorder providers array
                    const item = this.providers.splice(evt.oldIndex, 1)[0];
                    this.providers.splice(evt.newIndex, 0, item);
                    // Send new order to API
                    const items = this.providers.map((p, i) => ({ id: p.id, sort_index: i }));
                    try {
                        await this.api('POST', `/api/providers/${this.currentApp}/reorder`, { items });
                    } catch (e) {
                        this.showToast('Reorder failed: ' + e.message, 'error');
                    }
                },
            });
        },

        // ================================================================
        // MCP
        // ================================================================
        async loadMcp() {
            this.loading = true;
            try {
                const data = await this.api('GET', '/api/mcp');
                this.mcpServers = Array.isArray(data) ? data : [];
            } catch { this.mcpServers = []; }
            this.loading = false;
        },

        getMcpCommand(m) {
            try {
                const cfg = typeof m.server_config === 'string' ? JSON.parse(m.server_config) : m.server_config;
                if (cfg.command) {
                    const args = cfg.args ? (Array.isArray(cfg.args) ? cfg.args.join(' ') : cfg.args) : '';
                    return cfg.command + ' ' + args;
                }
                if (cfg.url) return cfg.url;
                return '-';
            } catch { return '-'; }
        },

        openAddMcp() {
            this.editingMcp = null;
            this.mcpForm = { id: '', name: '', command: '', args: '', description: '', tags: '', homepage: '', docs: '', enabled_claude: true, enabled_codex: false, enabled_gemini: false, enabled_opencode: false };
            this.currentView = 'mcpForm';
        },

        editMcpServer(m) {
            this.editingMcp = m;
            let cfg = {};
            try { cfg = typeof m.server_config === 'string' ? JSON.parse(m.server_config) : m.server_config; } catch {}
            this.mcpForm = {
                id: m.id,
                name: m.name || '',
                command: cfg.command || '',
                args: Array.isArray(cfg.args) ? cfg.args.join(',') : (cfg.args || ''),
                description: m.description || '',
                tags: m.tags || '',
                homepage: m.homepage || '',
                docs: m.docs || '',
                enabled_claude: !!m.enabled_claude,
                enabled_codex: !!m.enabled_codex,
                enabled_gemini: !!m.enabled_gemini,
                enabled_opencode: !!m.enabled_opencode,
            };
            this.currentView = 'mcpForm';
        },

        selectMcpPreset(preset) {
            this.mcpForm.id = preset.id;
            this.mcpForm.name = preset.name;
            this.mcpForm.command = preset.command;
            this.mcpForm.args = preset.args;
            this.mcpForm.tags = preset.tags || '';
            this.mcpForm.homepage = preset.homepage || '';
            this.mcpForm.docs = preset.docs || '';
        },

        async saveMcp() {
            const args = this.mcpForm.args ? this.mcpForm.args.split(',').map(s => s.trim()).filter(Boolean) : [];
            const id = this.mcpForm.id || this.mcpForm.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || ('mcp-' + Date.now());
            const data = {
                id: id,
                name: this.mcpForm.name,
                server_config: JSON.stringify({ type: 'stdio', command: this.mcpForm.command, args: args }),
                description: this.mcpForm.description || null,
                tags: this.mcpForm.tags || '[]',
                homepage: this.mcpForm.homepage || null,
                docs: this.mcpForm.docs || null,
                enabled_claude: this.mcpForm.enabled_claude ? 1 : 0,
                enabled_codex: this.mcpForm.enabled_codex ? 1 : 0,
                enabled_gemini: this.mcpForm.enabled_gemini ? 1 : 0,
                enabled_opencode: this.mcpForm.enabled_opencode ? 1 : 0,
            };
            try {
                await this.api('POST', '/api/mcp', data);
                this.showToast(this.editingMcp ? 'MCP updated' : 'MCP added', 'success');
                this.currentView = 'mcp';
                await this.loadMcp();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async toggleMcpApp(id, app, checked) {
            try {
                await this.api('PUT', `/api/mcp/${id}`, { ['enabled_' + app]: checked ? 1 : 0 });
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
                await this.loadMcp();
            }
        },

        async deleteMcp(id) {
            if (!confirm('Delete this MCP server?')) return;
            try {
                await this.api('DELETE', `/api/mcp/${id}`);
                this.showToast('MCP deleted', 'success');
                await this.loadMcp();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async syncMcp() {
            try {
                await this.api('POST', '/api/mcp/sync');
                this.showToast('MCP synced', 'success');
                await this.loadMcp();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        // ================================================================
        // SKILLS
        // ================================================================
        async loadSkills() {
            this.loading = true;
            try {
                const data = await this.api('GET', '/api/skills');
                this.skills = Array.isArray(data) ? data : [];
            } catch { this.skills = []; }
            this.loading = false;
        },

        async toggleSkillApp(id, app, checked) {
            try {
                await this.api('PUT', `/api/skills/${id}`, { ['enabled_' + app]: checked ? 1 : 0 });
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
                await this.loadSkills();
            }
        },

        async installSkill() {
            try {
                await this.api('POST', '/api/skills/install', {
                    repo_owner: this.skillForm.repo_owner,
                    repo_name: this.skillForm.repo_name,
                    directory: this.skillForm.directory,
                });
                this.showInstallSkill = false;
                this.skillForm = { repo_owner: '', repo_name: '', directory: '' };
                this.showToast('Skill installed', 'success');
                await this.loadSkills();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async deleteSkill(id) {
            if (!confirm('Delete this skill?')) return;
            try {
                await this.api('DELETE', `/api/skills/${id}`);
                this.showToast('Skill deleted', 'success');
                await this.loadSkills();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async syncSkills() {
            try {
                await this.api('POST', '/api/skills/sync');
                this.showToast('Skills synced', 'success');
                await this.loadSkills();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        // Skill repos
        async loadSkillRepos() {
            try {
                const data = await this.api('GET', '/api/skill-repos');
                this.skillRepos = Array.isArray(data) ? data : [];
            } catch { this.skillRepos = []; }
        },

        async addSkillRepo() {
            if (!this.newRepoOwner || !this.newRepoName) return;
            try {
                await this.api('POST', '/api/skill-repos', { owner: this.newRepoOwner, name: this.newRepoName });
                this.newRepoOwner = '';
                this.newRepoName = '';
                this.showToast('Repo added', 'success');
                await this.loadSkillRepos();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async removeSkillRepo(owner, name) {
            try {
                await this.api('DELETE', `/api/skill-repos/${owner}/${name}`);
                this.showToast('Repo removed', 'success');
                await this.loadSkillRepos();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async discoverFromRepo(owner, name) {
            try {
                const data = await this.api('POST', `/api/skill-repos/${owner}/${name}/discover`);
                this.discoveredSkills = Array.isArray(data) ? data : [];
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async loadDiscoveredSkills() {
            // Discover from all repos
            for (const repo of this.skillRepos) {
                try {
                    const data = await this.api('POST', `/api/skill-repos/${repo.owner}/${repo.name}/discover`);
                    if (Array.isArray(data)) {
                        this.discoveredSkills = [...this.discoveredSkills, ...data];
                    }
                } catch {}
            }
        },

        isSkillInstalled(ds) {
            return this.skills.some(s => s.directory === ds.directory || s.id === `${ds.repo_owner}/${ds.repo_name}:${ds.directory}`);
        },

        async installDiscoveredSkill(ds) {
            try {
                await this.api('POST', '/api/skills/install', {
                    repo_owner: ds.repo_owner,
                    repo_name: ds.repo_name,
                    directory: ds.directory,
                    name: ds.name,
                    description: ds.description,
                });
                this.showToast('Skill installed', 'success');
                await this.loadSkills();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        // ================================================================
        // PROMPTS
        // ================================================================
        async loadPrompts() {
            this.loading = true;
            try {
                const data = await this.api('GET', `/api/prompts/${this.currentApp}`);
                this.prompts = Array.isArray(data) ? data : [];
                this.activePrompt = this.prompts.find(p => p.is_active) || null;
            } catch { this.prompts = []; this.activePrompt = null; }
            this.loading = false;
        },

        openAddPrompt() {
            this.editingPrompt = null;
            this.promptForm = { title: '', description: '', content: '' };
            this.currentView = 'promptForm';
        },

        editPrompt(p) {
            this.editingPrompt = p;
            this.promptForm = {
                title: p.title || p.name || '',
                description: p.description || '',
                content: p.content || '',
            };
            this.currentView = 'promptForm';
        },

        async savePrompt() {
            const data = {
                name: this.promptForm.title,
                title: this.promptForm.title,
                description: this.promptForm.description,
                content: this.promptForm.content,
            };
            try {
                if (this.editingPrompt) {
                    await this.api('PUT', `/api/prompts/${this.currentApp}/${this.editingPrompt.id}`, data);
                    this.showToast('Prompt updated', 'success');
                } else {
                    await this.api('POST', `/api/prompts/${this.currentApp}`, data);
                    this.showToast('Prompt added', 'success');
                }
                this.closePromptForm();
                await this.loadPrompts();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async deletePrompt(id) {
            if (!confirm('Delete this prompt?')) return;
            try {
                await this.api('DELETE', `/api/prompts/${this.currentApp}/${id}`);
                this.showToast('Prompt deleted', 'success');
                await this.loadPrompts();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        async togglePrompt(pr) {
            // Toggle active status - only one can be active at a time
            const newActive = !pr.is_active;
            try {
                await this.api('PUT', `/api/prompts/${this.currentApp}/${pr.id}`, { is_active: newActive ? 1 : 0 });
                await this.loadPrompts();
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        closePromptForm() {
            this.currentView = 'prompts';
            this.editingPrompt = null;
            this.promptForm = { title: '', description: '', content: '' };
        },

        // ================================================================
        // PROXY
        // ================================================================
        async loadProxyAll() {
            await Promise.all([this.loadProxyStatus(), this.loadProxyConfig(), this.loadProxyHealth()]);
        },

        async loadProxyStatus() {
            try {
                const data = await this.api('GET', '/api/proxy/status');
                this.proxyStatus = data || { running: false };
            } catch { this.proxyStatus = { running: false }; }
        },

        async loadProxyConfig() {
            try {
                const data = await this.api('GET', `/api/proxy/config/${this.currentApp}`);
                this.proxyConfig = data || {};
            } catch { this.proxyConfig = {}; }
        },

        async loadProxyHealth() {
            try {
                const data = await this.api('GET', `/api/proxy/health/${this.currentApp}`);
                this.proxyHealth = Array.isArray(data?.circuit_breaker) ? data.circuit_breaker : [];
            } catch { this.proxyHealth = []; }
        },

        async startProxy() {
            try {
                await this.api('POST', '/api/proxy/start');
                this.showToast('Proxy started', 'success');
                await this.loadProxyStatus();
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async stopProxy() {
            try {
                await this.api('POST', '/api/proxy/stop');
                this.showToast('Proxy stopped', 'success');
                await this.loadProxyStatus();
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async saveProxyConfig() {
            if (!this.proxyConfig) return;
            try {
                await this.api('PUT', `/api/proxy/config/${this.currentApp}`, this.proxyConfig);
                this.showToast('Proxy config saved', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        // Proxy Takeover
        async loadProxyTakeoverStatus() {
            try {
                const data = await this.api('GET', '/api/proxy/takeover/status');
                this.proxyTakeover = data || {};
            } catch { this.proxyTakeover = {}; }
        },

        async toggleProxyTakeover() {
            const enabled = !this.proxyTakeover[this.currentApp];
            try {
                await this.api('POST', `/api/proxy/takeover/${this.currentApp}/${enabled ? 'enable' : 'disable'}`);
                this.proxyTakeover[this.currentApp] = enabled;
                this.showToast(`Proxy takeover ${enabled ? 'enabled' : 'disabled'}`, 'success');
            } catch (e) {
                this.showToast('Error: ' + e.message, 'error');
            }
        },

        // ================================================================
        // SETTINGS
        // ================================================================
        async loadSettings() {
            try {
                const data = await this.api('GET', '/api/settings');
                this.settingsData = { ...this.settingsData, ...(data || {}) };
            } catch {}
        },

        async saveSettings() {
            try {
                await this.api('PUT', '/api/settings', this.settingsData);
                this.showToast('Settings saved', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        // ================================================================
        // BACKUP
        // ================================================================
        async createBackup() {
            try {
                await this.api('POST', '/api/backup/create');
                this.showToast('Backup created', 'success');
                await this.loadBackups();
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async loadBackups() {
            try {
                const data = await this.api('GET', '/api/backup/list');
                this.backups = Array.isArray(data) ? data : [];
            } catch { this.backups = []; }
        },

        async restoreBackup(filename) {
            if (!confirm('Restore from ' + filename + '?')) return;
            try {
                await this.api('POST', '/api/backup/restore', { filename });
                this.showToast('Backup restored', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async cleanupBackups() {
            try {
                await this.api('POST', '/api/backup/cleanup');
                this.showToast('Old backups cleaned', 'success');
                await this.loadBackups();
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        // ================================================================
        // SYNC
        // ================================================================
        async syncPush() {
            try {
                await this.api('POST', '/api/sync/push');
                this.showToast('Sync push complete', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async syncPull() {
            try {
                await this.api('POST', '/api/sync/pull');
                this.showToast('Sync pull complete', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        async syncTest() {
            try {
                await this.api('POST', '/api/sync/test');
                this.showToast('Sync test OK', 'success');
            } catch (e) { this.showToast('Error: ' + e.message, 'error'); }
        },

        // ================================================================
        // USAGE
        // ================================================================
        async loadUsageAll() {
            await Promise.all([this.loadUsageStats(), this.loadUsageLogs()]);
        },

        async loadUsageStats() {
            const params = new URLSearchParams({ app: this.usageFilter.app, period: this.usageFilter.period });
            try {
                const data = await this.api('GET', `/api/usage/stats?${params}`);
                this.usageStats = data || {};
            } catch { this.usageStats = {}; }
        },

        async loadUsageLogs() {
            const params = new URLSearchParams({ app: this.usageFilter.app });
            try {
                const data = await this.api('GET', `/api/usage/logs?${params}`);
                this.usageLogs = Array.isArray(data) ? data : [];
            } catch { this.usageLogs = []; }
        },

        // ================================================================
        // VIEW SWITCHING with auto-loading
        // ================================================================
        // Use x-effect or watch currentView via Alpine's $watch
        // Auto-load data when view changes
        get currentViewWatcher() {
            const v = this.currentView;
            if (v === 'mcp' && this.mcpServers.length === 0) this.loadMcp();
            if (v === 'skills' && this.skills.length === 0) this.loadSkills();
            if (v === 'prompts' && this.prompts.length === 0) this.loadPrompts();
            if (v === 'settings') { this.loadSettings(); this.loadProxyAll(); }
            return v;
        },

        // ================================================================
        // UTILITIES
        // ================================================================
        async api(method, url, body) {
            const opts = { method, headers: { 'Content-Type': 'application/json' } };
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
            const id = ++this.toastCounter;
            const toast = { id, message, type, visible: true };
            this.toasts.push(toast);
            setTimeout(() => {
                toast.visible = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }, 300);
            }, 3000);
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
