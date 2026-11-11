<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends AceController
{
    private const FIELDS = [
        'legal_name', 'trade_name', 'document_type', 'document_number', 'contact_email',
        'phone', 'postal_code', 'street', 'street_number', 'address_complement',
        'district', 'city', 'state', 'legal_representative',
    ];

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        return response()->json(['data' => [[
            'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug,
            'role' => $tenant->pivot->role, 'plan' => $tenant->plan ?? 'free',
            'seat_limit' => (int) $tenant->seat_limit,
            'is_active' => (bool) $tenant->is_active,
            ...$tenant->only(self::FIELDS),
        ]]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:140'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'document_type' => ['nullable', 'in:CPF,CNPJ'],
            'document_number' => ['nullable', 'string', 'max:18'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'max:9'],
            'street' => ['nullable', 'string', 'max:180'],
            'street_number' => ['nullable', 'string', 'max:20'],
            'address_complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'legal_representative' => ['nullable', 'string', 'max:140'],
        ]);
        foreach (['document_number', 'postal_code', 'phone'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = preg_replace('/\D/', '', $data[$field]);
            }
        }
        if (! empty($data['document_number'])) {
            $number = $data['document_number'];
            $type = $data['document_type'] ?? $this->tenant($request)->document_type;
            if (! self::validDocument($number, $type)) {
                throw ValidationException::withMessages(['document_number' => 'CPF ou CNPJ inválido.']);
            }
            $data['document_type'] = $type;
            if (\App\Models\Tenant::where('document_number', $number)
                ->where('id', '!=', $this->tenant($request)->id)->exists()) {
                throw ValidationException::withMessages(['document_number' => 'Documento já cadastrado.']);
            }
        } elseif (array_key_exists('document_type', $data) && $data['document_type']
            && ! ($data['document_number'] ?? $this->tenant($request)->document_number)) {
            throw ValidationException::withMessages(['document_number' => 'Informe o documento.']);
        }
        if (isset($data['postal_code']) && $data['postal_code'] !== '' && strlen($data['postal_code']) !== 8) {
            throw ValidationException::withMessages(['postal_code' => 'CEP deve conter 8 dígitos.']);
        }
        if (isset($data['phone']) && $data['phone'] !== '' && ! in_array(strlen($data['phone']), [10, 11], true)) {
            throw ValidationException::withMessages(['phone' => 'Telefone deve conter 10 ou 11 dígitos.']);
        }
        if (isset($data['state'])) $data['state'] = strtoupper($data['state']);
        $tenant = $this->tenant($request);
        $tenant->update($data);
        $this->logAction($request, 'workspace.company.updated');
        return response()->json(['data' => ['id' => $tenant->id, 'name' => $tenant->name, ...$tenant->only(self::FIELDS)]]);
    }

    public function create(Request $request): JsonResponse
    {
        // A new subscription/account must be created via signup, not inside an existing workspace.
        return response()->json(['message' => 'Outra empresa exige nova contratação e outro login.'], 403);
    }

    private static function validDocument(string $number, ?string $type): bool
    {
        if ($type === 'CPF' && preg_match('/^\d{11}$/', $number)) {
            if (count(array_unique(str_split($number))) === 1) return false;
            for ($length = 9; $length <= 10; $length++) {
                $sum = 0;
                for ($i = 0; $i < $length; $i++) $sum += (int) $number[$i] * ($length + 1 - $i);
                $check = ($sum * 10) % 11;
                if ($check === 10) $check = 0;
                if ($check !== (int) $number[$length]) return false;
            }
            return true;
        }
        if ($type === 'CNPJ' && preg_match('/^\d{14}$/', $number)) {
            if (count(array_unique(str_split($number))) === 1) return false;
            foreach ([12, 13] as $length) {
                $weights = $length === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
                $sum = 0;
                for ($i = 0; $i < $length; $i++) $sum += (int) $number[$i] * $weights[$i];
                $check = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
                if ($check !== (int) $number[$length]) return false;
            }
            return true;
        }
        return false;
    }
}
