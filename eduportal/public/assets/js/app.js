/**
 * App JavaScript - Instituto Politécnico Sumayya
 * Funções utilitárias globais
 */

// Configurações
const SUMAYYA_CONFIG = {
    apiUrl: '../../api/index.php',
    sessionLifetime: 15 * 60 * 1000, // 15 minutos
    refreshInterval: 5 * 60 * 1000   // 5 minutos
};

/**
 * Verificar se usuário está autenticado
 */
function isAuthenticated() {
    const token = localStorage.getItem('sumayya_token');
    return !!token;
}

/**
 * Obter token de autenticação
 */
function getToken() {
    return localStorage.getItem('sumayya_token');
}

/**
 * Obter dados do usuário logado
 */
function getUser() {
    try {
        return JSON.parse(localStorage.getItem('sumayya_usuario') || '{}');
    } catch (e) {
        return {};
    }
}

/**
 * Fazer requisição autenticada à API
 */
async function apiRequest(rota, acao, options = {}) {
    const token = getToken();
    
    if (!token) {
        throw new Error('Não autenticado');
    }
    
    const url = new URL(`${SUMAYYA_CONFIG.apiUrl}`, window.location.origin);
    url.searchParams.append('rota', rota);
    url.searchParams.append('acao', acao);
    url.searchParams.append('token', token);
    
    const config = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
        },
        ...options
    };
    
    if (config.body && typeof config.body === 'object') {
        config.body = JSON.stringify(config.body);
    }
    
    const response = await fetch(url.toString(), config);
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.error || 'Erro na requisição');
    }
    
    return data;
}

/**
 * Formatar valor monetário (MZN)
 */
function formatCurrency(value) {
    return new Intl.NumberFormat('pt-MZ', {
        style: 'currency',
        currency: 'MZN'
    }).format(value || 0);
}

/**
 * Formatar data
 */
function formatDate(date, options = {}) {
    if (!date) return '--';
    
    const d = new Date(date);
    if (isNaN(d.getTime())) return '--';
    
    const defaultOptions = { day: '2-digit', month: '2-digit', year: 'numeric' };
    return d.toLocaleDateString('pt-BR', { ...defaultOptions, ...options });
}

/**
 * Formatar data e hora
 */
function formatDateTime(date) {
    if (!date) return '--';
    
    const d = new Date(date);
    if (isNaN(d.getTime())) return '--';
    
    return d.toLocaleString('pt-BR');
}

/**
 * Obter nome do mês
 */
function getMonthName(month, short = false) {
    const months = short 
        ? ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez']
        : ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    
    return months[month - 1] || '';
}

/**
 * Truncar texto
 */
function truncate(text, length = 50) {
    if (!text || text.length <= length) return text;
    return text.substring(0, length) + '...';
}

/**
 * Validar email
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validar código de acesso (6-8 caracteres alfanuméricos)
 */
function isValidCodigo(codigo) {
    const re = /^[A-Z0-9]{6,8}$/;
    return re.test(codigo.toUpperCase());
}

/**
 * Gerar cor a partir de string (para avatares)
 */
function stringToColor(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    
    const colors = [
        '#1a237e', '#3949ab', '#4caf50', '#ff9800', 
        '#f44336', '#9c27b0', '#00bcd4', '#795548'
    ];
    
    return colors[Math.abs(hash) % colors.length];
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Mostrar notificação toast
 */
function showToast(message, type = 'info', duration = 3000) {
    // Remover toast anterior se existir
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <span class="toast-message">${message}</span>
            <button class="toast-close" onclick="this.parentElement.parentElement.remove()">&times;</button>
        </div>
    `;
    
    // Estilos inline para o toast
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : type === 'warning' ? '#ff9800' : '#2196f3'};
        color: white;
        padding: 16px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

/**
 * Confirmar ação
 */
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

/**
 * Copiar para clipboard
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        showToast('Copiado para a área de transferência!', 'success');
    } catch (err) {
        showToast('Erro ao copiar', 'error');
    }
}

/**
 * Download de arquivo
 */
function downloadFile(content, filename, type = 'text/plain') {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

/**
 * Verificar conexão com internet
 */
function isOnline() {
    return navigator.onLine;
}

/**
 * Adicionar listener para mudanças de conexão
 */
function addConnectionListeners(onlineCallback, offlineCallback) {
    window.addEventListener('online', onlineCallback);
    window.addEventListener('offline', offlineCallback);
}

/**
 * Animar contador
 */
function animateCounter(element, target, duration = 1000) {
    const start = 0;
    const increment = target / (duration / 16);
    let current = start;
    
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            current = target;
            clearInterval(timer);
        }
        element.textContent = Math.floor(current);
    }, 16);
}

/**
 * Sanitizar input (prevenir XSS)
 */
function sanitizeInput(input) {
    const div = document.createElement('div');
    div.textContent = input;
    return div.innerHTML;
}

/**
 * Obter parâmetros da URL
 */
function getUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const result = {};
    for (const [key, value] of params) {
        result[key] = value;
    }
    return result;
}

/**
 * Atualizar URL sem recarregar
 */
function updateUrl(params, replace = false) {
    const url = new URL(window.location);
    
    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    });
    
    if (replace) {
        window.history.replaceState({}, '', url);
    } else {
        window.history.pushState({}, '', url);
    }
}

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    // Adicionar animações CSS para toast
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
    
    // Verificar conexão
    if (!isOnline()) {
        showToast('Você está offline. Algumas funcionalidades podem não funcionar.', 'warning', 5000);
    }
});

// Exportar funções para uso global
window.SUMAYYA_CONFIG = SUMAYYA_CONFIG;
window.isAuthenticated = isAuthenticated;
window.getToken = getToken;
window.getUser = getUser;
window.apiRequest = apiRequest;
window.formatCurrency = formatCurrency;
window.formatDate = formatDate;
window.formatDateTime = formatDateTime;
window.getMonthName = getMonthName;
window.truncate = truncate;
window.isValidEmail = isValidEmail;
window.isValidCodigo = isValidCodigo;
window.stringToColor = stringToColor;
window.debounce = debounce;
window.throttle = throttle;
window.showToast = showToast;
window.confirmAction = confirmAction;
window.copyToClipboard = copyToClipboard;
window.downloadFile = downloadFile;
window.isOnline = isOnline;
window.addConnectionListeners = addConnectionListeners;
window.animateCounter = animateCounter;
window.sanitizeInput = sanitizeInput;
window.getUrlParams = getUrlParams;
window.updateUrl = updateUrl;
