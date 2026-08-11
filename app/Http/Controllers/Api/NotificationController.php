<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->latest()->limit(30)->get()->map(
                fn (DatabaseNotification $item) => $this->format($item)
            ),
            'meta' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $notification)
    {
        $notifikasi = $request->user()->notifications()->where('id', $notification)->firstOrFail();
        $notifikasi->markAsRead();

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    private function format(DatabaseNotification $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->data['title'] ?? 'Notifikasi',
            'message' => $item->data['message'] ?? '',
            'type' => $item->data['type'] ?? 'umum',
            'action_url' => $item->data['action_url'] ?? null,
            'read_at' => $item->read_at,
            'created_at' => $item->created_at?->diffForHumans(),
        ];
    }
}
