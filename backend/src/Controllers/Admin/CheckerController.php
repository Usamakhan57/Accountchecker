<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Checkers\CheckerRegistry;
use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\CheckerTypeRepository;
use AccountCheck\Repositories\StatsRepository;
use AccountCheck\Services\AdminService;

/**
 * Checker administration.
 *
 * Three settings are editable: whether the checker runs, what a check costs and
 * how large a batch may be. Provider credentials are not among them. They come
 * from the environment, are never written to the database, and are never
 * returned here: `configured` says whether a credential is present, which is
 * all an administrator needs to know from a web page.
 */
final class CheckerController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly CheckerTypeRepository $checkers,
        private readonly CheckerRegistry $registry,
        private readonly StatsRepository $stats,
    ) {
    }

    public function index(Request $request): Response
    {
        $usage = [];

        foreach ($this->stats->checkerUsage(30) as $row) {
            $usage[(string) $row['slug']] = $row;
        }

        $items = array_map(
            static function (array $row) use ($usage): array {
                $slug = (string) $row['slug'];

                return [
                    'id' => (int) $row['id'],
                    'slug' => $slug,
                    'label' => (string) $row['label'],
                    'description' => (string) ($row['description'] ?? ''),
                    'credit_cost' => (int) $row['credit_cost'],
                    'max_batch_size' => (int) $row['max_batch_size'],
                    'is_enabled' => (bool) $row['is_enabled'],
                    'usage_30d' => (int) ($usage[$slug]['checks'] ?? 0),
                ];
            },
            $this->checkers->all(),
        );

        // Whether an authorized source is actually configured comes from the
        // registry, not the database: it is a property of the environment.
        $capabilities = [];

        foreach ($this->registry->capabilities() as $capability) {
            $capabilities[(string) $capability['slug']] = $capability;
        }

        foreach ($items as $index => $item) {
            $items[$index]['configured'] = (bool) ($capabilities[$item['slug']]['configured'] ?? false);
            $items[$index]['mode'] = (string) ($capabilities[$item['slug']]['mode'] ?? 'unknown');
        }

        return Response::success(['items' => $items], 'Checkers');
    }

    public function update(Request $request): Response
    {
        $input = $this->validate($request, [
            'is_enabled' => 'boolean',
            'credit_cost' => 'integer',
            'max_batch_size' => 'integer',
        ]);

        $updated = $this->admin->updateChecker(
            $this->user($request),
            (string) $request->routeParam('slug', ''),
            array_intersect_key($input, array_flip(['is_enabled', 'credit_cost', 'max_batch_size'])),
            $request->ip(),
        );

        return Response::success([
            'checker' => [
                'slug' => (string) $updated['slug'],
                'label' => (string) $updated['label'],
                'credit_cost' => (int) $updated['credit_cost'],
                'max_batch_size' => (int) $updated['max_batch_size'],
                'is_enabled' => (bool) $updated['is_enabled'],
            ],
        ], 'Checker updated');
    }
}
