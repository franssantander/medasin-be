<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);
        $query = $request->user()->notifications();
        if ($data['unread_only'] ?? false) {
            $query->unread();
        }

        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $page->through(fn (DatabaseNotification $notification): array => $this->serialize($notification));

        return $this->success($page);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return $this->success($this->serialize($item));
    }

    /** @return array<string, mixed> */
    private function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
