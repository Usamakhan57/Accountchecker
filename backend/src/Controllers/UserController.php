<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Auth\SessionManager;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\SessionRepository;
use AccountCheck\Repositories\SettingsRepository;
use AccountCheck\Repositories\UserRepository;
use AccountCheck\Services\SessionPayloadService;

/**
 * The signed-in user's own account: profile, sessions and activity.
 *
 * Every query here is scoped by the authenticated user id, never by an id
 * taken from the request, so one account cannot read another's.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly SessionManager $sessionManager,
        private readonly ActivityLogRepository $activity,
        private readonly SettingsRepository $settings,
        private readonly SessionPayloadService $sessionPayload,
    ) {
    }

    public function me(Request $request): Response
    {
        $user = $this->user($request);

        return Response::success(
            $this->sessionPayload->build($user) + [
                'permissions' => $user->permissions,
                'is_staff' => $user->isStaff(),
                'settings' => $this->settings->publicSettings(),
            ],
            'Session is active',
        );
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->user($request);

        $input = $this->validate($request, ['name' => 'required|string|min:2|max:120']);

        $this->users->updateProfile($user->id, (string) $input['name']);
        $this->activity->record($user->id, 'profile.updated', 'user', $user->id, [], $request->ip());

        return Response::success($this->sessionPayload->build($user), 'Profile updated.');
    }

    /** Devices with a live session, so the user can spot one they do not recognise. */
    public function sessions(Request $request): Response
    {
        $user = $this->user($request);
        $sessions = $this->sessions->listForUser($user->id);

        foreach ($sessions as $index => $session) {
            $sessions[$index]['is_current'] = (int) $session['id'] === $user->sessionId;
        }

        return Response::success(['items' => $sessions], 'Active sessions');
    }

    public function revokeOtherSessions(Request $request): Response
    {
        $user = $this->user($request);

        $revoked = $this->sessionManager->destroyOthers($user->id, $user->sessionId);
        $this->activity->record($user->id, 'sessions.revoked', 'user', $user->id, ['count' => $revoked], $request->ip());

        return Response::success(
            ['revoked' => $revoked],
            $revoked > 0 ? sprintf('%d other session(s) signed out.', $revoked) : 'No other sessions were active.',
        );
    }

    public function activity(Request $request): Response
    {
        $user = $this->user($request);
        $paginator = $this->paginator($request);

        $result = $this->activity->paginateForUser($user->id, $paginator);

        return Response::success(
            $paginator->envelope($result['items'], $result['total']),
            'Recent account activity',
        );
    }
}
