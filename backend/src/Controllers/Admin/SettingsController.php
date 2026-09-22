<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\SettingsRepository;
use AccountCheck\Services\AdminService;

/**
 * System settings.
 *
 * Reading is open to any administrator; writing is restricted to SUPER_ADMIN in
 * AdminService, because some of these switch the product off for everyone.
 * `can_edit` tells the interface which it is, so the form is disabled rather
 * than failing on submit.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::success([
            'items' => $this->settings->allWithMetadata(),
            'can_edit' => $this->user($request)->role === 'SUPER_ADMIN',
        ], 'System settings');
    }

    public function update(Request $request): Response
    {
        $input = $this->validate($request, [
            'key' => 'required|string|max:64',
            'value' => 'required|string|max:1000',
        ]);

        $this->admin->updateSetting(
            $this->user($request),
            (string) $input['key'],
            (string) $input['value'],
            $request->ip(),
        );

        return Response::success(
            ['items' => $this->settings->allWithMetadata()],
            'Setting updated',
        );
    }
}
