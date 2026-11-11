<?php

declare(strict_types=1);

namespace ConciliaRH\Core\Session;

/**
 * Fachada de sesión: autenticación, mensajes flash y token CSRF.
 */
final class Session
{
    public function __construct(private readonly SessionStore $store)
    {
    }

    public function store(): SessionStore
    {
        return $this->store;
    }

    public function start(): void
    {
        $this->store->start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->store->remove($key);
    }

    // --- Autenticación -------------------------------------------------

    public function login(int $userId): void
    {
        // Nuevo ID de sesión y nuevo token CSRF al elevar privilegios.
        $this->store->regenerate();
        $now = time();
        $this->store->set('_auth', ['user_id' => $userId, 'auth_at' => $now, 'last_activity' => $now]);
        $this->rotateCsrfToken();
    }

    public function logout(): void
    {
        $this->store->invalidate();
    }

    public function userId(): ?int
    {
        $auth = $this->store->get('_auth');
        return is_array($auth) && is_int($auth['user_id'] ?? null) ? $auth['user_id'] : null;
    }

    /** @return array{user_id:int,auth_at:int,last_activity:int}|null */
    public function auth(): ?array
    {
        $auth = $this->store->get('_auth');
        return is_array($auth) && isset($auth['user_id'], $auth['auth_at'], $auth['last_activity']) ? $auth : null;
    }

    public function touch(): void
    {
        $auth = $this->auth();
        if ($auth !== null) {
            $auth['last_activity'] = time();
            $this->store->set('_auth', $auth);
        }
    }

    // --- CSRF -----------------------------------------------------------

    public function csrfToken(): string
    {
        $token = $this->store->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = $this->rotateCsrfToken();
        }
        return $token;
    }

    public function rotateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store->set('_csrf', $token);
        return $token;
    }

    public function isValidCsrfToken(?string $candidate): bool
    {
        $token = $this->store->get('_csrf');
        return is_string($token) && is_string($candidate) && $candidate !== '' && hash_equals($token, $candidate);
    }

    // --- Flash ----------------------------------------------------------

    /** @param 'success'|'error'|'warning'|'info' $type */
    public function flash(string $type, string $message): void
    {
        $flashes = $this->store->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->store->set('_flash', $flashes);
    }

    /** @return list<array{type:string,message:string}> */
    public function consumeFlashes(): array
    {
        $flashes = $this->store->get('_flash', []);
        $this->store->remove('_flash');
        return is_array($flashes) ? $flashes : [];
    }

    /** Conserva datos del formulario para re-mostrar tras un error de validación. */
    public function flashInput(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmacion'], $input['password_actual'], $input['password_nueva']);
        $this->store->set('_old', $input);
    }

    /** @return array<string,mixed> */
    public function consumeOldInput(): array
    {
        $old = $this->store->get('_old', []);
        $this->store->remove('_old');
        return is_array($old) ? $old : [];
    }
}
