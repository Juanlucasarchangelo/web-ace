<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Authenticated user's own identity, independent of tenant roles (including DEV). */
class ProfileController extends Controller
{
    private function summary(Request $request): array
    {
        $user = $request->user();
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'job_title' => $user->job_title,
            'has_avatar' => (bool) $user->avatar_path,
            'avatar_url' => $user->avatar_path ? '/api/v1/auth/profile/avatar' : null,
            'is_dev' => (bool) $user->is_dev,
            'dev_notification_emails' => $user->is_dev ? ($user->dev_notification_emails ?? []) : [],
        ];
    }

    private function audit(Request $request, string $action): void
    {
        $user = $request->user();
        $tenantId = $user->is_dev ? null : $user->tenants()->where('tenants.is_active', true)->orderBy('tenants.created_at')->value('tenants.id');
        AuditEvent::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''), 'created_at' => now(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summary($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:100'],
        ]);
        $user->update([
            'name' => trim($data['name']), 'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'] ?? null, 'job_title' => $data['job_title'] ?? null,
        ]);
        $this->audit($request, 'profile.updated');
        return response()->json(['message' => 'Perfil atualizado.', 'data' => $this->summary($request)]);
    }

    /** Alert recipients are independent from login address and managed only by DEV itself. */
    public function notificationEmails(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_dev && $request->user()->is_active, 403);
        $data = $request->validate([
            'emails' => ['required', 'array', 'max:12'],
            'emails.*' => ['required', 'email:rfc', 'max:255'],
        ]);
        $emails = collect($data['emails'])->map(fn ($v) => Str::lower(trim($v)))
            ->unique()->values()->all();
        $request->user()->forceFill(['dev_notification_emails' => $emails])->save();
        $this->audit($request, 'profile.dev_notifications.updated');
        return response()->json(['message' => 'Destinatários DEV atualizados.', 'data' => $this->summary($request)]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Senha atual incorreta.']);
        }
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        // Preserve this browser session; disconnect other devices and API tokens.
        DB::table('sessions')->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())->delete();
        $user->tokens()->delete();
        $this->audit($request, 'profile.password.changed');
        return response()->json(['message' => 'Senha alterada. Outros dispositivos foram desconectados.']);
    }

    public function avatar(Request $request)
    {
        $path = $request->user()->avatar_path;
        abort_unless(is_string($path) && Str::startsWith($path, 'avatars/') && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate(['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $user = $request->user();
        $old = $user->avatar_path;
        $path = $data['avatar']->store('avatars', 'local');
        $user->forceFill(['avatar_path' => $path])->save();
        if ($old) Storage::disk('local')->delete($old);
        $this->audit($request, 'profile.avatar.updated');
        return response()->json(['message' => 'Foto de perfil atualizada.', 'data' => $this->summary($request)]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();
        if ($old) Storage::disk('local')->delete($old);
        $this->audit($request, 'profile.avatar.removed');
        return response()->json(['message' => 'Foto removida.', 'data' => $this->summary($request)]);
    }
}
