// Configuration for Gismac MS
// This file detects if running locally or on HostPinnacle

const CONFIG = {
    // Auto-detect API URL based on hostname
    API_URL: (() => {
        const hostname = window.location.hostname;
        
        // Local development
        if (hostname === 'localhost' || hostname === '127.0.0.1') {
            return 'http://localhost:4000/api/v1';
        }
        
        // HostPinnacle / cPanel hosting (PHP backend)
        // API is in /backend/ folder
        return `${window.location.origin}/backend/api/v1`;
    })(),
    
    // Frontend URL
    FRONTEND_URL: window.location.origin,
    
    // App version
    VERSION: '3.0.0-php'
};

// For debugging
console.log('Gismac MS Config:', CONFIG);
