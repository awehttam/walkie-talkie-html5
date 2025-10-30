/**
 * WebAuthn Authentication Client
 * Handles passkey registration and login
 */

// Check browser support
window.addEventListener('DOMContentLoaded', () => {
    if (!window.PublicKeyCredential) {
        document.getElementById('browserWarning').classList.add('show');
        document.getElementById('registerBtn').disabled = true;
        document.getElementById('loginBtn').disabled = true;
    }
});

// Register button handler
document.getElementById('registerBtn')?.addEventListener('click', async () => {
    const username = document.getElementById('registerUsername').value.trim();
    if (!username) {
        showError('Please enter a screen name');
        return;
    }

    setLoading(true, 'registerBtn');
    clearError();

    try {
        const result = await registerWithPasskey(username);

        // Store tokens
        localStorage.setItem('access_token', result.tokens.access_token);

        // Redirect to app
        window.location.href = '/';
    } catch (error) {
        showError(error.message);
        setLoading(false, 'registerBtn');
    }
});

// Login button handler
document.getElementById('loginBtn')?.addEventListener('click', async () => {
    const username = document.getElementById('loginUsername').value.trim();
    if (!username) {
        showError('Please enter your screen name');
        return;
    }

    setLoading(true, 'loginBtn');
    clearError();

    try {
        const result = await loginWithPasskey(username);

        // Store tokens
        localStorage.setItem('access_token', result.tokens.access_token);

        // Redirect to app
        window.location.href = '/';
    } catch (error) {
        showError(error.message);
        setLoading(false, 'loginBtn');
    }
});

// Allow Enter key to submit
document.getElementById('registerUsername')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        document.getElementById('registerBtn').click();
    }
});

document.getElementById('loginUsername')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        document.getElementById('loginBtn').click();
    }
});

/**
 * Register a new user with passkey
 */
async function registerWithPasskey(username, nickname = null) {
    // Step 1: Get registration options from server
    const optionsResponse = await fetch('/auth/register-options.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username })
    });

    const optionsResult = await optionsResponse.json();
    if (!optionsResult.success) {
        throw new Error(optionsResult.error || 'Failed to start registration');
    }

    const options = optionsResult.options;

    // Step 2: Create credential using WebAuthn API
    const credential = await navigator.credentials.create({
        publicKey: {
            challenge: base64ToArrayBuffer(options.challenge),
            rp: options.rp,
            user: {
                id: base64ToArrayBuffer(options.user.id),
                name: options.user.name,
                displayName: options.user.displayName
            },
            pubKeyCredParams: options.pubKeyCredParams,
            timeout: options.timeout,
            attestation: options.attestation,
            authenticatorSelection: options.authenticatorSelection
        }
    });

    if (!credential) {
        throw new Error('Passkey creation was cancelled');
    }

    // Step 3: Send credential to server for verification
    const verifyResponse = await fetch('/auth/register-verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            credential: credentialToJSON(credential),
            nickname: nickname
        })
    });

    const result = await verifyResponse.json();
    if (!result.success) {
        throw new Error(result.error || 'Registration failed');
    }

    return result;
}

/**
 * Login with existing passkey
 */
async function loginWithPasskey(username) {
    // Step 1: Get authentication options from server
    const optionsResponse = await fetch('/auth/login-options.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username })
    });

    const optionsResult = await optionsResponse.json();
    if (!optionsResult.success) {
        throw new Error(optionsResult.error || 'Failed to start login');
    }

    const options = optionsResult.options;

    // Step 2: Get credential using WebAuthn API
    const credential = await navigator.credentials.get({
        publicKey: {
            challenge: base64ToArrayBuffer(options.challenge),
            timeout: options.timeout,
            rpId: options.rpId,
            allowCredentials: options.allowCredentials.map(cred => ({
                type: cred.type,
                id: base64ToArrayBuffer(cred.id),
                transports: cred.transports
            })),
            userVerification: options.userVerification
        }
    });

    if (!credential) {
        throw new Error('Login was cancelled');
    }

    // Step 3: Send credential to server for verification
    const verifyResponse = await fetch('/auth/login-verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            credential: credentialToJSON(credential)
        })
    });

    const result = await verifyResponse.json();
    if (!result.success) {
        throw new Error(result.error || 'Login failed');
    }

    return result;
}

/**
 * Helper: Convert base64url to ArrayBuffer
 */
function base64ToArrayBuffer(base64) {
    // Handle base64url format (replace - with +, _ with /)
    const base64Cleaned = base64.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64Cleaned);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

/**
 * Helper: Convert ArrayBuffer to base64url
 */
function arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * Helper: Convert credential to JSON-serializable format
 */
function credentialToJSON(credential) {
    const response = credential.response;

    return {
        id: credential.id,
        rawId: arrayBufferToBase64(credential.rawId),
        response: {
            clientDataJSON: arrayBufferToBase64(response.clientDataJSON),
            attestationObject: response.attestationObject
                ? arrayBufferToBase64(response.attestationObject)
                : undefined,
            authenticatorData: response.authenticatorData
                ? arrayBufferToBase64(response.authenticatorData)
                : undefined,
            signature: response.signature
                ? arrayBufferToBase64(response.signature)
                : undefined,
            userHandle: response.userHandle
                ? arrayBufferToBase64(response.userHandle)
                : undefined,
            transports: response.getTransports ? response.getTransports() : []
        },
        type: credential.type
    };
}

/**
 * UI Helpers
 */
function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    errorDiv.textContent = message;
    errorDiv.classList.add('show');
}

function clearError() {
    const errorDiv = document.getElementById('errorMessage');
    errorDiv.textContent = '';
    errorDiv.classList.remove('show');
}

function setLoading(loading, buttonId) {
    const button = document.getElementById(buttonId);
    if (loading) {
        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = 'Processing...';
    } else {
        button.disabled = false;
        button.textContent = button.dataset.originalText || button.textContent;
    }
}
