/**
 * 24/7 - Centralized API & Utility Module
 */

const API = {
    baseUrl: 'api/',

    async request(endpoint, options = {}) {
        const url = new URL(this.baseUrl + endpoint, window.location.href);
        
        if (options.params) {
            Object.keys(options.params).forEach(k => {
                if (options.params[k] !== undefined && options.params[k] !== null) {
                    url.searchParams.append(k, options.params[k]);
                }
            });
        }

        const fetchOptions = {
            method: options.method || 'GET',
            headers: {
                'Accept': 'application/json',
                ...options.headers
            },
            credentials: 'same-origin'
        };

        if (options.body) {
            if (options.body instanceof FormData) {
                fetchOptions.body = options.body;
            } else {
                fetchOptions.headers['Content-Type'] = 'application/json';
                fetchOptions.body = JSON.stringify(options.body);
            }
        }

        try {
            const response = await fetch(url, fetchOptions);
            const contentType = response.headers.get('content-type') || '';
            
            // Handle CSV or file downloads
            if (contentType.includes('text/csv')) {
                const blob = await response.blob();
                return { isBlob: true, blob };
            }

            const data = await response.json();

            if (!response.ok) {
                const errorMsg = data.message || `HTTP Error ${response.status}`;
                if (response.status === 401 && !endpoint.includes('auth.php')) {
                    // Session expired
                    window.dispatchEvent(new CustomEvent('auth:expired'));
                }
                throw new Error(errorMsg);
            }

            return data;
        } catch (error) {
            console.error(`[API Error: ${endpoint}]`, error);
            throw error;
        }
    },

    get(endpoint, params = {}) {
        return this.request(endpoint, { method: 'GET', params });
    },

    post(endpoint, body = {}, params = {}) {
        return this.request(endpoint, { method: 'POST', body, params });
    },

    // Toast Notifications
    toast(message, type = 'info', duration = 4000) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }

        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span style="font-weight:800; font-size:1.1rem;">${icons[type] || '•'}</span>
            <div style="flex:1;">${escapeHtml(message)}</div>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.transition = 'all 0.3s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
};

function escapeHtml(str) {
    if (typeof str !== 'string') return str;
    return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
