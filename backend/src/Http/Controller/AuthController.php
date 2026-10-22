<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Service\AuthService;
use RegRuControl\Support\Validator;

/**
 * Session authentication for the panel.
 *
 * CSRF is enforced globally for cookie-authenticated unsafe requests, so the
 * login endpoint already receives a token from `GET /api/v1/bootstrap`.
 */
final readonly class AuthController
{
    public function __construct(
        private AuthService $auth,
        private Config $config,
    ) {
    }

    #[Route('POST', '/api/v1/auth/login')]
    public function login(Request $request): Response
    {
        $validator = new Validator($request->body());
        $login = $validator->requiredString('login', 'Логин', 1, 190);
        $password = $validator->requiredString('password', 'Пароль', 1, 200);
        $validator->throwIfInvalid();

        $user = $this->auth->attempt($login, $password, $request->ip());

        return ApiPresenter::data([
            'user' => $user->toArray(),
            'session' => [
                'idle_timeout' => $this->config->int('session.idle', 1800),
            ],
        ]);
    }

    #[Route('POST', '/api/v1/auth/logout', [AuthMiddleware::class])]
    public function logout(Request $request): Response
    {
        AuthContext::fromRequest($request);
        $this->auth->logout();

        return ApiPresenter::noContent();
    }

    #[Route('GET', '/api/v1/auth/me', [AuthMiddleware::class])]
    public function me(Request $request): Response
    {
        $auth = AuthContext::fromRequest($request);
        $user = $this->auth->currentUser();

        return ApiPresenter::data([
            'user' => $user?->toArray(),
            'auth' => $auth->toArray(),
        ]);
    }

    #[Route('POST', '/api/v1/auth/password', [AuthMiddleware::class])]
    public function changePassword(Request $request): Response
    {
        $auth = AuthContext::fromRequest($request);
        $user = $this->auth->currentUser();

        if ($user === null) {
            throw \RegRuControl\Kernel\HttpException::unauthorized();
        }

        $validator = new Validator($request->body());
        $current = $validator->requiredString('current_password', 'Текущий пароль', 1, 200);
        $new = $validator->requiredString('new_password', 'Новый пароль', 8, 200);
        $validator->throwIfInvalid();

        $this->auth->changePassword($user, $current, $new);

        return ApiPresenter::data(['changed' => true]);
    }
}
