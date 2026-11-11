<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevAdminAudit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Explicit, audited internal provisioning; does not depend on billing or SMTP. */
final class DevProvisionController extends Controller
{
    private function audit(Request $request, string $action, Tenant $tenant): void
    {
        DevAdminAudit::create([
            'actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id,
            'action' => $action, 'created_at' => now(),
        ]);
    }

    public function createTenant(Request $request): JsonResponse
    {
        $input = $request->validate([
            'company' => ['required', 'string', 'min:2', 'max:140'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_email' => ['required', 'email:rfc', 'max:255'],
            'owner_password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'plan' => ['required', Rule::in(['free', 'cloud', 'pro'])],
            'seat_limit' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);
        $email = Str::lower(trim($input['owner_email']));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['owner_email' => 'Este e-mail já pertence a um usuário.']);
        }
        $tenant = DB::transaction(function () use ($request, $input, $email): Tenant {
            $tenant = Tenant::create([
                'name' => trim($input['company']),
                'slug' => Str::slug($input['company']).'-'.Str::lower(Str::random(8)),
                'is_active' => true, 'plan' => $input['plan'],
                'seat_limit' => (int) $input['seat_limit'],
                'billing_provider' => 'manual', 'billing_status' => 'unverified',
            ]);
            $owner = User::create([
                'name' => trim($input['owner_name']), 'email' => $email,
                'password' => $input['owner_password'], 'is_active' => true,
                // Internal provisioning does not depend on email delivery.
                // Do not claim the mailbox was verified without proof.
                'email_verified_at' => null,
            ]);
            $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
            $this->audit($request, 'dev.tenant.provisioned', $tenant);
            return $tenant;
        });
        return response()->json(['data' => [
            'id' => $tenant->id, 'name' => $tenant->name, 'plan' => $tenant->plan,
            'billing_source' => 'manual', 'owner_email' => $email,
        ], 'message' => 'Empresa e Master criados pelo DEV. Nenhum pagamento foi confirmado.'], 201);
    }

    public function createUser(Request $request): JsonResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'uuid', Rule::exists('tenants', 'id')],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);
        $email = Str::lower(trim($input['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'E-mail já cadastrado.']);
        }
        $user = DB::transaction(function () use ($request, $input, $email): User {
            $tenant = Tenant::query()->whereKey($input['tenant_id'])->lockForUpdate()->firstOrFail();
            abort_unless($tenant->is_active, 422, 'Empresa inativa.');
            $used = DB::table('tenant_user')->where('tenant_id', $tenant->id)->count();
            if ($used >= $tenant->seat_limit) {
                throw ValidationException::withMessages(['tenant_id' => 'Limite de vagas atingido. Ajuste as vagas no DEV.']);
            }
            $user = User::create([
                'name' => trim($input['name']), 'email' => $email,
                'password' => $input['password'], 'is_active' => true,
                'email_verified_at' => null,
            ]);
            $user->tenants()->attach($tenant->id, ['role' => $input['role']]);
            $this->audit($request, 'dev.user.provisioned', $tenant);
            return $user;
        });
        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
        ], 'message' => 'Usuário criado e vinculado à empresa.'], 201);
    }
}
