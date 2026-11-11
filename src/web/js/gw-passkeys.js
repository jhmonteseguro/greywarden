/*
 * Passkeys (WebAuthn) for Greywarden: buttons with data-gw-passkey="login" or "register".
 *
 *   data-gw-passkey="login"     data-options-url, data-submit-url, data-mode ("second-step" or "passwordless")
 *   data-gw-passkey="register"  data-options-url, data-submit-url, data-name-input (selector of the name field)
 *   data-gw-passkey-error       (on any ancestor) selector of the element that shows errors
 *
 * The server sends options with binary values as base64url (lbuchs/webauthn) and receives the
 * browser's response the same way. Nothing here is inline, so the page CSP stays strict.
 */
(function () {
    'use strict';

    function fromB64url(text) {
        var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) { b64 += '='; }
        var bin = atob(b64), bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes.buffer;
    }

    function toB64url(buffer) {
        var bytes = new Uint8Array(buffer), bin = '';
        for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function csrf() {
        var param = document.querySelector('meta[name="csrf-param"]');
        var token = document.querySelector('meta[name="csrf-token"]');
        return param && token ? [param.getAttribute('content'), token.getAttribute('content')] : null;
    }

    function post(url, fields) {
        var body = new URLSearchParams();
        var token = csrf();
        if (token) { body.append(token[0], token[1]); }
        Object.keys(fields).forEach(function (key) {
            var value = fields[key];
            if (value && typeof value === 'object') {
                Object.keys(value).forEach(function (sub) {
                    if (value[sub] !== null && value[sub] !== undefined) { body.append(key + '[' + sub + ']', value[sub]); }
                });
            } else if (value !== null && value !== undefined) {
                body.append(key, value);
            }
        });
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (r) {
            if (!r.ok) { throw new Error('The server answered ' + r.status + '.'); }
            return r.json();
        });
    }

    function showError(button, message) {
        var holder = button.closest('[data-gw-passkey-error]');
        var target = holder ? document.querySelector(holder.getAttribute('data-gw-passkey-error')) : null;
        if (target) {
            target.textContent = message;
            target.hidden = false;
        }
    }

    function clearError(button) {
        var holder = button.closest('[data-gw-passkey-error]');
        var target = holder ? document.querySelector(holder.getAttribute('data-gw-passkey-error')) : null;
        if (target) { target.hidden = true; target.textContent = ''; }
    }

    function handleServer(button, data) {
        if (data && data.redirect && !data.error) { window.location.assign(data.redirect); return null; }
        if (data && data.error) {
            showError(button, data.error);
            if (data.redirect) { window.setTimeout(function () { window.location.assign(data.redirect); }, 1500); }
            return null;
        }
        return data;
    }

    function browserError(button, error) {
        if (error && error.name === 'NotAllowedError') {
            showError(button, 'The passkey request was cancelled or timed out.');
        } else if (error && error.name === 'InvalidStateError') {
            showError(button, 'This device already has a passkey for your account.');
        } else {
            showError(button, (error && error.message) || 'The passkey request failed.');
        }
    }

    function login(button) {
        var mode = button.getAttribute('data-mode') || 'passwordless';
        return post(button.getAttribute('data-options-url'), {mode: mode}).then(function (data) {
            var options = handleServer(button, data);
            if (!options) { return; }
            var pk = options.publicKey;
            pk.challenge = fromB64url(pk.challenge);
            (pk.allowCredentials || []).forEach(function (c) { c.id = fromB64url(c.id); });
            return navigator.credentials.get({publicKey: pk}).then(function (cred) {
                return post(button.getAttribute('data-submit-url'), {
                    mode: mode,
                    credential: {
                        id: toB64url(cred.rawId),
                        clientDataJSON: toB64url(cred.response.clientDataJSON),
                        authenticatorData: toB64url(cred.response.authenticatorData),
                        signature: toB64url(cred.response.signature),
                        userHandle: cred.response.userHandle ? toB64url(cred.response.userHandle) : null
                    }
                }).then(function (result) { handleServer(button, result); });
            });
        });
    }

    function register(button) {
        var nameInput = document.querySelector(button.getAttribute('data-name-input'));
        var name = nameInput ? nameInput.value.trim() : '';
        return post(button.getAttribute('data-options-url'), {}).then(function (data) {
            var options = handleServer(button, data);
            if (!options) { return; }
            var pk = options.publicKey;
            pk.challenge = fromB64url(pk.challenge);
            pk.user.id = fromB64url(pk.user.id);
            (pk.excludeCredentials || []).forEach(function (c) { c.id = fromB64url(c.id); });
            return navigator.credentials.create({publicKey: pk}).then(function (cred) {
                return post(button.getAttribute('data-submit-url'), {
                    name: name,
                    credential: {
                        clientDataJSON: toB64url(cred.response.clientDataJSON),
                        attestationObject: toB64url(cred.response.attestationObject)
                    }
                }).then(function (result) { handleServer(button, result); });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var supported = !!(window.PublicKeyCredential && navigator.credentials && window.fetch);
        document.querySelectorAll('[data-gw-passkey]').forEach(function (button) {
            if (!supported) {
                button.disabled = true;
                showError(button, 'This browser does not support passkeys.');
                return;
            }
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (button.disabled) { return; }
                clearError(button);
                button.disabled = true;
                var run = button.getAttribute('data-gw-passkey') === 'register' ? register : login;
                run(button).catch(function (error) { browserError(button, error); }).then(function () {
                    button.disabled = false;
                });
            });
        });
    });
}());
