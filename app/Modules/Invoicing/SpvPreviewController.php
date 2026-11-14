<?php
declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Modules\Admin\AdminNotificationService;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use Psr\Http\Message\ResponseInterface;

/** Read-only design preview. No ANAF credentials, settings or transmission are accessed. */
final class SpvPreviewController
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->view->render('admin/pages/spv-preview.twig', [
            'admin_section' => 'spv_preview',
            'admin_context' => 'SPV · RO e-Factura',
            'admin_unread_count' => $this->notifications->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
